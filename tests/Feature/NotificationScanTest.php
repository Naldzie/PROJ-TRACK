<?php

namespace Tests\Feature;

use App\Console\Commands\SendNotifications;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ============================================================================
 * NotificationScanTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates Phase N2 of the notification system (gaps #12):
 *   - `notifications:scan` sends deadline reminders in the right bucket.
 *   - Buckets escalate (30 → 14 → 7 → overdue) and each fires only once.
 *   - Overdue-inspection alerts fire once per (project, month), only for
 *     previous months (not the current month), and not for already-recorded months.
 *   - notification_enabled=false suppresses the whole scan.
 *   - Contractors receive notices only for their own project.
 *
 * Uses Carbon::setTestNow to move the simulated clock (same technique as the
 * DevDateController) — no scheduler/queue dependency.
 *
 * Run With
 * --------
 *   php artisan test --filter=NotificationScanTest
 */
class NotificationScanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private User $contractorUser;
    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Scan Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin']);
        $this->staff = User::create(['name' => 'Scan Staff', 'email' => fake()->unique()->safeEmail(), 'role' => 'staff']);
        $this->contractorUser = User::create(['name' => 'Scan Contractor', 'email' => fake()->unique()->safeEmail(), 'role' => 'contractor']);
        $this->contractor = Contractor::create([
            'user_id' => $this->contractorUser->id,
            'contractor_name' => 'Scan Builders',
            'company_name' => 'Scan Builders Inc',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runScan(): void
    {
        $this->artisan('notifications:scan')->run();
    }

    private function projectWithDeadline(Carbon $endDate): Project
    {
        return Project::factory()->create([
            'status'           => 'Ongoing',
            'contractor_id'    => $this->contractor->id,
            'planned_end_date' => $endDate,
            'assigned_project_manager' => (string) $this->staff->id,
        ]);
    }

    /**
     * Inspection-only project: far-future planned_end_date so the deadline
     * branch of the scan never fires, isolating the inspection assertions.
     */
    private function projectForInspection(): Project
    {
        return Project::factory()->create([
            'status'           => 'Ongoing',
            'contractor_id'    => $this->contractor->id,
            'planned_end_date' => Carbon::now()->addYear(),
            'assigned_project_manager' => (string) $this->staff->id,
        ]);
    }

    // === Deadline buckets ===

    public function test_deadline_within_30_days_fires_30_bucket(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 08:00:00'));
        $this->projectWithDeadline(Carbon::parse('2026-08-20'));

        $this->runScan();

        $this->assertSame(1, $this->admin->notifications()->count());
        $this->assertSame(1, $this->staff->notifications()->count());
        $this->assertSame(1, $this->contractorUser->notifications()->count());

        $data = $this->admin->notifications()->first()->data;
        $this->assertSame('deadline', $this->typeOf($this->admin->notifications()->first()->type));
        $this->assertSame('30', $data['bucket']);
        $this->assertSame(19, $data['days_remaining']);
    }

    public function test_deadline_not_within_any_bucket_is_skipped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 08:00:00'));
        $this->projectWithDeadline(Carbon::parse('2026-12-20'));

        $this->runScan();

        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_deadline_buckets_escalate_and_fire_once_each(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 08:00:00'));
        $this->projectWithDeadline(Carbon::parse('2026-07-20'));

        // 19 days out → within 30 bucket
        $this->runScan();
        // Same day again → deduped (still only 1)
        $this->runScan();

        $this->assertSame(1, $this->admin->notifications()->count());
        $data = $this->admin->notifications()->first()->data;
        $this->assertSame('30', $data['bucket']);

        // 6 days later → 13 days out → within 14 bucket
        Carbon::setTestNow(Carbon::parse('2026-07-07 08:00:00'));
        $this->runScan();

        $buckets = $this->admin->notifications()->get()->map(fn ($n) => $n->data['bucket'])->sort()->values();
        $this->assertEquals(['14', '30'], $buckets->all());

        // Past deadline → overdue bucket
        Carbon::setTestNow(Carbon::parse('2026-07-25 08:00:00'));
        $this->runScan();

        $buckets = $this->admin->notifications()->get()->map(fn ($n) => $n->data['bucket'])->sort()->values();
        $this->assertEquals(['14', '30', 'overdue'], $buckets->all());
    }

    public function test_overdue_deadline_sends_overdue_bucket(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 08:00:00'));
        $this->projectWithDeadline(Carbon::parse('2026-07-25'));

        $this->runScan();

        $this->assertSame(1, $this->admin->notifications()->count());
        $data = $this->admin->notifications()->first()->data;
        $this->assertSame('overdue', $data['bucket']);
    }

    // === Overdue inspections ===

    public function test_overdue_inspection_alert_fires_for_past_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00'));

        $project = $this->projectForInspection();

        ProjectBaselineSchedule::factory()->create([
            'project_id'   => $project->id,
            'month_label'  => '2026-07',
            'planned_date' => '2026-07-31',
        ]);

        $this->runScan();

        $this->assertSame(1, $this->admin->notifications()->count());
        $data = $this->admin->notifications()->first()->data;
        $this->assertSame('overdue_inspection', $this->typeOf($this->admin->notifications()->first()->type));
        $this->assertSame('2026-07', $data['month_label']);
        $this->assertSame('July-2026', $data['month_display']);
        $this->assertSame('2026-07-31', $data['planned_date']);
    }

    public function test_overdue_inspection_fires_immediately_after_planned_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 08:00:00')); // 3 days past 07-31, no grace period

        $project = $this->projectForInspection();
        ProjectBaselineSchedule::factory()->create([
            'project_id'   => $project->id,
            'month_label'  => '2026-07',
            'planned_date' => '2026-07-31',
        ]);

        $this->runScan();

        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_overdue_inspection_skips_recorded_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00'));

        $project = $this->projectForInspection();
        ProjectBaselineSchedule::factory()->create([
            'project_id'   => $project->id,
            'month_label'  => '2026-07',
            'planned_date' => '2026-07-31',
        ]);
        ProjectProgressHistory::factory()->create([
            'project_id'  => $project->id,
            'month_label' => '2026-07',
            'inspection_date' => '2026-07-28',
        ]);

        $this->runScan();

        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_inspection_alert_fires_once_per_project_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00'));

        $project = $this->projectForInspection();
        ProjectBaselineSchedule::factory()->create([
            'project_id'   => $project->id,
            'month_label'  => '2026-07',
            'planned_date' => '2026-07-31',
        ]);

        $this->runScan();
        $this->runScan();

        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_overdue_inspection_alert_fires_for_each_overdue_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00'));

        $project = $this->projectForInspection();
        foreach (['2026-05' => '2026-05-31', '2026-06' => '2026-06-30', '2026-07' => '2026-07-31'] as $label => $planned) {
            ProjectBaselineSchedule::factory()->create([
                'project_id'   => $project->id,
                'month_label'  => $label,
                'planned_date' => $planned,
            ]);
        }

        $this->runScan();

        $this->assertSame(3, $this->admin->notifications()->count());
        $this->assertSame(3, $this->staff->notifications()->count());
        $this->assertSame(0, $this->contractorUser->notifications()->count());

        $rows = $this->admin->notifications()->get()->sortBy(fn ($n) => $n->data['month_label'])->values();

        $this->assertSame('overdue_inspection', $this->typeOf($rows[0]->type));
        $this->assertSame('2026-05', $rows[0]->data['month_label']);
        $this->assertSame('May-2026', $rows[0]->data['month_display']);
        $this->assertSame('2026-05-31', $rows[0]->data['planned_date']);
        $this->assertSame('June-2026', $rows[1]->data['month_display']);
        $this->assertSame('July-2026', $rows[2]->data['month_display']);
    }

    public function test_completed_project_gets_no_inspection_alert(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00'));

        $project = Project::factory()->completed()->create();
        ProjectBaselineSchedule::factory()->create([
            'project_id'   => $project->id,
            'month_label'  => '2026-07',
            'planned_date' => '2026-07-31',
        ]);

        $this->runScan();

        $this->assertSame(0, $this->admin->notifications()->count());
    }

    // === Toggle + isolation ===

    public function test_disabled_notification_scan_is_suppressed(): void
    {
        Setting::setValue('notification_enabled', false);
        Carbon::setTestNow(Carbon::parse('2026-08-01 08:00:00'));
        $this->projectWithDeadline(Carbon::parse('2026-08-20'));

        $this->runScan();

        $this->assertSame(0, $this->admin->notifications()->count());
    }

    public function test_scan_does_not_notify_users_of_other_contractors(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 08:00:00'));

        $otherContractor = User::create(['name' => 'Other', 'email' => fake()->unique()->safeEmail(), 'role' => 'contractor']);
        Contractor::create(['user_id' => $otherContractor->id, 'contractor_name' => 'Other Co', 'company_name' => 'Other Co']);

        $project = $this->projectForInspection();
        ProjectBaselineSchedule::factory()->create([
            'project_id'   => $project->id,
            'month_label'  => '2026-07',
            'planned_date' => '2026-07-31',
        ]);

        $this->runScan();

        // Contractors don't receive inspection notifications (internal PMU concern)
        $this->assertSame(0, $this->contractorUser->notifications()->count());
        $this->assertSame(0, $otherContractor->notifications()->count());
    }

    public function test_current_month_not_duplicate_between_overdue_and_month_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27 08:00:00')); // within 7 days of Aug 31

        $project = $this->projectForInspection();
        ProjectBaselineSchedule::factory()->create([
            'project_id'   => $project->id,
            'month_label'  => '2026-07',
            'planned_date' => '2026-07-31',
        ]);
        ProjectBaselineSchedule::factory()->create([
            'project_id'   => $project->id,
            'month_label'  => '2026-08',
            'planned_date' => '2026-08-01',
        ]);

        $this->runScan();

        $notifications = $this->admin->notifications()->get();
        $augNotifications = $notifications->filter(
            fn ($n) => ($n->data['month_label'] ?? '') === '2026-08'
        );

        $this->assertGreaterThanOrEqual(1, $augNotifications->count());
        $augNotifications->each(function ($n) {
            $this->assertStringContainsString('MonthEndInspectionReminder', $n->type);
        });

        $overdueForAug = $notifications->filter(
            fn ($n) => ($n->data['month_label'] ?? '') === '2026-08'
                && str_contains($n->type, 'OverdueInspectionReminder')
        );
        $this->assertCount(0, $overdueForAug);
    }

    private function typeOf(string $className): string
    {
        return match (true) {
            str_contains($className, 'DeadlineReminder') => 'deadline',
            str_contains($className, 'OverdueInspectionReminder') => 'overdue_inspection',
            str_contains($className, 'PlannedInspectionReminder') => 'planned_inspection',
            str_contains($className, 'MonthEndInspectionReminder') => 'month_end_inspection',
            str_contains($className, 'ExtensionStatusChanged') => 'extension',
            str_contains($className, 'IssueReported') => 'issue',
            default => 'unknown',
        };
    }
}
