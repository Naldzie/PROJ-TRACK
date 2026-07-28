<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiSolution extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = [
        'project_id',
        'regression_snapshot',
        'model_used',
        'analysis_text',
    ];

    protected $casts = [
        'regression_snapshot' => 'array',
    ];

    // An AI solution belongs to a single project
    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
