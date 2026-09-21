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
 * SCurveHeaderProposalController
 *
 * E1-1 silent capture: stashes header blocks from failed S-curve imports
 * ("No S-curve table found" 422s) as proposed layout rows. Collection is open to
 * admin AND staff (E1b: same trust as keywords); contractors get 403 (read-only,
 * they never upload). Captures never affect extraction until E1-2 review.
 *
 * 2026-09-15: the class was declared `ScurveHeaderProposalController` (lower-case
 * "c") inside SCurveHeaderProposalController.php. PSR-4 maps a class name to a file
 * path letter for letter, so it only resolved because routes/web.php happens to
 * import the FILE's spelling — any reference to the declared name would fail to
 * autoload on a case-sensitive (Linux) server. The declaration now matches the
 * file, the routes and its sibling SCurveImportController.
 */
class SCurveHeaderProposalController extends Controller
{
    use LogsActivity;

    public function __construct(
        protected SCurveImportService $sCurveImportService,
    ) {}

    public function collect(Request $request): JsonResponse
    {
        // Same gate as every other endpoint here. It used to be an inline copy —
        // identical today, but free to drift the day one of them changed (2026-09-15).
        $this->ensureReviewer();

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
            // 2026-09-15: the browser's answer to "does this capture's own layout read
            // the file it came from?" (captureReadsItsOwnFile). Absent = not checked.
            'capture_check' => 'sometimes|string|in:reads,refused,unread',
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
            // The type is part of the match (2026-09-15). The old after-the-fact
            // `update(['source_type' => 'pdf'])` is gone: it is what could turn a
            // matching Excel row into a PDF row.
            ScurveHeaderProposal::storeAiDerived(
                $contractorId,
                $aiHash,
                mb_substr($text, 0, 4000),
                $columnMap,
                'pdf'
            );

            return response()->json(['success' => true, 'stored' => 1], 201);
        }

        // One identity rule for both kinds: the slice text, normalized.
        $hash = hash('sha256', mb_strtolower(preg_replace('/\s+/', ' ', trim($text))));

        // Scoped by TYPE as well as hash: an Excel row and a PDF row must never
        // merge into each other, since every reader keys off source_type.
        // findForCapture (2026-09-21) also sees DELETED rows, preferring an active one.
        $row = ScurveHeaderProposal::findForCapture($hash, $isPdf ? 'pdf' : 'excel', $contractorId);
        if ($row) {
            $row->increment('occurrences');
            // A REJECTED layout — or one deleted after rejection — stays refused
            // (2026-09-21, parity with the keyword desk). It used to be soft-deleted
            // on Reject, which this lookup could not see, so the very next failed
            // import re-captured it as a fresh Proposed row and the reviewer judged
            // it again with no memory of the decision. The count still rises (it is
            // evidence); nothing else changes. Only the desk can undo a rejection.
            if ($row->isRefused()) {
                return response()->json(['success' => true, 'stored' => 1, 'refused' => true], 201);
            }
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
            // Nor a row with NO map at all — the Excel twin of the same mistake,
            // confirmed on the SEGMENT specimen 2026-09-14. When the rules cannot
            // find the header (GAP 2: every row tags [DATA]) the capture stores
            // column_map null, and promoting that produced a LIVE row the reader
            // has nothing to read with. Repetition cannot derive a layout either.
            //
            // Nor a PDF capture whose own layout does NOT read its own file (2026-09-15).
            // The one-page BUHI's capture is numbered ("Month 1…12") but its bands start
            // at the labels, the half-month misread the read guard refuses. Seen three
            // times it went live, could never read the file, and shadowed the reviewer's
            // correct taught layout. The server owns no PDF reader, so the browser checks
            // and says so (capture_check). A capture checked as anything but "reads" stays
            // proposed; an unchecked one (older pages, Excel) keeps the old rule.
            $selfCheckFailed = $isPdf && isset($validated['capture_check'])
                && $validated['capture_check'] !== 'reads';
            $unreadable = $row->column_map === null
                || ScurveHeaderProposal::pdfMapIsUnnumbered($row->column_map)
                || $selfCheckFailed;
            if ($row->occurrences >= 3 && $row->status === 'proposed'
                && $row->source !== 'ai-derived' && !$unreadable) {
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
     * List captures for the review screen (?status=proposed|live|rejected, default all).
     * Most-seen first so the highest-value layouts are reviewed first.
     */
    public function getAllForManagement(Request $request)
    {
        $this->ensureReviewer();

        $query = ScurveHeaderProposal::with('contractor')->orderByDesc('occurrences')->orderBy('id');
        if (in_array($request->query('status'), ['proposed', 'live', 'rejected'], true)) {
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

        // APPROVING AN UNREADABLE LAYOUT IS A NO-OP, so refuse instead of pretending
        // (2026-09-14). Two captures reach the desk with nothing the reader can use:
        // an Excel one whose header the rules could not find (column_map null), and
        // an ambiguous PDF one whose months carry no numbering. Both went live on a
        // click, showed "live", and the next import still answered "could not read
        // the S-curve" — the reviewer was told the opposite of what happened.
        // Teaching is what these rows need, and the message says so.
        $map = $proposal->column_map;
        if ($map === null) {
            return response()->json([
                'success' => false,
                'message' => 'This capture has no column map, so approving it would change nothing — '
                    . 'the reader still could not use it. Teach its columns first, then approve.',
            ], 422);
        }
        if (ScurveHeaderProposal::pdfMapIsUnnumbered($map)) {
            return response()->json([
                'success' => false,
                'message' => 'This layout records where the columns sit but not which month each one is, '
                    . 'so approving it would change nothing. Teach the months first, then approve.',
            ], 422);
        }

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
     * Reject a capture: record the verdict (status 'rejected') and KEEP the row.
     *
     * 2026-09-21: this used to soft-delete, the same mistake the keyword desk fixed
     * on 2026-09-20 — collect() could not see a deleted row, so the next failed
     * import re-captured the layout as a new Proposed row. The row now stays in the
     * Rejected tab and collect() keeps refusing it. A live layout rejected here
     * stops being consulted by the reader (it only reads status 'live').
     */
    public function destroy(ScurveHeaderProposal $proposal)
    {
        $this->ensureReviewer();

        $id = $proposal->id;
        $proposal->update(['status' => 'rejected']);

        $this->logActivity('Reject S-Curve Header', "Rejected S-curve header capture #{$id}");

        return response()->json([
            'success' => true,
            'message' => 'Header rejected successfully',
        ]);
    }

    /* ─── Deleting rejected layouts (2026-09-21, parity with the keyword desk) ──
     * Delete (admin + staff) SOFT-deletes a REJECTED row: it leaves the desk, but
     * collect() still sees it (findForCapture) and keeps refusing the layout. The
     * Deleted tab, Restore (back to Rejected) and Permanent delete are ADMIN-only.
     * Permanent delete is the one action after which a capture may come back.
     * ────────────────────────────────────────────────────────────────────── */

    // Admin-only gate for the Deleted tab's endpoints (the role middleware turns
    // staff away first; this is the second gate).
    protected function ensureAdmin()
    {
        if (auth()->user()->role !== 'admin') {
            abort(response()->json(['message' => 'Only an admin can manage deleted layouts.'], 403));
        }
    }

    /**
     * Soft-delete a rejected layout (tidies the desk; the refusal still holds).
     * Called by: the Delete button on a Rejected card in the review modal.
     */
    public function trash(ScurveHeaderProposal $proposal)
    {
        $this->ensureReviewer();

        // Only a decided refusal may be tidied away — a proposed row would skip
        // review, and a live one would silently stop being read.
        if ($proposal->status !== 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'Only rejected layouts can be deleted. Reject it first.',
            ], 422);
        }

        $proposal->delete();

        $this->logActivity('Delete S-Curve Header', "Deleted rejected S-curve header capture #{$proposal->id}");

        return response()->json([
            'success' => true,
            'message' => 'Rejected layout deleted',
        ]);
    }

    /**
     * List soft-deleted layouts for the admin's Deleted tab (newest deletion first).
     * Same row shape as getAllForManagement() plus deleted_at.
     * Called by: loadScurveHeadersForManagement() when the viewer is an admin.
     */
    public function getDeleted()
    {
        $this->ensureReviewer();
        $this->ensureAdmin();

        $rows = ScurveHeaderProposal::onlyTrashed()->with('contractor')
            ->orderByDesc('deleted_at')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'contractor_id' => $row->contractor_id,
                    'contractor' => $row->contractor
                        ? ($row->contractor->company_name ?: $row->contractor->contractor_name)
                        : '',
                    'source_type' => $row->source_type,
                    'header_rows_text' => $row->header_rows_text ?? '',
                    'column_map' => $row->column_map,
                    'occurrences' => $row->occurrences,
                    'status' => $row->status,
                    'source' => $row->source ?? 'auto-capture',
                    'has_map' => $row->column_map !== null,
                    'deleted_at' => $row->deleted_at,
                ];
            });

        return response()->json($rows);
    }

    /**
     * Restore a deleted layout to the desk. It ALWAYS returns as Rejected: rows
     * deleted before 2026-09-21 were rejected by soft-delete while their status
     * still read proposed or live, and restoring one of those must not silently
     * put a layout back in front of the reader.
     * Called by: the Restore button on the admin's Deleted tab.
     */
    public function restore(ScurveHeaderProposal $proposal)
    {
        $this->ensureReviewer();
        $this->ensureAdmin();

        if (!$proposal->trashed()) {
            return response()->json(['success' => false, 'message' => 'This layout is not deleted.'], 422);
        }

        $proposal->restore();
        $proposal->update(['status' => 'rejected']);

        $this->logActivity('Restore S-Curve Header', "Restored S-curve header capture #{$proposal->id} to Rejected");

        return response()->json([
            'success' => true,
            'message' => 'Layout restored to Rejected',
        ]);
    }

    /**
     * Permanently delete a soft-deleted layout. The loop forgets it, so the next
     * failed import of the same file captures it again as a new Proposed row.
     * Called by: the Delete permanently button on the admin's Deleted tab.
     */
    public function forceDestroy(ScurveHeaderProposal $proposal)
    {
        $this->ensureReviewer();
        $this->ensureAdmin();

        // Two-step on purpose: only something already in the Deleted tab can be
        // destroyed, so no single click erases a row still on the desk.
        if (!$proposal->trashed()) {
            return response()->json(['success' => false, 'message' => 'Delete the layout first; only deleted layouts can be removed permanently.'], 422);
        }

        $id = $proposal->id;
        $proposal->forceDelete();

        $this->logActivity('Permanently Delete S-Curve Header', "Permanently deleted S-curve header capture #{$id}");

        return response()->json([
            'success' => true,
            'message' => 'Layout permanently deleted',
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
            // Up to MAX_MONTHS (2026-09-16; was 12) — a longer project was unteachable.
            'months' => 'required|array|min:2|max:' . SCurveImportService::MAX_MONTHS,
            'months.*' => 'required|array|min:1|max:2',
            'months.*.*' => 'required|string|max:3',
            'header_rows' => 'required|array|min:1|max:2',
            'header_rows.*' => 'required|integer|min:1|max:40',
            // Row roles (2026-09-14). Both optional: an untaught layout behaves
            // exactly as before, which is what lets this ship without a re-teach.
            'excluded_rows' => 'nullable|array|max:40',
            'excluded_rows.*' => 'required|integer|min:1|max:40',
            'cumulative_row' => 'nullable|integer|min:1|max:40',
        ]);

        // Parse the stored slice: COLUMNS legend + R<n> [ROLE]: csv lines.
        $parsed = $this->parseStoredSlice($proposal->header_rows_text ?? '');
        if ($parsed === null) {
            return response()->json([
                'success' => false,
                'message' => 'This capture has no readable header block to teach from.',
            ], 422);
        }
        [$letters, $rlines, $roles] = $parsed;

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
            // Row roles are an EXCEL-only control, and the refusal is explicit
            // rather than a silent drop. The PDF reader finds its cumulative row by
            // SHAPE (isCumulativeShapedRow — percent-written, non-decreasing, ends
            // >= 50), which is label-independent and already immune to the novel
            // wordings this teaching exists to cover. Storing sigs the PDF reader
            // will never consult is precisely the half-wired save that cost four
            // acceptance rounds: the reviewer is told "saved" and nothing changes.
            if (!empty($validated['excluded_rows']) || ($validated['cumulative_row'] ?? null) !== null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Row roles apply to Excel layouts only — a PDF layout finds its'
                        . ' cumulative row by shape, so there is nothing to teach here.',
                ], 422);
            }
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

            // Row roles -> text signatures. Every refusal below is a case where
            // storing the pick would be worse than not storing it, because the
            // damage is invisible at read time: a row that quietly stops being
            // summed simply makes every month read low.
            $sigFor = function (int $rno) use ($rlines, $roles): string {
                if (!isset($rlines[$rno])) {
                    throw new RuntimeException("Row R{$rno} is not in the stored block.");
                }
                if (($roles[$rno] ?? '') === 'HEADER') {
                    throw new RuntimeException("R{$rno} is the header row — it is never summed as an activity.");
                }
                $sig = SCurveImportService::rowSignature($rlines[$rno]);
                if ($sig === '') {
                    // A row of nothing but numbers cannot be recognised again in
                    // the live file, and an empty signature would match every blank
                    // row there is.
                    throw new RuntimeException("R{$rno} has no label text to recognise it by — pick a row that names itself.");
                }

                return $sig;
            };

            $excludeSigs = [];
            foreach (($validated['excluded_rows'] ?? []) as $rno) {
                if (in_array((int) $rno, array_map('intval', $validated['header_rows']), true)) {
                    throw new RuntimeException("R{$rno} is a header row you picked — it cannot also be a non-activity row.");
                }
                $excludeSigs[] = $sigFor((int) $rno);
            }

            $cumulativeSig = null;
            if (($validated['cumulative_row'] ?? null) !== null) {
                $cno = (int) $validated['cumulative_row'];
                if (in_array($cno, array_map('intval', $validated['header_rows']), true)) {
                    throw new RuntimeException("R{$cno} is a header row you picked — it cannot also be the cumulative row.");
                }
                $cumulativeSig = $sigFor($cno);
            }

            // Two rows whose labels normalize identically cannot be told apart at
            // read time, so excluding one would silently exclude both.
            if (count($excludeSigs) !== count(array_unique($excludeSigs))) {
                throw new RuntimeException('Two of the rows you picked read the same — pick rows with different labels.');
            }

            $why = null;
            $map = $this->sCurveImportService->buildTaughtMap(
                array_values($merged),
                (int) $descCol,
                $codeCol,
                $weightCol,
                $contractCol,
                $monthSpans,
                $why,
                $excludeSigs,
                $cumulativeSig
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if ($map === null) {
            return response()->json([
                'success' => false,
                // 2026-09-14: this used to be the ONE message every Excel refusal
                // got, so a reviewer who put a column in two places was told only
                // that "those columns do not form a usable layout" and had to find
                // the clash by eye. The PDF branch already names its gate; this one
                // now does too, using the reviewer's OWN letters.
                'message' => $this->explainTaughtMapRefusal($why),
            ], 422);
        }

        // Header-row memory (display-only): remember which R-lines the reviewer
        // picked so the teach modal can tick them back on reopen. The reader
        // never consults this key (it matches by sig), so old maps without it
        // keep working exactly as today.
        $headerRows = array_values(array_unique(array_map(
            'intval',
            (array) ($validated['header_rows'] ?? [])
        )));
        sort($headerRows);
        $map['headerRows'] = $headerRows;

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
            // Variant mode creates a SIBLING and leaves the row the reviewer was
            // looking at untouched by design — so from their side the screen does
            // not change and the save looks like it failed (reported 2026-09-14,
            // where the teaching had in fact saved correctly). Say where it went.
            'message' => $mode === 'replace'
                ? 'Layout updated — it dropped back to Proposed and needs approving again.'
                : 'Saved as a NEW layout, waiting in Proposed — approve it to use it. '
                    . 'The row you taught from is deliberately left as it was.',
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
    /**
     * Turn a buildTaughtMap() refusal code into a sentence naming what to fix.
     *
     * The codes carry the reviewer's own column letters and month numbers, so the
     * message can point at the exact click that clashed instead of restating the
     * rule. Anything unrecognised falls back to the old general wording — a new
     * gate must never produce a blank refusal.
     *
     * Pure. Called by: teachColumns() Excel branch.
     */
    protected function explainTaughtMapRefusal(?string $why): string
    {
        $generic = 'Those columns do not form a usable layout (need a description column '
            . 'plus at least two non-overlapping months, left to right).';
        if ($why === null || $why === '') {
            return $generic;
        }

        $parts = explode(':', $why);
        switch ($parts[0]) {
            case 'no-description':
            case 'description-cell-empty':
                return 'Pick the column holding the work names as the description — '
                    . 'the one you chose has no label on the header row.';
            case 'too-few-months':
                return 'A schedule needs at least two month columns; assign one more before saving.';
            case 'month-shares-column':
                return "Column {$parts[1]} is assigned to two different months — each column belongs to one month.";
            case 'label-in-month':
                return "Column {$parts[2]} is both the {$parts[1]} column and part of month {$parts[3]} — "
                    . 'give it to one or the other.';
            case 'months-out-of-order':
                return 'The months do not run left to right across the grid — check their order.';
            case 'header-row-too-empty':
                return 'The header row you picked is almost empty, so there is nothing to recognise '
                    . 'this layout by later. Pick the row carrying the column labels.';
            case 'empty-month':
                return "Month {$parts[1]} has no column assigned to it.";
            default:
                return $generic;
        }
    }

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
        $roles = [];
        foreach ($lines as $line) {
            if (!preg_match('/^R(\d+)\s*\[([A-Z]+)\]:\s?(.*)$/', trim($line), $m)) {
                continue;
            }
            $cells = str_getcsv($m[3]);
            // Pad short rows so letter positions stay aligned.
            $cells = array_pad(array_values($cells), count($letters), '');
            $rlines[(int) $m[1]] = $cells;
            // 2026-09-14: the role was captured and thrown away. Row-role teaching
            // needs it to explain a refusal in the reviewer's own terms ("R4 is the
            // header row"), so it is kept as a THIRD element — two-element list()
            // destructuring in the existing callers is unaffected.
            $roles[(int) $m[1]] = $m[2];
        }
        if (count($rlines) === 0) {
            return null;
        }

        return [$letters, $rlines, $roles];
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
        // F-3 (2026-09-21): only a document of a project the caller may view
        if (!$document || !$document->isViewableBy($request->user()) || !$document->fileExists()) {
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
            // F-3: a document the caller may not view scopes nothing
            if ($document && !$document->isViewableBy(auth()->user())) {
                return null;
            }

            return $document?->project?->contractor_id;
        }

        return null;
    }
}
