<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Issue extends Model
{
    use HasFactory;

    /**
     * Available issue type categories.
     * Used for form dropdowns and AI recommendation rules.
     */
    const TYPES = [
        'material', 'contractor', 'weather', 'design',
        'regulatory', 'financial', 'other',
    ];

    /**
     * Available severity levels.
     * Used for AI severity-weighted scoring and escalation rules.
     */
    const SEVERITIES = ['Low', 'Medium', 'High', 'Critical'];

    protected $fillable = [
        'project_id',
        'issue_name',
        'description',
        'status',
        'issue_type',
        'severity',
        'resolved_at',
        'baseline_schedule_id',
        'resolution_notes',
        'source_risk_id',
    ];

    protected $casts = [
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
        'resolved_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * An issue belongs to a project
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The baseline schedule month this issue is linked to (optional)
     */
    public function baselineSchedule()
    {
        return $this->belongsTo(ProjectBaselineSchedule::class);
    }

    /**
     * The risk that materialized into this issue (set on conversion)
     */
    public function sourceRisk()
    {
        return $this->belongsTo(Risk::class, 'source_risk_id');
    }

    /**
     * Evidence files attached to this issue (issue photo/document or resolution proof)
     */
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ==================== SCOPES ====================

    /**
     * Scope to issues that are not yet resolved (Open or In Progress)
     */
    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', ['Open', 'In Progress']);
    }

    /**
     * Scope to open issues (Open or In Progress) — alias for the Overview card
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['Open', 'In Progress']);
    }

    /**
     * Scope to unresolved issues that have been open beyond a given number of days
     */
    public function scopeAging($query, $days = 30)
    {
        return $query->unresolved()
            ->where('created_at', '<=', now()->subDays($days));
    }

    /**
     * Scope to unresolved issues of a specific type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('issue_type', $type)->unresolved();
    }
}
