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
        if (!in_array(auth()->user()->role, ['admin', 'staff'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

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
                $row->increment('occurrences');
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
        if (in_array($request->query('status'), ['proposed', 'live'], true)) {
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

        $proposal->update(['status' => 'live']);

        $this->logActivity('Approve Parser Keyword', "Approved parser keyword '{$proposal->label_phrase}' for field {$proposal->field}");

        return response()->json([
            'success' => true,
            'message' => 'Keyword approved successfully',
            'proposal' => $proposal->fresh(),
        ]);
    }

    /**
     * Reject a proposal: soft-delete it (recoverable via restore, keeps history).
     */
    public function destroy(ParserKeywordProposal $proposal)
    {
        $this->ensureReviewer();

        $label = $proposal->label_phrase;
        $proposal->delete();

        $this->logActivity('Reject Parser Keyword', "Rejected parser keyword '{$label}' for field {$proposal->field}");

        return response()->json([
            'success' => true,
            'message' => 'Keyword rejected successfully',
        ]);
    }
}
