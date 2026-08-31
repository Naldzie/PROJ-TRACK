<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warranty extends Model
{
    protected $fillable = [
        'project_id',
        'contractor_id',
        'title',
        'completion_date',
        'start_date',
        'end_date',
        'mid_year_inspection',
        'final_inspection',
        'status',
        'remarks',
        'file_path',
    ];

    protected $casts = [
        'completion_date' => 'date:Y-m-d',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'mid_year_inspection' => 'date:Y-m-d',
        'final_inspection' => 'date:Y-m-d',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function contractor()
    {
        return $this->belongsTo(Contractor::class);
    }
}