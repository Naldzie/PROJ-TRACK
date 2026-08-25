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

        $this->assertEquals(5, $result['performance_rating']);
        $this->assertEquals(100, $result['on_time_delivery_pct']);
        $this->assertEquals(1, $result['completed_projects_count']);
        $this->assertEquals(0, $result['avg_delay_days']);
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
}
