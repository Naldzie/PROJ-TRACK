<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectProgressHistory extends Model
{
    use HasFactory;

    protected $table = 'project_progress_history';

    protected $fillable = [
        'project_id',
        'baseline_schedule_id',
        'activity_id',
        'inspection_date',
        'month_label',
        'progress_percentage',
        'planned_progress_percent',
        'actual_progress_percent',
        'variance_percent',
        'previous_progress',
        'month_number',
        'actual_start_date',
        'actual_end_date',
        'status',
        'remarks',
        'recorded_by',
        'original_values',
        'edited_by',
        'edited_at',
        'edit_count',
    ];

    protected $casts = [
        'inspection_date' => 'date:Y-m-d',
        'actual_start_date' => 'date:Y-m-d',
        'actual_end_date' => 'date:Y-m-d',
        'progress_percentage' => 'decimal:2',
        'planned_progress_percent' => 'decimal:2',
        'actual_progress_percent' => 'decimal:2',
        'variance_percent' => 'decimal:2',
        'previous_progress' => 'decimal:2',
        'month_number' => 'integer',
        'original_values' => 'json',
        'activity_id' => 'array',
        'edited_at' => 'datetime',
        'edit_count' => 'integer',
    ];

    // No $appends — attributes are appended manually in blade/controller
    // to avoid N+1 queries on collection serialization.

    // === RELATIONSHIPS ===
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function activities()
    {
        $ids = $this->activity_id;
        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?? [];
        }
        return ProjectActivity::whereIn('id', $ids ?? []);
    }

    public function baselineSchedule()
    {
        return $this->belongsTo(ProjectBaselineSchedule::class, 'baseline_schedule_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    // === ACCESSORS ===
    
    /**
     * Get the progress change from previous record
     */
    public function getProgressChangeAttribute()
    {
        if ($this->previous_progress !== null) {
            return $this->progress_percentage - $this->previous_progress;
        }
        return $this->progress_percentage;
    }

    /**
     * Get the recorded by user's display name
     */
    public function getRecordedByNameAttribute()
    {
        return $this->recordedBy?->name ?? 'System';
    }

    /**
     * Get activity names from the activity_id array
     */
    public function getActivityNamesAttribute()
    {
        $ids = $this->activity_id;
        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?? [];
        }
        $ids = $ids ?? [];
        if (empty($ids)) {
            return [];
        }
        return ProjectActivity::whereIn('id', $ids)
            ->pluck('activity_name')
            ->toArray();
    }

    /**
     * Check if this record has been edited
     */
    public function hasBeenEdited()
    {
        return $this->edit_count > 0 && $this->edited_at !== null;
    }

    /**
     * Store original values before updating record
     * Captures all relevant columns so the audit trail is complete.
     */
    public function storeOriginalValues()
    {
        if (!$this->original_values) {
            $this->original_values = [
                'month_label' => $this->month_label,
                'inspection_date' => $this->inspection_date?->format('Y-m-d'),
                'actual_progress_percent' => $this->actual_progress_percent !== null ? (float) $this->actual_progress_percent : null,
                'planned_progress_percent' => $this->planned_progress_percent !== null ? (float) $this->planned_progress_percent : null,
                'variance_percent' => $this->variance_percent !== null ? (float) $this->variance_percent : null,
                'previous_progress' => $this->previous_progress !== null ? (float) $this->previous_progress : null,
                'activity_id' => $this->activity_id,
                'activity_names' => $this->activity_names,
                'actual_start_date' => $this->actual_start_date?->format('Y-m-d'),
                'actual_end_date' => $this->actual_end_date?->format('Y-m-d'),
                'status' => $this->status,
                'remarks' => $this->remarks,
                'recorded_by' => $this->recorded_by,
                'recorded_by_name' => $this->recorded_by_name,
            ];
        }
    }

    /**
     * Mark record as edited
     */
    public function recordEdit($editedBy)
    {
        $this->storeOriginalValues();
        $this->edited_by = $editedBy;
        $this->edited_at = now();
        $this->edit_count = ($this->edit_count ?? 0) + 1;
    }

    /**
     * Get formatted edit history with all current values
     */
    public function getEditHistory()
    {
        return [
            'has_been_edited' => $this->hasBeenEdited(),
            'edit_count' => $this->edit_count,
            'edited_by' => $this->edited_by,
            'edited_at' => $this->edited_at?->format('d M Y, H:i'),
            'original_values' => $this->original_values,
            'current_values' => [
                'month_label' => $this->month_label,
                'inspection_date' => $this->inspection_date?->format('Y-m-d'),
                'actual_progress_percent' => $this->actual_progress_percent !== null ? (float) $this->actual_progress_percent : null,
                'planned_progress_percent' => $this->planned_progress_percent !== null ? (float) $this->planned_progress_percent : null,
                'variance_percent' => $this->variance_percent !== null ? (float) $this->variance_percent : null,
                'previous_progress' => $this->previous_progress !== null ? (float) $this->previous_progress : null,
                'activity_id' => $this->activity_id,
                'activity_names' => $this->activity_names,
                'actual_start_date' => $this->actual_start_date?->format('Y-m-d'),
                'actual_end_date' => $this->actual_end_date?->format('Y-m-d'),
                'status' => $this->status,
                'remarks' => $this->remarks,
                'recorded_by' => $this->recorded_by,
                'recorded_by_name' => $this->recorded_by_name,
            ]
        ];
    }
}
