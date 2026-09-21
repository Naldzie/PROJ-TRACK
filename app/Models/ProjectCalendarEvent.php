<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Idempotency record for one pushed Google Calendar event.
// One row per (project, user, event key) so re-syncs update in place.
class ProjectCalendarEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'user_id',
        'event_key',
        'baseline_schedule_id',
        'google_event_id',
        'target_date',
        'last_synced_at',
        'sync_status',
        'sync_error',
    ];

    protected $casts = [
        'target_date' => 'date:Y-m-d',
        'last_synced_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function baselineSchedule()
    {
        return $this->belongsTo(ProjectBaselineSchedule::class, 'baseline_schedule_id');
    }
}
