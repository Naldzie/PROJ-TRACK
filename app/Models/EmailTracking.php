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
        'tracking_token',
        'sent_at',
        'delivered_at',
        'opened_at',
        'open_count',
        'is_read',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'open_count' => 'integer',
        'is_read' => 'boolean',
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

    public static function generateToken(): string
    {
        return Str::random(64);
    }
}
