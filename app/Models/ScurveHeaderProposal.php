<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// E1 learning-loop store: stashed header blocks from failed S-curve imports.
// One row per (contractor_id, header_hash); raw text is layout context only.
// A row goes live only via E1-2 review (admin/staff approval) or ≥3 occurrences,
// and is then trusted ONLY for its contractor first (global NULL-contractor rows second).
class ScurveHeaderProposal extends Model
{
    use HasFactory, SoftDeletes;

    // Phase-A: reviewer-taught variants arrive as manual-teach (proposed, own approval).
    // Phase-B: AI-derived maps arrive as ai-derived (proposed, NEVER auto-live).
    public const SOURCES = ['auto-capture', 'manual-teach', 'ai-derived'];

    public const SOURCE_TYPES = ['excel', 'pdf'];

    protected $fillable = [
        'contractor_id',
        'source_type',
        'header_rows_text',
        'header_hash',
        'column_map',
        'occurrences',
        'status',
        'source',
    ];

    protected $casts = [
        'occurrences' => 'integer',
        'column_map' => 'array',
    ];

    public function contractor()
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * Scope for live (promoted) maps only.
     */
    public function scopeLive($query)
    {
        return $query->where('status', 'live');
    }

    /**
     * Scope for pending review captures.
     */
    public function scopeProposed($query)
    {
        return $query->where('status', 'proposed');
    }

    /**
     * E1-B — store an AI-derived layout map (learn-from-AI).
     *
     * Backfills a map-less matching row (same hash + contractor scope) or creates a
     * proposed ai-derived row. Backfilled rows drop to proposed (an unreviewed map
     * must never ride a live status). Occurrences are NEVER touched here — repeats
     * must not manufacture corroboration for machine-learned maps. AI-derived rows
     * never auto-live (collect() enforces); only human approval flips them.
     *
     * Called by: SCurveImportController LearnMap step (best-effort).
     */
    public static function storeAiDerived(
        ?int $contractorId,
        string $hash,
        string $text,
        array $columnMap
    ): self {
        $query = static::where('header_hash', $hash);
        if ($contractorId === null) {
            $query->whereNull('contractor_id');
        } else {
            $query->where('contractor_id', $contractorId);
        }

        $row = $query->first();
        if ($row) {
            if ($row->column_map === null) {
                $row->update([
                    'column_map' => $columnMap,
                    'source' => 'ai-derived',
                    'status' => 'proposed',
                ]);
            }

            return $row->fresh();
        }

        return static::create([
            'contractor_id' => $contractorId,
            'source_type' => 'excel',
            'header_rows_text' => mb_substr($text, 0, 4000),
            'header_hash' => $hash,
            'column_map' => $columnMap,
            'occurrences' => 1,
            'status' => 'proposed',
            'source' => 'ai-derived',
        ]);
    }
}
