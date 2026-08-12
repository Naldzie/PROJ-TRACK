<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AI\AIPredictionService;

/**
 * Dev-only: show forecast accuracy broken down by data_source (C7).
 *
 * Predictions are logged with the mode that produced them
 * (project_history / hybrid / similar_projects). This endpoint surfaces the
 * error-rate comparison so weights / thresholds / the Phase 2 blend weight k
 * can be tuned from real evidence instead of guesses.
 *
 * Non-production only (routes/web.php dev block).
 */
class DevPredictionAccuracyController extends Controller
{
    public function index(AIPredictionService $predictionService)
    {
        return response()->json([
            'success'   => true,
            'accuracy_by_data_source' => $predictionService->predictionAccuracyByDataSource(),
        ]);
    }
}