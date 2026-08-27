<?php

namespace Tests\Unit\Services\AI;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectExtension;
use App\Models\User;
use App\Services\AI\ContractorPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractorPerformanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContractorPerformanceService $service;
    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ContractorPerformanceService();

        $user = User::create([
            'name' => 'Test User',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'contractor',
            'is_active' => true,
        ]);

        $this->contractor = Contractor::create([
            'user_id' => $user->id,
            'contractor_name' => 'Test Contractors',
            'company_name' => 'Test Co',
        ]);
    }

    public function test_recompute_with_no_completed_projects(): void
    {
        $result = $this->service->recompute($this->contractor);

        $this->assertNull($result['performance_rating']);
        $this->assertNull($result['on_time_delivery_pct']);
        $this->assertEquals(0, $result['completed_projects_count']);
        $this->assertNull($result['avg_delay_days']);
        $this->assertNull($result['last_project_completed_at']);

        $this->contractor->refresh();
        $this->assertNull($this->contractor->performance_rating);
        $this->assertEquals(0, $this->contractor->completed_projects_count);
    }

    public function test_recompute_with_one_on_time_project(): void
    {
        $project = Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(10),
            'actual_end_date' => now()->subDays(10),
        ]);

        $result = $this->service->recompute($this->contractor);

        $this->assertEquals(1, $result['completed_projects_count']);
        $this->assertEquals(100.0, $result['on_time_delivery_pct']);
        $this->assertEquals(0, $result['avg_delay_days']);
        $this->assertNotNull($result['performance_rating']);
    }

    // ========================================================================
    // getPerformanceRating — config-driven tiers
    // ========================================================================

    public function test_rating_tier_5_superior(): void
    {
        $rating = $this->service->getPerformanceRating(95.0, 3.0);
        $this->assertSame(5, $rating);
    }

    public function test_rating_tier_4_good(): void
    {
        $rating = $this->service->getPerformanceRating(85.0, 10.0);
        $this->assertSame(4, $rating);
    }

    public function test_rating_tier_3_satisfactory(): void
    {
        $rating = $this->service->getPerformanceRating(75.0, 20.0);
        $this->assertSame(3, $rating);
    }

    public function test_rating_tier_2_marginal(): void
    {
        $rating = $this->service->getPerformanceRating(60.0, 40.0);
        $this->assertSame(2, $rating);
    }

    public function test_rating_tier_1_poor(): void
    {
        $rating = $this->service->getPerformanceRating(30.0, 80.0);
        $this->assertSame(1, $rating);
    }

    public function test_rating_config_override_changes_result(): void
    {
        // Default: 95/3 → tier 5
        $this->assertSame(5, $this->service->getPerformanceRating(95.0, 3.0));

        // Override: make tier 5 require 98% on-time
        config(['ai.contractor_rating.5.on_time_pct' => 98]);
        $this->assertSame(4, $this->service->getPerformanceRating(95.0, 3.0));
    }

    // ========================================================================
    // getPerformanceSummary
    // ========================================================================

    public function test_performance_summary_structure(): void
    {
        $result = $this->service->getPerformanceSummary($this->contractor);

        $this->assertArrayHasKey('performance_rating', $result);
        $this->assertArrayHasKey('on_time_delivery_pct', $result);
        $this->assertArrayHasKey('avg_delay_days', $result);
        $this->assertArrayHasKey('completed_projects_count', $result);
        $this->assertArrayHasKey('last_project_completed_at', $result);
    }

    // ========================================================================
    // recompute — late project
    // ========================================================================

    public function test_recompute_with_late_project(): void
    {
        $project = Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(30),
            'actual_end_date' => now()->subDays(10),
        ]);

        $result = $this->service->recompute($this->contractor);

        $this->assertEquals(1, $result['completed_projects_count']);
        $this->assertEquals(0.0, $result['on_time_delivery_pct']);
        $this->assertGreaterThan(0, $result['avg_delay_days']);
    }

    public function test_recompute_with_one_late_project(): void
    {
        $project = Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(30),
            'actual_end_date' => now()->subDays(10),
        ]);

        $result = $this->service->recompute($this->contractor);

        $this->assertLessThanOrEqual(2, $result['performance_rating']);
        $this->assertEquals(0, $result['on_time_delivery_pct']);
        $this->assertEquals(1, $result['completed_projects_count']);
        $this->assertGreaterThan(0, $result['avg_delay_days']);
    }

    public function test_recompute_with_mixed_projects(): void
    {
        Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(20),
            'actual_end_date' => now()->subDays(20),
        ]);

        Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(30),
            'actual_end_date' => now()->subDays(10),
        ]);

        $result = $this->service->recompute($this->contractor);

        $this->assertEquals(2, $result['completed_projects_count']);
        $this->assertEquals(50, $result['on_time_delivery_pct']);
    }

    public function test_extension_adjusted_deadline(): void
    {
        $project = Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(30),
            'actual_end_date' => now()->subDays(10),
        ]);

        ProjectExtension::create([
            'project_id' => $project->id,
            'extension_number' => 1,
            'original_end_date' => now()->subDays(30),
            'new_end_date' => now()->subDays(5),
            'approved_new_end_date' => now()->subDays(5),
            'reason' => 'Weather delays',
            'status' => 'approved',
            'duration_days' => 25,
        ]);

        $result = $this->service->recompute($this->contractor);

        $this->assertEquals(100, $result['on_time_delivery_pct']);
        $this->assertEquals(5, $result['performance_rating']);
    }

    public function test_superseded_extension_not_counted(): void
    {
        $project = Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(40),
            'actual_end_date' => now()->subDays(10),
        ]);

        ProjectExtension::create([
            'project_id' => $project->id,
            'extension_number' => 1,
            'original_end_date' => now()->subDays(40),
            'new_end_date' => now()->subDays(20),
            'approved_new_end_date' => now()->subDays(20),
            'reason' => 'First extension',
            'status' => 'superseded',
            'duration_days' => 20,
        ]);

        ProjectExtension::create([
            'project_id' => $project->id,
            'extension_number' => 2,
            'original_end_date' => now()->subDays(20),
            'new_end_date' => now()->subDays(5),
            'approved_new_end_date' => now()->subDays(5),
            'reason' => 'Second extension',
            'status' => 'approved',
            'duration_days' => 15,
        ]);

        $result = $this->service->recompute($this->contractor);

        $this->assertEquals(100, $result['on_time_delivery_pct']);
    }

    public function test_rating_algorithm_boundaries(): void
    {
        $this->assertEquals(5, $this->service->getPerformanceRating(90, 7));
        $this->assertEquals(4, $this->service->getPerformanceRating(80, 15));
        $this->assertEquals(3, $this->service->getPerformanceRating(70, 30));
        $this->assertEquals(2, $this->service->getPerformanceRating(50, 60));
        $this->assertEquals(1, $this->service->getPerformanceRating(49, 61));
    }

    public function test_recompute_saves_to_contractor(): void
    {
        Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(20),
            'actual_end_date' => now()->subDays(20),
        ]);

        $this->service->recompute($this->contractor);
        $this->contractor->refresh();

        $this->assertNotNull($this->contractor->performance_rating);
        $this->assertNotNull($this->contractor->on_time_delivery_pct);
        $this->assertEquals(1, $this->contractor->completed_projects_count);
        $this->assertNotNull($this->contractor->last_project_completed_at);
    }

    public function test_recompute_all_bulk(): void
    {
        Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(20),
            'actual_end_date' => now()->subDays(20),
        ]);

        $this->service->recomputeAll();
        $this->contractor->refresh();

        $this->assertNotNull($this->contractor->performance_rating);
    }

    public function test_get_performance_summary(): void
    {
        $this->contractor->update([
            'performance_rating' => 4,
            'on_time_delivery_pct' => 85.5,
            'completed_projects_count' => 5,
            'avg_delay_days' => 3.2,
        ]);

        $summary = $this->service->getPerformanceSummary($this->contractor);

        $this->assertEquals(4, $summary['performance_rating']);
        $this->assertEquals(85.5, $summary['on_time_delivery_pct']);
        $this->assertEquals(5, $summary['completed_projects_count']);
        $this->assertEquals(3.2, $summary['avg_delay_days']);
    }

    public function test_projects_without_actual_end_date_are_skipped(): void
    {
        Project::factory()->create([
            'contractor_id' => $this->contractor->id,
            'status' => 'Completed',
            'actual_end_date' => null,
            'original_planned_end_date' => now()->subDays(20),
        ]);

        $result = $this->service->recompute($this->contractor);

        $this->assertEquals(0, $result['completed_projects_count']);
    }

    public function test_projects_without_original_planned_end_date_are_skipped(): void
    {
        Project::factory()->create([
            'contractor_id' => $this->contractor->id,
            'status' => 'Completed',
            'actual_end_date' => now()->subDays(10),
            'original_planned_end_date' => null,
        ]);

        $result = $this->service->recompute($this->contractor);

        $this->assertEquals(0, $result['completed_projects_count']);
    }

    public function test_extension_stats_computed_from_approved_extensions(): void
    {
        $project = Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(30),
            'actual_end_date' => now()->subDays(10),
        ]);

        ProjectExtension::create([
            'project_id' => $project->id,
            'extension_number' => 1,
            'original_end_date' => now()->subDays(30),
            'new_end_date' => now()->subDays(20),
            'approved_new_end_date' => now()->subDays(20),
            'reason' => 'Weather',
            'status' => 'approved',
            'duration_days' => 10,
        ]);

        ProjectExtension::create([
            'project_id' => $project->id,
            'extension_number' => 2,
            'original_end_date' => now()->subDays(20),
            'new_end_date' => now()->subDays(5),
            'approved_new_end_date' => now()->subDays(5),
            'reason' => 'Materials',
            'status' => 'approved',
            'duration_days' => 15,
        ]);

        $result = $this->service->recompute($this->contractor);

        $this->assertEquals(2, $result['total_extensions_count']);
        $this->assertEquals(25, $result['total_extension_days']);
    }

    public function test_rejected_extensions_not_counted(): void
    {
        $project = Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(30),
            'actual_end_date' => now()->subDays(10),
        ]);

        ProjectExtension::create([
            'project_id' => $project->id,
            'extension_number' => 1,
            'original_end_date' => now()->subDays(30),
            'new_end_date' => now()->subDays(20),
            'approved_new_end_date' => now()->subDays(20),
            'reason' => 'Weather',
            'status' => 'approved',
            'duration_days' => 10,
        ]);

        ProjectExtension::create([
            'project_id' => $project->id,
            'extension_number' => 2,
            'original_end_date' => now()->subDays(20),
            'new_end_date' => now()->subDays(5),
            'reason' => 'Bad excuse',
            'status' => 'rejected',
            'duration_days' => 15,
        ]);

        $result = $this->service->recompute($this->contractor);

        $this->assertEquals(1, $result['total_extensions_count']);
        $this->assertEquals(10, $result['total_extension_days']);
    }

    public function test_pending_extensions_not_counted(): void
    {
        $project = Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(30),
            'actual_end_date' => now()->subDays(10),
        ]);

        ProjectExtension::create([
            'project_id' => $project->id,
            'extension_number' => 1,
            'original_end_date' => now()->subDays(30),
            'new_end_date' => now()->subDays(20),
            'approved_new_end_date' => now()->subDays(20),
            'reason' => 'Weather',
            'status' => 'approved',
            'duration_days' => 10,
        ]);

        ProjectExtension::create([
            'project_id' => $project->id,
            'extension_number' => 2,
            'original_end_date' => now()->subDays(20),
            'new_end_date' => now()->subDays(5),
            'reason' => 'Pending request',
            'status' => 'pending',
            'duration_days' => 15,
        ]);

        $result = $this->service->recompute($this->contractor);

        $this->assertEquals(1, $result['total_extensions_count']);
        $this->assertEquals(10, $result['total_extension_days']);
    }

    public function test_empty_metrics_return_zero_for_extension_fields(): void
    {
        $result = $this->service->recompute($this->contractor);

        $this->assertEquals(0, $result['total_extensions_count']);
        $this->assertNull($result['total_extension_days']);
    }

    public function test_get_performance_summary_includes_extension_fields(): void
    {
        $this->contractor->update([
            'total_extensions_count' => 5,
            'total_extension_days' => 45.0,
        ]);

        $summary = $this->service->getPerformanceSummary($this->contractor);

        $this->assertEquals(5, $summary['total_extensions_count']);
        $this->assertEquals(45.0, $summary['total_extension_days']);
    }

    /*
    |==========================================================================
    | getPerformanceTrend()
    |==========================================================================
    */

    public function test_trend_empty_when_no_completed_projects(): void
    {
        $trend = $this->service->getPerformanceTrend($this->contractor);

        $this->assertEmpty($trend);
    }

    public function test_trend_empty_when_only_one_completed_project(): void
    {
        Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(10),
            'actual_end_date' => now()->subDays(10),
        ]);

        $trend = $this->service->getPerformanceTrend($this->contractor);

        $this->assertEmpty($trend);
    }

    public function test_trend_returns_two_points_for_two_projects(): void
    {
        Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(30),
            'actual_end_date' => now()->subDays(20),
        ]);
        Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(10),
            'actual_end_date' => now()->subDays(5),
        ]);

        $trend = $this->service->getPerformanceTrend($this->contractor);

        $this->assertCount(2, $trend);
        $this->assertArrayHasKey('date', $trend[0]);
        $this->assertArrayHasKey('on_time_pct', $trend[0]);
        $this->assertArrayHasKey('avg_delay', $trend[0]);
        $this->assertArrayHasKey('count', $trend[0]);
    }

    public function test_trend_running_cumulative_is_accurate(): void
    {
        // Project 1: on-time (completed day 30 ago, planned 30 ago)
        Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(30),
            'actual_end_date' => now()->subDays(30),
        ]);
        // Project 2: 10 days late (completed 5 days ago, planned 15 ago)
        Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(15),
            'actual_end_date' => now()->subDays(5),
        ]);
        // Project 3: on-time (completed 1 day ago, planned 1 ago)
        Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDay(),
            'actual_end_date' => now()->subDay(),
        ]);

        $trend = $this->service->getPerformanceTrend($this->contractor);

        $this->assertCount(3, $trend);

        // After project 1: 1/1 on-time = 100%
        $this->assertEquals(100.0, $trend[0]['on_time_pct']);
        $this->assertEquals(0, $trend[0]['avg_delay']);
        $this->assertEquals(1, $trend[0]['count']);

        // After project 2: 1/2 on-time = 50%, avg delay = 10 days
        $this->assertEquals(50.0, $trend[1]['on_time_pct']);
        $this->assertEquals(10.0, $trend[1]['avg_delay']);
        $this->assertEquals(2, $trend[1]['count']);

        // After project 3: 2/3 on-time = 66.7%, avg delay = 10 days
        $this->assertEquals(66.7, $trend[2]['on_time_pct']);
        $this->assertEquals(10.0, $trend[2]['avg_delay']);
        $this->assertEquals(3, $trend[2]['count']);
    }

    public function test_trend_projects_ordered_by_actual_end_date(): void
    {
        // Create projects out of order — trend should sort by actual_end_date
        Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(10),
            'actual_end_date' => now()->subDays(5),  // second to complete
        ]);
        Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(30),
            'actual_end_date' => now()->subDays(20), // first to complete
        ]);

        $trend = $this->service->getPerformanceTrend($this->contractor);

        $this->assertCount(2, $trend);
        // First point should be the earlier date
        $this->assertLessThan($trend[1]['date'], $trend[0]['date']);
    }

    public function test_trend_includes_extension_adjusted_deadlines(): void
    {
        // First project: on-time baseline
        Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(30),
            'actual_end_date' => now()->subDays(30),
        ]);

        // Second project: would be late without extension, but extension makes it on-time
        $project = Project::factory()->completed()->create([
            'contractor_id' => $this->contractor->id,
            'original_planned_end_date' => now()->subDays(20),
            'actual_end_date' => now()->subDays(10),
        ]);

        ProjectExtension::create([
            'project_id' => $project->id,
            'extension_number' => 1,
            'status' => 'approved',
            'original_end_date' => $project->original_planned_end_date,
            'new_end_date' => now()->subDays(5),
            'approved_new_end_date' => now()->subDays(5),
            'reason' => 'Weather delays',
            'duration_days' => 15,
        ]);

        $trend = $this->service->getPerformanceTrend($this->contractor);

        $this->assertCount(2, $trend);
        // Both on-time (second project has extension), so 100% on-time
        $this->assertEquals(100.0, $trend[1]['on_time_pct']);
        $this->assertEquals(0, $trend[1]['avg_delay']);
    }
}
