<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'project_id',
        'action',
        'description',
        'ip_address',
        'visibility',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The project this log entry belongs to (null for global/system entries)
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Scope a query to only include logs visible to the given role.
     * Admins see everything. Staff (internal) see 'all' + 'staff' tiers.
     * External roles (contractor) only see visibility = 'all'
     * (Phase 8 / Decision 16).
     */
    public function scopeVisibleTo($query, $role)
    {
        if ($role === 'admin') {
            return $query;
        }

        return $query->whereIn('visibility', $role === 'staff' ? ['all', 'staff'] : ['all']);
    }

    /**
     * Scope for the global dashboard feed. Admins see everything; staff see
     * visibility = 'all' entries minus routine deletions (Delete Project is
     * still shown) and admin-housekeeping entries (Edit User, Password Change).
     * Deletions remain visible on the project-scoped Overview feed.
     */
    public function scopeForDashboard($query, $role)
    {
        $query->visibleTo($role);

        if ($role !== 'admin') {
            $query->where(function ($q) {
                $q->where('action', 'not like', 'Delete%')
                  ->orWhere('action', 'Delete Project');
            })->whereNotIn('action', ['Edit User', 'Password Change']);
        }

        return $query;
    }
}