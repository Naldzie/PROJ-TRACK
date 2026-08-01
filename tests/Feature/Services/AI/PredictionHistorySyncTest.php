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
    private function recordMonthlyProgress(Project $project, string $month, float $pct, ?string $inspectionDate = null): void
    {
        $request = Request::create('/progress', 'POST', [
            'inspection_date'         => $inspectionDate ?? Carbon::parse($month . '-01')->format('Y-m-d'),
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
     * the June prediction untouched. Re-running the prediction then REFRESHES
     * the single June row in place (keep-latest) instead of duplicating it.
     */
    public function test_same_month_re_inspection_updates_the_correct_actual(): void
    {
        $project = $this->makeProject();
        $this->seedBaselineObservations($project);
        $service = app(AIPredictionService::class);

        // April 2% → May prediction
        $service->generatePrediction($project);

        // May 15 (4%) → May actual = 4.0, then June prediction saved
        $this->recordMonthlyProgress($project, '2026-05', 4.0, '2026-05-15');

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

        // May 22 (4.5%) → May actual becomes 4.5, June actual stays null.
        $this->recordMonthlyProgress($project, '2026-05', 4.5, '2026-05-22');

        $mayPrediction->refresh();
        $junePrediction->refresh();

        $this->assertEquals(4.5, $mayPrediction->actual_next);
        $this->assertNull($junePrediction->actual_next);
        $this->assertCount(2, PredictionHistory::where('project_id', $project->id)->get());

        // Re-running the prediction REFRESHES the single June row in place
        // (data state changed 4.0 → 4.5) — keep-latest, no duplicate.
        $service->generatePrediction($project);
        $this->assertCount(2, PredictionHistory::where('project_id', $project->id)->get());

        $junePrediction->refresh();
        $this->assertEquals(4.5, $junePrediction->current_progress);

        // Identical same-data re-generation adds nothing more.
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

        // Delete the newest (latest) May record → actual_next reverts to the
        // remaining older record's value (4.0). Only the latest record may be
        // deleted (Fix A), so we must remove it before the older one.
        $deleteResponse = app(ProjectProgressController::class)->destroyProgress($project, $newer->id);
        $this->assertTrue($deleteResponse->getData()->success);

        $mayPrediction->refresh();
        $this->assertEquals(4.0, $mayPrediction->actual_next);

        // Delete the last remaining May record → actual_next reverts to null
        $lastResponse = app(ProjectProgressController::class)->destroyProgress($project, $older->id);
        $this->assertTrue($lastResponse->getData()->success);

        $mayPrediction->refresh();
        $this->assertNull($mayPrediction->actual_next);

        // Re-record May → actual_next refills
        $this->recordMonthlyProgress($project, '2026-05', 4.0);

        $mayPrediction->refresh();
        $this->assertEquals(4.0, $mayPrediction->actual_next);
    }

    /**
     * Under keep-latest the service never creates duplicate rows, but legacy
     * data (pre-v3) may contain multiple rows for one month. Recording the
     * month's actual must still write it to the NEWEST prediction row only,
     * nulling any superseded rows (self-healing).
     */
    public function test_sync_attributes_actual_to_latest_row_even_with_legacy_duplicates(): void
    {
        $project = $this->makeProject();
        $this->seedBaselineObservations($project);
        $service = app(AIPredictionService::class);

        // April 2% → May prediction
        $service->generatePrediction($project);

        // May 4.0 → June forecast saved
        $this->recordMonthlyProgress($project, '2026-05', 4.0, '2026-05-15');
        $service->generatePrediction($project);

        $juneRow = PredictionHistory::where('project_id', $project->id)
            ->where('month_label', '2026-06')
            ->first();

        // Simulate a legacy superseded duplicate (rev 1/rev 2 data).
        $legacyDuplicate = PredictionHistory::create([
            'project_id'           => $project->id,
            'month_label'          => '2026-06',
            'baseline_schedule_id' => $juneRow->baseline_schedule_id,
            'regression_method'    => $juneRow->regression_method,
            'current_progress'     => 4.0,
            'predicted_next'       => $juneRow->predicted_next,
            'r_squared'            => $juneRow->r_squared,
            'regression_snapshot'  => $juneRow->regression_snapshot,
        ]);

        $juneRow->refresh();
        $this->assertNull($juneRow->actual_next);
        $this->assertNull($legacyDuplicate->actual_next);

        // June is inspected → actual goes ONLY to the newest June row.
        $this->recordMonthlyProgress($project, '2026-06', 6.0, '2026-06-15');

        $juneRow->refresh();
        $legacyDuplicate->refresh();
        $this->assertNull($juneRow->actual_next);
        $this->assertEquals(6.0, $legacyDuplicate->actual_next);
    }

    /**
     * Once a predicted month is fulfilled (has an actual), no NEW forecast
     * row may be created for it — even if the data changes and the model's
     * "next month" still points at it (observations not aligned to the
     * baseline start). Prevents hindsight rows.
     */
    public function test_no_prediction_row_created_after_month_is_fulfilled(): void
    {
        $project = $this->makeProject();

        // Observations start at March (skip February) → the model's "next
        // month" (baseline offset by observation count) points BACK at the
        // last observed month instead of a future month.
        $this->recordMonthlyProgress($project, '2026-03', 1.5);
        $this->recordMonthlyProgress($project, '2026-04', 2.0);
        $this->recordMonthlyProgress($project, '2026-05', 4.0, '2026-05-15');

        $service = app(AIPredictionService::class);
        $service->generatePrediction($project);

        // One prediction row FOR May is saved (the target month), pending.
        $rows = PredictionHistory::where('project_id', $project->id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('2026-05', $rows[0]->month_label);
        $this->assertNull($rows[0]->actual_next);

        // A second inspection of May fulfills that row (actual = 4.5).
        $this->recordMonthlyProgress($project, '2026-05', 4.5, '2026-05-22');

        $rows[0]->refresh();
        $this->assertEquals(4.5, $rows[0]->actual_next);

        // Regenerating now must NOT create a new (hindsight) row for May.
        $service->generatePrediction($project);
        $this->assertCount(1, PredictionHistory::where('project_id', $project->id)->get());
    }

    /**
     * Editing a progress record updates inspection_date (the canonical actual
     * inspection date). The record keeps its month_label bucket; there is no
     * inspection_month_date to stay in sync anymore (refactor).
     */
    public function test_edit_updates_inspection_date(): void
    {
        $project = $this->makeProject();
        $this->seedBaselineObservations($project);

        $record = ProjectProgressHistory::where('project_id', $project->id)
            ->where('month_label', '2026-04')
            ->first();
        $this->assertNotNull($record);

        $editRequest = Request::create('/progress/' . $record->id, 'PUT', [
            'inspection_date'     => '2026-04-18',
            'progress_percentage' => 2.5,
        ]);
        $editResponse = app(ProjectProgressController::class)->editProgressRecord($editRequest, $project, $record->id);
        $this->assertTrue($editResponse->getData()->success);

        $record->refresh();
        $this->assertEquals('2026-04-18', $record->inspection_date->format('Y-m-d'));
        $this->assertEquals('2026-04', $record->month_label);
    }

    /**
     * A monthly progress record whose inspection date falls in a DIFFERENT
     * month than the selected month_label must be rejected — otherwise the
     * history would contain out-of-order months (e.g. Jan, Feb, Mar, Apr, Mar).
     */
    public function test_record_rejects_inspection_date_mismatching_month(): void
    {
        $project = $this->makeProject();

        $request = Request::create('/progress', 'POST', [
            'inspection_date'         => '2026-03-25', // March date
            'month_label'             => '2026-04',   // but April month selected
            'actual_progress_percent' => 2.0,
            'remarks'                 => null,
        ]);

        $response = app(ProjectProgressController::class)->recordMonthlyProgress($request, $project);
        $this->assertFalse($response->getData()->success);
        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEmpty(ProjectProgressHistory::where('project_id', $project->id)->get());
    }

    /**
     * The LAST monthly target keeps a 1-month grace window (Fix G): its
     * inspection may land up to one month AFTER the target month — the final
     * inspection often only reaches 100% a few days into the next month.
     */
    public function test_record_allows_last_month_inspection_one_month_after(): void
    {
        $project = $this->makeProject(); // last target = 2026-07

        $request = Request::create('/progress', 'POST', [
            'inspection_date'         => '2026-08-15', // one month after July
            'month_label'             => '2026-07',
            'actual_progress_percent' => 99.0,
            'remarks'                 => null,
        ]);

        $response = app(ProjectProgressController::class)->recordMonthlyProgress($request, $project);
        $this->assertTrue($response->getData()->success);
        $this->assertEquals(1, ProjectProgressHistory::where('project_id', $project->id)->count());
    }

    /**
     * The last-month grace is exactly ONE month — a date beyond that is still
     * rejected.
     */
    public function test_record_rejects_last_month_inspection_beyond_one_month_grace(): void
    {
        $project = $this->makeProject(); // last target = 2026-07

        $request = Request::create('/progress', 'POST', [
            'inspection_date'         => '2026-09-01', // two months after July
            'month_label'             => '2026-07',
            'actual_progress_percent' => 99.0,
            'remarks'                 => null,
        ]);

        $response = app(ProjectProgressController::class)->recordMonthlyProgress($request, $project);
        $this->assertFalse($response->getData()->success);
        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEmpty(ProjectProgressHistory::where('project_id', $project->id)->get());
    }

    /**
     * The grace applies ONLY to the last target — an earlier month is still
     * strictly bound to its own month.
     */
    public function test_record_rejects_non_last_month_inspection_outside_its_month(): void
    {
        $project = $this->makeProject(); // last target = 2026-07

        $request = Request::create('/progress', 'POST', [
            'inspection_date'         => '2026-07-15', // one month after June
            'month_label'             => '2026-06',
            'actual_progress_percent' => 30.0,
            'remarks'                 => null,
        ]);

        $response = app(ProjectProgressController::class)->recordMonthlyProgress($request, $project);
        $this->assertFalse($response->getData()->success);
        $this->assertEquals(422, $response->getStatusCode());
        $this->assertEmpty(ProjectProgressHistory::where('project_id', $project->id)->get());
    }

    /**
     * Deleting the LAST progress record for a project removes ALL prediction
     * rows — they become pending months that will never be observed.
     * (v4 fix — full wipe only.)
     */
    public function test_deleting_last_progress_record_removes_all_prediction_rows(): void
    {
        $project = $this->makeProject();
        $this->seedBaselineObservations($project);
        app(AIPredictionService::class)->generatePrediction($project);

        $this->assertNotEmpty(PredictionHistory::where('project_id', $project->id)->get());

        // Delete two of three records (latest first — Fix A delete guard) —
        // prediction rows must remain.
        foreach (ProjectProgressHistory::where('project_id', $project->id)->orderByDesc('id')->get()->take(2) as $record) {
            $response = app(ProjectProgressController::class)->destroyProgress($project, $record->id);
            $this->assertTrue($response->getData()->success);
        }

        $this->assertNotEmpty(PredictionHistory::where('project_id', $project->id)->get());

        // Delete the LAST remaining record → full wipe → all rows pruned.
        $last = ProjectProgressHistory::where('project_id', $project->id)->first();
        $this->assertNotNull($last);

        $response = app(ProjectProgressController::class)->destroyProgress($project, $last->id);
        $this->assertTrue($response->getData()->success);

        $this->assertEmpty(PredictionHistory::where('project_id', $project->id)->get());
    }

    /**
     * Deleting an individual/partial progress record must NEVER prune
     * prediction rows — that would permanently orphan the month's
     * forecast-vs-actual accuracy link (v4 guard).
     */
    public function test_deleting_partial_progress_keeps_prediction_rows(): void
    {
        $project = $this->makeProject();
        $this->seedBaselineObservations($project);
        $service = app(AIPredictionService::class);
        $service->generatePrediction($project);
        $this->recordMonthlyProgress($project, '2026-05', 4.0, '2026-05-15');
        $service->generatePrediction($project);

        $this->assertCount(2, PredictionHistory::where('project_id', $project->id)->get());

        // Delete one of several records (the latest — Fix A delete guard) →
        // prediction rows stay intact.
        $oneRecord = ProjectProgressHistory::where('project_id', $project->id)
            ->where('month_label', '2026-05')
            ->first();

        $response = app(ProjectProgressController::class)->destroyProgress($project, $oneRecord->id);
        $this->assertTrue($response->getData()->success);

        $this->assertCount(2, PredictionHistory::where('project_id', $project->id)->get());
    }

    /**
     * After a full wipe, re-entering progress rebuilds prediction rows
     * naturally on the next generation (v4 behavior).
     */
    public function test_reentering_progress_recreates_prediction_rows(): void
    {
        $project = $this->makeProject();
        $this->seedBaselineObservations($project);
        $service = app(AIPredictionService::class);
        $service->generatePrediction($project);
        $this->assertNotEmpty(PredictionHistory::where('project_id', $project->id)->get());

        // Full wipe (delete latest-first to satisfy the Fix A delete guard).
        foreach (ProjectProgressHistory::where('project_id', $project->id)->orderByDesc('id')->get() as $record) {
            app(ProjectProgressController::class)->destroyProgress($project, $record->id);
        }

        $this->assertEmpty(ProjectProgressHistory::where('project_id', $project->id)->get());
        $this->assertEmpty(PredictionHistory::where('project_id', $project->id)->get());

        // Re-enter observations → a fresh row is created on generation.
        $this->seedBaselineObservations($project);
        $service->generatePrediction($project);

        $this->assertNotEmpty(PredictionHistory::where('project_id', $project->id)->get());
    }
}
