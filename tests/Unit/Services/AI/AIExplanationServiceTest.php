<?php

namespace Tests\Unit\Services\AI;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Services\AI\AIExplanationService;
use App\Services\AI\AIPredictionService;
use App\Services\AI\BaselineComparisonService;
use App\Services\AI\CompletionComparisonService;
use App\Services\AI\CompletionEstimationService;
use App\Services\AI\ForecastReliabilityService;
use App\Services\AI\ForecastService;
use App\Services\AI\HistoricalSimilarityService;
use App\Services\AI\ProjectAssessmentService;
use App\Services\AI\Regression\LinearRegressionService;
use App\Services\AI\Regression\PolynomialRegressionService;
use App\Services\AI\Regression\RegressionDatasetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * AIExplanationServiceTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the AI prompt builder (Phase 14). The forecast-reliability block
 * must be the trust statement handed to the model, while R² is demoted to a
 * "curve fit, matches past data only" note.
 *
 * The private buildPrompt() is exercised via reflection so no Gemini API call
 * is made (consistent with the codebase's no-mocking convention).
 *
 * Run With
 * --------
 *   php artisan test --filter=AIExplanationServiceTest
 */
class AIExplanationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AIExplanationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $datasetBuilder = new RegressionDatasetBuilder();
        $regressionService = new LinearRegressionService();
        $polynomialService = new PolynomialRegressionService();
        $forecastService = new ForecastService($regressionService, $polynomialService);
        $comparisonService = new BaselineComparisonService();
        $assessmentService = new ProjectAssessmentService();
        $completionService = new CompletionEstimationService();
        $completionComparisonService = new CompletionComparisonService($completionService);
        $historicalSimilarityService = new HistoricalSimilarityService();
        $reliabilityService = new ForecastReliabilityService();

        $predictionService = new AIPredictionService(
            $datasetBuilder,
            $regressionService,
            $polynomialService,
            $forecastService,
            $comparisonService,
            $assessmentService,
            $completionService,
            $completionComparisonService,
            $historicalSimilarityService,
            $reliabilityService
        );

        $this->service = new AIExplanationService($predictionService);
    }

    /**
     * ------------------------------------------------------------------------
     * Build a representative prediction report for the prompt builder
     * ------------------------------------------------------------------------
     */
    private function predictionReport(array $overrides = []): array
    {
        $base = [
            'current_state' => ['progress' => 42.0],
            'next_prediction' => ['progress' => 44.2],
            'forecast' => [],
            'assessment' => [
                ['month_index' => 4, 'variance' => 1.5, 'risk_level' => 'Low'],
                ['month_index' => 5, 'variance' => 1.8, 'risk_level' => 'Low'],
            ],
            'regression' => [
                'r_squared' => 0.954,
                'fit_quality' => 'Excellent fit',
                'regression_method' => 'slr',
                'equation' => 'y = 2.0x + 5.0',
                'method_reason' => 'Best R²',
                'fit_quality_label' => 'Curve Fit Quality',
            ],
            'statistics' => ['observation_count' => 8],
            'completion_multi' => [],
            'reliability' => [
                'next_reliability' => ['score' => 69, 'label' => 'Medium'],
                'completion_reliability' => ['score' => 75, 'label' => 'Medium'],
                'factors' => [
                    [
                        'key' => 'backtest_accuracy',
                        'label' => 'Backtest accuracy',
                        'score' => 60,
                        'detail' => 'No prediction history yet',
                    ],
                    [
                        'key' => 'data_sufficiency',
                        'label' => 'Data sufficiency',
                        'score' => 70,
                        'detail' => '5 real progress records',
                    ],
                    [
                        'key' => 'forecast_horizon',
                        'label' => 'Forecast horizon',
                        'score' => 100,
                        'detail' => '1 month ahead',
                    ],
                    [
                        'key' => 'method_agreement',
                        'label' => 'Method agreement',
                        'score' => 100,
                        'detail' => 'All 2 methods agree (High, spread 15 days)',
                    ],
                ],
            ],
        ];

        return array_replace_recursive($base, $overrides);
    }

    /**
     * ------------------------------------------------------------------------
     * Invoke the private buildPrompt via reflection
     * ------------------------------------------------------------------------
     */
    private function buildPrompt(Project $project, array $prediction): string
    {
        $method = new \ReflectionMethod(AIExplanationService::class, 'buildPrompt');
        $method->setAccessible(true);

        return $method->invoke($this->service, $project, $prediction);
    }

    /*
    |==========================================================================
    | Forecast Reliability Block (Phase 14)
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Prompt carries the reliability trust statement
     * ------------------------------------------------------------------------
     */
    public function test_prompt_contains_reliability_block(): void
    {
        $project = Project::factory()->create();
        $prediction = $this->predictionReport();

        $prompt = $this->buildPrompt($project, $prediction);

        $this->assertStringContainsString('FORECAST RELIABILITY:', $prompt);
        $this->assertStringContainsString('Next-month forecast: Medium (69/100)', $prompt);
        $this->assertStringContainsString('Completion estimate: Medium (75/100)', $prompt);
    }

    /**
     * ------------------------------------------------------------------------
     * Prompt lists the weighted reliability factors
     * ------------------------------------------------------------------------
     */
    public function test_prompt_contains_reliability_factors(): void
    {
        $project = Project::factory()->create();
        $prediction = $this->predictionReport();

        $prompt = $this->buildPrompt($project, $prediction);

        $this->assertStringContainsString('Prediction history: 60/100 — No prediction history yet', $prompt);
        $this->assertStringContainsString('Data: 70/100 — 5 real progress records', $prompt);
        $this->assertStringContainsString('Forecast horizon: 100/100 — 1 month ahead', $prompt);
        $this->assertStringContainsString('Method agreement: 100/100 — All 2 methods agree (High, spread 15 days)', $prompt);
    }

    /**
     * ------------------------------------------------------------------------
     * R² is demoted to a curve-fit note, never the trust statement
     * ------------------------------------------------------------------------
     */
    public function test_r_squared_is_demoted_to_curve_fit_note(): void
    {
        $project = Project::factory()->create();
        $prediction = $this->predictionReport();

        $prompt = $this->buildPrompt($project, $prediction);

        $this->assertStringContainsString(
            'Curve Fit Quality (matches past data only): R² = 0.954 (Excellent fit)',
            $prompt
        );
        $this->assertStringNotContainsString(
            '- R²: 0.954 (Fit Quality: Excellent fit)',
            $prompt
        );
        $this->assertStringContainsString('Do NOT use R-squared', $prompt);
        $this->assertStringContainsString('nothing about whether future predictions will be correct', $prompt);
        $this->assertStringContainsString('Use the FORECAST RELIABILITY score', $prompt);
    }

    /**
     * ------------------------------------------------------------------------
     * Missing reliability block degrades gracefully
     * ------------------------------------------------------------------------
     */
    public function test_prompt_handles_missing_reliability(): void
    {
        $project = Project::factory()->create();
        $prediction = $this->predictionReport(['reliability' => null]);

        $prompt = $this->buildPrompt($project, $prediction);

        $this->assertStringContainsString(
            'FORECAST RELIABILITY:',
            $prompt
        );
        $this->assertStringContainsString(
            'N/A — insufficient data for a reliability estimate',
            $prompt
        );
    }

    /*
    |==========================================================================
    | Phase E: AI ↔ Baseline Linkage
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * saveAnalysis stamps the pending baseline ID on the ai_solutions record
     * ------------------------------------------------------------------------
     */
    public function test_saveAnalysis_stamps_pending_baseline_id(): void
    {
        $project = Project::factory()->create();

        // Create 3 baseline months
        for ($i = 0; $i < 3; $i++) {
            ProjectBaselineSchedule::create([
                'project_id'                 => $project->id,
                'planned_date'               => now()->addMonths($i + 1)->endOfMonth(),
                'month_label'                => now()->addMonths($i + 1)->format('Y-m'),
                'planned_progress_percent'   => ($i + 1) * 25,
                'original_planned_progress_percent' => ($i + 1) * 25,
                'original_planned_date'      => now()->addMonths($i + 1)->endOfMonth(),
            ]);
        }

        // Record progress for first 2 months only — month 3 is pending
        \App\Models\ProjectProgressHistory::create([
            'project_id'              => $project->id,
            'month_label'             => now()->addMonth()->format('Y-m'),
            'actual_progress_percent' => 25.0,
            'inspection_date'         => now()->addMonth()->endOfMonth(),
        ]);
        \App\Models\ProjectProgressHistory::create([
            'project_id'              => $project->id,
            'month_label'             => now()->addMonths(2)->format('Y-m'),
            'actual_progress_percent' => 50.0,
            'inspection_date'         => now()->addMonths(2)->endOfMonth(),
        ]);

        // Invoke saveAnalysis via reflection (private method)
        $method = new \ReflectionMethod(AIExplanationService::class, 'saveAnalysis');
        $method->setAccessible(true);

        $solution = $method->invoke($this->service, $project, 'Test analysis text', []);

        $this->assertNotNull($solution->baseline_schedule_id);
        $pending = $project->baselineSchedules()
            ->orderBy('planned_date')
            ->get()
            ->first(fn ($b) => !$project->progressHistory()->pluck('month_label')->contains($b->month_label));
        $this->assertEquals($pending->id, $solution->baseline_schedule_id);
    }

    /**
     * ------------------------------------------------------------------------
     * saveAnalysis stores null baseline_schedule_id when no pending baseline
     * ------------------------------------------------------------------------
     */
    public function test_saveAnalysis_stamps_null_when_no_pending_baseline(): void
    {
        $project = Project::factory()->create();

        // Create 2 baseline months
        for ($i = 0; $i < 2; $i++) {
            ProjectBaselineSchedule::create([
                'project_id'                 => $project->id,
                'planned_date'               => now()->addMonths($i + 1)->endOfMonth(),
                'month_label'                => now()->addMonths($i + 1)->format('Y-m'),
                'planned_progress_percent'   => ($i + 1) * 50,
                'original_planned_progress_percent' => ($i + 1) * 50,
                'original_planned_date'      => now()->addMonths($i + 1)->endOfMonth(),
            ]);
        }

        // Record progress for ALL baseline months — no pending baseline
        \App\Models\ProjectProgressHistory::create([
            'project_id'              => $project->id,
            'month_label'             => now()->addMonth()->format('Y-m'),
            'actual_progress_percent' => 50.0,
            'inspection_date'         => now()->addMonth()->endOfMonth(),
        ]);
        \App\Models\ProjectProgressHistory::create([
            'project_id'              => $project->id,
            'month_label'             => now()->addMonths(2)->format('Y-m'),
            'actual_progress_percent' => 100.0,
            'inspection_date'         => now()->addMonths(2)->endOfMonth(),
        ]);

        $method = new \ReflectionMethod(AIExplanationService::class, 'saveAnalysis');
        $method->setAccessible(true);

        $solution = $method->invoke($this->service, $project, 'Test text', []);

        $this->assertNull($solution->baseline_schedule_id);
    }
}
