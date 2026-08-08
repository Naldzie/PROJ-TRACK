<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectBaselineSchedule extends Model
{
    use HasFactory;

    protected $table = 'project_baseline_schedules';

    // No updated_at - this table is write-once (immutable)
    const UPDATED_AT = null;

    protected $fillable = [
        'project_id',
        'planned_date',
        'month_label',
        'planned_progress_percent',
        'original_planned_progress_percent', // frozen value, set only at initial baseline setup
        'original_planned_date',             // frozen target date, set only at initial baseline setup
        'description',
    ];

    protected $casts = [
        'planned_date' => 'date',
        'original_planned_date' => 'date',
        'planned_progress_percent' => 'decimal:2',
        'original_planned_progress_percent' => 'decimal:2',
    ];

    /**
     * Relationships
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function activities()
    {
        return $this->hasMany(ProjectActivity::class, 'baseline_schedule_id');
    }

    public function progressHistory()
    {
        return $this->hasMany(ProjectProgressHistory::class, 'baseline_schedule_id');
    }

    /**
     * Scopes
     */
    public function scopeForMonth($query, $monthLabel)
    {
        return $query->where('month_label', $monthLabel);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('planned_date', $date);
    }

    public function scopeOrderByMonth($query)
    {
        return $query->orderBy('planned_date');
    }

    // NEW: rows that were part of the ORIGINAL baseline plan (frozen at initial setup)
    public function scopeOriginal($query)
    {
        return $query->whereNotNull('original_planned_progress_percent');
    }

    // NEW: rows added/edited AFTER an extension — never had a frozen original value
    public function scopeExtensionOnly($query)
    {
        return $query->whereNull('original_planned_progress_percent');
    }

    /**
     * Accessors
     */
    public function getMonthDateAttribute()
    {
        return $this->planned_date->format('Y-m');
    }

    /**
     * Human-readable month label, e.g. "June-2026" (from a "Y-m" month_label).
     */
    public function getMonthDisplayAttribute()
    {
        if (empty($this->month_label)) {
            return 'N/A';
        }

        foreach (['Y-m', 'Y-m-d'] as $format) {
            try {
                return \Illuminate\Support\Carbon::createFromFormat($format, $this->month_label)->format('F-Y');
            } catch (\Throwable $e) {
            }
        }

        try {
            return \Illuminate\Support\Carbon::parse($this->month_label)->format('F-Y');
        } catch (\Throwable $e) {
            return $this->month_label;
        }
    }
}
