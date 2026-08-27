<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Prediction Cache
    |--------------------------------------------------------------------------
    |
    | Controls caching for AIPredictionService::generatePrediction() results.
    | TTL in minutes. null = no time-based expiry (cache only invalidated on
    | data changes). 480 = 8 hours — stale projects recompute once per working
    | day on first morning load.
    |
    */

    'cache' => [
        'prediction_ttl' => 480,
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk Level Thresholds
    |--------------------------------------------------------------------------
    |
    | Gap-based risk classification ladder (positive = behind schedule).
    | Used by ProjectStatusService, ProjectHealthService, RecommendationEngine.
    |
    */

    'risk' => [
        'gap' => [
            'ahead'  => -5,   // variance <= -5 → ahead of schedule
            'on_track' => 5,  // variance <= 5  → on track
            'low'    => 10,   // variance < 10  → low risk
            'medium' => 15,   // variance < 15  → medium risk; >= 15 = high risk
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trend Detection
    |--------------------------------------------------------------------------
    |
    | Variance delta threshold for classifying trend direction.
    | abs(delta) >= threshold → Accelerating/Decelerating.
    |
    */

    'trend' => [
        'variance_delta' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fit Quality (R-squared) Thresholds
    |--------------------------------------------------------------------------
    |
    | Maps R-squared values to fit quality labels.
    | Used by LinearRegressionService, AIPredictionPresenter.
    |
    */

    'fit_quality' => [
        'excellent' => 0.90,
        'very_good' => 0.80,
        'good'      => 0.70,
        'moderate'  => 0.50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Accuracy Thresholds
    |--------------------------------------------------------------------------
    |
    | Defines "accurate" and "close" for prediction history backtesting.
    | abs(variance) < accurate → accurate; < close → close.
    |
    */

    'accuracy' => [
        'accurate_threshold' => 2,
        'close_threshold'    => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Sufficiency Thresholds
    |--------------------------------------------------------------------------
    |
    | Observation count thresholds for data quality labels and regression gates.
    |
    */

    'data_sufficiency' => [
        'low'                 => 3,  // observations <= 3 → low data
        'moderate'            => 6,  // observations <= 6 → moderate data
        'min_for_regression'  => 2,  // observations < 2 → insufficient
        'min_polynomial'      => 4,  // observations < 4 → disable polynomial
        'min_observations_for_curve' => 4,
        'min_similar_projects' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Regression Settings
    |--------------------------------------------------------------------------
    |
    | Minimum observations and R-squared improvement thresholds.
    |
    */

    'regression' => [
        'min_observations'            => 2,
        'min_observations_polynomial' => 4,
        'slr_polynomial_r2_threshold' => 0.02,
    ],

    /*
    |--------------------------------------------------------------------------
    | Contractor Performance Rating Tiers
    |--------------------------------------------------------------------------
    |
    | [min_on_time_pct, max_avg_delay_days] → rating (5=Superior ... 1=Poor).
    | Evaluated top-down; first match wins.
    |
    */

    'contractor_rating' => [
        5 => ['on_time_pct' => 90, 'avg_delay_days' => 7],
        4 => ['on_time_pct' => 80, 'avg_delay_days' => 15],
        3 => ['on_time_pct' => 70, 'avg_delay_days' => 30],
        2 => ['on_time_pct' => 50, 'avg_delay_days' => 60],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Thresholds
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        'aging_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Recommendation Engine Thresholds
    |--------------------------------------------------------------------------
    */

    'recommendations' => [
        'max_ai'                => 6,
        'max_rule'              => 8,
        'min_observations'      => 2,
        'escalation_gap'        => -20,
        'escalation_days'       => 30,
        'systemic_issues_count' => 5,
        'material_issues_count' => 3,
        'contractor_issues_count' => 2,
        'contractor_rating_max' => 2,
        'design_issues_count'   => 2,
        'aging_issues_count'    => 2,
        'carryover_count'       => 2,
        'carryover_gap'         => -5,
        'deadline_days'         => 15,
        'deadline_progress'     => 90,
        'issue_resolution_open_count' => 3,
        'progress_gap_for_extension' => -10,
        'at_risk_run'           => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Forecast Reliability
    |--------------------------------------------------------------------------
    */

    'reliability' => [
        'high_threshold'    => 80,
        'medium_threshold'  => 55,
        'r2_floor'          => 0.50,
        'min_real_obs'      => 2,
        'honesty_fulfilled' => 3,
        'honesty_real_obs'  => 6,
        'backtest_point_weight'    => 0.6,
        'backtest_coverage_weight' => 0.4,
        'weights' => [
            'backtest'  => 40,
            'data'      => 30,
            'horizon'   => 15,
            'agreement' => 15,
        ],
        'data_tiers' => [
            ['min' => 8, 'score' => 100],
            ['min' => 6, 'score' => 85],
            ['min' => 4, 'score' => 70],
            ['min' => 2, 'score' => 40],
        ],
        'data_default_score' => 20,
        'horizon_tiers' => [
            ['max' => 3,  'score' => 100],
            ['max' => 6,  'score' => 85],
            ['max' => 12, 'score' => 65],
            ['max' => 24, 'score' => 45],
        ],
        'horizon_default_score' => 25,
        'agreement_scores' => [
            'High'     => 100,
            'Moderate' => 70,
            'Low'      => 40,
        ],
        'agreement_default_score' => 60,
        'blend_shrink' => [
            'small_class_thresholds' => [
                ['max' => 3,  'multiplier' => 0.80],
                ['max' => 5,  'multiplier' => 0.90],
            ],
            'spread_thresholds' => [
                ['min' => 25, 'multiplier' => 0.85],
                ['min' => 15, 'multiplier' => 0.92],
            ],
            'floor' => 0.60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Historical Similarity
    |--------------------------------------------------------------------------
    */

    'similarity' => [
        'weights' => [
            'category'   => 30,
            'budget'     => 25,
            'duration'   => 20,
            'contractor' => 15,
            'workers'    => 10,
        ],
        'tolerances' => [
            'budget'   => 0.20,
            'duration' => 0.30,
            'workers'  => 0.40,
        ],
        'min_score'           => 50,
        'min_similar_projects' => 3,
        'min_observations'    => 4,
        'blend_k'             => 4,
        'scale_ratio_min'     => 0.5,
        'scale_ratio_max'     => 5.0,
        'aggregate_interval'  => 5,
        'recency' => [
            'full_weight_years' => 2.0,
            'decay_end_years'   => 5.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Completion Estimation
    |--------------------------------------------------------------------------
    */

    'completion' => [
        'high_confidence_r2'       => 0.85,
        'medium_confidence_r2'     => 0.70,
        'min_r2_for_estimate'      => 0.50,
        'high_confidence_obs'      => 6,
        'medium_confidence_obs'    => 4,
        'high_confidence_index_ratio' => 1.5,
        'low_confidence_index_ratio'  => 2.0,
        'max_reasonable_months'    => 1000,
        'agreement' => [
            'high_days'     => 15,
            'moderate_days' => 45,
        ],
    ],

];
