<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\CompletionEstimationService;
use App\Services\AI\Regression\PolynomialRegressionService;
use App\Services\AI\Regression\LinearRegressionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class PolynomialOverOptimismTest extends TestCase
{
    private CompletionEstimationService $completionService;
    private PolynomialRegressionService $polynomialService;
    private LinearRegressionService $slrService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->completionService = new CompletionEstimationService();
        $this->polynomialService = new PolynomialRegressionService();
        $this->slrService = new LinearRegressionService();
    }

    private function makeSchedulesWithPlanned(int $count, array $plannedProgress): Collection
    {
        $start = Carbon::now()->subMonth();
        $schedules = [];
        for ($i = 0; $i < $count; $i++) {
            $schedule = new \stdClass();
            $schedule->planned_date = $start->copy()->addMonths($i);
            $schedule->planned_progress_percent = $plannedProgress[$i] ?? 100.0;
            $schedules[] = $schedule;
        }
        return new Collection($schedules);
    }

    // Test Dataset 1: Project with 8 observations, always behind schedule
    // Planned: S-curve (slow start, rapid middle, plateau)
    // Actual:  Same S-curve shape but shifted right (consistently ~20% behind)
    // Expected: Polynomial should PREDICT early completion (over-optimistic)
    //           Schedule-Aware should show delay (realistic)
    public function test_dataset_1_polynomial_over_optimistic_vs_schedule_aware(): void
    {
        $plannedProgress = [2, 5, 10, 18, 28, 40, 55, 70, 85, 100];

        // Actual: S-curve shifted ~2 months behind the plan
        $actualProgress = [1, 2, 5, 10, 18, 28, 40, 55];

        $x = [1, 2, 3, 4, 5, 6, 7, 8];
        $schedules = $this->makeSchedulesWithPlanned(10, $plannedProgress);
        $plannedEndDate = $schedules->last()->planned_date;

        $rawPolyResult = $this->polynomialService->analyzeDataset($x, $actualProgress);

        // estimatePolynomial() requires regression_method key in analysis
        $polyResult = array_merge($rawPolyResult, ['regression_method' => 'polynomial']);

        $this->assertNotNull($polyResult);
        echo "\n--- Dataset 1: S-Curve Behind Schedule ---\n";
        echo "Planned: " . json_encode($plannedProgress) . "\n";
        echo "Actual:  " . json_encode($actualProgress) . "\n";
        echo "Polynomial R²: " . $polyResult['r_squared'] . "\n";

        $polyEstimate = $this->completionService->estimatePolynomial(
            $polyResult,
            10,
            $plannedEndDate,
            $schedules
        );

        if ($polyEstimate) {
            echo "Polynomial Estimate: " . $polyEstimate['estimated_completion_date']->format('M d, Y') . "\n";
            echo "Polynomial Delay: " . $polyEstimate['predicted_delay_days'] . " days\n";
        } else {
            echo "Polynomial Estimate: null\n";
        }

        $slrResult = $this->slrService->analyzeDataset($x, $actualProgress);
        $slrEstimate = $this->completionService->estimate(
            $plannedEndDate,
            $slrResult,
            8,
            $schedules,
            55.0
        );

        echo "Schedule-Aware Estimate: " . ($slrEstimate['estimated_completion_date']->format('M d, Y') ?? 'null') . "\n";
        echo "Schedule-Aware Delay: " . ($slrEstimate['predicted_delay_days'] ?? 'null') . " days\n";

        // THE KEY ASSERTION: Document whether polynomial over-estimates
        if ($polyEstimate !== null && $slrEstimate !== null) {
            $polyDelay = $polyEstimate['predicted_delay_days'];
            $saDelay = $slrEstimate['predicted_delay_days'];

            echo "\nPolynomial delay: {$polyDelay} days\n";
            echo "Schedule-Aware delay: {$saDelay} days\n";

            if ($polyDelay < $saDelay) {
                echo "⚠️ Polynomial predicts EARLIER completion than Schedule-Aware\n";
                echo "   (Over-optimistic: project is consistently behind but polynomial\n";
                echo "    extrapolates the S-curve acceleration toward early completion)\n";
            }
            $this->assertTrue($polyDelay > $saDelay,
                "Polynomial ($polyDelay days) should NOT predict earlier than Schedule-Aware ($saDelay days)");
        } else {
            echo "Polynomial returned null (cannot compare)\n";
        }
    }

    // Dataset 2: Project with 12 observations, consistently 15% behind plan
    // Simulates a mid-construction project where the S-curve is accelerating
    // but the project is still behind
    public function test_dataset_2_polynomial_over_optimism_with_mid_project_data(): void
    {
        $plannedProgress = [1, 3, 6, 10, 15, 22, 30, 40, 50, 60, 70, 80, 90, 100];

        // Actual: S-curve shifted ~2 months behind
        $actualProgress = [1, 2, 3, 6, 10, 15, 22, 30, 40, 50, 60, 68];

        $x = range(1, 12);
        $schedules = $this->makeSchedulesWithPlanned(14, $plannedProgress);
        $plannedEndDate = $schedules->last()->planned_date;

        $rawPolyResult = $this->polynomialService->analyzeDataset($x, $actualProgress);
        $polyResult = array_merge($rawPolyResult, ['regression_method' => 'polynomial']);

        echo "\n--- Dataset 2: Mid-Project Behind Schedule ---\n";
        echo "Planned: " . json_encode($plannedProgress) . "\n";
        echo "Actual:  " . json_encode($actualProgress) . "\n";
        echo "Polynomial R²: " . $polyResult['r_squared'] . "\n";

        $polyEstimate = $this->completionService->estimatePolynomial(
            $polyResult,
            14,
            $plannedEndDate,
            $schedules
        );

        $slrResult = $this->slrService->analyzeDataset($x, $actualProgress);
        $slrEstimate = $this->completionService->estimate(
            $plannedEndDate,
            $slrResult,
            12,
            $schedules,
            68.0
        );

        echo "Polynomial Estimate: " . (($polyEstimate['estimated_completion_date'] ?? null)?->format('M d, Y') ?? 'null') . "\n";
        echo "Polynomial Delay: " . ($polyEstimate['predicted_delay_days'] ?? 'null') . " days\n";
        echo "Schedule-Aware Estimate: " . ($slrEstimate['estimated_completion_date']->format('M d, Y') ?? 'null') . "\n";
        echo "Schedule-Aware Delay: " . ($slrEstimate['predicted_delay_days'] ?? 'null') . " days\n";

        if ($polyEstimate !== null && $slrEstimate !== null) {
            $polyDelay = $polyEstimate['predicted_delay_days'];
            $saDelay = $slrEstimate['predicted_delay_days'];

            echo "\nPolynomial delay: {$polyDelay} days\n";
            echo "Schedule-Aware delay: {$saDelay} days\n";

            if ($polyDelay < $saDelay) {
                echo "⚠️ Polynomial predicts EARLIER than Schedule-Aware (over-optimistic)\n";
            }
        } else {
            echo "Polynomial returned null (cannot compare)\n";
        }
    }

    // Dataset 3: User's actual Project 138 scenario
    // Using the values the user reported from the front-end
    public function test_dataset_3_project_138_scenario(): void
    {
        // Simulate Project 138's data based on user report:
        // Polynomial: Nov 30, 2026 (90 days ahead)
        // Sched Aware: Mar 11, 2027 (11 days behind)
        // EAC time: Apr 04, 2027 (35 days behind)

        // 14-month project, 10 months of data
        $plannedProgress = [
            0.6, 1.8, 3.8, 7.0, 12.0, 19.0, 28.0, 39.0, 50.0,
            60.0, 72.0, 84.0, 94.0, 100.0
        ];

        // Actual: consistently behind, S-curve shifted ~3 months right
        $actualProgress = [
            0.3, 0.8, 1.5, 3.5, 6.0, 10.0, 16.0, 24.0, 33.0, 42.0
        ];

        $x = range(1, 10);
        $schedules = $this->makeSchedulesWithPlanned(14, $plannedProgress);
        $plannedEndDate = $schedules->last()->planned_date;

        $rawPolyResult = $this->polynomialService->analyzeDataset($x, $actualProgress);
        $polyResult = array_merge($rawPolyResult, ['regression_method' => 'polynomial']);

        echo "\n--- Dataset 3: Project 138 Simulation ---\n";
        echo "Planned: " . json_encode($plannedProgress) . "\n";
        echo "Actual:  " . json_encode($actualProgress) . "\n";
        echo "Polynomial R²: " . $polyResult['r_squared'] . "\n";

        // Check if polynomial was selected (R² improves over SLR)
        $slrResult = $this->slrService->analyzeDataset($x, $actualProgress);
        $r2Improvement = $polyResult['r_squared'] - $slrResult['r_squared'];
        echo "SLR R²: " . $slrResult['r_squared'] . "\n";
        echo "R² improvement: " . $r2Improvement . "\n";
        echo ($r2Improvement >= 0.02 ? "-> Polynomial SELECTED\n" : "-> SLR SELECTED\n");

        $polyEstimate = $this->completionService->estimatePolynomial(
            $polyResult,
            14,
            $plannedEndDate,
            $schedules
        );

        $slrAnalysis = [
            'r_squared' => $slrResult['r_squared'],
            'observation_count' => count($x),
        ];

        $saEstimate = $this->completionService->estimate(
            $plannedEndDate,
            $slrAnalysis,
            10,
            $schedules,
            42.0
        );

        $esEstimate = $this->completionService->estimateEarnedSchedule(
            $plannedEndDate,
            10,
            42.0,
            $schedules,
            $slrAnalysis
        );

        echo "\n=== COMPLETION ESTIMATES ===\n";
        $polyDate = $polyEstimate['estimated_completion_date'] ?? null;
        $polyDelay = $polyEstimate['predicted_delay_days'] ?? null;
        $saDate = $saEstimate['estimated_completion_date'] ?? null;
        $saDelay = $saEstimate['predicted_delay_days'] ?? null;
        $esDate = $esEstimate['estimated_completion_date'] ?? null;
        $esDelay = $esEstimate['predicted_delay_days'] ?? null;

        $fmtDate = fn($d) => $d ? $d->format('M d, Y') : 'N/A';
        $fmtDelay = fn($d) => $d !== null ? ($d >= 0 ? "{$d} days behind" : abs($d) . " days ahead") : 'N/A';

        echo "Polynomial:     " . $fmtDate($polyDate) . " (" . $fmtDelay($polyDelay) . ")\n";
        echo "Schedule-Aware: " . $fmtDate($saDate) . " (" . $fmtDelay($saDelay) . ")\n";
        echo "EAC_time:       " . $fmtDate($esDate) . " (" . $fmtDelay($esDelay) . ")\n";

        // Document the over-optimism if present
        if ($polyEstimate !== null && $saEstimate !== null) {
            if ($polyDelay < $saDelay) {
                echo "\n⚠️ CONFIRMED: Polynomial predicts EARLIER completion than Schedule-Aware\n";
                echo "   Gap: " . ($saDelay - $polyDelay) . " days difference\n";
            }
        }
    }
}
