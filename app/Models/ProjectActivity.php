<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectActivity extends Model
{
    use HasFactory;

    protected $table = 'project_activities';

    protected $fillable = [
        'project_id',
        'baseline_schedule_id',
        'activity_name',
        'description',
        'month_label',
        'is_completed',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
    ];

    // ===== Relationships =====

    /**
     * The project this activity belongs to
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The baseline schedule month this activity is assigned to
     */
    public function baselineSchedule()
    {
        return $this->belongsTo(ProjectBaselineSchedule::class, 'baseline_schedule_id');
    }

    /**
     * Progress history records that reference this as the checked activity
     */
    public function progressHistory()
    {
        return $this->hasMany(ProjectProgressHistory::class, 'activity_id');
    }

    /**
     * All carryover records for this activity (oldest first)
     */
    public function carryovers()
    {
        return $this->hasMany(ProjectActivityCarryover::class, 'activity_id')
            ->orderBy('carryover_number');
    }

    /**
     * The most recent carryover for this activity
     */
    public function latestCarryover()
    {
        return $this->hasOne(ProjectActivityCarryover::class, 'activity_id')
            ->latestOfMany('carryover_number');
    }

    // ===== Scopes =====

    /**
     * Scope to activities for a specific month
     */
    public function scopeForMonth($query, $monthLabel)
    {
        return $query->where('month_label', $monthLabel);
    }

    /**
     * Scope to completed activities
     */
    public function scopeCompleted($query)
    {
        return $query->where('is_completed', true);
    }

    /**
     * Scope to incomplete (pending) activities
     */
    public function scopePending($query)
    {
        return $query->where('is_completed', false);
    }

    /**
     * Scope to activities that have been carried over at least once
     */
    public function scopeCarriedOver($query)
    {
        return $query->whereHas('carryovers');
    }

    // ===== Helpers =====

    /**
     * Check if this activity has been carried over from a previous month
     */
    public function isCarriedOver(): bool
    {
        return $this->carryovers()->exists();
    }

    /**
     * Get how many times this activity has been carried over
     */
    public function carriedOverCount(): int
    {
        return $this->carryovers()->count();
    }
}
