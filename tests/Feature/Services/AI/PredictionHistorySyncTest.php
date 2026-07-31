<?php

namespace Tests\Feature\Services\AI;

use App\Http\Controllers\ProjectProgressController;
use App\Models\PredictionHistory;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use App\Services\AI\AIPredictionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ============================================================================
 * PredictionHistorySyncTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the Phase 7 prediction-history auto-save fix end-to-end through
 * the real controller flow:
 *
 *   1. A prediction row is saved FOR the predicted month, linked to that
 *      month's baseline schedule.
 *   2. Recording monthly progress updates the CORRECT month's actual_next
 *      (a second inspection in the same month overrides, never a later
 *      month's prediction row).
 *   3. Editing and deleting progress records recompute actual_next from the
 *      remaining records — reverting to null when none remain.
 *
 * Run With
 * --------
 *   php artisan test --filter=PredictionHistorySyncTest
 */
class PredictionHistorySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create([
            'name'  => 'Test Admin',
            'email' => fake()->unique()->safeEmail(),
            'role'  => 'admin',
        ]));
    }

    /**
     * Create a project with baseline schedules for Feb..Jul 2026.
     */
    private function makeProject(): Project
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        foreach (['2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07'] as $i => $month) {
            ProjectBaselineSchedule::create([
                'project_id'                       => $project->id,
                'month_label'                      => $month,
                'planned_date'                     => Carbon::parse($month . '-01')->endOfMonth()->format('Y-m-d'),
                'planned_progress_percent'         => ($i + 1) * 5,
                'original_planned_progress_percent'=> ($i + 1) * 5,
            ]);
        }

        return $project;
    }

    /**
     * Record monthly progress through the controller (the same path the app uses).
     */
    private function recordMonthlyProgress(Project $project, string $month, float $pct): void
    {
        $request = Request::create('/progress', 'POST', [
            'inspection_month_date'   => Carbon::parse($month . '-01')->format('Y-m-d'),
            'month_label'             => $month,
            'actual_progress_percent' => $pct,
            'remarks'                 => null,
        ]);

        $response = app(ProjectProgressController::class)->recordMonthlyProgress($request, $project);

        $this->assertTrue($response->getData()->success, "recordMonthlyProgress failed for {$month} / {$pct}%");
    }

    /**
     * Baseline scenario: Feb/Mar/Apr recorded, April 2% is the latest
     * observation, so the first prediction is FOR May.
     */
    private function seedBaselineObservations(Project $project): void
    {
        $this->recordMonthlyProgress($project, '2026-02', 1.0);
        $this->recordMonthlyProgress($project, '2026-03', 1.5);
        $this->recordMonthlyProgress($project, '2026-04', 2.0);
    }

    /**
     * April 2% → May prediction saved with the May baseline id and the
     * predicted month_label 2026-05.
     */
    public function test_april_prediction_is_saved_for_may_with_baseline_link(): void
    {
        $project = $this->makeProject();
        $this->seedBaselineObservations($project);

        app(AIPredictionService::class)->generatePrediction($project);

        $rows = PredictionHistory::where('project_id', $project->id)->get();
        $this->assertCount(1, $rows);

        $mayBaseline = ProjectBaselineSchedule::where('project_id', $project->id)
            ->where('month_label', '2026-05')
            ->first();

        $this->assertEquals('2026-05', $rows[0]->month_label);
        $this->assertEquals($mayBaseline->id, $rows[0]->baseline_schedule_id);
        $this->assertEquals(2.0, $rows[0]->current_progress);
    }

    /**
     * May 15 (4%) fills May's actual; May 22 (4.5%) overrides it and leaves
     * the June prediction untouched — and never creates a duplicate June row.
     */
    public function test_same_month_re_inspection_updates_the_correct_actual(): void
    {
        $project = $this->makeProject();
        $this->seedBaselineObservations($project);
        $service = app(AIPredictionService::class);

        // April 2% → May prediction
        $service->generatePrediction($project);

        // May 15 (4%) → May actual = 4.0, then June prediction saved
        $this->recordMonthlyProgress($project, '2026-05', 4.0);

        $mayPrediction = PredictionHistory::where('project_id', $project->id)
            ->where('month_label', '2026-05')
            ->first();
        $this->assertEquals(4.0, $mayPrediction->actual_next);

        $service->generatePrediction($project);
        $this->assertCount(2, PredictionHistory::where('project_id', $project->id)->get());

        $junePrediction = PredictionHistory::where('project_id', $project->id)
            ->where('month_label', '2026-06')
            ->first();
        $this->assertNotNull($junePrediction);

        // May 22 (4.5%) → May actual becomes 4.5, June actual stays null,
        // and no duplicate June row is created.
        $this->recordMonthlyProgress($project, '2026-05', 4.5);

        $mayPrediction->refresh();
        $junePrediction->refresh();

        $this->assertEquals(4.5, $mayPrediction->actual_next);
        $this->assertNull($junePrediction->actual_next);
        $this->assertCount(2, PredictionHistory::where('project_id', $project->id)->get());

        // Re-running the prediction still does not duplicate June.
        $service->generatePrediction($project);
        $this->assertCount(2, PredictionHistory::where('project_id', $project->id)->get());
    }

    /**
     * Editing a progress record flows the edited value into actual_next;
     * deleting records recomputes from the latest remaining record; deleting
     * the last record for a month reverts actual_next to null; re-recording
     * refills it.
     */
    public function test_edit_and_delete_recompute_actual_next(): void
    {
        $project = $this->makeProject();
        $this->seedBaselineObservations($project);
        $service = app(AIPredictionService::class);

        $service->generatePrediction($project); // May prediction
        $this->recordMonthlyProgress($project, '2026-05', 4.0); // May record 1
        $service->generatePrediction($project); // June prediction
        $this->recordMonthlyProgress($project, '2026-05', 4.5); // May record 2

        $mayPrediction = PredictionHistory::where('project_id', $project->id)
            ->where('month_label', '2026-05')
            ->first();
        $this->assertEquals(4.5, $mayPrediction->actual_next);

        $mayRecords = ProjectProgressHistory::where('project_id', $project->id)
            ->where('month_label', '2026-05')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $mayRecords);

        $older = $mayRecords->first();
        $newer = $mayRecords->last();

        // Edit the newest May record 4.5 → 5.0
        $editRequest = Request::create('/progress/' . $newer->id, 'PUT', [
            'inspection_date'   => '2026-05-22',
            'progress_percentage' => 5.0,
        ]);
        $editResponse = app(ProjectProgressController::class)->editProgressRecord($editRequest, $project, $newer->id);
        $this->assertTrue($editResponse->getData()->success);

        $mayPrediction->refresh();
        $this->assertEquals(5.0, $mayPrediction->actual_next);

        // Delete the older May record (4.0) → actual_next = 5.0 (latest remaining)
        $deleteResponse = app(ProjectProgressController::class)->destroyProgress($project, $older->id);
        $this->assertTrue($deleteResponse->getData()->success);

        $mayPrediction->refresh();
        $this->assertEquals(5.0, $mayPrediction->actual_next);

        // Delete the last May record → actual_next reverts to null
        $lastResponse = app(ProjectProgressController::class)->destroyProgress($project, $newer->id);
        $this->assertTrue($lastResponse->getData()->success);

        $mayPrediction->refresh();
        $this->assertNull($mayPrediction->actual_next);

        // Re-record May → actual_next refills
        $this->recordMonthlyProgress($project, '2026-05', 4.0);

        $mayPrediction->refresh();
        $this->assertEquals(4.0, $mayPrediction->actual_next);
    }
}
