<?php

namespace Tests\Feature\Services\AI;

use App\Models\Contractor;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectExtension;
use App\Models\User;
use App\Services\AI\ContractorPerformanceService;
use App\Services\AI\RecommendationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractorPerformanceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_recommendation_engine_uses_contractor_performance(): void
    {
        $this->contractor->update([
            'performance_rating' => 1,
            'on_time_delivery_pct' => 20,
            'avg_delay_days' => 45,
        ]);

        $project = Project::factory()->create([
            'contractor_id' => $this->contractor->id,
            'status' => 'Ongoing',
            'planned_start_date' => now()->subMonths(3),
            'planned_end_date' => now()->addMonth(),
            'extension_count' => 2,
        ]);

        ProjectBaselineSchedule::create([
            'project_id' => $project->id,
            'planned_date' => now()->subMonths(2),
            'planned_progress_percent' => 25,
            'month_label' => now()->subMonths(2)->format('Y-m'),
        ]);
        ProjectBaselineSchedule::create([
            'project_id' => $project->id,
            'planned_date' => now()->subMonth(),
            'planned_progress_percent' => 50,
            'month_label' => now()->subMonth()->format('Y-m'),
        ]);
        ProjectBaselineSchedule::create([
            'project_id' => $project->id,
            'planned_date' => now(),
            'planned_progress_percent' => 75,
            'month_label' => now()->format('Y-m'),
        ]);

        Issue::create([
            'project_id' => $project->id,
            'issue_name' => 'Material delay',
            'description' => 'Material delivery delayed',
            'issue_type' => 'material',
            'severity' => 'High',
            'status' => 'Open',
        ]);
        Issue::create([
            'project_id' => $project->id,
            'issue_name' => 'Contractor performance issue',
            'description' => 'Contractor behind schedule',
            'issue_type' => 'contractor',
            'severity' => 'Medium',
            'status' => 'Open',
        ]);

        $prediction = [
            'assessment' => [
                ['risk_level' => 'Moderate', 'variance' => -5],
                ['risk_level' => 'Moderate', 'variance' => -8],
            ],
            'regression' => ['observation_count' => 5, 'fit_quality' => 'Good'],
            'current_state' => ['progress' => 40],
            'contractor_performance_rating' => 1,
            'contractor_on_time_pct' => 20,
            'contractor_avg_delay' => 45,
        ];

        $engine = new RecommendationEngine();
        $recommendations = $engine->generate($project, $prediction);

        $contractorRec = collect($recommendations)->filter(fn($r) => str_contains($r['message'], 'Contractor has a history of delays'))->first();
        $this->assertNotNull($contractorRec, 'Should generate contractor performance recommendation. Got: ' . collect($recommendations)->pluck('message')->implode(' | '));
        $this->assertEquals('corrective', $contractorRec['category']);
    }

    public function test_recommendation_engine_skips_when_no_open_issues(): void
    {
        $this->contractor->update([
            'performance_rating' => 2,
            'on_time_delivery_pct' => 45,
            'avg_delay_days' => 30,
        ]);

        $project = Project::factory()->create([
            'contractor_id' => $this->contractor->id,
            'status' => 'Ongoing',
            'planned_end_date' => now()->addMonth(),
        ]);

        $prediction = [
            'assessment' => [
                ['risk_level' => 'Low', 'variance' => -2],
                ['risk_level' => 'Low', 'variance' => -1],
            ],
            'regression' => ['observation_count' => 5, 'fit_quality' => 'Good'],
            'current_state' => ['progress' => 60],
            'contractor_performance_rating' => 2,
            'contractor_on_time_pct' => 45,
            'contractor_avg_delay' => 30,
        ];

        $engine = new RecommendationEngine();
        $recommendations = $engine->generate($project, $prediction);

        $contractorRec = collect($recommendations)->filter(fn($r) => str_contains($r['message'], 'Contractor has a history of delays'))->first();
        $this->assertNull($contractorRec, 'Should not generate contractor rec without open issues');
    }

    public function test_extension_approval_triggers_recompute(): void
    {
        $project = Project::factory()->create([
            'contractor_id' => $this->contractor->id,
            'status' => 'Ongoing',
            'original_planned_end_date' => now()->subDays(10),
            'planned_end_date' => now()->subDays(10),
        ]);

        $extension = ProjectExtension::create([
            'project_id' => $project->id,
            'extension_number' => 1,
            'original_end_date' => now()->subDays(10),
            'new_end_date' => now()->addDays(5),
            'approved_new_end_date' => now()->addDays(5),
            'reason' => 'Weather delays',
            'status' => 'approved',
            'duration_days' => 15,
        ]);

        $service = new ContractorPerformanceService();
        $service->recompute($this->contractor);
        $this->contractor->refresh();

        $this->assertNotNull($this->contractor->completed_projects_count);
    }

    public function test_contractor_performance_fields_are_fillable(): void
    {
        $this->contractor->update([
            'performance_rating' => 4.5,
            'on_time_delivery_pct' => 88.5,
            'completed_projects_count' => 10,
            'avg_delay_days' => 2.5,
            'last_project_completed_at' => now(),
        ]);

        $this->contractor->refresh();
        $this->assertEquals(4.5, $this->contractor->performance_rating);
        $this->assertEquals(88.5, $this->contractor->on_time_delivery_pct);
        $this->assertEquals(10, $this->contractor->completed_projects_count);
        $this->assertEquals(2.5, $this->contractor->avg_delay_days);
    }
}
