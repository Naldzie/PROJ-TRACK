<?php

namespace Tests\Unit\Services;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Services\ProjectHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * ProjectHealthServiceTest
 * ============================================================================
 *
 * Validates the single source of truth for project health:
 *   - classifyGap() boundary ladder (identical to the AI module's thresholds).
 *   - determine() outcomes when a FULL context array is supplied — the list
 *     views pass pre-fetched data, so the service must classify WITHOUT any
 *     database queries (this is what fixes the per-row N+1).
 *   - expectedProgress() effective-baseline lookups (context + time fallback).
 *   - the record-based escalation overlay (worst signal wins).
 *
 * Run With
 * --------
 *   php artisan test --filter=ProjectHealthServiceTest
 */
class ProjectHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProjectHealthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProjectHealthService();
    }

    /*
    |==========================================================================
    | classifyGap — boundary ladder (identical to the AI presenter)
    |==========================================================================
    */

    public function test_classify_gap_boundaries(): void
    {
        $this->assertSame('ahead', $this->service->classifyGap(-6.0));
        $this->assertSame('ahead', $this->service->classifyGap(-5.0));
        $this->assertSame('on_track', $this->service->classifyGap(0.0));
        $this->assertSame('on_track', $this->service->classifyGap(5.0));
        $this->assertSame('low_risk', $this->service->classifyGap(5.01));
        $this->assertSame('low_risk', $this->service->classifyGap(9.99));
        $this->assertSame('medium_risk', $this->service->classifyGap(10.0));
        $this->assertSame('medium_risk', $this->service->classifyGap(14.99));
        $this->assertSame('high_risk', $this->service->classifyGap(15.0));
        $this->assertSame('high_risk', $this->service->classifyGap(50.0));
    }

    /*
    |==========================================================================
    | determine() — with full context (NO database queries)
    |==========================================================================
    */

    private function ongoingProject(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'status'            => 'Ongoing',
            'planned_start_date' => now()->subMonths(2),
            'planned_end_date'  => now()->addMonths(2),
        ], $overrides));
    }

    private function context(array $overrides = []): array
    {
        return array_merge([
            'latest_progress'        => new ProjectProgressHistory(['actual_progress_percent' => 50]),
            'current_month_history'  => new ProjectProgressHistory(['planned_progress_percent' => 50]),
            'current_month_baseline' => null,
            'baseline_schedules'     => collect(),
            'days_elapsed'           => 60,
            'days_total'             => 120,
            'open_high_issues'       => false,
            'aging_issue_count'      => 0,
            'open_high_risks'        => false,
            'pending_extension'      => false,
        ], $overrides);
    }

    public function test_determine_on_track_when_gap_within_tolerance(): void
    {
        $project = $this->ongoingProject();

        $result = $this->service->determine($project, $this->context());

        $this->assertSame('on_track', $result['level']);
        $this->assertSame('ON TRACK', $result['label']);
        $this->assertSame('on_track', $result['risk']);
        $this->assertSame('risk-low', $result['risk_class']);
        $this->assertSame('fill-healthy', $result['fill_class']);
        $this->assertSame('var(--green-600)', $result['dot_color']);
        $this->assertFalse($result['is_overdue']);
        $this->assertFalse($result['is_at_risk']);
    }

    public function test_determine_at_risk_moderate(): void
    {
        $project = $this->ongoingProject();

        // expected 55, actual 50 → gap 5.01 → Moderate
        $result = $this->service->determine($project, $this->context([
            'current_month_history' => new ProjectProgressHistory(['planned_progress_percent' => 55.01]),
        ]));

        $this->assertSame('at_risk', $result['level']);
        $this->assertSame('AT RISK', $result['label']);
        $this->assertSame('low_risk', $result['risk']);
        $this->assertSame('risk-medium', $result['risk_class']);
        $this->assertSame('fill-warning', $result['fill_class']);
        $this->assertSame('var(--amber-600)', $result['dot_color']);
        $this->assertTrue($result['is_at_risk']);
        $this->assertStringContainsString('Behind plan by', $result['tooltip']);
    }

    public function test_determine_at_risk_high(): void
    {
        $project = $this->ongoingProject();

        // expected 70, actual 50 → gap 20 → high_risk
        $result = $this->service->determine($project, $this->context([
            'current_month_history' => new ProjectProgressHistory(['planned_progress_percent' => 70]),
        ]));

        $this->assertSame('at_risk', $result['level']);
        $this->assertSame('high_risk', $result['risk']);
        $this->assertSame('risk-high', $result['risk_class']);
        $this->assertSame('var(--red-600)', $result['dot_color']);
    }

    public function test_determine_at_risk_critical(): void
    {
        $project = $this->ongoingProject();

        // expected 80, actual 50 → gap 30 → high_risk (>=15)
        $result = $this->service->determine($project, $this->context([
            'current_month_history' => new ProjectProgressHistory(['planned_progress_percent' => 80]),
        ]));

        $this->assertSame('at_risk', $result['level']);
        $this->assertSame('high_risk', $result['risk']);
        $this->assertSame('risk-high', $result['risk_class']);
    }

    public function test_determine_overdue(): void
    {
        $project = $this->ongoingProject([
            'planned_end_date' => now()->subDays(5),
        ]);

        $result = $this->service->determine($project, $this->context());

        $this->assertSame('overdue', $result['level']);
        $this->assertSame('OVERDUE', $result['label']);
        $this->assertSame('fill-danger', $result['fill_class']);
        $this->assertSame('risk-high', $result['risk_class']);
        $this->assertTrue($result['is_overdue']);
        $this->assertFalse($result['is_at_risk']);
        $this->assertSame('5d overdue', $result['tooltip']);
    }

    public function test_determine_overdue_takes_priority_over_gap(): void
    {
        $project = $this->ongoingProject([
            'planned_end_date' => now()->subDay(),
        ]);

        // Even an ahead-of-plan project is OVERDUE once past its deadline.
        $result = $this->service->determine($project, $this->context([
            'current_month_history' => new ProjectProgressHistory(['planned_progress_percent' => 30]),
        ]));

        $this->assertSame('overdue', $result['level']);
    }

    public function test_determine_no_data_when_no_progress_records(): void
    {
        $project = $this->ongoingProject();

        $result = $this->service->determine($project, $this->context([
            'latest_progress' => null,
        ]));

        $this->assertSame('no_data', $result['level']);
        $this->assertSame('NO DATA', $result['label']);
        $this->assertSame('risk-neutral', $result['risk_class']);
        $this->assertSame('var(--gray-400)', $result['dot_color']);
    }

    public function test_determine_neutral_for_non_active_statuses(): void
    {
        foreach (['Planned', 'Suspended', 'Cancelled'] as $status) {
            $project = $this->ongoingProject(['status' => $status]);

            $result = $this->service->determine($project, $this->context());

            $this->assertSame('neutral', $result['level'], "status: {$status}");
            $this->assertSame(strtoupper($status), $result['label']);
            $this->assertSame('risk-neutral', $result['risk_class']);
        }
    }

    public function test_determine_completed(): void
    {
        $project = $this->ongoingProject(['status' => 'Completed']);

        $result = $this->service->determine($project, $this->context());

        $this->assertSame('completed', $result['level']);
        $this->assertSame('COMPLETED', $result['label']);
    }

    public function test_determine_escalates_on_track_project_with_open_high_issue(): void
    {
        config(['ai.health.escalation_enabled' => true]);
        $project = $this->ongoingProject();

        $result = $this->service->determine($project, $this->context([
            'open_high_issues' => true,
        ]));

        $this->assertSame('at_risk', $result['level']);
        $this->assertSame('AT RISK', $result['label']);
        $this->assertTrue($result['escalated']);
        $this->assertSame('Open High/Critical issue', $result['escalation_reason']);
        $this->assertSame('Open High/Critical issue', $result['tooltip']);
        $this->assertSame('risk-high', $result['risk_class']);
    }

    public function test_determine_escalates_for_pending_extension(): void
    {
        config(['ai.health.escalation_enabled' => true]);
        $project = $this->ongoingProject();

        $result = $this->service->determine($project, $this->context([
            'pending_extension' => true,
        ]));

        $this->assertSame('at_risk', $result['level']);
        $this->assertSame('Pending extension request', $result['escalation_reason']);
    }

    public function test_determine_escalates_for_aging_issue(): void
    {
        config(['ai.health.escalation_enabled' => true]);
        $project = $this->ongoingProject();

        $result = $this->service->determine($project, $this->context([
            'aging_issue_count' => 2,
        ]));

        $this->assertSame('at_risk', $result['level']);
        $this->assertSame('Unresolved issue aging > 30 days', $result['escalation_reason']);
    }

    public function test_determine_escalates_for_open_high_risk(): void
    {
        config(['ai.health.escalation_enabled' => true]);
        $project = $this->ongoingProject();

        $result = $this->service->determine($project, $this->context([
            'open_high_risks' => true,
        ]));

        $this->assertSame('at_risk', $result['level']);
        $this->assertSame('Open high-priority risk', $result['escalation_reason']);
    }

    public function test_determine_does_not_escalate_when_already_at_risk_by_gap(): void
    {
        config(['ai.health.escalation_enabled' => true]);
        $project = $this->ongoingProject();

        $result = $this->service->determine($project, $this->context([
            'current_month_history' => new ProjectProgressHistory(['planned_progress_percent' => 60]),
            'open_high_issues'      => true,
        ]));

        $this->assertSame('at_risk', $result['level']);
        // Gap-driven risk wins — not flagged as "escalated" (it was never on track).
        $this->assertFalse($result['escalated']);
        $this->assertSame('medium_risk', $result['risk']);
    }

    public function test_determine_does_not_escalate_when_toggle_off(): void
    {
        config(['ai.health.escalation_enabled' => false]);
        $project = $this->ongoingProject();

        $result = $this->service->determine($project, $this->context([
            'open_high_issues' => true,
        ]));

        $this->assertSame('on_track', $result['level']);
        $this->assertSame('ON TRACK', $result['label']);
        $this->assertFalse($result['escalated']);
        $this->assertNull($result['escalation_reason']);
    }

    /*
    |==========================================================================
    | expectedProgress — effective baseline lookups
    |==========================================================================
    */

    public function test_expected_progress_uses_current_month_history_planned(): void
    {
        $project = $this->ongoingProject();

        $expected = $this->service->expectedProgress($project, $this->context([
            'current_month_history' => new ProjectProgressHistory(['planned_progress_percent' => 42]),
        ]));

        $this->assertSame(42.0, $expected);
    }

    public function test_expected_progress_falls_back_to_baseline_collection(): void
    {
        $project = $this->ongoingProject();

        $schedules = collect([
            new ProjectBaselineSchedule(['planned_date' => now()->subMonths(2), 'planned_progress_percent' => 10]),
            new ProjectBaselineSchedule(['planned_date' => now()->subMonths(1), 'planned_progress_percent' => 30]),
            new ProjectBaselineSchedule(['planned_date' => now()->addMonth(), 'planned_progress_percent' => 70]),
        ]);

        $expected = $this->service->expectedProgress($project, $this->context([
            'current_month_history'  => null,
            'current_month_baseline' => null,
            'latest_progress'        => null,
            'baseline_schedules'     => $schedules,
        ]));

        // Last baseline with planned_date ≤ today → 30.
        $this->assertSame(30.0, $expected);
    }

    public function test_expected_progress_time_based_fallback(): void
    {
        $project = $this->ongoingProject();

        $expected = $this->service->expectedProgress($project, $this->context([
            'current_month_history'  => null,
            'current_month_baseline' => null,
            'latest_progress'        => null,
            'baseline_schedules'     => collect(),
            'days_elapsed'           => 25,
            'days_total'             => 100,
        ]));

        $this->assertSame(25.0, $expected);
    }

    public function test_expected_progress_zero_when_no_dates(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $expected = $this->service->expectedProgress($project, $this->context([
            'current_month_history'  => null,
            'current_month_baseline' => null,
            'latest_progress'        => null,
            'baseline_schedules'     => collect(),
            'days_elapsed'           => 10,
            'days_total'             => 0,
        ]));

        $this->assertSame(0.0, $expected);
    }
}