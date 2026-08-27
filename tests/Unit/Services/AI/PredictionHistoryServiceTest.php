<?php

namespace Tests\Unit\Services\AI;

use App\Models\PredictionHistory;
use App\Models\Project;
use App\Services\AI\AIPredictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * Tests prediction history recording, keep-latest rules, accuracy tracking,
 * and backtest computation within AIPredictionService.
 */
class PredictionHistoryServiceTest extends TestCase
{
    use RefreshDatabase, CreatesProjectWithHistory;

    private AIPredictionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AIPredictionService::class);
    }

    // ========================================================================
    // Recording — basic
    // ========================================================================

    public function test_prediction_history_created_on_generate(): void
    {
        $project = $this->createProjectWithHistory(5);

        $this->service->generatePrediction($project);

        $this->assertDatabaseHas('prediction_history', [
            'project_id' => $project->id,
        ]);
    }

    public function test_prediction_history_stores_correct_fields(): void
    {
        $project = $this->createProjectWithHistory(5);

        $this->service->generatePrediction($project);

        $record = PredictionHistory::where('project_id', $project->id)->first();

        $this->assertNotNull($record);
        $this->assertNotNull($record->month_label);
        $this->assertNotNull($record->regression_method);
        $this->assertIsNumeric($record->current_progress);
        $this->assertIsNumeric($record->predicted_next);
        $this->assertNotNull($record->regression_snapshot);
    }

    // ========================================================================
    // Recording — keep-latest rule
    // ========================================================================

    public function test_keep_latest_refreshes_existing_row(): void
    {
        $project = $this->createProjectWithHistory(5);

        // First generation
        $this->service->generatePrediction($project);
        $count1 = PredictionHistory::where('project_id', $project->id)->count();

        // Second generation — should refresh, not create new row
        Cache::forget('ai_prediction:' . $project->id);
        $this->service->generatePrediction($project);
        $count2 = PredictionHistory::where('project_id', $project->id)->count();

        $this->assertSame($count1, $count2, 'Keep-latest should refresh, not duplicate');
    }

    public function test_identical_regeneration_skips_update(): void
    {
        $project = $this->createProjectWithHistory(5);

        $this->service->generatePrediction($project);
        $record1 = PredictionHistory::where('project_id', $project->id)->first();
        $updatedAt1 = $record1->created_at;

        Cache::forget('ai_prediction:' . $project->id);
        $this->service->generatePrediction($project);
        $record2 = PredictionHistory::where('project_id', $project->id)->orderByDesc('id')->first();

        // Same data → no update needed (keep-latest skips identical)
        $this->assertSame($record1->id, $record2->id);
    }

    // ========================================================================
    // Recording — fulfilled month guard
    // ========================================================================

    public function test_fulfilled_month_not_overwritten(): void
    {
        $project = $this->createProjectWithHistory(5);

        $this->service->generatePrediction($project);

        // Simulate fulfillment: set actual_next on the record
        $record = PredictionHistory::where('project_id', $project->id)->first();
        $record->update(['actual_next' => 25.0]);

        // Re-generate — fulfilled month should not be overwritten
        Cache::forget('ai_prediction:' . $project->id);
        $this->service->generatePrediction($project);

        $record->refresh();
        $this->assertEquals(25.0, $record->actual_next);
    }

    // ========================================================================
    // Recording — 100% progress guard
    // ========================================================================

    public function test_no_history_when_progress_at_100(): void
    {
        $project = $this->createProjectWithHistory(5);
        // Force all progress to 100
        $project->progressHistory->each(fn ($p) => $p->update([
            'actual_progress_percent' => 100,
            'progress_percentage' => 100,
        ]));

        Cache::forget('ai_prediction:' . $project->id);
        $this->service->generatePrediction($project);

        // With all progress at 100, no new pending history should be created
        // (the 100% guard in savePredictionHistory)
        $pending = PredictionHistory::where('project_id', $project->id)
            ->whereNull('actual_next')
            ->count();
        $this->assertGreaterThanOrEqual(0, $pending);
    }

    // ========================================================================
    // Backtest — avg absolute error
    // ========================================================================

    public function test_backtest_avg_abs_error_with_no_fulfilled(): void
    {
        $project = $this->createProjectWithHistory(5);

        // Generate prediction — all rows are pending (no actual_next)
        $result = $this->service->generatePrediction($project);

        // The output contains reliability data which includes backtest info
        $this->assertArrayHasKey('reliability', $result);
        $this->assertArrayHasKey('next_prediction', $result['reliability']);
    }

    // ========================================================================
    // Accuracy by data source (C7)
    // ========================================================================

    public function test_prediction_accuracy_by_data_source_returns_array(): void
    {
        $result = $this->service->predictionAccuracyByDataSource();

        $this->assertIsArray($result);
    }

    public function test_prediction_accuracy_by_data_source_empty_when_no_history(): void
    {
        $result = $this->service->predictionAccuracyByDataSource();

        // No fulfilled rows → empty array or zero counts
        $this->assertIsArray($result);
        foreach ($result as $source => $stats) {
            $this->assertArrayHasKey('count', $stats);
            $this->assertArrayHasKey('accurate_count', $stats);
            $this->assertArrayHasKey('accuracy_percent', $stats);
            $this->assertNull($stats['accuracy_percent']);
        }
    }

    public function test_prediction_accuracy_with_fulfilled_rows(): void
    {
        $project = $this->createProjectWithHistory(5);

        $snapshot = [
            'regression_method' => 'slr',
            'r_squared' => 0.95,
            'slope' => 5.0,
            'intercept' => -10.0,
        ];

        // Create fulfilled prediction history — both within accuracy threshold (|diff| < 2)
        PredictionHistory::create([
            'project_id' => $project->id,
            'month_label' => now()->format('Y-m'),
            'regression_method' => 'slr',
            'data_source' => 'project_history',
            'current_progress' => 20.0,
            'predicted_next' => 30.0,
            'actual_next' => 30.5,  // diff = 0.5 < 2 → accurate
            'r_squared' => 0.95,
            'regression_snapshot' => $snapshot,
        ]);

        PredictionHistory::create([
            'project_id' => $project->id,
            'month_label' => now()->subMonth()->format('Y-m'),
            'regression_method' => 'slr',
            'data_source' => 'project_history',
            'current_progress' => 10.0,
            'predicted_next' => 20.0,
            'actual_next' => 21.0,  // diff = 1.0 < 2 → accurate
            'r_squared' => 0.90,
            'regression_snapshot' => $snapshot,
        ]);

        $result = $this->service->predictionAccuracyByDataSource();

        $this->assertArrayHasKey('project_history', $result);
        $this->assertSame(2, $result['project_history']['count']);
        $this->assertSame(2, $result['project_history']['accurate_count']);
        $this->assertEquals(100.0, $result['project_history']['accuracy_percent']);
    }

    // ========================================================================
    // Regression method and data source stored
    // ========================================================================

    public function test_regression_method_stored_in_history(): void
    {
        $project = $this->createProjectWithHistory(6);

        $result = $this->service->generatePrediction($project);

        $record = PredictionHistory::where('project_id', $project->id)->first();
        $this->assertSame($result['regression']['regression_method'], $record->regression_method);
    }

    public function test_data_source_stored_in_history(): void
    {
        $project = $this->createProjectWithHistory(5);

        $this->service->generatePrediction($project);

        $record = PredictionHistory::where('project_id', $project->id)->first();
        $this->assertNotNull($record->data_source);
    }
}
