<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warranty extends Model
{
    protected $fillable = [
        'project_id',
        'title',
        'start_date',
        'end_date',
        'status',
        'file_path',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}