<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Contractor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'contractor_id',
        'company_name',
        'contractor_name',
        'contact_number',
        'company_landline',
        'company_address',
        'performance_rating',
        'on_time_delivery_pct',
        'completed_projects_count',
        'avg_delay_days',
        'last_project_completed_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}