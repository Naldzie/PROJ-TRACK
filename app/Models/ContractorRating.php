<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContractorRating extends Model
{
    protected $fillable = [
        'contractor_id',
        'project_id',
        'ai_solution_id',
        'rating',
        'ai_review_text',
        'created_by',
    ];

    public function contractor()
    {
        return $this->belongsTo(Contractor::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function aiSolution()
    {
        return $this->belongsTo(AiSolution::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
