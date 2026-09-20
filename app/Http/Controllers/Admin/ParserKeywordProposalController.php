<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ParserKeywordProposal;
use App\Traits\LogsActivity;
use Illuminate\Http\Request;

// L1 learning loop: silent collection of (label → field) correction proposals.
// Collection is open to admin AND staff (Decision L0a); contractors are excluded by
// route middleware. Proposals NEVER affect extraction until L2 review promotes them.
class ParserKeywordProposalController extends Controller
{
    use LogsActivity;

    /**
     * Fields the loop is allowed to learn labels for (labels only, never values).
     * F-D adds 'scurveMonth' (PDF S-curve month-header words from verified AI
     * answers — same trust rule: ≥3 repeats or one-click approval).
     */
    public const LEARNABLE_FIELDS = [
        'title',
        'location',
        'description',
        'budget',
        'abc',
        'budgetSource',
        'category',
        'startDate',
        'endDate',
        'duration',
        'contractor',
        'scurveMonth',
    ];

    /**
     * Collect correction proposals from a preview Apply (fire-and-forget).
     * Upserts by (field, label_phrase): repeats bump occurrences for the ≥3
     * auto-promotion rule; label-less corrections are counted per field only.
     */
    public function collect(Request $request)
    {
        // Same gate as every other endpoint here. It used to be an inline copy —
        // identical today, but free to drift the day one of them changed (2026-09-15).
        $this->ensureReviewer();

        $validated = $request->validate([
            // Multi-label desk: up to 3 distinct labels per corrected box (11 fields × 3).
            // AI-4: origin tagging ('correction' default, 'AI-assisted' for AI-kept rows).
            'proposals' => 'required|array|max:33',
            'proposals.*.field' => 'required|string|in:' . implode(',', self::LEARNABLE_FIELDS),
            'proposals.*.label' => 'nullable|string|max:255',
            'proposals.*.excerpt' => 'nullable|string|max:1000',
            'proposals.*.had_disagree' => 'sometimes|boolean',
            'proposals.*.source' => 'sometimes|string|in:correction,AI-assisted',
        ]);

        $stored = 0;
        foreach ($validated['proposals'] as $item) {
            $label = isset($item['label']) ? trim($item['label']) : null;
            if ($label === '') {
                $label = null;
            }

            $query = ParserKeywordProposal::where('field', $item['field']);
            if ($label === null) {
                $query->whereNull('label_phrase');
            } else {
                $query->where('label_phrase', $label);
            }

            $row = $query->first();
            if ($row) {
                // The count is evidence and is always worth keeping — "this wording has
                // now been seen eleven times" is exactly what would justify revisiting a
                // refusal. But a REJECTED phrase must never climb back to proposed or
                // live on its own: a reviewer decided, and three more sightings is not a
                // rebuttal. Only the desk can undo a rejection.
                $row->increment('occurrences');
                if ($row->status === 'rejected') {
                    $stored++;
                    continue;
                }
                // L-2 (2026-09-20) — A ROW WITH NO WORDING MUST NEVER GO LIVE.
                // When the miner cannot anchor a correction it still posts a proposal,
                // with label null, so the COUNT is kept — "47 corrections to contractor
                // that we could not anchor" is exactly the evidence that would justify
                // working on the anchor. But the dedup key is (field, label_phrase) with
                // a whereNull branch, so EVERY unanchored correction for a field
                // collapses into ONE row, and at three it used to auto-promote to 'live'
                // with an EMPTY phrase.
                // The reader skips empty phrases, so such a row teaches nothing. It sat
                // in the desk reporting itself as a learned rule — the loop claiming
                // credit precisely when it had learned nothing. On SCANNED documents,
                // where anchoring usually fails, that is the normal path rather than the
                // edge case.
                // The count still rises. Only the promotion is refused.
                if ($row->label_phrase === null) {
                    $stored++;
                    continue;
                }
                if ($row->occurrences >= 3 && $row->status === 'proposed') {
                    $row->update(['status' => 'live']);
                }
            } else {
                ParserKeywordProposal::create([
                    'field' => $item['field'],
                    'label_phrase' => $label,
                    'excerpt' => $item['excerpt'] ?? null,
                    'occurrences' => 1,
                    'status' => 'proposed',
                    'source' => $item['source'] ?? 'correction',
                ]);
            }
            $stored++;
        }

        return response()->json([
            'success' => true,
            'stored' => $stored,
        ], 201);
    }

    // L2 review gate shared by every management endpoint (Decision L0a).
    // Collection stays open to admin+staff via collect(); review/add-ons match it.
    protected function ensureReviewer()
    {
        if (!in_array(auth()->user()->role, ['admin', 'staff'], true)) {
            abort(response()->json(['message' => 'Unauthorized'], 403));
        }
    }

    /**
     * List proposals for the review screen (?status=proposed|live, default all).
     * Most-seen first so the highest-value vocabulary is reviewed first.
     */
    public function getAllForManagement(Request $request)
    {
        $this->ensureReviewer();

        $query = ParserKeywordProposal::orderByDesc('occurrences')->orderBy('field');
        if (in_array($request->query('status'), ['proposed', 'live', 'rejected'], true)) {
            $query->where('status', $request->query('status'));
        }

        $rows = $query->get()->map(function ($row) {
            return [
                'id' => $row->id,
                'field' => $row->field,
                'label_phrase' => $row->label_phrase ?? '',
                'excerpt' => $row->excerpt ?? '',
                'occurrences' => $row->occurrences,
                'status' => $row->status,
                'source' => $row->source ?? 'correction',
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        });

        return response()->json($rows);
    }

    /**
     * Approve a proposal: flip it live so L3 (reader) may consult it.
     */
    public function approve(ParserKeywordProposal $proposal)
    {
        $this->ensureReviewer();

        // L-2: the same rule the collector follows. A row with no wording carries a
        // useful COUNT and nothing the reader can use, so approving it would put a
        // rule into live vocabulary that cannot match anything — the button would
        // appear to work and change nothing at all, which is worse than refusing.
        if ($proposal->label_phrase === null) {
            return response()->json([
                'success' => false,
                'message' => 'This row records corrections we could not trace to any wording, so there is nothing to approve. It is kept only as a count.',
            ], 422);
        }

        $proposal->update(['status' => 'live']);

        $this->logActivity('Approve Parser Keyword', "Approved parser keyword '{$proposal->label_phrase}' for field {$proposal->field}");

        return response()->json([
            'success' => true,
            'message' => 'Keyword approved successfully',
            'proposal' => $proposal->fresh(),
        ]);
    }

    /**
     * Reject a proposal.
     *
     * 2026-09-20: this used to SOFT-DELETE, and that quietly cost us the judgement.
     * A deleted row is invisible to the ordinary query, so collect() could not see that
     * a phrase had already been refused — the same bad wording was re-proposed,
     * re-reviewed and re-deleted with no memory that anyone had ever decided. (It also
     * misled a reading of this very table: a check that omitted withTrashed() concluded
     * the loop had never learned from a human, because the human's rows were deleted.)
     * It now records status='rejected' and KEEPS the row, so the decision survives and
     * collect() can honour it. Deletion remains available in the desk for genuine
     * housekeeping; refusal is no longer spelled the same way.
     */
    public function destroy(ParserKeywordProposal $proposal)
    {
        $this->ensureReviewer();

        $label = $proposal->label_phrase;
        $proposal->update(['status' => 'rejected']);

        $this->logActivity('Reject Parser Keyword', "Rejected parser keyword '{$label}' for field {$proposal->field}");

        return response()->json([
            'success' => true,
            'message' => 'Keyword rejected successfully',
        ]);
    }
}
