<?php

namespace Tests\Feature\Infrastructure;

use App\Models\AiSolution;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectExtension;
use App\Models\ProjectProgressHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * ============================================================================
 * FactoryVerificationTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Verifies that all model factories can create valid records in the
 * SQLite in-memory database. Also verifies the CreatesProjectWithHistory
 * helper trait works correctly.
 *
 * This is the first test to run after setting up test infrastructure
 * (Phase 8.1). All subsequent AI module tests depend on these factories.
 *
 * Run with:
 *   php artisan test --filter=FactoryVerificationTest
 */
class FactoryVerificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesProjectWithHistory;

    /*
    |==========================================================================
    | Individual Factory Tests
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Project Factory
     * ------------------------------------------------------------------------
     *
     * Verify Project factory creates a valid record with expected defaults.
     */
    public function test_project_factory_creates_valid_project(): void
    {
        $project = Project::factory()->create();

        $this->assertNotNull($project->id);
        $this->assertNotNull($project->code);
        $this->assertNotNull($project->title);
        $this->assertEquals('Ongoing', $project->status);
        $this->assertFalse($project->is_archived);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    /**
     * ------------------------------------------------------------------------
     * Completed Project State
     * ------------------------------------------------------------------------
     *
     * Verify the completed() state modifier works.
     */
    public function test_project_factory_completed_state(): void
    {
        $project = Project::factory()->completed()->create();

        $this->assertEquals('Completed', $project->status);
        $this->assertNotNull($project->actual_end_date);
    }

    /**
     * ------------------------------------------------------------------------
     * Planned Project State
     * ------------------------------------------------------------------------
     *
     * Verify the planned() state modifier works.
     */
    public function test_project_factory_planned_state(): void
    {
        $project = Project::factory()->planned()->create();

        $this->assertEquals('Planned', $project->status);
    }

    /**
     * ------------------------------------------------------------------------
     * Progress History Factory
     * ------------------------------------------------------------------------
     *
     * Verify ProjectProgressHistory factory creates a valid record
     * linked to a project.
     */
    public function test_progress_history_factory_creates_valid_record(): void
    {
        $project = Project::factory()->create();
        $history = ProjectProgressHistory::factory()->create([
            'project_id' => $project->id,
        ]);

        $this->assertNotNull($history->id);
        $this->assertEquals($project->id, $history->project_id);
        $this->assertNotNull($history->inspection_date);
        $this->assertNotNull($history->actual_progress_percent);
        $this->assertDatabaseHas('project_progress_history', ['id' => $history->id]);
    }

    /**
     * ------------------------------------------------------------------------
     * Baseline Schedule Factory
     * ------------------------------------------------------------------------
     *
     * Verify ProjectBaselineSchedule factory creates a valid record.
     */
    public function test_baseline_schedule_factory_creates_valid_record(): void
    {
        $project = Project::factory()->create();
        $schedule = ProjectBaselineSchedule::factory()->create([
            'project_id' => $project->id,
        ]);

        $this->assertNotNull($schedule->id);
        $this->assertEquals($project->id, $schedule->project_id);
        $this->assertNotNull($schedule->planned_date);
        $this->assertNotNull($schedule->planned_progress_percent);
        $this->assertDatabaseHas('project_baseline_schedules', ['id' => $schedule->id]);
    }

    /**
     * ------------------------------------------------------------------------
     * Baseline Schedule Original Plan State
     * ------------------------------------------------------------------------
     *
     * Verify originalPlan() state modifier freezes the original progress.
     */
    public function test_baseline_schedule_original_plan_state(): void
    {
        $project = Project::factory()->create();
        $schedule = ProjectBaselineSchedule::factory()->originalPlan()->create([
            'project_id' => $project->id,
        ]);

        $this->assertNotNull($schedule->original_planned_progress_percent);
        $this->assertEquals(
            $schedule->planned_progress_percent,
            $schedule->original_planned_progress_percent
        );
    }

    /**
     * ------------------------------------------------------------------------
     * Extension Factory
     * ------------------------------------------------------------------------
     *
     * Verify ProjectExtension factory creates a valid record.
     */
    public function test_extension_factory_creates_valid_record(): void
    {
        $project = Project::factory()->create();
        $extension = ProjectExtension::factory()->create([
            'project_id' => $project->id,
        ]);

        $this->assertNotNull($extension->id);
        $this->assertEquals($project->id, $extension->project_id);
        $this->assertNotNull($extension->extension_number);
        $this->assertNotNull($extension->reason);
        $this->assertDatabaseHas('project_extensions', ['id' => $extension->id]);
    }

    /**
     * ------------------------------------------------------------------------
     * Issue Factory
     * ------------------------------------------------------------------------
     *
     * Verify Issue factory creates a valid record.
     */
    public function test_issue_factory_creates_valid_record(): void
    {
        $project = Project::factory()->create();
        $issue = Issue::factory()->create([
            'project_id' => $project->id,
        ]);

        $this->assertNotNull($issue->id);
        $this->assertEquals($project->id, $issue->project_id);
        $this->assertNotNull($issue->issue_name);
        $this->assertContains($issue->status, ['Open', 'In Progress', 'Resolved', 'Closed']);
        $this->assertDatabaseHas('issues', ['id' => $issue->id]);
    }

    /**
     * ------------------------------------------------------------------------
     * Issue Factory — Open State
     * ------------------------------------------------------------------------
     *
     * Verify the open() state modifier works.
     */
    public function test_issue_factory_open_state(): void
    {
        $project = Project::factory()->create();
        $issue = Issue::factory()->open()->create([
            'project_id' => $project->id,
        ]);

        $this->assertEquals('Open', $issue->status);
    }

    /**
     * ------------------------------------------------------------------------
     * AI Solution Factory
     * ------------------------------------------------------------------------
     *
     * Verify AiSolution factory creates a valid record.
     */
    public function test_ai_solution_factory_creates_valid_record(): void
    {
        $project = Project::factory()->create();
        $solution = AiSolution::factory()->create([
            'project_id' => $project->id,
        ]);

        $this->assertNotNull($solution->id);
        $this->assertEquals($project->id, $solution->project_id);
        $this->assertNotNull($solution->analysis_text);
        $this->assertNotNull($solution->model_used);
        $this->assertIsArray($solution->regression_snapshot);
        $this->assertDatabaseHas('ai_solutions', ['id' => $solution->id]);
    }

    /*
    |==========================================================================
    | CreatesProjectWithHistory Trait Tests
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Create Project With History
     * ------------------------------------------------------------------------
     *
     * Verify the trait method creates a project with N months of
     * baseline schedules and progress history records.
     */
    public function test_create_project_with_history_creates_records(): void
    {
        $project = $this->createProjectWithHistory(months: 8);

        $this->assertNotNull($project->id);
        $this->assertCount(8, $project->baselineSchedules);
        $this->assertCount(8, $project->progressHistory);
        $this->assertEquals('Ongoing', $project->status);
    }

    /**
     * ------------------------------------------------------------------------
     * Create Project With Custom Progress
     * ------------------------------------------------------------------------
     *
     * Verify explicit Y values are used correctly.
     */
    public function test_create_project_with_custom_progress(): void
    {
        $yValues = [1.0, 2.0, 4.0, 7.0, 12.0];
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: $yValues
        );

        $this->assertCount(5, $project->progressHistory);

        $firstMonth = $project->progressHistory->where('month_number', 1)->first();
        $this->assertEquals(1.0, (float) $firstMonth->actual_progress_percent);

        $lastMonth = $project->progressHistory->where('month_number', 5)->first();
        $this->assertEquals(12.0, (float) $lastMonth->actual_progress_percent);
    }

    /**
     * ------------------------------------------------------------------------
     * Create Project With Known Dataset
     * ------------------------------------------------------------------------
     *
     * Verify the known dataset matches expected values.
     */
    public function test_create_project_with_known_dataset(): void
    {
        $project = $this->createProjectWithKnownDataset();

        $this->assertCount(10, $project->baselineSchedules);
        $this->assertCount(10, $project->progressHistory);

        $expectedY = [1.00, 2.00, 4.00, 7.00, 12.00, 18.00, 25.00, 33.00, 39.00, 45.00];

        foreach ($project->progressHistory as $record) {
            $monthNum = (int) $record->month_number;
            $this->assertEquals(
                $expectedY[$monthNum - 1],
                (float) $record->actual_progress_percent,
                "Month {$monthNum}: expected {$expectedY[$monthNum - 1]}%, got {$record->actual_progress_percent}%"
            );
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Create Project With Duplicate Inspections
     * ------------------------------------------------------------------------
     *
     * Verify the duplicate-inspection helper creates the expected
     * number of records (some months have multiple inspections).
     */
    public function test_create_project_with_duplicate_inspections(): void
    {
        $project = $this->createProjectWithDuplicateInspections();

        // 4 baselines + 1+3+1+2 = 7 progress records
        $this->assertCount(4, $project->baselineSchedules);
        $this->assertCount(7, $project->progressHistory);
    }

    /**
     * ------------------------------------------------------------------------
     * Create Project With Insufficient Data
     * ------------------------------------------------------------------------
     *
     * Verify the insufficient data helpers work.
     */
    public function test_create_project_with_zero_observations(): void
    {
        $project = $this->createProjectWithInsufficientData(observations: 0);

        $this->assertCount(0, $project->progressHistory);
        $this->assertCount(0, $project->baselineSchedules);
    }

    /**
     * ------------------------------------------------------------------------
     * Create Project With One Observation
     * ------------------------------------------------------------------------
     *
     * Verify single-observation project is created correctly.
     */
    public function test_create_project_with_one_observation(): void
    {
        $project = $this->createProjectWithInsufficientData(observations: 1);

        $this->assertCount(1, $project->progressHistory);
        $this->assertCount(1, $project->baselineSchedules);
    }

    /**
     * ------------------------------------------------------------------------
     * Create Completed Project
     * ------------------------------------------------------------------------
     *
     * Verify completed project is created correctly.
     */
    public function test_create_completed_project(): void
    {
        $project = $this->createCompletedProject();

        $this->assertEquals('Completed', $project->status);
        $this->assertNotNull($project->actual_end_date);
    }

    /**
     * ------------------------------------------------------------------------
     * Create Project Without Baselines
     * ------------------------------------------------------------------------
     *
     * Verify the no-baseline helper creates history but no baselines.
     */
    public function test_create_project_without_baselines(): void
    {
        $project = $this->createProjectWithoutBaselines(months: 5);

        $this->assertCount(5, $project->progressHistory);
        $this->assertCount(0, $project->baselineSchedules);
    }

    /**
     * ------------------------------------------------------------------------
     * Relationships Work
     * ------------------------------------------------------------------------
     *
     * Verify that project relationships load correctly after
     * using the helper trait.
     */
    public function test_project_relationships_are_loaded(): void
    {
        $project = $this->createProjectWithHistory(months: 6);

        $this->assertTrue($project->relationLoaded('baselineSchedules'));
        $this->assertTrue($project->relationLoaded('progressHistory'));
        $this->assertCount(6, $project->baselineSchedules);
        $this->assertCount(6, $project->progressHistory);
    }
}
