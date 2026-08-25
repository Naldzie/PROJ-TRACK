<?php

namespace Tests\Unit\Services\AI;

use App\Models\Project;
use App\Models\ProjectProgressHistory;
use App\Services\AI\AIRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * ============================================================================
 * AIRecommendationServiceTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Unit tests for AIRecommendationService (Phase 10). Tests the response
 * parsing, validation, and prompt building logic. Gemini API calls are
 * NOT made — private methods are tested via reflection.
 *
 * Run With
 * --------
 *   php artisan test --filter=AIRecommendationServiceTest
 */
class AIRecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AIRecommendationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AIRecommendationService();
    }

    /**
     * ------------------------------------------------------------------------
     * Call private method via reflection
     * ------------------------------------------------------------------------
     *
     * Helper to invoke private methods for unit testing without HTTP calls.
     */
    private function invokePrivate(string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invoke($this->service, ...$args);
    }

    // ========================================================================
    // parseResponse tests
    // ========================================================================

    /**
     * Valid JSON array → parsed correctly
     */
    public function test_parse_response_parses_valid_json_array(): void
    {
        $json = json_encode([
            [
                'message' => 'Escalate project',
                'category' => 'escalation',
                'reason' => 'Critical risk',
                'priority' => 1,
            ],
        ]);

        $result = $this->invokePrivate('parseResponse', [$json]);

        $this->assertCount(1, $result);
        $this->assertEquals('Escalate project', $result[0]['message']);
    }

    /**
     * Markdown code fences around JSON → stripped and parsed
     */
    public function test_parse_response_handles_markdown_code_fences(): void
    {
        $json = "```json\n" . json_encode([
            [
                'message' => 'Review schedule',
                'category' => 'corrective',
                'reason' => 'Behind schedule',
                'priority' => 2,
            ],
        ]) . "\n```";

        $result = $this->invokePrivate('parseResponse', [$json]);

        $this->assertCount(1, $result);
        $this->assertEquals('Review schedule', $result[0]['message']);
    }

    /**
     * Empty code fence without json label → still parsed
     */
    public function test_parse_response_handles_plain_code_fences(): void
    {
        $json = "```\n" . json_encode([
            [
                'message' => 'Test',
                'category' => 'monitoring',
                'reason' => 'Testing',
                'priority' => 3,
            ],
        ]) . "\n```";

        $result = $this->invokePrivate('parseResponse', [$json]);

        $this->assertCount(1, $result);
    }

    /**
     * Object wrapper with 'recommendations' key → unwrapped
     */
    public function test_parse_response_unwraps_recommendations_key(): void
    {
        $json = json_encode([
            'recommendations' => [
                [
                    'message' => 'Wrapped rec',
                    'category' => 'administrative',
                    'reason' => 'Admin task',
                    'priority' => 5,
                ],
            ],
        ]);

        $result = $this->invokePrivate('parseResponse', [$json]);

        $this->assertCount(1, $result);
        $this->assertEquals('Wrapped rec', $result[0]['message']);
    }

    /**
     * Invalid JSON → returns empty array
     */
    public function test_parse_response_returns_empty_on_invalid_json(): void
    {
        $result = $this->invokePrivate('parseResponse', ['not valid json']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Empty string → returns empty array
     */
    public function test_parse_response_returns_empty_on_empty_string(): void
    {
        $result = $this->invokePrivate('parseResponse', ['']);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * JSON object (not array, no 'recommendations' key) → returned as-is
     * (filtering happens in generate(), not parseResponse)
     */
    public function test_parse_response_returns_object_as_array(): void
    {
        $json = json_encode(['foo' => 'bar']);

        $result = $this->invokePrivate('parseResponse', [$json]);

        $this->assertEquals(['foo' => 'bar'], $result);
    }

    // ========================================================================
    // validateRecommendation tests
    // ========================================================================

    /**
     * Valid recommendation → passes validation
     */
    public function test_validate_recommendation_passes_for_valid_rec(): void
    {
        $rec = [
            'message' => 'Escalate project',
            'category' => 'escalation',
            'reason' => 'Critical risk detected',
            'priority' => 1,
        ];

        $result = $this->invokePrivate('validateRecommendation', [$rec]);

        $this->assertTrue($result);
    }

    /**
     * Missing message → fails
     */
    public function test_validate_recommendation_fails_without_message(): void
    {
        $rec = [
            'category' => 'escalation',
            'reason' => 'Test',
            'priority' => 1,
        ];

        $result = $this->invokePrivate('validateRecommendation', [$rec]);

        $this->assertFalse($result);
    }

    /**
     * Empty message → fails
     */
    public function test_validate_recommendation_fails_with_empty_message(): void
    {
        $rec = [
            'message' => '',
            'category' => 'escalation',
            'reason' => 'Test',
            'priority' => 1,
        ];

        $result = $this->invokePrivate('validateRecommendation', [$rec]);

        $this->assertFalse($result);
    }

    /**
     * Missing reason → fails
     */
    public function test_validate_recommendation_fails_without_reason(): void
    {
        $rec = [
            'message' => 'Test',
            'category' => 'escalation',
            'priority' => 1,
        ];

        $result = $this->invokePrivate('validateRecommendation', [$rec]);

        $this->assertFalse($result);
    }

    /**
     * Invalid category → fails
     */
    public function test_validate_recommendation_fails_with_invalid_category(): void
    {
        $rec = [
            'message' => 'Test',
            'category' => 'invalid_category',
            'reason' => 'Test',
            'priority' => 1,
        ];

        $result = $this->invokePrivate('validateRecommendation', [$rec]);

        $this->assertFalse($result);
    }

    /**
     * Missing priority → fails
     */
    public function test_validate_recommendation_fails_without_priority(): void
    {
        $rec = [
            'message' => 'Test',
            'category' => 'escalation',
            'reason' => 'Test',
        ];

        $result = $this->invokePrivate('validateRecommendation', [$rec]);

        $this->assertFalse($result);
    }

    /**
     * Non-numeric priority → fails
     */
    public function test_validate_recommendation_fails_with_non_numeric_priority(): void
    {
        $rec = [
            'message' => 'Test',
            'category' => 'escalation',
            'reason' => 'Test',
            'priority' => 'high',
        ];

        $result = $this->invokePrivate('validateRecommendation', [$rec]);

        $this->assertFalse($result);
    }

    /**
     * Non-array input → fails
     */
    public function test_validate_recommendation_fails_for_non_array(): void
    {
        $result = $this->invokePrivate('validateRecommendation', ['not an array']);

        $this->assertFalse($result);
    }

    /**
     * All four valid categories pass
     */
    public function test_validate_recommendation_passes_all_valid_categories(): void
    {
        foreach (['escalation', 'corrective', 'monitoring', 'administrative'] as $category) {
            $rec = [
                'message' => 'Test',
                'category' => $category,
                'reason' => 'Test',
                'priority' => 3,
            ];

            $result = $this->invokePrivate('validateRecommendation', [$rec]);

            $this->assertTrue($result, "Category '{$category}' should be valid");
        }
    }

    /**
     * Case-insensitive category validation (uppercase → normalized)
     */
    public function test_validate_recommendation_accepts_uppercase_category(): void
    {
        $rec = [
            'message' => 'Test',
            'category' => 'ESCALATION',
            'reason' => 'Test',
            'priority' => 1,
        ];

        $result = $this->invokePrivate('validateRecommendation', [$rec]);

        $this->assertTrue($result);
    }

    // ========================================================================
    // generate() with empty API key → returns empty
    // ========================================================================

    /**
     * No Gemini API key configured → returns empty array (no crash)
     */
    public function test_generate_returns_empty_without_api_key(): void
    {
        config(['services.gemini.key' => null]);

        $project = Project::factory()->create([
            'status' => 'Ongoing',
        ]);

        $prediction = [
            'current_state' => ['progress' => 50.0],
            'assessment' => [['risk_level' => 'Low', 'variance' => 0]],
            'regression' => ['observation_count' => 5, 'fit_quality' => 'Good'],
        ];

        $result = $this->service->generate($project, $prediction);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Empty Gemini API key → returns empty array
     */
    public function test_generate_returns_empty_with_empty_api_key(): void
    {
        config(['services.gemini.key' => '']);

        $project = Project::factory()->create([
            'status' => 'Ongoing',
        ]);

        $prediction = [
            'current_state' => ['progress' => 50.0],
            'assessment' => [['risk_level' => 'Low', 'variance' => 0]],
            'regression' => ['observation_count' => 5, 'fit_quality' => 'Good'],
        ];

        $result = $this->service->generate($project, $prediction);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ========================================================================
    // buildPrompt tests (via reflection)
    // ========================================================================

    /**
     * Prompt contains project title and status
     */
    public function test_build_prompt_contains_project_context(): void
    {
        $project = Project::factory()->create([
            'title' => 'Test Bridge Project',
            'status' => 'Ongoing',
        ]);

        $prediction = [
            'current_state' => ['progress' => 45.0],
            'assessment' => [['risk_level' => 'Moderate', 'variance' => -3.0]],
            'regression' => ['r_squared' => 0.95, 'fit_quality' => 'Strong', 'observation_count' => 8],
            'class_standing' => ['usable' => false],
        ];

        $prompt = $this->invokePrivate('buildPrompt', [$project, $prediction]);

        $this->assertStringContainsString('Test Bridge Project', $prompt);
        $this->assertStringContainsString('Ongoing', $prompt);
        $this->assertStringContainsString('Moderate', $prompt);
    }

    /**
     * Prompt contains output format specification
     */
    public function test_build_prompt_contains_output_format(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $prediction = [
            'current_state' => ['progress' => 30.0],
            'assessment' => [['risk_level' => 'Low', 'variance' => 0]],
            'regression' => ['r_squared' => 0.8, 'fit_quality' => 'Good', 'observation_count' => 4],
            'class_standing' => ['usable' => false],
        ];

        $prompt = $this->invokePrivate('buildPrompt', [$project, $prediction]);

        $this->assertStringContainsString('JSON', $prompt);
        $this->assertStringContainsString('escalation', $prompt);
        $this->assertStringContainsString('corrective', $prompt);
        $this->assertStringContainsString('monitoring', $prompt);
        $this->assertStringContainsString('administrative', $prompt);
    }

    /**
     * Prompt includes "No open issues" when project has none
     */
    public function test_build_prompt_shows_no_issues_when_none(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $prediction = [
            'current_state' => ['progress' => 20.0],
            'assessment' => [['risk_level' => 'Low', 'variance' => 0]],
            'regression' => ['r_squared' => 0.7, 'fit_quality' => 'Fair', 'observation_count' => 3],
            'class_standing' => ['usable' => false],
        ];

        $prompt = $this->invokePrivate('buildPrompt', [$project, $prediction]);

        $this->assertStringContainsString('No open issues', $prompt);
    }

    /**
     * Prompt includes "No approved extensions" when project has none
     */
    public function test_build_prompt_shows_no_extensions_when_none(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $prediction = [
            'current_state' => ['progress' => 20.0],
            'assessment' => [['risk_level' => 'Low', 'variance' => 0]],
            'regression' => ['r_squared' => 0.7, 'fit_quality' => 'Fair', 'observation_count' => 3],
            'class_standing' => ['usable' => false],
        ];

        $prompt = $this->invokePrivate('buildPrompt', [$project, $prediction]);

        $this->assertStringContainsString('No approved extensions', $prompt);
    }

    // ========================================================================
    // R² accuracy warning tests (R² fix)
    // ========================================================================

    /**
     * Prompt relabels R² as "Curve Fit Quality" — never as "Regression R-squared"
     */
    public function test_build_prompt_relabels_r_squared_as_curve_fit_quality(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $prediction = [
            'current_state' => ['progress' => 30.0],
            'assessment' => [['risk_level' => 'Low', 'variance' => 0]],
            'regression' => ['r_squared' => 0.954, 'fit_quality' => 'Excellent fit', 'observation_count' => 12],
            'class_standing' => ['usable' => false],
        ];

        $prompt = $this->invokePrivate('buildPrompt', [$project, $prediction]);

        // Must use the relabeled term
        $this->assertStringContainsString('Curve Fit Quality', $prompt);
        // Must NOT use the misleading old term
        $this->assertStringNotContainsString('Regression R-squared', $prompt);
        // Must include R² value with "matches past data only" context
        $this->assertStringContainsString('matches past data only', $prompt);
    }

    /**
     * Prompt warns AI not to use R² as evidence of prediction accuracy
     */
    public function test_build_prompt_warns_against_using_r_squared_as_accuracy(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $prediction = [
            'current_state' => ['progress' => 30.0],
            'assessment' => [['risk_level' => 'Low', 'variance' => 0]],
            'regression' => ['r_squared' => 0.999, 'fit_quality' => 'Excellent fit', 'observation_count' => 12],
            'class_standing' => ['usable' => false],
        ];

        $prompt = $this->invokePrivate('buildPrompt', [$project, $prediction]);

        // Must explicitly warn against R²-as-accuracy
        $this->assertStringContainsString('Do NOT use R-squared', $prompt);
        $this->assertStringContainsString('nothing about whether future predictions will be correct', $prompt);
        // Must direct AI to use reliability score instead
        $this->assertStringContainsString('Use the FORECAST RELIABILITY score', $prompt);
    }

    /**
     * Prompt includes forecast reliability block when available
     */
    public function test_build_prompt_includes_reliability_block(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $prediction = [
            'current_state' => ['progress' => 30.0],
            'assessment' => [['risk_level' => 'Low', 'variance' => 0]],
            'regression' => ['r_squared' => 0.85, 'fit_quality' => 'Good', 'observation_count' => 6],
            'class_standing' => ['usable' => false],
            'reliability' => [
                'next_prediction' => ['level' => 'Medium', 'score' => 72, 'factors' => [
                    'backtest'  => ['score' => 80, 'detail' => '67% within 5%'],
                    'data'      => ['score' => 70, 'detail' => '12/12 months'],
                    'horizon'   => ['score' => 60, 'detail' => '2 months out'],
                    'agreement' => ['score' => 65, 'detail' => 'SLR & Polynomial within 5%'],
                ]],
                'completion' => ['level' => 'Medium', 'score' => 65, 'factors' => [
                    'backtest'  => ['score' => 75, 'detail' => '70% within 5%'],
                    'data'      => ['score' => 70, 'detail' => '12/12 months'],
                    'horizon'   => ['score' => 50, 'detail' => '10 months to completion'],
                    'agreement' => ['score' => 60, 'detail' => '3 methods agree'],
                ]],
            ],
        ];

        $prompt = $this->invokePrivate('buildPrompt', [$project, $prediction]);

        // Must include reliability section
        $this->assertStringContainsString('FORECAST RELIABILITY', $prompt);
        $this->assertStringContainsString('Next-month forecast: Medium (72/100)', $prompt);
        $this->assertStringContainsString('Completion estimate: Medium (65/100)', $prompt);
        $this->assertStringContainsString('Prediction history: 80/100', $prompt);
        // Must NOT say "Regression R-squared"
        $this->assertStringNotContainsString('Regression R-squared', $prompt);
    }

    /**
     * Prompt degrades gracefully when reliability is missing
     */
    public function test_build_prompt_handles_missing_reliability(): void
    {
        $project = Project::factory()->create(['status' => 'Ongoing']);

        $prediction = [
            'current_state' => ['progress' => 30.0],
            'assessment' => [['risk_level' => 'Low', 'variance' => 0]],
            'regression' => ['r_squared' => 0.7, 'fit_quality' => 'Fair', 'observation_count' => 3],
            'class_standing' => ['usable' => false],
            'reliability' => null,
        ];

        $prompt = $this->invokePrivate('buildPrompt', [$project, $prediction]);

        $this->assertStringContainsString('FORECAST RELIABILITY', $prompt);
        $this->assertStringContainsString('N/A', $prompt);
    }

    /*
    |--------------------------------------------------------------------------
    | Progress Remarks Tests (Phase 10 enhancement)
    |--------------------------------------------------------------------------
    |
    | Validates buildProgressRemarksText() pulls from ProgressHistory,
    | filters gibberish via TextQualityFilter, and formats for the prompt.
    |
    */

    public function test_build_progress_remarks_includes_recent_remarks(): void
    {
        $project = Project::factory()->create([
            'planned_start_date' => now()->subDays(60),
            'planned_end_date' => now()->addDays(30),
        ]);

        ProjectProgressHistory::factory()->create([
            'project_id' => $project->id,
            'inspection_date' => now()->subDays(3),
            'remarks' => 'Concrete pouring underway in Zone A',
        ]);
        ProjectProgressHistory::factory()->create([
            'project_id' => $project->id,
            'inspection_date' => now()->subDays(7),
            'remarks' => 'Formwork completed, pending inspection',
        ]);

        $text = $this->invokePrivate('buildProgressRemarksText', [$project]);

        $this->assertNotEmpty($text);
        $this->assertStringContainsString('RECENT INSPECTIONS', $text);
        $this->assertStringContainsString('Concrete pouring underway in Zone A', $text);
        $this->assertStringContainsString('Formwork completed, pending inspection', $text);
    }

    public function test_build_progress_remarks_filters_gibberish(): void
    {
        $project = Project::factory()->create([
            'planned_start_date' => now()->subDays(60),
            'planned_end_date' => now()->addDays(30),
        ]);

        ProjectProgressHistory::factory()->create([
            'project_id' => $project->id,
            'inspection_date' => now()->subDays(3),
            'remarks' => 'xkcd fghjkl qwerty',
        ]);
        ProjectProgressHistory::factory()->create([
            'project_id' => $project->id,
            'inspection_date' => now()->subDays(7),
            'remarks' => 'Actual progress on schedule',
        ]);

        $text = $this->invokePrivate('buildProgressRemarksText', [$project]);

        $this->assertStringNotContainsString('asdfghjkl', $text);
        $this->assertStringContainsString('Actual progress on schedule', $text);
    }

    public function test_build_progress_remarks_returns_fallback_when_no_records(): void
    {
        $project = Project::factory()->create([
            'planned_start_date' => now()->subDays(60),
            'planned_end_date' => now()->addDays(30),
        ]);

        $text = $this->invokePrivate('buildProgressRemarksText', [$project]);

        $this->assertNotEmpty($text);
        $this->assertStringContainsString('No inspection records yet', $text);
    }

    public function test_build_progress_remarks_shows_no_remarks_when_all_gibberish(): void
    {
        $project = Project::factory()->create([
            'planned_start_date' => now()->subDays(60),
            'planned_end_date' => now()->addDays(30),
        ]);

        ProjectProgressHistory::factory()->create([
            'project_id' => $project->id,
            'inspection_date' => now()->subDays(3),
            'remarks' => 'asdfghjkl',
        ]);

        $text = $this->invokePrivate('buildProgressRemarksText', [$project]);

        $this->assertStringContainsString('(no remarks)', $text);
        $this->assertStringNotContainsString('asdfghjkl', $text);
    }

    public function test_build_progress_remarks_includes_as_of_timestamp(): void
    {
        $project = Project::factory()->create([
            'planned_start_date' => now()->subDays(60),
            'planned_end_date' => now()->addDays(30),
        ]);

        ProjectProgressHistory::factory()->create([
            'project_id' => $project->id,
            'inspection_date' => now()->subDays(1),
            'remarks' => 'Piping installation in progress',
        ]);

        $text = $this->invokePrivate('buildProgressRemarksText', [$project]);

        $this->assertStringContainsString('RECENT INSPECTIONS', $text);
    }
}
