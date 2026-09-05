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
            'proposals' => 'required|array|max:11',
            'proposals.*.field' => 'required|string|in:' . implode(',', self::LEARNABLE_FIELDS),
            'proposals.*.label' => 'nullable|string|max:255',
            'proposals.*.excerpt' => 'nullable|string|max:1000',
            'proposals.*.had_disagree' => 'sometimes|boolean',
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
                ]);
            }
            $stored++;
        }

        return response()->json([
            'success' => true,
            'stored' => $stored,
        ], 201);
    }
}
