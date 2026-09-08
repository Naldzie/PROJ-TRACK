<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

// L1 learning-loop store: harvested (label → field) correction proposals.
// Only label phrases are kept — user values (names, amounts, dates) are NEVER stored.
// A row goes live only via L2 review (admin/staff approval) or ≥3 occurrences.
// AI-4: `source` records provenance ('correction' human-taught vs 'AI-assisted') so the
// review desk can badge rows whose wording was kept from an AI-assisted read.
class ParserKeywordProposal extends Model
{
    use HasFactory, SoftDeletes;

    public const SOURCES = ['correction', 'AI-assisted'];

    protected $fillable = [
        'field',
        'label_phrase',
        'excerpt',
        'occurrences',
        'status',
        'source',
    ];

    protected $casts = [
        'occurrences' => 'integer',
    ];

    /**
     * Scope for live (promoted) pairs only.
     */
    public function scopeLive($query)
    {
        return $query->where('status', 'live');
    }

    /**
     * Scope for pending review proposals.
     */
    public function scopeProposed($query)
    {
        return $query->where('status', 'proposed');
    }
}
