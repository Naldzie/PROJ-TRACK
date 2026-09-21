<?php

namespace Tests\Feature;

use App\Http\Controllers\BaselineScheduleController;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectActivityCarryover;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectExtension;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ============================================================================
 * AdminOriginalBaselineEditTest
 * ============================================================================
 *
 * The "Edit Original Baseline" mode (admin only) re-opens the Set Up Baseline
 * modal to CORRECT the locked original plan:
 *   - GET  .../baseline-schedules/original       → returns ONLY original-baseline
 *     months (original_planned_progress_percent AND original_planned_date NOT NULL).
 *   - POST .../baseline-schedules/original/save  → bulk-save that updates BOTH the
 *     live and frozen columns of original months, adds/removes original months,
 *     and preserves activity identity. Extension-only months are untouched.
 *
 * Run With
 * --------
 *   php artisan test --filter=AdminOriginalBaselineEditTest
 */
class AdminOriginalBaselineEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'      => 'Original Edit Admin',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->staff = User::create([
            'name'      => 'Original Edit Staff',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'staff',
            'is_active' => true,
        ]);
    }

    // ──── Helpers ───────────────────────────────────────────────────────────

    private function projectBetween(string $start, string $end): Project
    {
        return Project::factory()->create([
            'status'                      => 'Ongoing',
            'planned_start_date'          => $start,
            'planned_end_date'            => $end,
            'original_planned_start_date' => $start,
            'original_planned_end_date'   => $end,
            'extension_count'             => 0,
        ]);
    }

    private function originalRow(Project $project, string $month, string $date, float $percent): ProjectBaselineSchedule
    {
        return ProjectBaselineSchedule::factory()->create([
            'project_id'                      => $project->id,
            'month_label'                     => $month,
            'planned_date'                    => $date,
            'planned_progress_percent'        => $percent,
            'original_planned_progress_percent' => $percent,
            'original_planned_date'           => $date,
            'description'                     => '',
        ]);
    }

    private function extensionRow(Project $project, string $month, string $date, float $percent): ProjectBaselineSchedule
    {
        return ProjectBaselineSchedule::factory()->create([
            'project_id'                      => $project->id,
            'month_label'                     => $month,
            'planned_date'                    => $date,
            'planned_progress_percent'        => $percent,
            'original_planned_progress_percent' => null,
            'original_planned_date'           => null,
        ]);
    }

    private function activity(Project $project, ProjectBaselineSchedule $schedule, string $name, bool $completed = false): ProjectActivity
    {
        return ProjectActivity::create([
            'project_id'           => $project->id,
            'baseline_schedule_id' => $schedule->id,
            'month_label'          => $schedule->month_label,
            'planned_date'         => $schedule->planned_date,
            'activity_name'        => $name,
            'description'          => '',
            'is_completed'         => $completed,
        ]);
    }

    private function recordProgress(Project $project, ProjectBaselineSchedule $schedule): void
    {
        ProjectProgressHistory::create([
            'project_id'               => $project->id,
            'baseline_schedule_id'     => $schedule->id,
            'inspection_date'          => Carbon::parse($schedule->month_label.'-01')->format('Y-m-d'),
            'month_label'              => $schedule->month_label,
            'progress_percentage'      => 1.0,
            'actual_progress_percent'  => 1.0,
            'planned_progress_percent' => $schedule->planned_progress_percent,
        ]);
    }

    private function getOriginal(Project $project): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->getJson(
            route('admin.projects.baseline-schedules.original', $project)
        );
    }

    private function saveOriginal(Project $project, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.original.save', $project),
            $payload
        );
    }

    private function monthPayload(ProjectBaselineSchedule $schedule, float $percent, array $activities = []): array
    {
        return [
            'id'                        => $schedule->id,
            'month_label'               => $schedule->month_label,
            'planned_date'              => $schedule->planned_date->format('Y-m-d'),
            'planned_progress_percent'  => $percent,
            'description'               => null,
            'activities'                => $activities,
        ];
    }

    // ──── GET /original ─────────────────────────────────────────────────────

    public function test_get_original_returns_only_original_baseline_months(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $this->originalRow($project, '2026-02', '2026-02-15', 100);
        // Extension-only rows must be excluded
        $this->extensionRow($project, '2026-03', '2026-03-15', 100);

        $this->activity($project, $m1, 'Site clearing');

        $response = $this->getOriginal($project)->assertOk()->assertJson(['success' => true]);

        $months = $response->json('months');
        $this->assertCount(2, $months);
        $this->assertSame('2026-01', $months[0]['month_label']);
        $this->assertSame('2026-02', $months[1]['month_label']);
        $this->assertSame(3, $project->baselineSchedules()->count());
        $this->assertFalse($months[0]['has_actual_progress']);
        $this->assertCount(1, $months[0]['activities']);
        $this->assertSame('Site clearing', $months[0]['activities'][0]['activity_name']);
        $this->assertArrayHasKey('id', $months[0]['activities'][0]);
        $this->assertSame('2026-01-15', $months[0]['original_planned_date']);
    }

    public function test_get_original_marks_progressed_month(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $this->recordProgress($project, $m1);

        $response = $this->getOriginal($project)->assertOk();

        $this->assertTrue($response->json('months.0.has_actual_progress'));
    }

    public function test_get_original_returns_frozen_original_values_not_live_adjusted(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        // Original plan was 40% / 100%...
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);
        // ...but an adjusted baseline later redistributed the live curve
        $m1->update(['planned_progress_percent' => 55, 'planned_date' => '2026-01-20']);
        $m2->update(['planned_progress_percent' => 100, 'planned_date' => '2026-03-10']);

        $response = $this->getOriginal($project)->assertOk()->assertJson(['success' => true]);

        $months = $response->json('months');
        // The modal seeds from original_planned_progress_percent / original_planned_date,
        // so the frozen original plan must come back intact despite live drift.
        $this->assertSame('40.00', $months[0]['original_planned_progress_percent']);
        $this->assertSame('2026-01-15', $months[0]['original_planned_date']);
        $this->assertSame('55.00', $months[0]['planned_progress_percent']);
        $this->assertSame('2026-01-20', $months[0]['planned_date']);
        $this->assertSame('100.00', $months[1]['original_planned_progress_percent']);
        $this->assertSame('2026-02-15', $months[1]['original_planned_date']);
    }

    // ──── POST /original/save ───────────────────────────────────────────────

    public function test_admin_edit_updates_both_live_and_frozen_columns(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);

        $this->saveOriginal($project, [
            'months' => [
                $this->monthPayload($m1, 30),
                $this->monthPayload($m2, 100),
            ],
        ])->assertOk();

        $this->assertDatabaseHas('project_baseline_schedules', [
            'id' => $m1->id,
            'planned_progress_percent'        => 30.0,
            'original_planned_progress_percent' => 30.0,
            'planned_date'                    => '2026-01-15 00:00:00',
            'original_planned_date'           => '2026-01-15 00:00:00',
        ]);
        $this->assertDatabaseHas('project_baseline_schedules', [
            'id' => $m2->id,
            'planned_progress_percent'        => 100.0,
            'original_planned_progress_percent' => 100.0,
        ]);
    }

    /**
     * A saved revision notifies the other admins + the assigned staff in-app
     * (never the editor); a no-change save notifies nobody (restudy F-11, 2026-09-21).
     */
    public function test_revising_the_original_baseline_sends_an_in_app_notice(): void
    {
        $otherAdmin = User::create(['name' => 'Other Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $project->update(['assigned_project_manager' => (string) $this->staff->id]);
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);

        // Nothing changed → no notice
        $this->saveOriginal($project, ['months' => [$this->monthPayload($m1, 40), $this->monthPayload($m2, 100)]])->assertOk();
        $this->assertSame(0, $this->staff->notifications()->count());

        $this->saveOriginal($project, ['months' => [$this->monthPayload($m1, 30), $this->monthPayload($m2, 100)]])->assertOk();

        $type = \App\Notifications\ProjectUpdateNotice::class;
        $this->assertSame(1, $this->staff->notifications()->where('type', $type)->count());
        $this->assertSame(1, $otherAdmin->notifications()->where('type', $type)->count());
        $this->assertSame(0, $this->admin->notifications()->count(), 'the editor is not told');
        $this->assertSame(\App\Notifications\ProjectUpdateNotice::BASELINE_REVISED, $this->staff->notifications()->first()->data['kind']);
    }

    public function test_admin_can_add_new_original_month_with_activities(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);

        $this->saveOriginal($project, [
            'months' => [
                $this->monthPayload($m1, 40),
                [
                    'id'                        => null,
                    'month_label'               => '2026-02',
                    'planned_date'              => '2026-02-15',
                    'planned_progress_percent'  => 100,
                    'description'               => 'added by admin',
                    'activities'                => [
                        ['id' => null, 'activity_name' => 'Finishing works', 'description' => ''],
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('project_baseline_schedules', [
            'project_id'                      => $project->id,
            'month_label'                     => '2026-02',
            'planned_progress_percent'        => 100.0,
            'original_planned_progress_percent' => 100.0,
            'original_planned_date'           => '2026-02-15 00:00:00',
        ]);
        $this->assertDatabaseHas('project_activities', [
            'project_id'           => $project->id,
            'month_label'          => '2026-02',
            'activity_name'        => 'Finishing works',
            'is_completed'         => false,
        ]);
    }

    public function test_admin_can_remove_original_month_with_cascade(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);
        $act = $this->activity($project, $m1, 'To be removed');

        $this->saveOriginal($project, [
            'months'      => [$this->monthPayload($m2, 100)],
            'removed_ids' => [$m1->id],
        ])->assertOk();

        $this->assertNull(ProjectBaselineSchedule::find($m1->id));
        $this->assertNull(ProjectActivity::find($act->id));
        $this->assertSame(1, ProjectBaselineSchedule::where('project_id', $project->id)->count());
    }

    public function test_removing_progressed_original_month_is_rejected(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);
        $this->recordProgress($project, $m1);

        $this->saveOriginal($project, [
            'months'      => [$this->monthPayload($m2, 100)],
            'removed_ids' => [$m1->id],
        ])->assertStatus(422);

        $this->assertNotNull(ProjectBaselineSchedule::find($m1->id));
    }

    public function test_editing_progressed_original_month_is_skipped(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);
        $this->recordProgress($project, $m1);

        $this->saveOriginal($project, [
            'months' => [
                $this->monthPayload($m1, 10),
                $this->monthPayload($m2, 100),
            ],
        ])->assertOk();

        $this->assertDatabaseHas('project_baseline_schedules', [
            'id'                            => $m1->id,
            'planned_progress_percent'      => 40.0,
            'original_planned_progress_percent' => 40.0,
        ]);
    }

    public function test_save_preserves_activity_identity_and_completion(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);

        $act = $this->activity($project, $m1, 'Site clearing', true);

        $this->saveOriginal($project, [
            'months' => [
                $this->monthPayload($m1, 45, [
                    ['id' => $act->id, 'activity_name' => 'Site clearing', 'description' => ''],
                ]),
                $this->monthPayload($m2, 100),
            ],
        ])->assertOk();

        $fresh = $act->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($act->id, $fresh->id);
        $this->assertTrue($fresh->is_completed);
    }

    public function test_save_preserves_carried_over_activity_and_its_log(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);

        $act = $this->activity($project, $m1, 'Survey');
        ProjectActivityCarryover::create([
            'activity_id'               => $act->id,
            'project_id'                => $project->id,
            'from_baseline_schedule_id' => $m1->id,
            'from_month_label'          => '2026-01',
            'to_baseline_schedule_id'   => $m2->id,
            'to_month_label'            => '2026-02',
            'carryover_number'          => 1,
            'carried_over_by'           => $this->admin->id,
        ]);
        $act->update([
            'baseline_schedule_id' => $m2->id,
            'month_label'          => '2026-02',
        ]);

        $this->saveOriginal($project, [
            'months' => [
                $this->monthPayload($m1, 45),
                $this->monthPayload($m2, 100, [
                    ['id' => $act->id, 'activity_name' => 'Survey', 'description' => ''],
                ]),
            ],
        ])->assertOk();

        $this->assertDatabaseHas('project_activities', [
            'id'          => $act->id,
            'month_label' => '2026-02',
        ]);
        $this->assertDatabaseHas('project_activity_carryovers', [
            'activity_id' => $act->id,
        ]);
        $this->assertSame(1, ProjectActivityCarryover::where('activity_id', $act->id)->count());
    }

    public function test_save_does_not_touch_extension_only_months(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-08-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);
        $ext = $this->extensionRow($project, '2026-03', '2026-03-15', 100);

        $this->saveOriginal($project, [
            'months' => [
                $this->monthPayload($m1, 30),
                $this->monthPayload($m2, 100),
            ],
        ])->assertOk();

        $this->assertDatabaseHas('project_baseline_schedules', [
            'id'                            => $ext->id,
            'planned_progress_percent'      => 100.0,
            'original_planned_progress_percent' => null,
            'original_planned_date'         => null,
        ]);
    }

    public function test_edit_preserves_live_curve_of_diverged_month(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-08-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);
        // Simulate an adjusted baseline that redistributed the live curve away
        // from the frozen original (dates AND percents drift).
        $m1->update(['planned_progress_percent' => 20.0, 'planned_date' => '2026-01-20']);
        $m2->update(['planned_progress_percent' => 100.0, 'planned_date' => '2026-03-10']);

        // Admin corrects only the frozen original of m1 (from 40 to 30).
        $this->saveOriginal($project, [
            'months' => [
                [
                    'id'                        => $m1->id,
                    'month_label'               => '2026-01',
                    'planned_date'              => $m1->original_planned_date->format('Y-m-d'),
                    'planned_progress_percent'  => 30,
                    'description'               => null,
                    'activities'                => [],
                ],
                [
                    'id'                        => $m2->id,
                    'month_label'               => '2026-02',
                    'planned_date'              => $m2->original_planned_date->format('Y-m-d'),
                    'planned_progress_percent'  => 100,
                    'description'               => null,
                    'activities'                => [],
                ],
            ],
        ])->assertOk();

        // Frozen original corrected...
        $this->assertDatabaseHas('project_baseline_schedules', [
            'id'                            => $m1->id,
            'original_planned_progress_percent' => 30.0,
            'original_planned_date'         => '2026-01-15 00:00:00',
        ]);
        // ...but the live/adjusted curve is untouched.
        $this->assertDatabaseHas('project_baseline_schedules', [
            'id'                       => $m1->id,
            'planned_progress_percent' => 20.0,
            'planned_date'             => '2026-01-20 00:00:00',
        ]);
        $this->assertDatabaseHas('project_baseline_schedules', [
            'id'                       => $m2->id,
            'original_planned_progress_percent' => 100.0,
            'original_planned_date'    => '2026-02-15 00:00:00',
            'planned_progress_percent' => 100.0,
            'planned_date'             => '2026-03-10 00:00:00',
        ]);
    }

    // ──── Validation ────────────────────────────────────────────────────────

    public function test_non_increasing_original_sequence_is_rejected(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);

        $this->saveOriginal($project, [
            'months' => [
                $this->monthPayload($m1, 60),
                $this->monthPayload($m2, 50),
            ],
        ])->assertStatus(422);

        $this->assertDatabaseHas('project_baseline_schedules', [
            'id'                       => $m1->id,
            'planned_progress_percent' => 40.0,
        ]);
    }

    public function test_original_sequence_not_reaching_100_is_rejected(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 60);

        $this->saveOriginal($project, [
            'months' => [
                $this->monthPayload($m1, 40),
                $this->monthPayload($m2, 60),
            ],
        ])->assertStatus(422);

        $this->assertDatabaseHas('project_baseline_schedules', [
            'id'                       => $m2->id,
            'planned_progress_percent' => 60.0,
        ]);
    }

    public function test_date_outside_project_timeline_is_rejected(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);

        $this->saveOriginal($project, [
            'months' => [
                $this->monthPayload($m1, 40),
                [
                    'id'                        => $m2->id,
                    'month_label'               => '2026-02',
                    'planned_date'              => '2026-11-05',
                    'planned_progress_percent'  => 100,
                    'description'               => null,
                    'activities'                => [],
                ],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseHas('project_baseline_schedules', [
            'id'                       => $m2->id,
            'planned_date'             => '2026-02-15 00:00:00',
        ]);
    }

    public function test_edit_attempt_on_extension_month_is_rejected(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-08-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $ext = $this->extensionRow($project, '2026-03', '2026-03-15', 100);

        $this->saveOriginal($project, [
            'months' => [
                $this->monthPayload($m1, 40),
                [
                    'id'                        => $ext->id,
                    'month_label'               => '2026-03',
                    'planned_date'              => '2026-03-15',
                    'planned_progress_percent'  => 100,
                    'description'               => null,
                    'activities'                => [],
                ],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseHas('project_baseline_schedules', [
            'id'                       => $ext->id,
            'planned_progress_percent' => 100.0,
        ]);
    }

    // ──── Access control ────────────────────────────────────────────────────

    public function test_staff_cannot_fetch_original_baseline(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $this->originalRow($project, '2026-01', '2026-01-15', 100);

        $this->actingAs($this->staff);
        $response = app(BaselineScheduleController::class)->getOriginalBaselineMonths($project);

        $this->assertFalse($response->getData()->success);
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_staff_cannot_save_edited_original_baseline(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 100);

        $this->actingAs($this->staff);
        $request = new Request(['months' => [$this->monthPayload($m1, 100)]]);
        $response = app(BaselineScheduleController::class)->saveEditedOriginalBaseline($request, $project);

        $this->assertFalse($response->getData()->success);
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_initial_setup_still_rejects_rerun_when_baseline_exists(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $this->originalRow($project, '2026-01', '2026-01-15', 100);

        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.setup', $project),
            ['months' => [
                ['month_label' => '2026-01', 'planned_date' => '2026-01-15', 'planned_progress_percent' => 100, 'description' => null],
            ]]
        );

        $response->assertStatus(422);
        $this->assertSame(1, ProjectBaselineSchedule::where('project_id', $project->id)->count());
    }

    public function test_initial_setup_freezes_both_original_columns_so_edit_original_finds_them(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');

        $this->actingAs($this->admin)->postJson(
            route('admin.projects.baseline-schedules.setup', $project),
            ['months' => [
                ['month_label' => '2026-01', 'planned_date' => '2026-01-15', 'planned_progress_percent' => 40, 'description' => null],
                ['month_label' => '2026-02', 'planned_date' => '2026-02-15', 'planned_progress_percent' => 100, 'description' => null],
            ]]
        )->assertOk();

        // Both frozen columns must be written for every setup row...
        $this->assertDatabaseHas('project_baseline_schedules', [
            'project_id'                      => $project->id,
            'month_label'                     => '2026-01',
            'original_planned_progress_percent' => 40.0,
            'original_planned_date'           => '2026-01-15 00:00:00',
        ]);
        $this->assertDatabaseHas('project_baseline_schedules', [
            'project_id'                      => $project->id,
            'month_label'                     => '2026-02',
            'original_planned_progress_percent' => 100.0,
            'original_planned_date'           => '2026-02-15 00:00:00',
        ]);

        // ...so the Edit Original Baseline modal shows them right away.
        $response = $this->getOriginal($project)->assertOk();
        $this->assertCount(2, $response->json('months'));
    }

    public function test_save_logs_edit_original_baseline_activity(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);

        $this->saveOriginal($project, [
            'months' => [
                $this->monthPayload($m1, 30),
                $this->monthPayload($m2, 100),
            ],
        ])->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id'    => $this->admin->id,
            'project_id' => $project->id,
            'action'     => 'Edit Original Baseline',
            'visibility' => 'all',
        ]);
    }

    public function test_save_with_no_changes_is_a_noop(): void
    {
        $project = $this->projectBetween('2026-01-15', '2026-06-15');
        $m1 = $this->originalRow($project, '2026-01', '2026-01-15', 40);
        $m2 = $this->originalRow($project, '2026-02', '2026-02-15', 100);
        $extension = ProjectExtension::factory()->approved()->create([
            'project_id'        => $project->id,
            'extension_number'  => 1,
            'baseline_snapshot' => 'sentinel-value',
        ]);

        $response = $this->saveOriginal($project, [
            'months' => [
                $this->monthPayload($m1, 40),
                $this->monthPayload($m2, 100),
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'No changes detected. Nothing was saved.',
            ]);

        // No DB writes, no ActivityLog, no extension snapshot rewrite.
        $this->assertDatabaseMissing('activity_logs', [
            'project_id' => $project->id,
            'action'     => 'Edit Original Baseline',
        ]);
        $this->assertSame('sentinel-value', $extension->fresh()->baseline_snapshot);
        $this->assertSame(2, ProjectBaselineSchedule::where('project_id', $project->id)->count());
    }
}
