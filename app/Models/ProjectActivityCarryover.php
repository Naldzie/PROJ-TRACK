<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectActivityCarryover extends Model
{
    use HasFactory;

    // Immutable log — never updated
    const UPDATED_AT = null;

    protected $table = 'project_activity_carryovers';

    protected $fillable = [
        'activity_id',
        'project_id',
        'from_baseline_schedule_id',
        'from_month_label',
        'to_baseline_schedule_id',
        'to_month_label',
        'carryover_number',
        'carried_over_by',
    ];

    /**
     * The activity that was carried over
     */
    public function activity()
    {
        return $this->belongsTo(ProjectActivity::class, 'activity_id');
    }

    /**
     * The project this carryover belongs to
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The baseline schedule month the activity was carried FROM
     */
    public function fromBaselineSchedule()
    {
        return $this->belongsTo(ProjectBaselineSchedule::class, 'from_baseline_schedule_id');
    }

    /**
     * The baseline schedule month the activity was carried TO
     */
    public function toBaselineSchedule()
    {
        return $this->belongsTo(ProjectBaselineSchedule::class, 'to_baseline_schedule_id');
    }

    /**
     * The user who performed the carryover
     */
    public function carriedOverBy()
    {
        return $this->belongsTo(User::class, 'carried_over_by');
    }
}
