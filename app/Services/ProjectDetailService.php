<?php

namespace App\Services;

use App\Models\Project;
use App\Services\AI\AIPredictionPresenter;

class ProjectDetailService
{
    private AIPredictionPresenter $aiPresenter;
    private ProjectOverviewService $overviewService;
    private ProjectStatusService $statusService;
    private ProjectChartService $chartService;
    private ProjectDocumentService $documentService;

    public function __construct(
        AIPredictionPresenter $aiPresenter,
        ProjectOverviewService $overviewService,
        ProjectStatusService $statusService,
        ProjectChartService $chartService,
        ProjectDocumentService $documentService
    ) {
        $this->aiPresenter = $aiPresenter;
        $this->overviewService = $overviewService;
        $this->statusService = $statusService;
        $this->chartService = $chartService;
        $this->documentService = $documentService;
    }

    /**
     * Prepare all project detail data for the view
     * 
     * @param Project $project
     * @return array
     */
    public function prepareProjectData(Project $project): array
    {
        // NOTE: charts and trend data are lazy-loaded via AJAX when the user
        // clicks the Progress tab. See getChartData() in ProjectController.
        //
        // ai_prediction is computed here (NOT deferred) because it renders in the
        // Overview tab via server-side Blade — it is never read from the AJAX response.
        // generateAIPredictionMockup() fetches its own two lightweight collections
        // (baseline schedules + progress history) and is fast enough to run on page load.

        // Get documents data
        $documentsData = $this->buildDocumentsComplianceData($project);

        return [
            'overview'         => $this->overviewService->getOverviewStats($project, null),
            'progress'         => $this->overviewService->getProgressMetrics($project),
            'charts'           => ['ready' => false],
            'status'           => $this->statusService->getProjectStatus($project, null),
            'ai_prediction'    => $this->buildAIPredictionData($project),
            'trends'           => ['ready' => false],
            'documents'        => $documentsData['documents'] ?? [],
            'documents_by_type'=> $documentsData['documents_by_type'] ?? collect(),
            'total_documents'  => $documentsData['total_documents'] ?? 0,
            'recent_activity'  => $this->overviewService->getRecentActivity($project),
            'alerts'           => $this->overviewService->getAlerts($project),
            'open_issues'      => $this->overviewService->getOpenIssues($project),
        ];
    }

    public function prepareTrendData(Project $project): array
    {
        return $this->chartService->prepareTrendData($project);
    }

    private function buildDocumentsComplianceData(Project $project): array
    {
        return $this->documentService->getDocumentsComplianceData($project);
    }

    public function prepareChartData(Project $project, $baselineSchedules = null, $progressHistory = null, $activities = null): array
    {
        return $this->chartService->prepareChartData($project, $baselineSchedules, $progressHistory, $activities);
    }

    public function getRiskLevel(float $behindPercent): array
    {
        return $this->statusService->getRiskLevel($behindPercent);
    }

    private function buildAIPredictionData(Project $project): array
    {
        return $this->aiPresenter->present($project);
    }

    /**
     * Prepare forecast chart data (polynomial near-term + completion shifted baselines).
     *
     * Pure computation — no DB queries. Takes the already-computed chart data and
     * AI prediction report, extracts polynomial forecast points and generates
     * shifted baseline curves for Schedule-Aware and EAC_time completion estimates.
     *
     * Called by: ProjectController::getChartData()
     * Calls: nothing (array manipulation only)
     *
     * @param array $chartData     Output from prepareChartData()
     * @param array $aiPrediction  Output from AIPredictionPresenter::present()
     * @return array{polynomial_forecast: array, completion_forecast: array}
     */
    public function prepareForecastData(array $chartData, array $aiPrediction): array
    {
        // Polynomial near-term forecast (Chart 1)
        // First 4 predicted progress points from polynomial regression
        $polynomialForecast = [];

        // Completion forecast (Chart 2)
        // Shifted planned baselines for Schedule-Aware and EAC_time
        $completionForecast = [];

        $isInsufficient = $aiPrediction['is_insufficient_data'] ?? false;
        $isCompleted = $aiPrediction['is_completed'] ?? false;

        if (!$isInsufficient && !$isCompleted) {
            // -- Chart 1: Polynomial near-term forecast (first 4 months) --
            $regressionMethod = $aiPrediction['regression_info']['regression_method'] ?? 'slr';
            $forecastData = $aiPrediction['forecast_data'] ?? [];

            if ($regressionMethod === 'polynomial' && count($forecastData) > 0) {
                $takeCount = min(4, count($forecastData));
                for ($i = 0; $i < $takeCount; $i++) {
                    $entry = $forecastData[$i];
                    $plannedDate = $entry['planned_date'] ?? null;
                    $ts = $plannedDate instanceof \Carbon\Carbon
                        ? $plannedDate->timestamp * 1000
                        : strtotime(($entry['month_label'] ?? '') . '-01') * 1000;
                    $polynomialForecast[] = [
                        'x' => $ts,
                        'y' => round((float) ($entry['predicted_progress'] ?? 0), 1),
                    ];
                }
                // Anchor the dashed line at the last actual progress point
                // so the forecast connects visually from where actuals end
                $actualData = $chartData['actual_data'] ?? [];
                if (!empty($actualData)) {
                    $lastActual = end($actualData);
                    array_unshift($polynomialForecast, [
                        'x' => $lastActual['x'],
                        'y' => $lastActual['y'],
                    ]);
                }
            }

            // -- Chart 2: Shifted baselines from completion estimates --
            $completionMulti = $aiPrediction['completion_multi'] ?? [];
            $methods = $completionMulti['methods'] ?? [];

            if (!empty($methods)) {
                $frozenPlanned = $chartData['frozen_planned_data'] ?? [];
                $adjustedPlanned = $chartData['adjusted_planned_data'] ?? [];
                $hasExtension = $chartData['has_extension'] ?? false;
                $msPerMonth = 30.44 * 86400 * 1000;

                // Use the effective baseline (adjusted plan if extensions exist)
                // as the base for curve shifting, so the shifted forecast aligns
                // with the current approved schedule, not the original frozen one
                $baseData = $hasExtension && !empty($adjustedPlanned)
                    ? $adjustedPlanned
                    : $frozenPlanned;
                $totalMonths = count($baseData);

                $completionForecast['frozen_planned_data'] = $frozenPlanned;
                $completionForecast['adjusted_planned_data'] = $adjustedPlanned;
                $completionForecast['has_extension'] = $hasExtension;
                $completionForecast['y_max'] = $chartData['y_max'] ?? 100;

                // Map internal method keys to shorter display keys for the frontend
                $outputKeyMap = ['schedule_aware' => 'schedule_aware', 'earned_schedule' => 'eac_time'];

                foreach (['schedule_aware', 'earned_schedule'] as $key) {
                    $method = $methods[$key] ?? null;
                    if (empty($method['estimated_completion_index'])) {
                        continue;
                    }
                    $outputKey = $outputKeyMap[$key];
                    $lag = (float) $method['estimated_completion_index'] - $totalMonths;
                    $shifted = [];
                    foreach ($baseData as $pt) {
                        $shifted[] = [
                            'x' => $pt['x'] + ($lag * $msPerMonth),
                            'y' => $pt['y'],
                        ];
                    }
                    $completionForecast[$outputKey . '_shifted'] = $shifted;
                    $completionForecast[$outputKey . '_label'] = $method['label_short']
                        ?? ($key === 'schedule_aware' ? 'Schedule-Aware' : 'EAC_time');
                }
            }
        }

        return [
            'polynomial_forecast' => $polynomialForecast,
            'completion_forecast' => $completionForecast,
        ];
    }

    public function getActivities($projectId)
    {
        return $this->overviewService->getActivities($projectId);
    }

    public function getActivitiesByMonth($projectId, $monthLabel)
    {
        return $this->overviewService->getActivitiesByMonth($projectId, $monthLabel);
    }

    public function getSCurveMonthlySummary($projectId)
    {
        return $this->chartService->getSCurveMonthlySummary($projectId);
    }

    public function getActivitiesStatistics($projectId)
    {
        return $this->overviewService->getActivitiesStatistics($projectId);
    }

    public function getCurrentProgress($projectId)
    {
        return $this->chartService->getCurrentProgress($projectId);
    }

    public function buildBaselineScheduleChartData(Project $project, $baselineSchedules = null, $progressHistory = null, $activities = null): array
    {
        return $this->chartService->buildBaselineScheduleChartData($project, $baselineSchedules, $progressHistory, $activities);
    }

    public function getMonthlySummary(Project $project): array
    {
        return $this->chartService->getMonthlySummary($project);
    }
}