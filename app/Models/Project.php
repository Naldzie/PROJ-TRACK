<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Staff;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'title',
        'category_id',
        'location',
        'description',
        'approved_budget',
        'proposal_id',
        'planned_start_date',
        'planned_duration_days',
        'planned_end_date',
        'actual_start_date',
        'actual_end_date',
        'actual_duration_days',
        'contract_duration_days',
        'status',
        'assigned_project_manager',
        'project_manager_contact',
        'contractor_id',
        'signed_contract',
        'supporting_documents',
        'is_archived',
        'archived_at',
        'estimated_workers',
        'planned_handing_over_date',
        'original_planned_start_date',
        'original_planned_end_date',
        'extension_count',
    ];

    protected $casts = [
        'approved_budget' => 'decimal:2',
        'planned_start_date' => 'date:Y-m-d',
        'planned_end_date' => 'date:Y-m-d',
        'planned_handing_over_date' => 'date:Y-m-d',
        'actual_start_date' => 'date:Y-m-d',
        'actual_end_date' => 'date:Y-m-d',
        'original_planned_start_date' => 'date:Y-m-d',
        'original_planned_end_date' => 'date:Y-m-d',
        'archived_at' => 'datetime',
        'planned_duration_days' => 'integer',
        'actual_duration_days' => 'integer',
        'contract_duration_days' => 'integer',
        'estimated_workers' => 'integer',
        'is_archived' => 'boolean',
        'supporting_documents' => 'array',
    ];

    // === RELATIONSHIPS ===
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function contractor()
    {
        return $this->belongsTo(Contractor::class);
    }

    public function proposal()
    {
        return $this->belongsTo(Proposal::class);
    }

    public function progressHistory()
    {
        return $this->hasMany(ProjectProgressHistory::class)->orderBy('inspection_date', 'asc');
    }

    public function activities()
    {
        return $this->hasMany(ProjectActivity::class)->orderBy('month_label');
    }

    public function baselineSchedules()
    {
        return $this->hasMany(ProjectBaselineSchedule::class)->orderBy('planned_date');
    }

    /**
     * Alias for baselineSchedules() for backward compatibility
     * @deprecated Use baselineSchedules() instead
     */
    public function scurvePlan()
    {
        return $this->baselineSchedules();
    }

    public function extensions()
    {
        return $this->hasMany(ProjectExtension::class)->orderBy('extension_number');
    }

    public function issues()
    {
        return $this->hasMany(Issue::class)->orderBy('created_at', 'desc');
    }

    public function documents()
    {
        return $this->hasMany(Document::class)->orderBy('created_at', 'desc');
    }

    // A project can have many AI-generated analysis records
    public function aiSolutions()
    {
        return $this->hasMany(AiSolution::class)->orderBy('created_at', 'desc');
    }

    // Historical predictions for accuracy tracking (newest first)
    public function predictionHistory()
    {
        return $this->hasMany(PredictionHistory::class)->orderBy('created_at', 'desc');
    }

    /**
     * All activity carryover records for this project (newest first)
     */
    public function activityCarryovers()
    {
        return $this->hasMany(ProjectActivityCarryover::class)->orderBy('created_at', 'desc');
    }

    // === HELPER METHODS ===
    


    /**
     * Get the latest cumulative actual progress percentage from
     * the monthly progress history records.
     */
    public function calculateProgressFromHistory()
    {
        $latestProgress = ProjectProgressHistory::where('project_id', $this->id)
            ->orderBy('month_label', 'desc')
            ->orderBy('inspection_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$latestProgress) {
            return null;
        }

        $progress = $latestProgress->actual_progress_percent;
        if ($progress === null) {
            $progress = $latestProgress->progress_percentage;
        }

        return round((float) ($progress ?? 0), 2);
    }

    /**
     * Get calculated progress from monthly progress history records
     */
    public function getProgressPercentage()
    {
        return $this->calculateProgressFromHistory() ?? 0;
    }

    // === HELPER METHODS ===
    
    /**
     * Calculate days remaining until deadline
     */
    public function getDaysRemaining()
    {
        if (!$this->planned_end_date) {
            return null;
        }
        $remaining = now()->diffInDays($this->planned_end_date, false);
        return (int) $remaining;
    }

    /**
     * Check if project is overdue
     */
    public function isOverdue()
    {
        if (!$this->planned_end_date || $this->status === 'Completed') {
            return false;
        }
        return now()->greaterThan($this->planned_end_date) && $this->getProgressPercentage() < 100;
    }

    /**
     * Check if project is at risk (nearing deadline with low progress)
     */
    public function isAtRisk()
    {
        if ($this->status === 'Completed' || $this->status === 'Cancelled') {
            return false;
        }

        $daysRemaining = $this->getDaysRemaining();
        if ($daysRemaining === null) {
            return false;
        }

        // At risk if: less than 15 days remaining AND progress < 90%
        return $daysRemaining < 15 && $this->getProgressPercentage() < 90;
    }

    /**
     * Calculate completion delay for completed projects
     * Returns days of delay (positive = late, negative = early, 0 = on time)
     */
    public function getCompletionDelay()
    {
        if ($this->status !== 'Completed' || !$this->actual_end_date || !$this->planned_end_date) {
            return null;
        }
        
        return $this->planned_end_date->diffInDays($this->actual_end_date);
    }

    /**
     * Get completion status label for completed projects
     * Returns simple status: On Time, Late, or Early (without day count)
     */
    public function getCompletionStatusLabel()
    {
        if ($this->status !== 'Completed') {
            return null;
        }

        $delay = $this->getCompletionDelay();
        
        if ($delay === null) {
            return 'Completed';
        }
        
        if ($delay == 0) {
            return 'On Time';
        } elseif ($delay > 0) {
            return 'Overdue';
        } else {
            return 'Early';
        }
    }

    /**
     * Get completion status color/class for UI
     */
    public function getCompletionStatusColor()
    {
        if ($this->status !== 'Completed') {
            return null;
        }

        $delay = $this->getCompletionDelay();
        
        if ($delay === null || $delay === 0) {
            return 'success';
        }
        
        return $delay > 0 ? 'warning' : 'success';
    }

    /**
     * Calculate time variance (planned vs actual)
     */
    public function getTimeVariance()
    {
        if (!$this->planned_duration_days) {
            return 0;
        }
        
        $actual = $this->actual_duration_days ?? $this->planned_duration_days;
        return $actual - $this->planned_duration_days; // positive = delay
    }

    /**
     * Get risk level color for UI
     */
    public function getRiskColor()
    {
        if ($this->isOverdue()) {
            return 'danger'; // red
        }
        if ($this->isAtRisk()) {
            return 'warning'; // orange
        }
        return 'success'; // green
    }

    /**
     * Get estimated completion date based on progress
     */
    public function getEstimatedCompletionDate()
    {
        $progress = $this->getProgressPercentage();
        
        if ($progress >= 100) {
            return $this->actual_end_date ?? $this->planned_end_date;
        }

        if (!$this->actual_start_date || $progress == 0) {
            return $this->planned_end_date;
        }

        $daysElapsed = $this->actual_start_date->diffInDays(now());
        if ($daysElapsed == 0) {
            return $this->planned_end_date;
        }
        
        $progressRate = $progress / $daysElapsed;
        $daysRemaining = (100 - $progress) / $progressRate;

        return now()->addDays($daysRemaining);
    }

    /**
     * Generate a new unique project code with format PRJ-YYYY-XXX
     */
    public static function generateCode()
    {
        $year = now()->year;
        $prefix = "PRJ-{$year}-";
        
        // Get all existing codes for this year and find the highest number
        $existingCodes = self::where('code', 'like', "{$prefix}%")->pluck('code');
        $maxNumber = 0;
        
        foreach ($existingCodes as $code) {
            if (preg_match('/PRJ-\d{4}-(\d+)/', $code, $matches)) {
                $number = (int)$matches[1];
                if ($number > $maxNumber) {
                    $maxNumber = $number;
                }
            }
        }
        
        // Generate the next code (format: PRJ-YYYY-NNNNN with 5 digits)
        $count = $maxNumber + 1;
        
        // Ensure it's unique (in case of concurrent requests)
        do {
            $code = sprintf('PRJ-%d-%05d', $year, $count);
            $exists = self::where('code', $code)->exists();
            if ($exists) {
                $count++;
            }
        } while ($exists);
        
        return $code;
    }
    protected static function booted()
{
    // CREATE
    static::created(function ($project) {

        if (!auth()->check() || request()->is('auth/*')) {
            return;
        }

        \App\Models\ActivityLog::withoutEvents(function () use ($project) {
            \App\Models\ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => 'Create Project',
                'description' => auth()->user()->name . " created project: " . ($project->title ?? 'N/A'),
                'ip_address' => request()->ip(),
                'visibility' => 'all',
            ]);
        });

    });

    // UPDATE
    static::updated(function ($project) {

        if (!auth()->check()) return;

        $changes = collect($project->getChanges())
            ->except(['updated_at'])
            ->map(function ($value, $field) use ($project) {
                $old = $project->getOriginal($field);
                return "{$field}: '{$old}' → '{$value}'";
            })
            ->implode(', ');

        if ($changes) {
            \App\Models\ActivityLog::withoutEvents(function () use ($project, $changes) {
                \App\Models\ActivityLog::create([
                    'user_id' => auth()->id(),
                    'project_id' => $project->id,
                    'action' => 'Update Project',
                    'description' => auth()->user()->name . " updated project: {$project->title} | {$changes}",
                    'ip_address' => request()->ip(),
                    'visibility' => 'all',
                ]);
            });
        }

    });

    // DELETING - Clean up documents before project deletion
    static::deleting(function ($project) {
        // Delete all associated documents
        // This triggers the Document model's deleting listener to delete files from storage
        try {
            $documents = $project->documents()->get();
            foreach ($documents as $document) {
                $document->delete();
            }
            \Log::info('Deleted documents for project', [
                'project_id' => $project->id,
                'project_code' => $project->code,
                'document_count' => $documents->count()
            ]);
        } catch (\Exception $e) {
            \Log::error('Error deleting documents for project', [
                'project_id' => $project->id,
                'error' => $e->getMessage()
            ]);
        }
    });

    // ✅ DELETE
    static::deleted(function ($project) {

        if (!auth()->check()) return;

        \App\Models\ActivityLog::withoutEvents(function () use ($project) {
            \App\Models\ActivityLog::create([
                'user_id' => auth()->id(),
                'project_id' => $project->id,
                'action' => 'Delete Project',
                'description' => auth()->user()->name . " deleted project: {$project->title}",
                'ip_address' => request()->ip(),
                'visibility' => 'admin_only',
            ]);
        });

    });
}

    /**
     * Check if a project with the same title already exists
     */
    public static function titleExists($title, $excludeId = null)
    {
        $query = self::where('title', $title);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }
        return $query->exists();
    }

    /**
     * Get the project manager's display name
     * Resolves the manager name from Admin or Staff records if stored as ID
     */
    public function getProjectManagerName()
    {
        if (!$this->assigned_project_manager) {
            return null;
        }

        $managerId = trim($this->assigned_project_manager);

        // Check if it's a numeric ID
        if (is_numeric($managerId)) {
            // Convert to integer for proper lookup
            $id = (int) $managerId;
            
            // Try to find in Admin table first
            $admin = Admin::where('id', $id)->with('user')->first();
            if ($admin) {
                return $admin->full_name ?? 'Admin (ID: ' . $id . ')';
            }

            // Try to find in Staff table
            $staff = Staff::where('id', $id)->with('user')->first();
            if ($staff) {
                $name = ($staff->first_name ?? '') . ' ' . ($staff->last_name ?? '');
                return trim($name) ?: 'Staff (ID: ' . $id . ')';
            }
            
            // If ID not found in either table, return ID with label
            return 'Manager (ID: ' . $id . ')';
        }

        // Return as is if not numeric (backward compatibility for old string names)
        return $managerId;
    }
}