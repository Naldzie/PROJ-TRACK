<?php

namespace Tests\Feature\Services\AI;

use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Services\AI\AIRecommendationService;
use App\Services\AI\RecommendationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * AIRecommendationIntegrationTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Integration tests for the AI-powered recommendation system (Phase 10).
 * Verifies the AI-first with rule-based fallback behavior end-to-end.
 *
 * Since we cannot make real Gemini API calls in tests, these tests verify:
 * - Fallback to rule-based logic when AI is unavailable (no API key)
 * - Output format matches Blade expectations
 * - Category color mapping works with all categories
 * - Rule-based engine still produces correct recommendations
 *
 * Run With
 * --------
 *   php artisan test --filter=AIRecommendationIntegrationTest
 */
class AIRecommendationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a project with enough data to pass early guards in generate().
     */
    private function createProjectWithBaselines(array $overrides = []): Project
    {
        $project = Project::factory()->create(array_merge([
            'status' => 'Ongoing',
            'planned_start_date' => now()->subMonths(6),
            'planned_end_date' => now()->addMonths(6),
            'extension_count' => 0,
        ], $overrides));

        // Create baseline schedules so has_baselines is true
        for ($i = 0; $i < 6; $i++) {
            ProjectBaselineSchedule::create([
                'project_id' => $project->id,
                'planned_date' => now()->subMonths(6 - $i),
                'planned_progress_percent' => ($i + 1) * 15,
                'month_label' => now()->subMonths(6 - $i)->format('Y-m'),
            ]);
        }

        return $project;
    }

    /**
     * Build a minimal valid prediction payload with ≥3 observations.
     */
    private function buildPrediction(array $overrides = []): array
    {
        return array_merge([
            'current_state' => [
                'progress'    => 30.0,
                'month_index' => 4,
            ],
            'assessment' => [
                ['risk_level' => 'Moderate', 'trend' => 'STABLE', 'variance' => -3.0, 'progress' => 30.0],
            ],
            'regression' => [
                'observation_count'  => 5,
                'fit_quality'        => 'Good',
                'r_squared'          => 0.85,
                'regression_method'  => 'slr',
            ],
            'class_standing' => [
                'usable'        => false,
                'similar_count' => 0,
                'tier'          => null,
                'tier_label'    => null,
                'at_risk'       => false,
                'at_risk_run'   => 0,
            ],
        ], $overrides);
    }

    // ========================================================================
    // Fallback behavior
    // ========================================================================

    /**
     * No API key → AI returns empty → fallback to rule-based recommendations.
     * The rule engine should still produce valid recommendations.
     */
    public function test_fallback_to_rules_when_ai_unavailable(): void
    {
        config(['services.gemini.key' => null]);

        $project = $this->createProjectWithBaselines();
        $prediction = $this->buildPrediction();

        $engine = new RecommendationEngine();
        $recs = $engine->generate($project, $prediction);

        // Should get rule-based recommendations (not empty)
        $this->assertIsArray($recs);
        $this->assertNotEmpty($recs);

        // Each recommendation should have the required format
        foreach ($recs as $rec) {
            $this->assertArrayHasKey('message', $rec);
            $this->assertArrayHasKey('category', $rec);
            $this->assertArrayHasKey('reason', $rec);
            $this->assertArrayHasKey('priority', $rec);

            $this->assertNotEmpty($rec['message']);
            $this->assertNotEmpty($rec['reason']);
            $this->assertContains($rec['category'], ['escalation', 'corrective', 'monitoring', 'administrative']);
            $this->assertGreaterThanOrEqual(1, $rec['priority']);
            $this->assertLessThanOrEqual(5, $rec['priority']);
        }
    }

    /**
     * Empty API key → same fallback behavior.
     */
    public function test_fallback_with_empty_api_key(): void
    {
        config(['services.gemini.key' => '']);

        $project = $this->createProjectWithBaselines();
        $prediction = $this->buildPrediction();

        $engine = new RecommendationEngine();
        $recs = $engine->generate($project, $prediction);

        $this->assertNotEmpty($recs);
    }

    // ========================================================================
    // Output format validation
    // ========================================================================

    /**
     * Recommendations are sorted by priority (ascending).
     */
    public function test_recommendations_sorted_by_priority(): void
    {
        config(['services.gemini.key' => null]);

        $project = $this->createProjectWithBaselines([
            'extension_count' => 2,
        ]);

        // Create issues to trigger more recommendations
        Issue::create([
            'project_id' => $project->id,
            'issue_name' => 'Material delay',
            'description' => 'Test material delay',
            'status' => 'Open',
            'issue_type' => 'material',
            'severity' => 'High',
        ]);
        Issue::create([
            'project_id' => $project->id,
            'issue_name' => 'Contractor issue',
            'description' => 'Test contractor issue',
            'status' => 'Open',
            'issue_type' => 'contractor',
            'severity' => 'Medium',
        ]);

        $prediction = $this->buildPrediction([
            'assessment' => [
                ['risk_level' => 'High', 'trend' => 'DECELERATING', 'variance' => -8.0, 'progress' => 30.0],
            ],
        ]);

        $engine = new RecommendationEngine();
        $recs = $engine->generate($project, $prediction);

        $this->assertNotEmpty($recs);

        // Verify sorted by priority ascending
        for ($i = 1; $i < count($recs); $i++) {
            $this->assertGreaterThanOrEqual(
                $recs[$i - 1]['priority'],
                $recs[$i]['priority'],
                "Recommendation at index {$i} should have priority >= index " . ($i - 1)
            );
        }
    }

    /**
     * Max 8 rule-based recommendations returned (cap).
     */
    public function test_rule_based_cap_at_8(): void
    {
        config(['services.gemini.key' => null]);

        $project = $this->createProjectWithBaselines([
            'extension_count' => 3,
        ]);

        // Create many issues of different types
        foreach (['material', 'contractor', 'design', 'weather', 'regulatory'] as $type) {
            Issue::create([
                'project_id' => $project->id,
                'issue_name' => "{$type} issue 1",
                'description' => "Test {$type} issue 1",
                'status' => 'Open',
                'issue_type' => $type,
                'severity' => 'High',
            ]);
            Issue::create([
                'project_id' => $project->id,
                'issue_name' => "{$type} issue 2",
                'description' => "Test {$type} issue 2",
                'status' => 'Open',
                'issue_type' => $type,
                'severity' => 'Critical',
            ]);
        }

        $prediction = $this->buildPrediction([
            'assessment' => [
                ['risk_level' => 'Critical', 'trend' => 'DECELERATING', 'variance' => -15.0, 'progress' => 30.0],
            ],
        ]);

        $engine = new RecommendationEngine();
        $recs = $engine->generate($project, $prediction);

        $this->assertNotEmpty($recs);
        $this->assertLessThanOrEqual(8, count($recs), 'Rule-based engine should cap at 8 recommendations');
    }

    // ========================================================================
    // Category color mapping
    // ========================================================================

    /**
     * All four category colors resolve correctly.
     */
    public function test_category_colors_resolve_for_all_categories(): void
    {
        $engine = new RecommendationEngine();

        $this->assertEquals('#ef4444', $engine->getCategoryColor('escalation'));
        $this->assertEquals('#f59e0b', $engine->getCategoryColor('corrective'));
        $this->assertEquals('#eab308', $engine->getCategoryColor('monitoring'));
        $this->assertEquals('#3b82f6', $engine->getCategoryColor('administrative'));
        $this->assertEquals('#6b7280', $engine->getCategoryColor('unknown'));
    }

    /**
     * Completed project → empty recommendations (no AI call, no rules).
     */
    public function test_completed_project_returns_empty(): void
    {
        config(['services.gemini.key' => null]);

        $project = $this->createProjectWithBaselines(['status' => 'Completed']);
        $prediction = $this->buildPrediction();

        $engine = new RecommendationEngine();
        $recs = $engine->generate($project, $prediction);

        $this->assertEmpty($recs);
    }

    /**
     * Project with 0 observations → empty recommendations.
     */
    public function test_project_with_no_observations_returns_empty(): void
    {
        config(['services.gemini.key' => null]);

        $project = $this->createProjectWithBaselines();
        $prediction = $this->buildPrediction([
            'regression' => ['observation_count' => 0],
        ]);

        $engine = new RecommendationEngine();
        $recs = $engine->generate($project, $prediction);

        $this->assertEmpty($recs);
    }

    /**
     * Project with 1-2 observations → data-focused admin recs only.
     */
    public function test_project_with_few_observations_gets_data_focused_recs(): void
    {
        config(['services.gemini.key' => null]);

        $project = $this->createProjectWithBaselines();
        $prediction = $this->buildPrediction([
            'regression' => ['observation_count' => 2],
        ]);

        $engine = new RecommendationEngine();
        $recs = $engine->generate($project, $prediction);

        $this->assertNotEmpty($recs);
        // Should only have administrative recommendations
        foreach ($recs as $rec) {
            $this->assertEquals('administrative', $rec['category']);
        }
    }

    // ========================================================================
    // AI service instantiation
    // ========================================================================

    /**
     * AIRecommendationService can be instantiated without errors.
     */
    public function test_ai_recommendation_service_instantiates(): void
    {
        $service = new AIRecommendationService();

        $this->assertInstanceOf(AIRecommendationService::class, $service);
    }

    /**
     * RecommendationEngine can be instantiated without arguments (backward compat).
     */
    public function test_recommendation_engine_instantiates_without_arguments(): void
    {
        $engine = new RecommendationEngine();

        $this->assertInstanceOf(RecommendationEngine::class, $engine);
    }

    /**
     * RecommendationEngine can be instantiated with AI service.
     */
    public function test_recommendation_engine_instantiates_with_ai_service(): void
    {
        $aiService = new AIRecommendationService();
        $engine = new RecommendationEngine($aiService);

        $this->assertInstanceOf(RecommendationEngine::class, $engine);
    }
}
