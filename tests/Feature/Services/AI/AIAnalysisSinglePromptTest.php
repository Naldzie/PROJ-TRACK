<?php

namespace Tests\Feature\Services\AI;

use App\Models\AiSolution;
use App\Models\User;
use App\Services\AI\AIExplanationService;
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

    /*
    |==========================================================================
    | Step 3 — the combined prompt
    |==========================================================================
    */

    /**
     * Invoke a private AIExplanationService method via reflection.
     */
    private function callPrivate(string $method, ...$args): mixed
    {
        $service = app(AIExplanationService::class);
        $ref = new \ReflectionMethod(AIExplanationService::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($service, ...$args);
    }

    /**
     * The five analysis sections as Gemini writes them.
     */
    private function sections(): string
    {
        return "[SECTION:Report]\nTrajectory text.\n[/SECTION]\n\n"
            . "[SECTION:Possible Risk]\n- Material delay\n[/SECTION]\n\n"
            . "[SECTION:Mitigation]\n- Track deliveries weekly\n[/SECTION]\n\n"
            . "[SECTION:Performance]\nPerformance text.\n[/SECTION]\n\n"
            . "[SECTION:AI Comment]\nBottom line.\n[/SECTION]";
    }

    /**
     * A full combined answer: the sections, then the recommendations block.
     */
    private function combinedAnswer(?string $recsBody = null): string
    {
        return $this->sections() . "\n\n" . AIExplanationService::RECS_OPEN . "\n"
            . ($recsBody ?? $this->recsJson()) . "\n" . AIExplanationService::RECS_CLOSE . "\n";
    }

    /**
     * ------------------------------------------------------------------------
     * The original analysis prompt is kept VERBATIM and the rest is appended
     * ------------------------------------------------------------------------
     *
     * Guard for the user's rule "do not change or remove the original prompt
     * of the AI analysis": the prompt without recommendations must be a
     * byte-exact PREFIX of the combined prompt, and it must still carry the
     * original instructions word for word.
     */
    public function test_combined_prompt_starts_with_the_unchanged_analysis_prompt(): void
    {
        $project = $this->createProjectWithHistory(8);
        $prediction = app(AIPredictionService::class)->generatePrediction($project);

        $original = $this->callPrivate('buildPrompt', $project, $prediction, false);
        $combined = $this->callPrivate('buildPrompt', $project, $prediction, true);

        $this->assertStringStartsWith($original, $combined);
        $this->assertGreaterThan(strlen($original), strlen($combined));

        // The original instructions, verbatim
        foreach ([
            'You are a project management analyst. Given the following project data, write a structured analysis in exactly 5 sections. Use professional, neutral tone suitable for a contractor audience. Cover factual observations from the data.',
            'OUTPUT FORMAT — Use exactly these 5 section markers (wrap each section title in [SECTION:Title] and [/SECTION]):',
            "[SECTION:Mitigation]\n[Bullet points: Areas requiring closer monitoring or preventive measures. If none, state 'No immediate mitigation actions required.']",
            'Do NOT mention that you are an AI or that this is an AI-generated analysis. Write as a human project analyst reporting to the contractor. Avoid management jargon - write directly for the contractor who is executing the work.',
        ] as $verbatim) {
            $this->assertStringContainsString($verbatim, $original);
        }

        // The original prompt says nothing about recommendations
        $this->assertStringNotContainsString(AIExplanationService::RECS_OPEN, $original);
    }

    /**
     * ------------------------------------------------------------------------
     * Plain-language style block follows the unchanged closing paragraph,
     * in BOTH modes, and comes before the recommendations appendix
     * ------------------------------------------------------------------------
     *
     * User, 2026-09-21: the analysis became "too technical" once the
     * reliability scores reached the prompt. The fix is appended, never
     * edited into the original text.
     */
    public function test_plain_language_block_is_appended_in_both_modes(): void
    {
        $project = $this->createProjectWithHistory(8);
        $prediction = app(AIPredictionService::class)->generatePrediction($project);

        $closing = 'write directly for the contractor who is executing the work.';
        $style = "\n\nWRITING STYLE FOR THE 5 SECTIONS — keep them non-technical:";

        foreach ([false, true] as $withRecs) {
            $prompt = $this->callPrivate('buildPrompt', $project, $prediction, $withRecs);

            // The original closing paragraph, then immediately the style block
            $this->assertStringContainsString($closing . $style, $prompt);
            $this->assertStringContainsString('Do NOT quote those scores, percentiles or terms in the text.', $prompt);
            $this->assertStringContainsString('Keep the concrete project facts', $prompt);
        }

        // Style block sits before the recommendations appendix
        $combined = $this->callPrivate('buildPrompt', $project, $prediction, true);
        $this->assertLessThan(
            strpos($combined, AIExplanationService::RECS_OPEN),
            strpos($combined, 'WRITING STYLE FOR THE 5 SECTIONS')
        );
    }

    /**
     * ------------------------------------------------------------------------
     * The appendix carries the recommendation data, format, rules and the
     * consistency rule
     * ------------------------------------------------------------------------
     */
    public function test_combined_prompt_appendix_contents(): void
    {
        $project = $this->createProjectWithHistory(8);
        $prediction = app(AIPredictionService::class)->generatePrediction($project);

        $original = $this->callPrivate('buildPrompt', $project, $prediction, false);
        $appendix = substr($this->callPrivate('buildPrompt', $project, $prediction, true), strlen($original));

        // Data only the recommendation prompt had (F-3)
        foreach (['Expected Progress:', 'Days Remaining:', 'Category:', 'CONTRACTOR PERFORMANCE:',
                  'ISSUES:', 'EXTENSIONS:', 'RECENT INSPECTIONS', 'CROSS-PROJECT CONTEXT:'] as $block) {
            $this->assertStringContainsString($block, $appendix);
        }

        // Shared JSON shape + rules, and the markers
        $this->assertStringContainsString(AIRecommendationService::RECOMMENDATION_JSON_SHAPE, $appendix);
        $this->assertStringContainsString(AIRecommendationService::RECOMMENDATION_RULES, $appendix);
        $this->assertStringContainsString(AIExplanationService::RECS_OPEN, $appendix);
        $this->assertStringContainsString(AIExplanationService::RECS_CLOSE, $appendix);

        // The rule that is the point of combining (D5)
        $this->assertStringContainsString('consistent with your Possible Risk and Mitigation sections', $appendix);

        // The standalone "ONLY the JSON array, no other text" rule would
        // contradict the 5 sections — it must not be carried over
        $this->assertStringNotContainsString('Return ONLY the JSON array, no other text', $appendix);
    }

    /*
    |==========================================================================
    | Step 3 — splitting the one answer
    |==========================================================================
    */

    /**
     * Block present → both outputs; analysis is exactly the sections.
     */
    public function test_split_block_present(): void
    {
        $split = $this->callPrivate('splitCombinedAnswer', $this->combinedAnswer());

        $this->assertSame($this->sections(), $split['analysis']);
        $this->assertSame($this->recsJson(), $split['recommendations']);
    }

    /**
     * No block → analysis untouched (bar trim), recommendations null.
     */
    public function test_split_block_absent(): void
    {
        $split = $this->callPrivate('splitCombinedAnswer', $this->sections() . "\n");

        $this->assertSame($this->sections(), $split['analysis']);
        $this->assertNull($split['recommendations']);
    }

    /**
     * Block wrapped in a code fence → the fence goes with the block.
     */
    public function test_split_removes_a_fence_around_the_block(): void
    {
        $raw = $this->sections() . "\n\n```json\n" . AIExplanationService::RECS_OPEN . "\n"
            . $this->recsJson() . "\n" . AIExplanationService::RECS_CLOSE . "\n```\n";

        $split = $this->callPrivate('splitCombinedAnswer', $raw);

        $this->assertSame($this->sections(), $split['analysis']);
        $this->assertStringNotContainsString('```', $split['analysis']);
    }

    /**
     * Fence INSIDE the markers → kept for parseRecommendations() to strip.
     */
    public function test_split_fence_inside_block_still_parses(): void
    {
        $split = $this->callPrivate('splitCombinedAnswer', $this->combinedAnswer("```json\n" . $this->recsJson() . "\n```"));

        $recs = (new AIRecommendationService())->parseRecommendations($split['recommendations']);

        $this->assertCount(2, $recs);
        $this->assertSame($this->sections(), $split['analysis']);
    }

    /**
     * Block placed mid-text → cut out, both halves of the analysis kept.
     */
    public function test_split_block_mid_text(): void
    {
        $raw = "[SECTION:Report]\nA.\n[/SECTION]\n" . AIExplanationService::RECS_OPEN . $this->recsJson()
            . AIExplanationService::RECS_CLOSE . "\n[SECTION:AI Comment]\nB.\n[/SECTION]";

        $split = $this->callPrivate('splitCombinedAnswer', $raw);

        $this->assertStringContainsString('[SECTION:Report]', $split['analysis']);
        $this->assertStringContainsString('[SECTION:AI Comment]', $split['analysis']);
        $this->assertStringNotContainsString('RECOMMENDATIONS_JSON', $split['analysis']);
        $this->assertStringNotContainsString('"message"', $split['analysis']);
    }

    /**
     * Cut-off answer (opening marker, no closing one) → everything after the
     * marker is the block and never reaches the analysis.
     */
    public function test_split_unclosed_block(): void
    {
        $raw = $this->sections() . "\n" . AIExplanationService::RECS_OPEN . "\n[{\"message\": \"half";

        $split = $this->callPrivate('splitCombinedAnswer', $raw);

        $this->assertSame($this->sections(), $split['analysis']);
        $this->assertSame('[{"message": "half', $split['recommendations']);
    }

    /**
     * Markers forgotten but a JSON array follows the sections → treated as
     * the block, so raw JSON cannot leak into analysis_text (the PDF prints
     * analysis_text raw).
     */
    public function test_split_unmarked_json_tail(): void
    {
        $split = $this->callPrivate('splitCombinedAnswer', $this->sections() . "\n\n```json\n" . $this->recsJson() . "\n```");

        $this->assertSame($this->sections(), $split['analysis']);
        $this->assertCount(2, (new AIRecommendationService())->parseRecommendations($split['recommendations']));
    }

    /**
     * Ordinary prose after the sections is NOT mistaken for a block.
     */
    public function test_split_leaves_prose_tail_alone(): void
    {
        $raw = $this->sections() . "\nNote: figures as of September.";

        $split = $this->callPrivate('splitCombinedAnswer', $raw);

        $this->assertSame($raw, $split['analysis']);
        $this->assertNull($split['recommendations']);
    }

    /*
    |==========================================================================
    | Step 3 — generate(): one call, two outputs, rule-based fallback
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * ONE Gemini call writes both; the block never reaches analysis_text
     * ------------------------------------------------------------------------
     */
    public function test_generate_makes_one_call_and_saves_both_outputs(): void
    {
        Http::fake([self::GEMINI_URL => Http::response($this->geminiAnswer($this->combinedAnswer()))]);

        $project = $this->createProjectWithHistory(8);

        $text = app(AIExplanationService::class)->generate($project);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['contents'][0]['parts'][0]['text'], AIExplanationService::RECS_OPEN));

        $row = AiSolution::where('project_id', $project->id)->latest('id')->first();

        $this->assertSame($this->sections(), $text);
        $this->assertSame($this->sections(), $row->analysis_text);
        $this->assertStringNotContainsString('RECOMMENDATIONS_JSON', $row->analysis_text);

        $this->assertCount(2, $row->recommendations);
        $this->assertSame('AI rec one', $row->recommendations[0]['message']);
        $this->assertTrue($row->recommendations[0]['ai_generated']);
    }

    /**
     * ------------------------------------------------------------------------
     * No block → analysis saved, RULE-BASED recs, no second call (D2)
     * ------------------------------------------------------------------------
     */
    public function test_generate_without_block_falls_back_to_rules(): void
    {
        Http::fake([self::GEMINI_URL => Http::response($this->geminiAnswer($this->sections()))]);

        $project = $this->createProjectWithHistory(8);
        $prediction = app(AIPredictionService::class)->generatePrediction($project);

        app(AIExplanationService::class)->generate($project);

        Http::assertSentCount(1);

        $row = AiSolution::where('project_id', $project->id)->latest('id')->first();
        $this->assertSame($this->sections(), $row->analysis_text);
        $this->assertNotEmpty($row->recommendations);
        $this->assertSame((new RecommendationEngine())->generateRuleBased($project, $prediction), $row->recommendations);
    }

    /**
     * ------------------------------------------------------------------------
     * Malformed JSON / zero valid recs → rule-based, still one call
     * ------------------------------------------------------------------------
     */
    public function test_generate_with_malformed_block_falls_back_to_rules(): void
    {
        $invalid = json_encode([['message' => 'no category or reason', 'priority' => 1]]);

        foreach (['{not json', $invalid] as $body) {
            Http::fake([self::GEMINI_URL => Http::response($this->geminiAnswer($this->combinedAnswer($body)))]);

            $project = $this->createProjectWithHistory(8);
            app(AIExplanationService::class)->generate($project);

            $row = AiSolution::where('project_id', $project->id)->latest('id')->first();
            $this->assertSame($this->sections(), $row->analysis_text);
            $this->assertNotEmpty($row->recommendations);
            foreach ($row->recommendations as $rec) {
                $this->assertArrayNotHasKey('ai_generated', $rec);
            }
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Too few observations → recommendations not asked for; the prompt is
     * exactly the original analysis prompt, and the engine's own answer wins
     * ------------------------------------------------------------------------
     */
    public function test_generate_does_not_ask_for_recs_when_engine_would_not_use_them(): void
    {
        Http::fake([self::GEMINI_URL => Http::response($this->geminiAnswer($this->combinedAnswer()))]);

        $project = $this->createProjectWithHistory(2);
        $prediction = app(AIPredictionService::class)->generatePrediction($project);
        $this->assertFalse(app(RecommendationEngine::class)->acceptsAiRecommendations($project, $prediction));

        app(AIExplanationService::class)->generate($project);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => !str_contains($request['contents'][0]['parts'][0]['text'], 'RECOMMENDATIONS'));

        // Even though the (fake) model wrote a block, the data-focused pair
        // the engine returns for young projects is what is saved
        $row = AiSolution::where('project_id', $project->id)->latest('id')->first();
        $this->assertSame((new RecommendationEngine())->generateRuleBased($project, $prediction), $row->recommendations);
        $this->assertStringNotContainsString('RECOMMENDATIONS_JSON', $row->analysis_text);
    }

    /**
     * ------------------------------------------------------------------------
     * Gemini failure → '' returned, nothing saved, no retry call
     * ------------------------------------------------------------------------
     */
    public function test_generate_on_gemini_failure_saves_nothing(): void
    {
        Http::fake([self::GEMINI_URL => Http::response(['error' => 'unavailable'], 503)]);

        $project = $this->createProjectWithHistory(8);

        $this->assertSame('', app(AIExplanationService::class)->generate($project));

        Http::assertSentCount(1);
        $this->assertSame(0, AiSolution::where('project_id', $project->id)->count());
    }

    /**
     * ------------------------------------------------------------------------
     * An answer that is ONLY the block → no analysis → '' (static fallback)
     * ------------------------------------------------------------------------
     */
    public function test_generate_with_only_a_block_saves_nothing(): void
    {
        Http::fake([self::GEMINI_URL => Http::response($this->geminiAnswer(
            AIExplanationService::RECS_OPEN . $this->recsJson() . AIExplanationService::RECS_CLOSE
        ))]);

        $project = $this->createProjectWithHistory(8);

        $this->assertSame('', app(AIExplanationService::class)->generate($project));
        $this->assertSame(0, AiSolution::where('project_id', $project->id)->count());
    }

    /*
    |==========================================================================
    | Regenerate endpoint — the whole path
    |==========================================================================
    */

    /**
     * ------------------------------------------------------------------------
     * "Regenerate Analysis" makes ONE Gemini call and returns both outputs
     * ------------------------------------------------------------------------
     *
     * Before the merge this path made two calls (analysis, then recs).
     */
    public function test_regenerate_endpoint_makes_one_call(): void
    {
        Http::fake([self::GEMINI_URL => Http::response($this->geminiAnswer($this->combinedAnswer()))]);

        $admin = User::create([
            'name'      => 'Regen Admin',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $project = $this->createProjectWithHistory(8);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.projects.regenerate-analysis', $project));

        $response->assertOk();
        Http::assertSentCount(1);

        $this->assertSame($this->sections(), $response->json('analysis_text'));
        $this->assertSame('AI rec one', $response->json('recommendations.0.message'));
        $this->assertStringNotContainsString('RECOMMENDATIONS_JSON', $response->getContent());
    }
}
