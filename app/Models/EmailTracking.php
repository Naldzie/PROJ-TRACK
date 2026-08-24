<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmailTracking extends Model
{
    protected $fillable = [
        'contractor_id',
        'project_id',
        'ai_solution_id',
        'generated_by',
        'recipient_email',
        'subject',
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

    public function effectiveBody(): ?string
    {
        return $this->body ?? $this->aiSolution?->analysis_text;
    }
}
