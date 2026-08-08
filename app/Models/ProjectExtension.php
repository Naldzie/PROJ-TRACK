<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectExtension extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'project_extensions';

    // Structured EOT-style grounds for the request (Decision 3).
    const REASON_CATEGORIES = [
        'Weather', 'Materials', 'Labor', 'Site access', 'Permits', 'Design change', 'Other',
    ];

    protected $fillable = [
        'project_id',
        'extension_number',
        'original_end_date',
        'new_end_date',
        'duration_days',
        'reason',
        'reason_category',
        'status',
        'rejection_reason',
        'approved_by_user_id',
        'approved_at',
        'created_by_user_id',
        // Partial approval: granted date when an admin awards less than requested
        // (Decision 7). Baseline restore point captured on adjusted-baseline save (§4).
        'approved_new_end_date',
        'baseline_snapshot',
    ];

    protected $casts = [
        'original_end_date' => 'date',
        'new_end_date' => 'date',
        'approved_at' => 'datetime',
        // The date actually granted by the admin (may be < new_end_date).
        'approved_new_end_date' => 'date',
        // JSON snapshot of the whole baseline schedule at save time.
        'baseline_snapshot' => 'array',
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

    public function requester()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Evidence attachments (context 'extension') bound to this request.
     * Inverse morphMany for Attachment::ALLOWED_ATTACHABLE_TYPES (Decision 4).
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
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

    public function scopeSuperseded($query)
    {
        return $query->where('status', 'superseded');
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

    public function isSuperseded(): bool
    {
        return $this->status === 'superseded';
    }

    /**
     * The effective deadline this extension grants. Partial approvals
     * (approved_new_end_date) override the requested new_end_date.
     * (Decision 7 — the project deadline reads this everywhere.)
     */
    public function effectiveEndDate()
    {
        return $this->approved_new_end_date ?? $this->new_end_date;
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

    /**
     * Days this request has sat awaiting admin review (pending only).
     */
    public function daysAwaitingReview(): int
    {
        return max(0, (int) $this->created_at->diffInDays(now()));
    }
}
