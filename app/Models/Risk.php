<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Risk extends Model
{
    use HasFactory;

    /**
     * Available probability levels (1-3 mapping used for score computation).
     * The user's subjective estimate of how likely a risk is to occur.
     */
    const PROBABILITIES = ['Low', 'Medium', 'High'];

    /**
     * Available impact levels (1-3 mapping used for score computation).
     * The user's subjective estimate of how severe a risk would be.
     */
    const IMPACTS = ['Low', 'Medium', 'High'];

    /**
     * Available lifecycle statuses for a risk.
     */
    const STATUSES = ['Open', 'Mitigated', 'Closed'];

    /**
     * Level thresholds derived from risk_score (probability × impact, 1-9).
     * 1-3 = Low, 4-6 = Medium, 7-9 = High.
     */
    const LEVEL_THRESHOLDS = [
        'High'   => 7,
        'Medium' => 4,
        'Low'    => 1,
    ];

    /**
     * Numeric weights used to compute risk_score from probability × impact.
     * (Low=1, Medium=2, High=3)
     */
    const LEVEL_WEIGHTS = [
        'Low'    => 1,
        'Medium' => 2,
        'High'   => 3,
    ];

    protected $fillable = [
        'project_id',
        'risk_name',
        'description',
        'probability',
        'impact',
        'risk_score',
        'mitigation_plan',
        'owner',
        'status',
        'identified_at',
        'mitigated_at',
        'closed_at',
    ];

    protected $casts = [
        'identified_at' => 'date',
        'mitigated_at'  => 'datetime',
        'closed_at'     => 'datetime',
        'risk_score'    => 'integer',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * A risk belongs to a project
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    // ==================== SCOPES ====================

    /**
     * Scope to risks that are still open
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'Open');
    }

    /**
     * Scope to risks that have been mitigated
     */
    public function scopeMitigated($query)
    {
        return $query->where('status', 'Mitigated');
    }

    /**
     * Scope to risks that have been closed
     */
    public function scopeClosed($query)
    {
        return $query->where('status', 'Closed');
    }

    /**
     * Scope to high-priority risks (score >= 7)
     * Used for prioritization and the High-risk notification trigger.
     */
    public function scopeHighRisk($query)
    {
        return $query->where('risk_score', '>=', 7);
    }

    // ==================== HELPERS ====================

    /**
     * Compute the risk score (1-9) from probability × impact weights.
     * Example: Medium(2) × High(3) = 6.
     *
     * Called by: RiskController::store()/update(), tests
     */
    public static function computeScore(string $probability, string $impact): int
    {
        $p = self::LEVEL_WEIGHTS[$probability] ?? 1;
        $i = self::LEVEL_WEIGHTS[$impact] ?? 1;

        return $p * $i;
    }

    /**
     * Derive the risk level label from the score (1-3 Low, 4-6 Medium, 7-9 High).
     */
    public function getRiskLevelAttribute(): string
    {
        $score = (int) $this->risk_score;

        if ($score >= self::LEVEL_THRESHOLDS['High']) {
            return 'High';
        }
        if ($score >= self::LEVEL_THRESHOLDS['Medium']) {
            return 'Medium';
        }

        return 'Low';
    }
}
