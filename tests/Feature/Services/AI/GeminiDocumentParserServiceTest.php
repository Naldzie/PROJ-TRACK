<?php

namespace Tests\Feature\Services\AI;

use App\Services\AI\GeminiDocumentParserService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ============================================================================
 * GeminiDocumentParserServiceTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Unit tests for the Tier-3 AI fallback service (AI-1). No real Gemini calls —
 * Http::fake() stands in for generateContent in every case.
 *
 * Covers: strict-JSON success shape (+ normalization + unknown-key drop),
 * fence-wrapped payloads, malformed/non-object payloads, HTTP failure,
 * empty API key (no request sent), all-null payloads, empty image lists,
 * and the 3-image free-tier cap.
 *
 * Run With
 * --------
 *   php artisan test --filter=GeminiDocumentParserServiceTest
 */
class GeminiDocumentParserServiceTest extends TestCase
{
    // Fake a successful generateContent call carrying the given raw text.
    // Returns the text carrier for request assertions.
    private function fakeSuccess(string $text): void
    {
        Http::fake([
            '*generativelanguage*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $text]]]],
                ],
            ], 200),
        ]);
    }

    private function sliceImages(int $n = 1): array
    {
        $out = [];

        for ($i = 0; $i < $n; $i++) {
            $out[] = ['data' => base64_encode('fake-png-bytes-' . $i), 'mimeType' => 'image/png'];
        }

        return $out;
    }

    // Strict-JSON success: validated shape, normalized dates/money/duration,
    // unknown keys dropped.
    // Called by: DocumentParserController::parseWithGemini() (planned AI-2).
    public function test_strict_json_success_returns_validated_shape(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'title' => 'Rehabilitation/Refurbishment of Canteen at Food Laboratory Building',
            'location' => 'Nabua Campus',
            'description' => 'Canteen works',
            'budget' => 'Php 4,323,196.77',
            'abc' => 4834297.59,
            'budgetSource' => 'GAA',
            'category' => 'Renovation',
            'startDate' => 'July 14, 2025',
            'endDate' => '2026-03-13',
            'duration' => '205 calendar days',
            'contractor' => 'SkyScraper Construction and Engineering Services',
            'notAField' => 'dropped',
        ]));

        $out = (new GeminiDocumentParserService())->parseProjectSlice($this->sliceImages());

        $this->assertNotNull($out);
        $this->assertSame('4834297.59', $out['abc']);
        $this->assertSame('4323196.77', $out['budget']);
        $this->assertSame('2025-07-14', $out['startDate']);
        $this->assertSame('2026-03-13', $out['endDate']);
        $this->assertSame('205', $out['duration']);
        $this->assertArrayNotHasKey('notAField', $out);
        $this->assertCount(11, $out);
    }

    // Gemini commonly wraps JSON in ```json fences — stripped before decode.
    // Called by: parseProjectSlice() fence-strip path.
    public function test_fence_wrapped_json_is_parsed(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess("```json\n{\"abc\": \"4834297.59\", \"title\": \"Canteen Works Project\"}\n```");

        $out = (new GeminiDocumentParserService())->parseProjectSlice($this->sliceImages());

        $this->assertNotNull($out);
        $this->assertSame('4834297.59', $out['abc']);
        $this->assertSame('Canteen Works Project', $out['title']);
    }

    // Malformed JSON, non-object JSON, and HTTP failures all decline (null).
    // Called by: parseProjectSlice() failure paths.
    public function test_malformed_and_failed_responses_decline(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $service = new GeminiDocumentParserService();

        $this->fakeSuccess('not json at all {{{');
        $this->assertNull($service->parseProjectSlice($this->sliceImages()));

        $this->fakeSuccess(json_encode(['just', 'a', 'list']));
        $this->assertNull($service->parseProjectSlice($this->sliceImages()));

        Http::fake(['*generativelanguage*' => Http::response('server boom', 500)]);
        $this->assertNull($service->parseProjectSlice($this->sliceImages()));
    }

    // Empty API key: no request is sent, null returns (graceful decline).
    // Called by: parseProjectSlice() empty-key guard.
    public function test_empty_key_sends_nothing_and_declines(): void
    {
        config(['services.gemini.key' => null]);

        $out = (new GeminiDocumentParserService())->parseProjectSlice($this->sliceImages());

        $this->assertNull($out);
        Http::assertNothingSent();
    }

    // All-null payloads and empty image lists decline (honest miss).
    // Called by: parseProjectSlice() empty guards.
    public function test_all_null_and_empty_images_decline(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $service = new GeminiDocumentParserService();

        $this->fakeSuccess(json_encode(array_fill_keys(GeminiDocumentParserService::FIELDS, null)));
        $this->assertNull($service->parseProjectSlice($this->sliceImages()));

        $this->assertNull($service->parseProjectSlice([]));
        $this->assertNull($service->parseProjectSlice([['data' => '']]));
    }

    // Free-tier guard: at most 3 images leave the server in one call.
    // Called by: parseProjectSlice() image cap.
    public function test_image_cap_sends_at_most_three_slices(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode(['title' => 'Canteen Works Project Title Here']));

        $out = (new GeminiDocumentParserService())->parseProjectSlice($this->sliceImages(5));

        $this->assertNotNull($out);

        Http::assertSent(function ($request) {
            $parts = $request->data()['contents'][0]['parts'] ?? [];

            // 1 text prompt + at most 3 inline_data slices.
            return count($parts) === 4;
        });
    }

    // Invalid field values normalize to null instead of leaking through.
    // Called by: validateParsed() normalizers.
    public function test_invalid_values_normalize_to_null(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'title' => 'x',
            'budget' => 'not an amount',
            'startDate' => 'sometime-ish',
            'duration' => '99999',
            'contractor' => 'SkyScraper Construction and Engineering Services Company',
        ]));

        $out = (new GeminiDocumentParserService())->parseProjectSlice($this->sliceImages());

        $this->assertNotNull($out);
        $this->assertNull($out['title']);
        $this->assertNull($out['budget']);
        $this->assertNull($out['startDate']);
        $this->assertNull($out['duration']);
        $this->assertSame('SkyScraper Construction and Engineering Services Company', $out['contractor']);
    }
}
