<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\Project;
use App\Models\ScurveHeaderProposal;
use App\Services\SCurveImportService;
use App\Traits\LogsActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * ScurveHeaderProposalController
 *
 * E1-1 silent capture: stashes header blocks from failed S-curve imports
 * ("No S-curve table found" 422s) as proposed layout rows. Collection is open to
 * admin AND staff (E1b: same trust as keywords); contractors get 403 (read-only,
 * they never upload). Captures never affect extraction until E1-2 review.
 */
class ScurveHeaderProposalController extends Controller
{
    use LogsActivity;

    public function __construct(
        protected SCurveImportService $sCurveImportService,
    ) {}

    public function collect(Request $request): JsonResponse
    {
        if (!in_array(auth()->user()->role, ['admin', 'staff'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'contractor_id' => 'nullable|integer|exists:contractors,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'source_type' => 'sometimes|string|in:excel,pdf',
        ]);

        $hasFile = $request->hasFile('file');
        $documentId = $request->input('document_id');

        if (($hasFile ? 1 : 0) + (!empty($documentId) ? 1 : 0) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Provide either an uploaded file or a document_id.',
            ], 422);
        }

        try {
            $path = $this->resolveSource($request, $hasFile, $documentId);
            $contractorId = $this->resolveContractor($validated, $documentId);
            $text = $this->sCurveImportService->serializeForAi($path, 15);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $hash = hash('sha256', mb_strtolower(preg_replace('/\s+/', ' ', trim($text))));

        // E1-3: auto-derive a versioned layout map from the failed bytes (user
        // choice: auto, not reviewer-assigned). Best-effort — null when nothing
        // derivable exists; the capture still stores (reviewable, never consulted).
        $columnMap = null;
        try {
            $columnMap = $this->sCurveImportService->deriveAutoColumnMap($path);
        } catch (\Throwable $e) {
            $columnMap = null;
        }

        $query = ScurveHeaderProposal::where('header_hash', $hash);
        if ($contractorId === null) {
            $query->whereNull('contractor_id');
        } else {
            $query->where('contractor_id', $contractorId);
        }

        $row = $query->first();
        if ($row) {
            $row->increment('occurrences');
            if ($row->occurrences >= 3 && $row->status === 'proposed') {
                $row->update(['status' => 'live']);
            }
            // Backfill pre-E1-3 rows captured when maps were always null.
            if ($row->column_map === null && $columnMap !== null) {
                $row->update(['column_map' => $columnMap]);
            }
        } else {
            ScurveHeaderProposal::create([
                'contractor_id' => $contractorId,
                'source_type' => $validated['source_type'] ?? 'excel',
                'header_rows_text' => mb_substr($text, 0, 4000),
                'header_hash' => $hash,
                'column_map' => $columnMap,
                'occurrences' => 1,
                'status' => 'proposed',
                'source' => 'auto-capture',
            ]);
        }

        return response()->json(['success' => true, 'stored' => 1], 201);
    }

    /**
     * List captures for the review screen (?status=proposed|live, default all).
     * Most-seen first so the highest-value layouts are reviewed first.
     */
    public function getAllForManagement(Request $request)
    {
        $this->ensureReviewer();

        $query = ScurveHeaderProposal::with('contractor')->orderByDesc('occurrences')->orderBy('id');
        if (in_array($request->query('status'), ['proposed', 'live'], true)) {
            $query->where('status', $request->query('status'));
        }

        $rows = $query->get()->map(function ($row) {
            return [
                'id' => $row->id,
                'contractor_id' => $row->contractor_id,
                'contractor' => $row->contractor
                    ? ($row->contractor->company_name ?: $row->contractor->contractor_name)
                    : '',
                'source_type' => $row->source_type,
                'header_rows_text' => $row->header_rows_text ?? '',
                'header_hash' => $row->header_hash ?? '',
                'column_map' => $row->column_map,
                'occurrences' => $row->occurrences,
                'status' => $row->status,
                'source' => $row->source ?? 'auto-capture',
                'has_map' => $row->column_map !== null,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        });

        return response()->json($rows);
    }

    /**
     * Approve a capture: flip it live so the E1-3 reader may consult it.
     * The auto-derived column_map (when derivable) rides along; approval
     * trusts this (contractor, header-block, column_map) mapping.
     */
    public function approve(ScurveHeaderProposal $proposal)
    {
        $this->ensureReviewer();

        $proposal->update(['status' => 'live']);

        $who = $proposal->contractor
            ? ($proposal->contractor->company_name ?: $proposal->contractor->contractor_name)
            : 'global';
        $this->logActivity('Approve S-Curve Header', "Approved S-curve header capture #{$proposal->id} ({$who})");

        return response()->json([
            'success' => true,
            'message' => 'Header approved successfully',
            'proposal' => $proposal->fresh(),
        ]);
    }

    /**
     * Reject a capture: soft-delete it (recoverable via restore, keeps history).
     */
    public function destroy(ScurveHeaderProposal $proposal)
    {
        $this->ensureReviewer();

        $id = $proposal->id;
        $proposal->delete();

        $this->logActivity('Reject S-Curve Header', "Rejected S-curve header capture #{$id}");

        return response()->json([
            'success' => true,
            'message' => 'Header rejected successfully',
        ]);
    }

    /**
     * Phase-A point-and-teach: build a column map from reviewer-picked letters.
     *
     * The desk sends letters (desc + optional code/weight/contract + per-month
     * letter groups + 1-2 header R-lines); the server converts to 0-based
     * indices, validates like an auto-map, and stamps v/sig itself — client sigs
     * are never trusted. Mode variant (default) saves a SIBLING row so
     * alternating contractor formats are never forgotten; explicit replace
     * overwrites this row's map AND drops it to proposed for re-review.
     * Approval stays the sole path to live either way.
     */
    public function teachColumns(Request $request, ScurveHeaderProposal $proposal)
    {
        $this->ensureReviewer();

        $validated = $request->validate([
            'mode' => 'sometimes|string|in:variant,replace',
            'desc' => 'required|string|max:3',
            'code' => 'nullable|string|max:3',
            'weight' => 'nullable|string|max:3',
            'contract' => 'nullable|string|max:3',
            'months' => 'required|array|min:2|max:12',
            'months.*' => 'required|array|min:1|max:2',
            'months.*.*' => 'required|string|max:3',
            'header_rows' => 'required|array|min:1|max:2',
            'header_rows.*' => 'required|integer|min:1|max:40',
        ]);

        // Parse the stored slice: COLUMNS legend + R<n> [ROLE]: csv lines.
        $parsed = $this->parseStoredSlice($proposal->header_rows_text ?? '');
        if ($parsed === null) {
            return response()->json([
                'success' => false,
                'message' => 'This capture has no readable header block to teach from.',
            ], 422);
        }
        [$letters, $rlines] = $parsed;

        $toIndex = function (?string $letter, string $field) use ($letters): ?int {
            if ($letter === null || trim($letter) === '') {
                return null;
            }
            $key = strtoupper(trim($letter));
            $idx = array_search($key, $letters, true);
            if ($idx === false) {
                throw new RuntimeException("Unknown column \"{$letter}\" for {$field} — pick a letter from the grid.");
            }

            return (int) $idx;
        };

        try {
            $descCol = $toIndex($validated['desc'], 'description');
            $codeCol = $toIndex($validated['code'] ?? null, 'item code');
            $weightCol = $toIndex($validated['weight'] ?? null, 'weight');
            $contractCol = $toIndex($validated['contract'] ?? null, 'contract amount');

            $monthSpans = [];
            foreach ($validated['months'] as $m => $cols) {
                $mi = (int) $m;
                $mapped = [];
                foreach ((array) $cols as $letter) {
                    $mapped[] = $toIndex($letter, "month {$mi}");
                }
                $monthSpans[$mi] = $mapped;
            }

            // Merge the taught header R-lines positionally (fixed width keeps
            // letter alignment when lines differ in length). Non-empty wins;
            // both differ join with a space (mirrors mergeHeaderRows).
            $width = count($letters);
            $merged = array_fill(0, $width, '');
            foreach ($validated['header_rows'] as $rno) {
                if (!isset($rlines[$rno])) {
                    throw new RuntimeException("Header row R{$rno} is not in the stored block.");
                }
                foreach ($rlines[$rno] as $col => $cell) {
                    if ($col >= $width) continue;
                    $cell = trim((string) $cell);
                    if ($cell === '') continue;
                    if ($merged[$col] === '') {
                        $merged[$col] = $cell;
                    } elseif (strtolower($merged[$col]) !== strtolower($cell)) {
                        $merged[$col] .= ' ' . $cell;
                    }
                }
            }

            $map = $this->sCurveImportService->buildTaughtMap(
                array_values($merged),
                (int) $descCol,
                $codeCol,
                $weightCol,
                $contractCol,
                $monthSpans
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if ($map === null) {
            return response()->json([
                'success' => false,
                'message' => 'Those columns do not form a usable layout (need a description column plus at least two non-overlapping months, left to right).',
            ], 422);
        }

        // Merged cells are keyed by position — reindex to 0-based for the builder.
        $mode = $validated['mode'] ?? 'variant';
        $sourceId = $proposal->id;
        if ($mode === 'replace') {
            $proposal->update(['column_map' => $map, 'status' => 'proposed']);
            $this->logActivity('Teach S-Curve Columns', "Re-taught header capture #{$sourceId} (re-review required)");
        } else {
            $proposal = ScurveHeaderProposal::create([
                'contractor_id' => $proposal->contractor_id,
                'source_type' => $proposal->source_type ?? 'excel',
                'header_rows_text' => $proposal->header_rows_text,
                'header_hash' => $proposal->header_hash,
                'column_map' => $map,
                'occurrences' => 1,
                'status' => 'proposed',
                'source' => 'manual-teach',
            ]);
            $this->logActivity('Teach S-Curve Columns', "Taught a layout variant from capture #{$sourceId} (new #{$proposal->id})");
        }

        return response()->json([
            'success' => true,
            'message' => $mode === 'replace' ? 'Layout updated — re-review required.' : 'Layout variant saved for review.',
            'mode' => $mode,
            'proposal' => $proposal->fresh(),
        ]);
    }

    /**
     * Parse a stored header slice into [letters, rlines] for teaching.
     *
     * Letters come from the COLUMNS legend; rlines maps R-number => cell list
     * (proper CSV split — quoted cells break naive comma splits). Returns null
     * when the block carries no usable structure.
     */
    protected function parseStoredSlice(string $text): ?array
    {
        $lines = preg_split('/\r?\n/', trim($text));
        if (!$lines || count($lines) < 2) {
            return null;
        }

        $legend = array_shift($lines);
        if (!str_starts_with($legend, 'COLUMNS:')) {
            return null;
        }
        $legendBody = trim(explode('|', mb_substr($legend, mb_strlen('COLUMNS:')), 2)[0] ?? '');
        $letters = array_map(fn ($s) => strtoupper(trim($s)), explode(',', $legendBody));
        $letters = array_values(array_filter($letters, fn ($s) => $s !== ''));
        if (count($letters) === 0) {
            return null;
        }

        $rlines = [];
        foreach ($lines as $line) {
            if (!preg_match('/^R(\d+)\s*\[([A-Z]+)\]:\s?(.*)$/', trim($line), $m)) {
                continue;
            }
            $cells = str_getcsv($m[3]);
            // Pad short rows so letter positions stay aligned.
            $cells = array_pad(array_values($cells), count($letters), '');
            $rlines[(int) $m[1]] = $cells;
        }
        if (count($rlines) === 0) {
            return null;
        }

        return [$letters, $rlines];
    }

    /**
     * Review gate shared by every management endpoint (E1b: staff + admin).
     */
    protected function ensureReviewer()
    {
        if (!in_array(auth()->user()->role, ['admin', 'staff'], true)) {
            abort(response()->json(['message' => 'Unauthorized'], 403));
        }
    }

    /**
     * Resolve the workbook to a filesystem path (same dual input as the importer).
     *
     * Reads stored bytes via the Storage disk (not full_path) so Storage::fake()
     * feature tests exercise the same code as production.
     *
     * @throws RuntimeException
     */
    protected function resolveSource(Request $request, bool $hasFile, mixed $documentId): string
    {
        if ($hasFile) {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, ['xls', 'xlsx'], true)) {
                throw new RuntimeException('Only Excel (.xls / .xlsx) header blocks can be captured.');
            }

            return $file->getRealPath();
        }

        $document = Document::find($documentId);
        if (!$document || !$document->fileExists()) {
            throw new RuntimeException('The selected document could not be found.');
        }

        $extension = strtolower($document->getFileExtensionAttribute());
        if (!in_array($extension, ['xls', 'xlsx'], true)) {
            throw new RuntimeException('Only Excel (.xls / .xlsx) header blocks can be captured.');
        }

        return Storage::disk('public')->path($document->file_path);
    }

    /**
     * Resolve the contractor: explicit id wins, else the document's project,
     * else null (global map per E1a hybrid scope).
     */
    protected function resolveContractor(array $validated, mixed $documentId): ?int
    {
        if (!empty($validated['contractor_id'])) {
            return Contractor::where('id', $validated['contractor_id'])->exists()
                ? (int) $validated['contractor_id']
                : null;
        }

        if (!empty($validated['project_id'])) {
            $project = Project::find($validated['project_id']);

            return $project?->contractor_id;
        }

        if (!empty($documentId)) {
            $document = Document::find($documentId);

            return $document?->project?->contractor_id;
        }

        return null;
    }
}
