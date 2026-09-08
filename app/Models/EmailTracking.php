<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmailTracking extends Model
{
    /**
     * Review-window workflow statuses.
     *
     * DRAFT .......... manually composed message, send whenever staff choose.
     * PENDING_REVIEW . auto-generated on the 25th; editable during the 5-day
     *                  review window, auto-sent once review_until passes.
     * SENT ........... delivered; content is locked (edits re-send as revision).
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_SENT = 'sent';

    protected $fillable = [
        'contractor_id',
        'project_id',
        'ai_solution_id',
        'generated_by',
        'recipient_email',
        'subject',
        'status',
        'review_until',
        'body',
        'tracking_token',
        'sent_at',
        'delivered_at',
        'opened_at',
        'open_count',
        'is_read',
        'is_edited',
        'edited_by',
        'edited_at',
    ];

    protected $casts = [
        'review_until' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'edited_at' => 'datetime',
        'open_count' => 'integer',
        'is_read' => 'boolean',
        'is_edited' => 'boolean',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function contractor()
    {
        return $this->belongsTo(Contractor::class);
    }

    public function aiSolution()
    {
        return $this->belongsTo(AiSolution::class);
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function editedBy()
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }

    public function isDraft(): bool
    {
        return is_null($this->sent_at);
    }

    /**
     * Whether this row is an auto-generated report sitting in its 5-day
     * review window (unsent, awaiting the auto-send step or manual send).
     */
    public function isPendingReview(): bool
    {
        return is_null($this->sent_at) && $this->status === self::STATUS_PENDING_REVIEW;
    }

    /**
     * Whether the review window has ended and the row is due for auto-send.
     */
    public function isDueForSending(): bool
    {
        return $this->isPendingReview()
            && $this->review_until !== null
            && $this->review_until->lte(now());
    }

    /**
     * Auto-staged reports awaiting their send date.
     */
    public function scopePendingReview($query)
    {
        return $query->whereNull('sent_at')->where('status', self::STATUS_PENDING_REVIEW);
    }

    /**
     * Pending-review rows whose window has ended (sendable now).
     */
    public function scopeDueForSending($query)
    {
        return $query->pendingReview()->where('review_until', '<=', now());
    }

    public function effectiveBody(): ?string
    {
        return $this->body ?? $this->aiSolution?->analysis_text;
    }
}
