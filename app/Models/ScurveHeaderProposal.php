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
    public const SOURCES = ['auto-capture', 'manual-teach'];

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
}
