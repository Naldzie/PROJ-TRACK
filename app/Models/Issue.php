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

    // ==================== SCOPES ====================

    /**
     * Scope to issues that are not yet resolved (Open or In Progress)
     */
    public function scopeUnresolved($query)
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
