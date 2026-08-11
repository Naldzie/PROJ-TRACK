<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PredictionHistory extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $table = 'prediction_history';

    protected $fillable = [
        'project_id',
        'month_label',
        'baseline_schedule_id',
        'regression_method',
        'current_progress',
        'predicted_next',
        'predicted_low',
        'predicted_high',
        'actual_next',
        'r_squared',
        'regression_snapshot',
    ];

    protected $casts = [
        'current_progress'    => 'float',
        'predicted_next'      => 'float',
        'predicted_low'       => 'float',
        'predicted_high'      => 'float',
        'actual_next'         => 'float',
        'r_squared'           => 'float',
        'regression_snapshot' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function baselineSchedule()
    {
        return $this->belongsTo(ProjectBaselineSchedule::class, 'baseline_schedule_id');
    }
}
