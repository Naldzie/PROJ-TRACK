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
