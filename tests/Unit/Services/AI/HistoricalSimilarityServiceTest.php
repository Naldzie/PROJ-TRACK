<?php

namespace Tests\Unit\Services\AI;

use App\Models\Category;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use App\Services\AI\HistoricalSimilarityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests HistoricalSimilarityService — shouldSupplement, findSimilarProjects,
 * buildAggregateCurve, and computeSimilarityBreakdown.
 */
class HistoricalSimilarityServiceTest extends TestCase
{
    use RefreshDatabase;

    private HistoricalSimilarityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HistoricalSimilarityService();
    }

    // ========================================================================
    // shouldSupplement — mode determination
    // ========================================================================

    public function test_should_supplement_returns_none_for_completed_project(): void
    {
        $project = Project::factory()->completed()->create();

        $result = $this->service->shouldSupplement($project, 5);

        $this->assertSame('none', $result);
    }

    public function test_should_supplement_returns_none_for_zero_observations_without_duration(): void
    {
        $project = Project::factory()->create([
            'status' => 'Ongoing',
            'planned_duration_days' => null,
        ]);

        $result = $this->service->shouldSupplement($project, 0);

        $this->assertSame('none', $result);
    }

    public function test_should_supplement_returns_fallback_for_zero_observations_with_duration(): void
    {
        $project = Project::factory()->create([
            'status' => 'Ongoing',
            'planned_duration_days' => 180,
        ]);

        $result = $this->service->shouldSupplement($project, 0);

        $this->assertSame('fallback', $result);
    }

    public function test_should_supplement_returns_supplement_for_one_observation(): void
    {
        $project = Project::factory()->create([
            'status' => 'Ongoing',
            'planned_duration_days' => 180,
        ]);

        $result = $this->service->shouldSupplement($project, 1);

        $this->assertSame('supplement', $result);
    }

    public function test_should_supplement_returns_none_for_two_plus_observations(): void
    {
        $project = Project::factory()->create([
            'status' => 'Ongoing',
            'planned_duration_days' => 180,
        ]);

        $result = $this->service->shouldSupplement($project, 2);

        $this->assertSame('none', $result);
    }

    // ========================================================================
    // findSimilarProjects — scoring and filtering
    // ========================================================================

    public function test_find_similar_projects_returns_empty_when_no_completed_projects(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);
        $category = $project->category;

        $result = $this->service->findSimilarProjects($project);

        $this->assertCount(0, $result);
    }

    public function test_find_similar_projects_finds_same_category(): void
    {
        $category = Category::create(['name' => 'New Construction', 'description' => 'Test']);
        $contractor = $this->createContractor();

        $project = Project::factory()->create([
            'status' => 'Ongoing',
            'category_id' => $category->id,
            'contractor_id' => $contractor->id,
            'planned_duration_days' => 180,
            'approved_budget' => 5000000,
        ]);

        // Create a completed project in the same category with progress history
        $completed = Project::factory()->completed()->create([
            'category_id' => $category->id,
            'contractor_id' => $contractor->id,
            'planned_duration_days' => 180,
            'approved_budget' => 5000000,
        ]);

        $this->createProgressHistory($completed, 6);

        $result = $this->service->findSimilarProjects($project);

        $this->assertGreaterThanOrEqual(1, $result->count());
    }

    public function test_find_similar_projects_excludes_different_category(): void
    {
        $cat1 = Category::create(['name' => 'New Construction', 'description' => '']);
        $cat2 = Category::create(['name' => 'Renovation', 'description' => '']);

        $project = Project::factory()->create([
            'status' => 'Ongoing',
            'category_id' => $cat1->id,
            'planned_duration_days' => 180,
        ]);

        $completed = Project::factory()->completed()->create([
            'category_id' => $cat2->id,
            'planned_duration_days' => 180,
        ]);

        $this->createProgressHistory($completed, 6);

        $result = $this->service->findSimilarProjects($project);

        $this->assertCount(0, $result);
    }

    public function test_find_similar_projects_excludes_self(): void
    {
        $project = Project::factory()->completed()->create([
            'planned_duration_days' => 180,
        ]);

        $this->createProgressHistory($project, 6);

        $result = $this->service->findSimilarProjects($project);

        $this->assertCount(0, $result);
    }

    // ========================================================================
    // buildAggregateCurve
    // ========================================================================

    public function test_build_aggregate_curve_returns_empty_when_no_similar_projects(): void
    {
        $project = Project::factory()->create([
            'status' => 'Ongoing',
            'planned_duration_days' => 180,
        ]);

        $result = $this->service->buildAggregateCurve($project);

        $this->assertEmpty($result);
    }

    public function test_build_aggregate_curve_returns_empty_when_insufficient_usable_curves(): void
    {
        $category = Category::create(['name' => 'Test', 'description' => '']);
        $project = Project::factory()->create([
            'status' => 'Ongoing',
            'category_id' => $category->id,
            'planned_duration_days' => 180,
        ]);

        // Create only 1 completed project (below MIN_SIMILAR_PROJECTS=3)
        $completed = Project::factory()->completed()->create([
            'category_id' => $category->id,
            'planned_duration_days' => 180,
        ]);
        $this->createProgressHistory($completed, 6);

        $result = $this->service->buildAggregateCurve($project);

        $this->assertEmpty($result);
    }

    // ========================================================================
    // computeSimilarityBreakdown (via reflection or public interface)
    // ========================================================================

    public function test_count_usable_curves_returns_zero_when_no_similar(): void
    {
        $project = Project::factory()->create([
            'status' => 'Ongoing',
            'planned_duration_days' => 180,
        ]);

        $result = $this->service->countUsableCurves($project);

        $this->assertSame(0, $result);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createContractor(): Contractor
    {
        $user = User::create([
            'name' => 'Test Contractor User',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'contractor',
            'is_active' => true,
        ]);

        return Contractor::create([
            'user_id' => $user->id,
            'contractor_name' => 'Test Contractors',
            'company_name' => 'Test Co',
        ]);
    }

    private function createProgressHistory(Project $project, int $months): void
    {
        $startDate = now()->subMonths($months)->startOfMonth();

        for ($i = 0; $i < $months; $i++) {
            $monthDate = (clone $startDate)->addMonths($i);
            $monthLabel = $monthDate->format('Y-m');
            $plannedDate = (clone $monthDate)->endOfMonth()->format('Y-m-d');
            $plannedProgress = round((($i + 1) / $months) * 100, 2);
            $actualProgress = max(0, min(100, round($plannedProgress + fake()->randomFloat(2, -5, 5), 2)));

            ProjectBaselineSchedule::create([
                'project_id' => $project->id,
                'month_label' => $monthLabel,
                'planned_date' => $plannedDate,
                'planned_progress_percent' => $plannedProgress,
                'original_planned_progress_percent' => $plannedProgress,
            ]);

            ProjectProgressHistory::create([
                'project_id' => $project->id,
                'month_number' => $i + 1,
                'month_label' => $monthLabel,
                'inspection_date' => (clone $monthDate)->endOfMonth()->subDays(2)->format('Y-m-d'),
                'actual_progress_percent' => $actualProgress,
                'progress_percentage' => $actualProgress,
            ]);
        }
    }
}
