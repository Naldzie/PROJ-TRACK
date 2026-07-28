<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class ProjectMilestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'weight_percentage',
        'planned_start_date',
        'planned_end_date',
        'actual_start_date',
        'actual_end_date',
        'progress_percentage',
        'status',
        'remarks',
        'assigned_to',
    ];

    protected $casts = [
        'planned_start_date' => 'date:Y-m-d',
        'planned_end_date' => 'date:Y-m-d',
        'actual_start_date' => 'date:Y-m-d',
        'actual_end_date' => 'date:Y-m-d',
        'weight_percentage' => 'decimal:2',
        'progress_percentage' => 'decimal:2',
    ];

    // ==================== RELATIONSHIPS ====================

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function progressHistory()
    {
        return $this->hasMany(ProjectProgressHistory::class, 'milestone_id')
                    ->orderBy('inspection_date', 'desc');
    }

    // ==================== ACCESSORS ====================

    /**
     * Get the planned duration in days
     */
    public function getPlannedDurationAttribute()
    {
        if ($this->planned_start_date && $this->planned_end_date) {
            return $this->planned_start_date->diffInDays($this->planned_end_date) + 1;
        }
        return null;
    }

    /**
     * Get the actual duration in days
     */
    public function getActualDurationAttribute()
    {
        if ($this->actual_start_date && $this->actual_end_date) {
            return $this->actual_start_date->diffInDays($this->actual_end_date) + 1;
        }
        return null;
    }

    /**
     * Get weighted progress (contribution to overall project)
     */
    public function getWeightedProgressAttribute()
    {
        return ($this->weight_percentage / 100) * $this->progress_percentage;
    }

    /**
     * Check if milestone is delayed
     */
    public function getIsDelayedAttribute()
    {
        if ($this->status === 'Completed') {
            return false;
        }
        
        if ($this->planned_end_date && $this->planned_end_date->isPast()) {
            return $this->progress_percentage < 100;
        }
        
        return false;
    }

    /**
     * Get delay days (positive = delayed, negative = ahead)
     */
    public function getDelayDaysAttribute()
    {
        if (!$this->planned_end_date) {
            return 0;
        }

        if ($this->actual_end_date) {
            return $this->planned_end_date->diffInDays($this->actual_end_date, false);
        }

        if ($this->progress_percentage >= 100) {
            return 0;
        }

        if ($this->planned_end_date->isPast()) {
            return $this->planned_end_date->diffInDays(Carbon::today(), false);
        }

        return 0;
    }

    /**
     * Get status color for UI display
     */
    public function getStatusColorAttribute()
    {
        return match($this->status) {
            'Not Started' => 'secondary',
            'In Progress' => 'primary',
            'Completed' => 'success',
            'Delayed' => 'danger',
            'On Hold' => 'warning',
            default => 'secondary'
        };
    }

    // ==================== SCOPES ====================

    public function scopeOrdered($query)
    {
        return $query->orderBy('planned_start_date');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'In Progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'Completed');
    }

    public function scopeDelayed($query)
    {
        return $query->where('status', 'Delayed');
    }

    // ==================== METHODS ====================

    /**
     * Update status based on progress and dates
     */
    public function updateStatusFromProgress()
    {
        if ($this->progress_percentage >= 100) {
            $this->status = 'Completed';
            if (!$this->actual_end_date) {
                $this->actual_end_date = Carbon::today();
            }
        } elseif ($this->progress_percentage > 0) {
            if (!$this->actual_start_date) {
                $this->actual_start_date = Carbon::today();
            }
            
            if ($this->is_delayed) {
                $this->status = 'Delayed';
            } else {
                $this->status = 'In Progress';
            }
        }
        
        return $this;
    }
}
