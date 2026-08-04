<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectExtension extends Model
{
    use HasFactory;

    protected $table = 'project_extensions';

    protected $fillable = [
        'project_id',
        'extension_number',
        'original_end_date',
        'new_end_date',
        'duration_days',
        'reason',
        'status',
        'rejection_reason',
        'approved_by_user_id',
        'approved_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'original_end_date' => 'date',
        'new_end_date' => 'date',
        'approved_at' => 'datetime',
    ];

    /**
     * Relationships
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Scopes
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('extension_number');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Helpers
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Accessors
     */
    public function getExtensionDurationAttribute()
    {
        if ($this->new_end_date && $this->original_end_date) {
            return $this->new_end_date->diffInMonths($this->original_end_date);
        }
        return 0;
    }
}