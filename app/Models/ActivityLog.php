<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
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
     * Scope a query to only include logs visible to the given role.
     * Admins see everything. Everyone else only sees visibility = 'all'.
     */
    public function scopeVisibleTo($query, $role)
    {
        if ($role === 'admin') {
            return $query;
        }

        return $query->where('visibility', 'all');
    }
}