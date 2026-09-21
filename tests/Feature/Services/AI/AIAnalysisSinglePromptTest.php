<?php

namespace Tests\Feature\Services\AI;

use App\Services\AI\AIPredictionPresenter;
use App\Services\AI\AIPredictionService;
use App\Services\AI\AIRecommendationService;
use App\Services\AI\RecommendationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * ============================================================================
 * AIAnalysisSinglePromptTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Pins the behaviour of textfiles/ai_analysis_recommendation_merge_plan.md:
 * one Gemini call for the analysis AND the recommendations, split before
 * saving, rule-based fallback, and no Gemini call on a page render.
 *
 * Every test fakes HTTP and forbids stray requests, so no test can reach the
 * real Gemini key a developer's .env may carry.
 *
 * Run With
 * --------
 *   php artisan test --filter=AIAnalysisSinglePromptTest
 */
class AIAnalysisSinglePromptTest extends TestCase
{
    use RefreshDatabase;
    use CreatesProjectWithHistory;

    /**
     * Gemini endpoint pattern used by both AI services.
     */
    private const GEMINI_URL = 'generativelanguage.googleapis.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'test-model']);
        Http::preventStrayRequests();
    }

    /**
     * Wrap a text answer in Gemini's generateContent response shape.
     */
    private function geminiAnswer(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    /**
     * A valid standalone recommendations answer (JSON array only).
     */
    private function recsJson(): string
    {
        return json_encode([
            ['message' => 'AI rec one', 'category' => 'corrective', 'reason' => 'Gap of 12%', 'priority' => 2],
            ['message' => 'AI rec two', 'category' => 'monitoring', 'reason' => 'Trend declining', 'priority' => 3],
        ]);
    }

    /*
    |==========================================================================
    | Step 2 — D3: no Gemini call on a page render
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * Positive control: the AI-first generate() DOES call Gemini
     * ------------------------------------------------------------------------
     *
     * Proves the fake below would catch a call, so the "nothing sent" tests
     * are not vacuous.
     */
    public function test_generate_still_calls_gemini_first(): void
    {
        Http::fake([self::GEMINI_URL => Http::response($this->geminiAnswer($this->recsJson()))]);

        $project = $this->createProjectWithHistory(8);
        $prediction = app(AIPredictionService::class)->generatePrediction($project);

        $recs = (new RecommendationEngine(new AIRecommendationService()))->generate($project, $prediction);

        Http::assertSentCount(1);
        $this->assertSame('AI rec one', $recs[0]['message']);
        $this->assertTrue($recs[0]['ai_generated']);
    }

    /**
     * ------------------------------------------------------------------------
     * generateRuleBased() never calls Gemini and returns the rule output
     * ------------------------------------------------------------------------
     */
    public function test_generate_rule_based_never_calls_gemini(): void
    {
        Http::fake([self::GEMINI_URL => Http::response($this->geminiAnswer($this->recsJson()))]);

        $project = $this->createProjectWithHistory(8);
        $prediction = app(AIPredictionService::class)->generatePrediction($project);

        $withAi = new RecommendationEngine(new AIRecommendationService());
        $rulesOnly = new RecommendationEngine();

        $recs = $withAi->generateRuleBased($project, $prediction);

        Http::assertNothingSent();
        $this->assertNotEmpty($recs);
        // Same output as the engine that has no AI service at all
        $this->assertSame($rulesOnly->generate($project, $prediction), $recs);
        foreach ($recs as $rec) {
            $this->assertArrayNotHasKey('ai_generated', $rec);
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Rendering the AI Prediction tab with no stored analysis sends nothing
     * ------------------------------------------------------------------------
     *
     * Uses the container-built presenter, whose engine HAS the AI service —
     * the production wiring (AppServiceProvider). Before D3 this made a
     * Gemini call on every view of a never-regenerated project.
     */
    public function test_presenter_render_never_calls_gemini(): void
    {
        Http::fake([self::GEMINI_URL => Http::response($this->geminiAnswer($this->recsJson()))]);

        $project = $this->createProjectWithHistory(8);

        $presented = app(AIPredictionPresenter::class)->present($project);

        Http::assertNothingSent();
        $this->assertNotEmpty($presented['structured_recommendations']);
    }
}
