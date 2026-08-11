<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Services\AI\AIPredictionPresenter;
use App\Services\AI\AIPredictionService;
use App\Services\AI\BaselineComparisonService;
use App\Services\AI\CompletionComparisonService;
use App\Services\AI\CompletionEstimationService;
use App\Services\AI\ForecastReliabilityService;
use App\Services\AI\ForecastService;
use App\Services\AI\HistoricalSimilarityService;
use App\Services\AI\ProjectAssessmentService;
use App\Services\AI\RecommendationEngine;
use App\Services\AI\Regression\LinearRegressionService;
use App\Services\AI\Regression\PolynomialRegressionService;
use App\Services\AI\Regression\RegressionDatasetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * ============================================================================
 * AIPredictionTabRenderTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Phase 14 Step 7 — renders the live ai-prediction tab blade for every mode
 * the presenter can return, proving the Forecast Reliability relabel, badges,
 * and tooltips work across all branches without blade errors:
 *
 *   1. Completed project
 *   2. Insufficient data
 *   3. Normal SLR prediction
 *   4. Polynomial regression
 *   5. Cross-project supplement (hybrid data source)
 *
 * The presenter output is fed straight into the real blade (same variable
 * contract ProjectDetailService uses), and each render is asserted for its
 * mode marker plus the absence of the old "Prediction Confidence" wording.
 *
 * Run With
 * --------
 *   php artisan test --filter=AIPredictionTabRenderTest
 */
class AIPredictionTabRenderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesProjectWithHistory;

    private AIPredictionPresenter $presenter;

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

        $this->presenter = new AIPredictionPresenter(
            $predictionService,
            new RecommendationEngine()
        );
    }

    /**
     * Render the real AI tab blade for a project/presentation pair.
     */
    private function renderTab(object $project, array $presented): string
    {
        return view(
            'projects.project_detail.components.tabs.ai-prediction',
            ['project' => $project, 'prediction' => $presented]
        )->render();
    }

    /**
     * Assert a mode render contains its marker and no legacy wording.
     */
    private function assertModeRender(string $html, string $marker): void
    {
        $this->assertStringContainsString($marker, $html);
        $this->assertStringNotContainsString('Prediction Confidence', $html);
    }

    /*
    |==========================================================================
    | Mode 1 — Completed project
    |==========================================================================
    */

    public function test_completed_project_tab_renders(): void
    {
        $project = $this->createCompletedProject();

        $presented = $this->presenter->present($project);
        $this->assertTrue($presented['is_completed']);

        $html = $this->renderTab($project, $presented);

        $this->assertModeRender($html, 'Project Completed');
    }

    /*
    |==========================================================================
    | Mode 2 — Insufficient data
    |==========================================================================
    */

    public function test_insufficient_data_tab_renders(): void
    {
        $project = $this->createProjectWithInsufficientData();

        $presented = $this->presenter->present($project);
        $this->assertTrue($presented['is_insufficient_data']);

        $html = $this->renderTab($project, $presented);

        $this->assertModeRender($html, 'Insufficient Data');
    }

    /*
    |==========================================================================
    | Mode 3 — Normal SLR prediction
    |==========================================================================
    */

    public function test_normal_slr_tab_renders_with_reliability(): void
    {
        // Perfectly linear progress so SLR is selected, not polynomial.
        $project = $this->createProjectWithHistory(
            months: 6,
            actualProgress: [5, 10, 15, 20, 25, 30]
        );

        $presented = $this->presenter->present($project);
        $this->assertFalse($presented['is_insufficient_data']);
        $this->assertSame('slr', $presented['regression_info']['regression_method']);

        $html = $this->renderTab($project, $presented);

        $this->assertModeRender($html, 'Linear (SLR)');
        $this->assertStringContainsString('Forecast Reliability', $html);
        $this->assertStringContainsString('reliability-hint', $html);
    }

    /*
    |==========================================================================
    | Mode 4 — Polynomial regression
    |==========================================================================
    */

    public function test_polynomial_tab_renders(): void
    {
        // Curved dataset so the polynomial fit clearly beats SLR.
        $project = $this->createProjectWithHistory(
            months: 8,
            actualProgress: [1, 2, 4, 8, 14, 24, 40, 62]
        );

        $presented = $this->presenter->present($project);
        $this->assertFalse($presented['is_insufficient_data']);
        $this->assertSame('polynomial', $presented['regression_info']['regression_method']);

        $html = $this->renderTab($project, $presented);

        $this->assertModeRender($html, 'Polynomial (Cubic)');
        $this->assertStringContainsString('Forecast Reliability', $html);
    }

    /*
    |==========================================================================
    | Mode 5 — Cross-project supplement (hybrid data)
    |==========================================================================
    */

    public function test_supplement_mode_tab_renders(): void
    {
        $category = Category::create([
            'name'        => 'Render Supplement Category',
            'description' => 'Test category',
            'is_active'   => true,
        ]);

        for ($i = 0; $i < 3; $i++) {
            $this->createProjectWithHistory(
                months: 6,
                actualProgress: [10, 25, 45, 65, 85, 100],
                overrides: [
                    'status'      => 'Completed',
                    'category_id' => $category->id,
                ]
            );
        }

        $target = $this->createProjectWithHistory(
            months: 1,
            actualProgress: [5.0],
            overrides: ['status' => 'Ongoing', 'category_id' => $category->id]
        );

        $presented = $this->presenter->present($target);
        $this->assertFalse($presented['is_insufficient_data']);
        $this->assertSame('hybrid', $presented['regression_info']['data_source']);

        $html = $this->renderTab($target, $presented);

        $this->assertModeRender($html, 'Cross-Project Supplement');
        $this->assertStringContainsString('Forecast Reliability', $html);
    }
}
