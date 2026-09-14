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
            // E1-PDF: the PDF path supplies what it read, because the server owns
            // no PDF reader (see the branch below).
            'header_rows_text' => 'sometimes|string|max:8000',
            'column_map' => 'sometimes|string|max:20000',
            // PDF-3: a layout learned FROM a verified AI read, never auto-live.
            'source' => 'sometimes|string|in:auto-capture,ai-derived',
        ]);

        $isPdf = ($validated['source_type'] ?? 'excel') === 'pdf';
        $hasFile = $request->hasFile('file');
        $documentId = $request->input('document_id');

        if (!$isPdf && ($hasFile ? 1 : 0) + (!empty($documentId) ? 1 : 0) !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'Provide either an uploaded file or a document_id.',
            ], 422);
        }

        if ($isPdf) {
            // PDF captures arrive pre-read: serializeForAi and deriveAutoColumnMap
            // both need a workbook, and no PDF extractor exists server-side, so the
            // browser's pdf.js pass is the only reader there is. Trust is unchanged
            // — a capture is a PROPOSAL either way, and approval is still the gate.
            $text = trim((string) ($validated['header_rows_text'] ?? ''));
            if ($text === '' || !str_starts_with($text, 'COLUMNS:')) {
                return response()->json([
                    'success' => false,
                    'message' => 'A PDF capture must carry the slice it read.',
                ], 422);
            }
            $columnMap = null;
            if (!empty($validated['column_map'])) {
                $decoded = json_decode($validated['column_map'], true);
                // Only a PDF-shaped map may ride in, so an Excel map can never be
                // stored against a PDF row (the readers key off exactly this).
                if (is_array($decoded) && ($decoded['kind'] ?? null) === 'pdf') {
                    $columnMap = $decoded;
                }
            }
            $contractorId = $this->resolveContractor($validated, $documentId);
        } else {
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

            // E1-3: auto-derive a versioned layout map from the failed bytes (user
            // choice: auto, not reviewer-assigned). Best-effort — null when nothing
            // derivable exists; the capture still stores (reviewable, never consulted).
            $columnMap = null;
            try {
                $columnMap = $this->sCurveImportService->deriveAutoColumnMap($path);
            } catch (\Throwable $e) {
                $columnMap = null;
            }
        }

        // PDF-3 — a layout learned from a verified AI read takes the SAME route the
        // Excel learn-from-AI path uses: storeAiDerived backfills a map-less row or
        // creates a proposed one, never touches occurrences (repeats must not
        // manufacture corroboration for a machine-made map) and can never
        // auto-live. Approval stays the only way a layout becomes readable.
        if ($isPdf && ($validated['source'] ?? 'auto-capture') === 'ai-derived') {
            if ($columnMap === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'An AI-derived layout must carry its map.',
                ], 422);
            }
            $aiHash = hash('sha256', mb_strtolower(preg_replace('/\s+/', ' ', trim($text))));
            $row = ScurveHeaderProposal::storeAiDerived(
                $contractorId,
                $aiHash,
                mb_substr($text, 0, 4000),
                $columnMap
            );
            if ($row->source_type !== 'pdf') {
                $row->update(['source_type' => 'pdf']);
            }

            return response()->json(['success' => true, 'stored' => 1], 201);
        }

        // One identity rule for both kinds: the slice text, normalized.
        $hash = hash('sha256', mb_strtolower(preg_replace('/\s+/', ' ', trim($text))));

        // Scoped by TYPE as well as hash: an Excel row and a PDF row must never
        // merge into each other, since every reader keys off source_type.
        $query = ScurveHeaderProposal::where('header_hash', $hash)
            ->where('source_type', $isPdf ? 'pdf' : 'excel');
        if ($contractorId === null) {
            $query->whereNull('contractor_id');
        } else {
            $query->where('contractor_id', $contractorId);
        }

        $row = $query->first();
        if ($row) {
            $row->increment('occurrences');
            // Phase-B absolute: ai-derived rows NEVER auto-live (a hallucinated layout
            // must not self-trust into baselines) — only human approval flips them.
            //
            // Nor may an UNNUMBERED capture (2026-09-14). Seeing the same ambiguous
            // layout three times corroborates WHERE its columns sit, never WHICH
            // month each one is — the roof prints "30 days" six times however often
            // you import it. Promoting one produced a row badged live and Mapped that
            // the reader still refuses, so the user approved nothing and got "could
            // not read the S-curve" anyway. Repetition cannot supply what the bytes
            // do not contain; only teaching or an AI numbering can.
            $unnumbered = ScurveHeaderProposal::pdfMapIsUnnumbered($row->column_map);
            if ($row->occurrences >= 3 && $row->status === 'proposed'
                && $row->source !== 'ai-derived' && !$unnumbered) {
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

        // PDF-4 (2026-09-13): a PDF row is a different KIND of layout and must not
        // go down the Excel path. It used to: the desk offers Teach on every row,
        // nothing here branched on source_type, so a taught PDF row was handed an
        // EXCEL map ({v, sig, cols}) which the PDF reader then ignored outright
        // (usableLayoutMap gates on kind === 'pdf'). The reviewer saw "saved" and
        // nothing ever changed — and in replace mode the write had already
        // destroyed whatever working map the row held. collect() has refused a
        // non-PDF map on a pdf row since PDF-1; this is the same guard, one
        // endpoint later.
        if (($proposal->source_type ?? 'excel') === 'pdf') {
            try {
                $map = $this->buildTaughtPdfMap($proposal, $validated);
            } catch (RuntimeException $e) {
                // Refused BEFORE any write, which is what stops replace destroying
                // a good map on the way to a bad one.
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return $this->storeTaughtMap($proposal, $map, $validated['mode'] ?? 'variant');
        }

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

        return $this->storeTaughtMap($proposal, $map, $validated['mode'] ?? 'variant');
    }

    /**
     * Save a taught map as a variant (default) or over the row it came from.
     *
     * Extracted 2026-09-13 so the Excel and PDF branches cannot drift on the part
     * that matters most: approval stays the only path to live either way, a
     * variant is a SIBLING row so alternating contractor formats are never
     * forgotten, and an explicit replace drops the row back to proposed.
     *
     * Called by: teachColumns(), both branches.
     */
    protected function storeTaughtMap(ScurveHeaderProposal $proposal, array $map, string $mode)
    {
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
     * PDF-4: turn reviewer-picked letters into a PDF-shaped layout map.
     *
     * A PDF map is geometry, not column letters: bands expressed as FRACTIONS of
     * the page width, so the same layout still fits a different page size or DPI.
     * The bridge between the two is pseudoCols — [{letter, x, xEnd}] recorded by
     * the capture — so a taught letter converts straight back into the band it
     * was drawn from.
     *
     * REFUSES rather than guesses, in three cases, and each refusal happens BEFORE
     * anything is written:
     *  - no recorded geometry. An AMBIGUOUS capture (positional wording like the
     *    roof's repeated "30 days", where nothing in the bytes says which month a
     *    column is) is stored with column_map NULL, and pseudoCols goes with it.
     *    Letters without coordinates cannot become bands.
     *  - MULTI-PAGE layouts. The desk grid renders the first table page only, so a
     *    reviewer literally cannot see the months printed on later pages. Saving
     *    what they can see would teach a SHORT schedule — the exact defect that
     *    made single-page capture unsafe in the first place — so it is refused
     *    until the grid can show every page.
     *  - geometry that is not a table: fewer than two months, overlapping month
     *    bands, or a description column sitting inside one. Same spirit as
     *    buildTaughtMap's gates on the Excel side.
     *
     * The header-row picker does NOT re-key a PDF map, and that is deliberate:
     * sig is matched against individual pdf.js TOKENS, and a token may contain a
     * space ("30 days" is one token). Rebuilding sig by splitting the stored cell
     * text would shatter such tokens and the map would stop matching its own page.
     * The capture already recorded which printed line is the header, and its exact
     * tokens, so that is what the taught map keeps.
     *
     * Called by: teachColumns() for source_type 'pdf'.
     * @throws RuntimeException with a reviewer-facing reason.
     */
    protected function buildTaughtPdfMap(ScurveHeaderProposal $proposal, array $validated): array
    {
        $map = $proposal->column_map;

        if (!is_array($map) || ($map['kind'] ?? null) !== 'pdf' || empty($map['pages']) || !is_array($map['pages'])) {
            throw new RuntimeException('This capture did not record where its columns sit on the page, so there is nothing to point at yet. Re-import the PDF to capture it again.');
        }

        // Letters run on across the printed pages (page 1 = A…K, page 2 = L…M), so a
        // letter identifies BOTH a page and a band on it. That is what lets the desk
        // render a column-split table as one grid and still teach it: the reviewer
        // points at one table, and the map it produces keeps the pages separate.
        $bands = [];
        foreach ($map['pages'] as $pageIndex => $page) {
            foreach (($page['pseudoCols'] ?? []) as $pc) {
                if (!is_array($pc) || !isset($pc['letter'], $pc['x'], $pc['xEnd'])) {
                    continue;
                }
                $bands[strtoupper(trim((string) $pc['letter']))] = [
                    'page' => $pageIndex,
                    'x' => (float) $pc['x'],
                    'xEnd' => (float) $pc['xEnd'],
                ];
            }
        }

        if (count($bands) === 0) {
            throw new RuntimeException('This capture recorded no column positions, so its letters cannot be turned into a layout. Re-import the PDF to capture it again.');
        }

        $bandFor = function (string $letter, string $field) use ($bands): array {
            $key = strtoupper(trim($letter));
            if (!isset($bands[$key])) {
                throw new RuntimeException("Column \"{$letter}\" has no recorded position on the page — pick a letter from the grid for {$field}.");
            }

            return $bands[$key];
        };

        $desc = $bandFor($validated['desc'], 'the description');

        // Group the taught months by the page their columns live on.
        $byPage = [];
        foreach ($validated['months'] as $m => $cols) {
            $mi = (int) $m;
            $x = null;
            $xEnd = null;
            $onPage = null;
            foreach ((array) $cols as $letter) {
                $b = $bandFor((string) $letter, "month {$mi}");
                if ($onPage !== null && $onPage !== $b['page']) {
                    throw new RuntimeException("Month {$mi} was given columns from two different printed pages — a month lives on one page.");
                }
                $onPage = $b['page'];
                $x = $x === null ? $b['x'] : min($x, $b['x']);
                $xEnd = $xEnd === null ? $b['xEnd'] : max($xEnd, $b['xEnd']);
            }
            $byPage[$onPage][] = ['index' => $mi, 'x' => $x, 'xEnd' => $xEnd];
        }

        // WIDEN THE TAUGHT DESCRIPTION TO A BAND, the way a DERIVED map computes it.
        //
        // A reviewer points at where the work names sit — one column. But descRight
        // does not mean "where the names end", it means "where the numbers begin":
        // derivePdfLayout takes it from the header boundary before the first
        // numbered column, so on the roof it spans ITEM NO. | DESCRIPTION | UNIT |
        // QTY (A–D) and NOT the DESCRIPTION strip alone. Measured on the real file:
        // any band reaching the first numbered column reads the true curve
        // (6.32/24.31/…), while A-alone AND B-alone both read 2.48/10.8/… — so a
        // single pseudo-column is wrong even when the reviewer picks the RIGHT one.
        //
        // This only became possible to get wrong when §2 split that left block into
        // separate columns; before it, one letter WAS the whole band. So extend to
        // the left edge of the nearest thing the reviewer assigned to something
        // else on this page — their own answers bound it, nothing is guessed.
        $stops = [];
        foreach (['contract', 'weight', 'code'] as $field) {
            $letter = $validated[$field] ?? null;
            if ($letter === null || trim((string) $letter) === '') {
                continue;
            }
            $b = $bandFor((string) $letter, $field);
            if ($b['page'] === $desc['page'] && $b['x'] > $desc['x'] + 1e-9) {
                $stops[] = $b['x'];
            }
        }
        foreach (($byPage[$desc['page']] ?? []) as $mc) {
            if ($mc['x'] > $desc['x'] + 1e-9) {
                $stops[] = $mc['x'];
            }
        }
        if (count($stops) > 0) {
            $desc['xEnd'] = max($desc['xEnd'], min($stops));
        }

        $allMonths = array_merge(...array_values($byPage));
        if (count($allMonths) < 2) {
            throw new RuntimeException('A layout needs at least two month columns to describe a schedule.');
        }

        $seen = [];
        foreach ($allMonths as $mc) {
            if (isset($seen[$mc['index']])) {
                throw new RuntimeException("Month {$mc['index']} was assigned twice — each month belongs to one set of columns.");
            }
            $seen[$mc['index']] = true;
        }

        $taughtPages = [];
        foreach ($map['pages'] as $pageIndex => $page) {
            $monthCols = $byPage[$pageIndex] ?? [];
            usort($monthCols, fn ($a, $b) => $a['index'] <=> $b['index']);

            // Within a page, ascending month numbers must run left to right and no
            // two may claim the same strip — a band overlapping its neighbour would
            // count one printed cell into two different months.
            for ($i = 1; $i < count($monthCols); $i++) {
                if ($monthCols[$i]['x'] < $monthCols[$i - 1]['xEnd'] - 1e-9) {
                    throw new RuntimeException("Month {$monthCols[$i]['index']} starts left of where month {$monthCols[$i - 1]['index']} ends — months must run left to right without overlapping.");
                }
            }

            $taught = $page;
            unset($taught['unnumbered']);
            $taught['monthCols'] = array_values($monthCols);

            // The description belongs to the page it was pointed at; a continuation
            // page legitimately has none, exactly as a capture of one does.
            if ($desc['page'] === $pageIndex) {
                foreach ($monthCols as $mc) {
                    if ($desc['x'] < $mc['xEnd'] - 1e-9 && $mc['x'] < $desc['xEnd'] - 1e-9) {
                        throw new RuntimeException("The description column overlaps month {$mc['index']} — the work names and the monthly figures cannot share a column.");
                    }
                }
                $taught['descX'] = $desc['x'];
                $taught['descRight'] = $desc['xEnd'];
            }

            // A page the reviewer gave no months to is dropped rather than stored
            // empty: usableLayoutMap refuses a month-less page anyway, and keeping
            // it would leave a half-taught layout looking complete.
            if (count($monthCols) > 0) {
                $taughtPages[] = $taught;
            }
        }

        return [
            // Inherit the capture's version rather than inventing one: the reader
            // gates on an exact match, so a taught map must age with its source.
            'v' => $map['v'] ?? 1,
            'kind' => 'pdf',
            'pages' => $taughtPages,
        ];
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
