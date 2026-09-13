<?php

namespace Tests\Feature\Services\AI;

use App\Services\AI\GeminiDocumentParserService;
use App\Services\AI\GeminiTransportException;
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

    // Malformed JSON and non-object JSON decline (null).
    // Called by: parseProjectSlice() failure paths.
    public function test_malformed_and_failed_responses_decline(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $service = new GeminiDocumentParserService();

        $this->fakeSuccess('not json at all {{{');
        $this->assertNull($service->parseProjectSlice($this->sliceImages()));

        $this->fakeSuccess(json_encode(['just', 'a', 'list']));
        $this->assertNull($service->parseProjectSlice($this->sliceImages()));
    }

    // Transport failures speak (RuntimeException) instead of mimicking a miss.
    // Single fake: re-faking serves the first stub, so each status gets its own test.
    // Called by: postGemini() throw path.
    public function test_http_500_speaks_after_retry(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $service = new GeminiDocumentParserService();

        Http::fake(['*generativelanguage*' => Http::response('server boom', 500, ['Retry-After' => 0])]);
        try {
            $service->parseProjectSlice($this->sliceImages());
            $this->fail('Transport failures must speak, not decline silently.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be reached', $e->getMessage());
        }
    }

    // Bounded retry (user choice: 1 retry): a transient 503 then success heals.
    // Called by: postGemini() retryable path.
    public function test_transient_503_then_success_heals(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake(['*generativelanguage*' => Http::sequence()
            ->push('overloaded', 503, ['Retry-After' => 0])
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'months' => [
                    ['month_index' => 1, 'planned_progress_percent' => 50],
                    ['month_index' => 2, 'planned_progress_percent' => 100],
                ],
                'hasSummaryRow' => false,
            ])]]]]]], 200),
        ]);

        $out = (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");

        $this->assertNotNull($out);
        $this->assertCount(2, $out['months']);
    }

    // Sustained 503s speak the busy message (never silent, never generic-miss).
    // Called by: transportMessage() 503 branch.
    public function test_sustained_503_speaks_busy_message(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $service = new GeminiDocumentParserService();

        Http::fake(['*generativelanguage*' => Http::response('overloaded', 503, ['Retry-After' => 0])]);
        try {
            $service->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");
            $this->fail('Sustained overload must speak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('busy (overloaded)', $e->getMessage());
        }
    }

    // 429 gets its own voice (quota). Single fake per test (re-faking keeps
    // serving the first stub — proven 2026-09-10).
    // Called by: transportMessage() 429 branch.
    public function test_quota_exhaustion_speaks(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $service = new GeminiDocumentParserService();

        Http::fake(['*generativelanguage*' => Http::response('slow down', 429, ['Retry-After' => 0])]);
        try {
            $service->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");
            $this->fail('Quota exhaustion must speak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('quota', $e->getMessage());
        }
    }

    // 404 gets its own voice (dead model). Single fake per test (see above).
    // Called by: transportMessage() 404 branch.
    public function test_dead_model_speaks(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $service = new GeminiDocumentParserService();

        Http::fake(['*generativelanguage*' => Http::response('no such model', 404)]);
        try {
            $service->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");
            $this->fail('Dead models must speak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unavailable', $e->getMessage());
        }
    }

    // Normal-doc Lite fallback: parser-model 429 then Lite success returns a shape.
    // Single sequence fake (one fake per test — re-faking serves the first stub).
    // Called by: callGeminiWithImages() fallback branch.
    public function test_images_fallback_to_lite_on_quota(): void
    {
        config([
            'services.gemini.key' => 'main-key',
            'services.gemini.model' => 'lite-model',
            'services.gemini.parser_key' => 'parser-key',
            'services.gemini.parser_model' => 'flash-model',
        ]);

        Http::fake(['*generativelanguage*' => Http::sequence()
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'title' => 'Canteen Works Project Title Here',
            ])]]]]]], 200),
        ]);

        $service = new GeminiDocumentParserService();
        $out = $service->parseProjectSlice($this->sliceImages());

        $this->assertNotNull($out);
        $this->assertSame('Canteen Works Project Title Here', $out['title']);
        $this->assertSame('lite-model', $service->lastFallbackModel);

        $sent = Http::recorded();
        $this->assertCount(3, $sent);
        $this->assertStringContainsString('flash-model', $sent[0][0]->url());
        $this->assertStringContainsString('lite-model', $sent[2][0]->url());
    }

    // Excel never falls back (a Lite S-curve in a baseline is the known failure).
    // Called by: callGeminiText() — no fallback branch by design.
    public function test_excel_never_falls_back_to_lite(): void
    {
        config([
            'services.gemini.key' => 'main-key',
            'services.gemini.model' => 'lite-model',
            'services.gemini.parser_key' => 'parser-key',
            'services.gemini.parser_model' => 'flash-model',
        ]);

        Http::fake(['*generativelanguage*' => Http::response('slow down', 429, ['Retry-After' => 0])]);
        try {
            (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");
            $this->fail('Excel quota failures must speak, never Lite-fallback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('quota', $e->getMessage());
        }

        foreach (Http::recorded() as $pair) {
            $this->assertStringNotContainsString('lite-model', $pair[0]->url());
        }
    }

    // Auth failures never fall back either (Lite shares the project — same 401).
    // Called by: isFallbackEligible() exclusion.
    public function test_images_no_fallback_on_auth_failure(): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake(['*generativelanguage*' => Http::response('bad key', 401)]);
        try {
            (new GeminiDocumentParserService())->parseProjectSlice($this->sliceImages());
            $this->fail('Auth failures must speak, never Lite-fallback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be reached', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    // Quota-kind handling: per-DAY exhaustion fails fast with a tomorrow message —
    // no retry burned on a meter that refills at midnight.
    // Called by: extractQuotaId() + isPerDayQuota() + transportMessage().
    public function test_per_day_quota_fails_fast_with_tomorrow_message(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $service = new GeminiDocumentParserService();

        Http::fake(['*generativelanguage*' => Http::response(json_encode([
            'error' => [
                'code' => 429,
                'status' => 'RESOURCE_EXHAUSTED',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
                    'violations' => [[
                        'quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier',
                    ]],
                ]],
            ],
        ]), 429)]);
        try {
            $service->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");
            $this->fail('Per-day exhaustion must speak tomorrow-message.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tomorrow', $e->getMessage());
        }
        Http::assertSentCount(1);
    }

    // Per-minute quota keeps the backoff retry (yesterday's storm heals this way).
    // Called by: postGemini() retry path with quotaId present but not per-day.
    public function test_per_minute_quota_still_retries(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $quotaBody = json_encode([
            'error' => [
                'code' => 429,
                'status' => 'RESOURCE_EXHAUSTED',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
                    'violations' => [[
                        'quotaId' => 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier',
                    ]],
                ]],
            ],
        ]);
        Http::fake(['*generativelanguage*' => Http::sequence()
            ->push($quotaBody, 429, ['Retry-After' => 0])
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'months' => [
                    ['month_index' => 1, 'planned_progress_percent' => 50],
                    ['month_index' => 2, 'planned_progress_percent' => 100],
                ],
                'hasSummaryRow' => false,
            ])]]]]]], 200),
        ]);

        $out = (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");

        $this->assertNotNull($out);
        $this->assertCount(2, $out['months']);
    }

    // Empty API key: no request is sent, null returns (graceful decline).
    // Called by: parseProjectSlice() empty-key guard.
    public function test_empty_key_sends_nothing_and_declines(): void
    {
        // Null BOTH keys (live .env leaks its real key into parser_key at boot —
        // nulling one still sends over the other, hitting real network).
        config(['services.gemini.key' => null, 'services.gemini.parser_key' => null]);

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

    // E2 Excel slice: months JSON validates + sanitizes (monotonic clamp,
    // force-last-100, contract sanity, unknown keys dropped).
    // Called by: SCurveImportController::parseWithGemini() (planned E2 endpoint).
    public function test_excel_slice_success_sanitizes_curve(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 30],
                ['month_index' => 3, 'planned_progress_percent' => 99.5],
            ],
            'contractAmount' => '2,239,599.72',
            'bogus' => 'dropped',
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");

        $this->assertNotNull($out);
        $this->assertCount(3, $out['months']);
        $this->assertSame(50.0, $out['months'][0]['planned_progress_percent']);
        $this->assertSame(50.0, $out['months'][1]['planned_progress_percent']);
        $this->assertSame(100.0, $out['months'][2]['planned_progress_percent']);
        $this->assertSame(2239599.72, $out['meta']['contractAmount']);
        $this->assertNotEmpty($out['meta']['warnings']);
    }

    // E2 parity (2026-09-09): two-series AI output keeps the activity series
    // and warns past 1.0 gaps against the author's summary (mirrors 2b).
    // Called by: validateSCurveMonths() reference branch.
    public function test_excel_slice_warns_on_summary_gap(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 70],
                ['month_index' => 3, 'planned_progress_percent' => 100],
            ],
            'summary' => [
                ['month_index' => 1, 'percent' => 50],
                ['month_index' => 2, 'percent' => 45],
                ['month_index' => 3, 'percent' => 100],
            ],
            'contractAmount' => 1000000,
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice("WORK DETAILS,P1\nWorks,50000");

        $this->assertNotNull($out);
        // Activity series kept (month 2 stays 70, not the summary's 45).
        $this->assertSame(70.0, $out['months'][1]['planned_progress_percent']);
        $this->assertStringContainsString(
            "Month 2",
            implode("\n", $out['meta']['warnings'])
        );
        $this->assertStringContainsString('45%', implode("\n", $out['meta']['warnings']));
    }

    // Agreeing summary stays silent (gap ≤1.0 is not worth a look).
    // Called by: validateSCurveMonths() reference branch.
    public function test_excel_slice_silent_when_summary_agrees(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'summary' => [
                ['month_index' => 1, 'percent' => 50.5],
                ['month_index' => 2, 'percent' => 100],
            ],
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice("WORK DETAILS,P1\nWorks,50000");

        $this->assertNotNull($out);
        $this->assertCount(1, $out['meta']['warnings']);
    }

    // No usable activity values: the summary is adopted as the curve, flagged.
    // Called by: validateSCurveMonths() fallback branch (mirrors rules).
    public function test_excel_slice_adopts_summary_without_activity_values(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 10],
            ],
            'summary' => [
                ['month_index' => 1, 'percent' => 45],
                ['month_index' => 2, 'percent' => 60],
                ['month_index' => 3, 'percent' => 100],
            ],
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice("WORK DETAILS,P1\nWorks,50000");

        $this->assertNotNull($out);
        $this->assertSame(45.0, $out['months'][0]['planned_progress_percent']);
        $this->assertSame(60.0, $out['months'][1]['planned_progress_percent']);
        $this->assertSame(100.0, $out['months'][2]['planned_progress_percent']);
        $this->assertStringContainsString(
            'own summary column',
            implode("\n", $out['meta']['warnings'])
        );
    }

    // Neither series usable: honest decline (summary with <2 points is not a curve).
    // Called by: validateSCurveMonths() decline path.
    public function test_excel_slice_declines_when_both_series_unusable(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $service = new GeminiDocumentParserService();

        $this->fakeSuccess(json_encode([
            'months' => [['month_index' => 1, 'planned_progress_percent' => 10]],
            'summary' => [['month_index' => 1, 'percent' => 45]],
        ]));
        $this->assertNull($service->parseExcelSlice('WORK DETAILS,P1'));

        $this->fakeSuccess(json_encode(['bogus' => true]));
        $this->assertNull($service->parseExcelSlice('WORK DETAILS,P1'));
    }

    // Strict homework: months that don't follow from the stated amounts are
    // declined as fabrication (needs no layout knowledge — pure arithmetic).
    // Called by: monthsFollowAmounts() recompute check.
    public function test_excel_slice_declines_fabricated_months(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $service = new GeminiDocumentParserService();

        // Stated 70% but stated amounts (50k+50k of 1M) recompute to 10%.
        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 5],
                ['month_index' => 2, 'planned_progress_percent' => 70],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 50000],
                ['month_index' => 2, 'pesos' => 50000],
            ],
            'contractAmount' => 1000000,
        ]));
        $this->assertNull($service->parseExcelSlice('WORK DETAILS,P1'));
    }

    // Consistent homework passes the recompute (pre-sanitizer values checked,
    // so our own clamp/force never punishes honest output).
    // Called by: monthsFollowAmounts() via extractRawPoints().
    public function test_excel_slice_passes_consistent_homework(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 30],
                ['month_index' => 3, 'planned_progress_percent' => 99.5],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 200000],
                ['month_index' => 2, 'pesos' => -99999],
                ['month_index' => 3, 'pesos' => 198000],
            ],
            'contractAmount' => 400000,
        ]));

        // Month 2's bogus negative amount is dropped → partial amounts skip
        // the recompute; the claimed 200000/198000 are really in the sheet,
        // so the presence audit passes too (weight-file safe).
        $out = (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1\nWorks,200000\nMore,198000");

        $this->assertNotNull($out);
        $this->assertCount(3, $out['months']);
    }

    // Months mirroring an attached summary are labeled, not passed silently.
    // Called by: validateSCurveMonths() mirror branch.
    public function test_excel_slice_labels_summary_mirror(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 45],
                ['month_index' => 2, 'planned_progress_percent' => 60],
                ['month_index' => 3, 'planned_progress_percent' => 100],
            ],
            'summary' => [
                ['month_index' => 1, 'percent' => 45],
                ['month_index' => 2, 'percent' => 60],
                ['month_index' => 3, 'percent' => 100],
            ],
            'hasSummaryRow' => true,
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice("WORK DETAILS,P1\nWorks,50000");

        $this->assertNotNull($out);
        $this->assertSame(45.0, $out['months'][0]['planned_progress_percent']);
        $this->assertStringContainsString(
            'could not be independently verified',
            implode("\n", $out['meta']['warnings'])
        );
    }

    // Claimed-but-unshown summary row earns an explicit unverified note.
    // Called by: validateSCurveMonths() hasSummaryRow branch.
    public function test_excel_slice_notes_uncompared_summary(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'hasSummaryRow' => true,
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice("WORK DETAILS,P1\nWorks,50000");

        $this->assertNotNull($out);
        $this->assertStringContainsString(
            'could not be compared',
            implode("\n", $out['meta']['warnings'])
        );
    }

    // Universal verdict: full consistent homework earns a checked confirmation.
    // Called by: validateSCurveMonths() amounts coverage branch.
    public function test_excel_slice_confirms_verified_homework(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 200000],
                ['month_index' => 2, 'pesos' => 200000],
            ],
            'contractAmount' => 400000,
            'hasSummaryRow' => false,
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1\nWorks,200000\nMore,200000");

        $this->assertNotNull($out);
        $this->assertStringContainsString(
            'Checked:',
            implode("\n", $out['meta']['warnings'])
        );
    }

    // Universal verdict: months-only replies are labeled single-series, never silent.
    // Called by: validateSCurveMonths() unverified branch.
    public function test_excel_slice_labels_single_series_reply(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 45],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'contractAmount' => 1000000,
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice("WORK DETAILS,P1\nWorks,50000");

        $this->assertNotNull($out);
        $this->assertSame(45.0, $out['months'][0]['planned_progress_percent']);
        $this->assertStringContainsString(
            'could not be cross-checked',
            implode("\n", $out['meta']['warnings'])
        );
    }

    // Slice-truth presence audit: internally-consistent homework for a copied
    // summary is declined when a claimed peso appears nowhere in the sheet
    // (the phantom 150,000 behind the 45-series).
    // Called by: amountsPresentInSheet() gate.
    public function test_excel_slice_declines_phantom_amounts(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $service = new GeminiDocumentParserService();

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 45],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 450000],
                ['month_index' => 2, 'pesos' => 550000],
            ],
            'contractAmount' => 1000000,
            'hasSummaryRow' => true,
        ]));
        // Internally consistent (45 → 100) but 450000/550000 are in no cell.
        $this->assertNull($service->parseExcelSlice("WORK DETAILS,P1,P2\nWorks,50000,50000"));
    }

    // Presence audit accepts adjacent-pair sums (paired layouts store halves).
    // Called by: extractSheetNumbers() pair sums.
    public function test_excel_slice_accepts_pair_sum_amounts(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 200000],
                ['month_index' => 2, 'pesos' => 200000],
            ],
            'contractAmount' => 400000,
            'hasSummaryRow' => false,
        ]));

        // Halves only in cells; the 200000 monthly totals exist as pair sums.
        $out = (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1,Month 1b,Month 2,Month 2b\nWorks,100000,100000,100000,100000");

        $this->assertNotNull($out);
        $this->assertStringContainsString(
            'Checked:',
            implode("\n", $out['meta']['warnings'])
        );
    }

    // Schema enforcement: homework format (months + hasSummaryRow required)
    // rides the wire so minimal replies fail closed at the protocol level.
    // Called by: callGeminiText() generationConfig.
    public function test_excel_slice_sends_response_schema(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'hasSummaryRow' => false,
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");

        $this->assertNotNull($out);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $schema = $body['generationConfig']['responseSchema'] ?? null;
            // Temperature rides as "0" over form-encoding — presence + zero value.
            $temp = $body['generationConfig']['temperature'] ?? null;

            return ($body['generationConfig']['responseMimeType'] ?? null) === 'application/json'
                && $temp !== null && $temp == 0
                && is_array($schema)
                && ($schema['required'] ?? []) === ['months', 'hasSummaryRow'];
        });
    }

    // Native workbook bytes ride alongside the tagged CSV (user-approved:
    // public schedule data; the webapp proved native-grid reads compute).
    // Called by: parseExcelSlice() file passthrough → callGeminiText().
    public function test_excel_slice_sends_workbook_file_part(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'hasSummaryRow' => false,
        ]));

        $file = ['data' => base64_encode('PKfake-bytes'), 'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        $out = (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000", $file);

        $this->assertNotNull($out);

        Http::assertSent(function ($request) use ($file) {
            $parts = $request->data()['contents'][0]['parts'] ?? [];

            return count($parts) === 2
                && ($parts[1]['inline_data']['mime_type'] ?? null) === $file['mimeType']
                && ($parts[1]['inline_data']['data'] ?? null) === $file['data'];
        });
    }

    // Text-only calls send no file part (oversize fallback path).
    // Called by: parseExcelSlice() with a null workbook file.
    public function test_excel_slice_without_file_sends_text_only(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'hasSummaryRow' => false,
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000", null);

        $this->assertNotNull($out);

        Http::assertSent(function ($request) {
            $parts = $request->data()['contents'][0]['parts'] ?? [];

            return count($parts) === 1 && isset($parts[0]['text']);
        });
    }

    // E2 declines: empty text/key, malformed payloads, single-month curves.
    // Called by: parseExcelSlice() failure paths.
    public function test_excel_slice_decline_paths(): void
    {
        $service = new GeminiDocumentParserService();

        // Empty keys first (nothing may be sent before this assertion — null BOTH,
        // since the live .env leaks its real key into parser_key at boot).
        config(['services.gemini.key' => null, 'services.gemini.parser_key' => null]);
        $this->assertNull($service->parseExcelSlice('DESCRIPTION,Month 1'));
        Http::assertNothingSent();

        config(['services.gemini.key' => 'test-key']);

        $this->assertNull($service->parseExcelSlice(''));
        $this->assertNull($service->parseExcelSlice('   '));

        $this->fakeSuccess('no json here');
        $this->assertNull($service->parseExcelSlice('DESCRIPTION,Month 1'));

        $this->fakeSuccess(json_encode(['months' => [['month_index' => 1, 'planned_progress_percent' => 50]]]));
        $this->assertNull($service->parseExcelSlice('DESCRIPTION,Month 1'));

        $this->fakeSuccess(json_encode(['months' => []]));
        $this->assertNull($service->parseExcelSlice('DESCRIPTION,Month 1'));
    }

    // E3 seam: parser key + model ride the wire (both senders — one fake per
    // test, since re-faking keeps serving the first stub).
    // Called by: callGeminiText(), callGeminiWithImages() via parserKey()/parserModel().
    public function test_parser_credentials_ride_the_excel_wire(): void
    {
        config([
            'services.gemini.key' => 'main-key',
            'services.gemini.model' => 'main-model',
            'services.gemini.parser_key' => 'parser-key',
            'services.gemini.parser_model' => 'gemini-2.5-flash',
        ]);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'hasSummaryRow' => false,
        ]));
        $out = (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");
        $this->assertNotNull($out);

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, 'key=parser-key')
                && str_contains($url, 'gemini-2.5-flash')
                && !str_contains($url, 'main-key');
        });
    }

    // E3 seam on the images sender (normal-doc slices use the same credentials).
    // Called by: callGeminiWithImages() via parserKey()/parserModel().
    public function test_parser_credentials_ride_the_images_wire(): void
    {
        config([
            'services.gemini.key' => 'main-key',
            'services.gemini.model' => 'main-model',
            'services.gemini.parser_key' => 'parser-key',
            'services.gemini.parser_model' => 'gemini-2.5-flash',
        ]);

        $this->fakeSuccess(json_encode(['title' => 'Canteen Works Project Title Here']));
        $out = (new GeminiDocumentParserService())->parseProjectSlice($this->sliceImages());
        $this->assertNotNull($out);

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, 'key=parser-key')
                && str_contains($url, 'gemini-2.5-flash')
                && !str_contains($url, 'main-key');
        });
    }

    // E3 fallback: missing parser env degrades to the shared credentials.
    // Called by: parserKey()/parserModel() runtime fallback.
    public function test_parser_credentials_fall_back_to_shared(): void
    {
        config([
            'services.gemini.key' => 'main-key',
            'services.gemini.model' => 'main-model',
            'services.gemini.parser_key' => null,
            'services.gemini.parser_model' => null,
        ]);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'hasSummaryRow' => false,
        ]));
        $out = (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");
        $this->assertNotNull($out);

        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, 'key=main-key')
                && str_contains($url, 'main-model');
        });
    }

    // B-1 layout passthrough: reported columns ride the result for derivation,
    // with activity/mirror flags the derivation trusts for skipping.
    // Called by: validateSCurveMonths() layout block.
    public function test_excel_slice_passes_layout_through(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'hasSummaryRow' => false,
            'headerRow' => 2,
            'monthColumns' => [
                ['month_index' => 1, 'columns' => ['F']],
                ['month_index' => 2, 'columns' => ['G', 'H']],
            ],
            'descCol' => 'B',
            'contractCol' => 'E',
            'bogusLayout' => 'dropped',
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");

        $this->assertNotNull($out);
        $this->assertTrue($out['layout']['activityBased']);
        $this->assertFalse($out['layout']['mirrored']);
        $this->assertSame(2, $out['layout']['headerRow']);
        $this->assertSame(['F'], $out['layout']['monthColumns'][1]);
        $this->assertSame(['G', 'H'], $out['layout']['monthColumns'][2]);
        $this->assertSame('B', $out['layout']['descCol']);
        $this->assertSame('E', $out['layout']['contractCol']);
        $this->assertArrayNotHasKey('bogusLayout', $out['layout']);
    }

    // Adopted curves carry activityBased=false so derivation refuses them.
    // Called by: validateSCurveMonths() fallback layout block.
    public function test_excel_slice_marks_adopted_layout_unlearnable(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [['month_index' => 1, 'planned_progress_percent' => 10]],
            'summary' => [
                ['month_index' => 1, 'percent' => 45],
                ['month_index' => 2, 'percent' => 100],
            ],
            'headerRow' => 2,
            'monthColumns' => [
                ['month_index' => 1, 'columns' => ['F']],
                ['month_index' => 2, 'columns' => ['G']],
            ],
            'descCol' => 'B',
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice("WORK DETAILS,P1\nWorks,50000");

        $this->assertNotNull($out);
        $this->assertFalse($out['layout']['activityBased']);
    }

    // F-A PDF slice success: months + printed names + checked verdict + labels.
    // Called by: parseSCurvePdfSlice() activity branch.
    public function test_pdf_slice_success_returns_months_names_and_checked_verdict(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $words = ['SCHEDULED TASKS STAGE 1 Mobilization Works Earthworks and Excavation '
            . 'Concrete and Masonry Works Finishing and Painting Works'];

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 5, 'activities' => ['Mobilization Works']],
                ['month_index' => 2, 'planned_progress_percent' => 20, 'activities' => ['Mobilization Works', 'Earthworks and Excavation']],
                ['month_index' => 3, 'planned_progress_percent' => 40, 'activities' => ['Earthworks and Excavation']],
                ['month_index' => 4, 'planned_progress_percent' => 60, 'activities' => ['Earthworks and Excavation', 'Concrete and Masonry Works']],
                ['month_index' => 5, 'planned_progress_percent' => 80, 'activities' => ['Concrete and Masonry Works']],
                ['month_index' => 6, 'planned_progress_percent' => 100, 'activities' => ['Finishing and Painting Works']],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 60000],
                ['month_index' => 2, 'pesos' => 180000],
                ['month_index' => 3, 'pesos' => 240000],
                ['month_index' => 4, 'pesos' => 240000],
                ['month_index' => 5, 'pesos' => 240000],
                ['month_index' => 6, 'pesos' => 240000],
            ],
            'hasSummaryRow' => false,
            'contractAmount' => 1200000,
            'monthLabels' => [
                ['month_index' => 1, 'label' => 'STAGE 1'],
                ['month_index' => 2, 'label' => 'STAGE 2'],
            ],
        ]));

        $out = (new GeminiDocumentParserService())->parseSCurvePdfSlice($this->sliceImages(2), $words);

        $this->assertNotNull($out);
        $this->assertCount(6, $out['months']);
        $this->assertSame(['Mobilization Works'], $out['months'][0]['activities']);
        $this->assertSame(['Mobilization Works', 'Earthworks and Excavation'], $out['months'][1]['activities']);
        $this->assertSame(1200000.0, $out['meta']['contractAmount']);
        $this->assertContains('AI-assisted curve — verify before locking the baseline.', $out['meta']['warnings']);
        $this->assertContains('Checked: these months follow from the stated monthly amounts.', $out['meta']['warnings']);
        $this->assertSame('STAGE 1', $out['monthLabels'][1]);
        $this->assertSame('STAGE 2', $out['monthLabels'][2]);
    }

    // F-A hallucinated names drop while the month survives (user: drop, generic).
    // Called by: validatePdfCurve() names block.
    public function test_pdf_slice_drops_hallucinated_names_but_keeps_months(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50, 'activities' => ['Mobilization Works', 'Skyhook Assembly']],
                ['month_index' => 2, 'planned_progress_percent' => 100, 'activities' => ['Mobilization Works']],
            ],
            'hasSummaryRow' => false,
        ]));

        $out = (new GeminiDocumentParserService())->parseSCurvePdfSlice(
            $this->sliceImages(1),
            ['Mobilization Works P1 P2']
        );

        $this->assertNotNull($out);
        $this->assertSame(['Mobilization Works'], $out['months'][0]['activities']);
        $this->assertSame(['Mobilization Works'], $out['months'][1]['activities']);
    }

    // F-A diverging summary warns (same plain wording as Excel) without moving months.
    // Called by: validatePdfCurve() summary block.
    public function test_pdf_slice_warns_on_summary_gap(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 50000],
                ['month_index' => 2, 'pesos' => 50000],
            ],
            'summary' => [
                ['month_index' => 1, 'percent' => 45],
                ['month_index' => 2, 'percent' => 100],
            ],
            'hasSummaryRow' => true,
            'contractAmount' => 100000,
        ]));

        $out = (new GeminiDocumentParserService())->parseSCurvePdfSlice($this->sliceImages(1));

        $this->assertNotNull($out);
        $this->assertSame(50.0, $out['months'][0]['planned_progress_percent']);
        $warned = false;
        foreach ($out['meta']['warnings'] as $w) {
            if (str_contains($w, 'Heads-up (Month 1)') && str_contains($w, '45%')) {
                $warned = true;
            }
        }
        $this->assertTrue($warned);
    }

    // F-A fabricated homework declines: amounts cover months but months don't follow.
    // Called by: validatePdfCurve() recompute block.
    public function test_pdf_slice_declines_fabricated_homework(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 90],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 10000],
                ['month_index' => 2, 'pesos' => 10000],
            ],
            'hasSummaryRow' => false,
            'contractAmount' => 100000,
        ]));

        $out = (new GeminiDocumentParserService())->parseSCurvePdfSlice($this->sliceImages(1));

        $this->assertNull($out);
    }

    // F-A empty inputs decline without sending (both credential paths nulled).
    // Called by: parseSCurvePdfSlice() guards.
    public function test_pdf_slice_empty_images_and_key_decline(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->assertNull((new GeminiDocumentParserService())->parseSCurvePdfSlice([]));

        config(['services.gemini.key' => null, 'services.gemini.parser_key' => null]);
        $this->fakeSuccess('{"months": []}');
        $this->assertNull((new GeminiDocumentParserService())->parseSCurvePdfSlice($this->sliceImages(1)));
        Http::assertNothingSent();
    }

    // F-A malformed JSON declines.
    // Called by: parseSCurvePdfSlice() decode block.
    public function test_pdf_slice_malformed_json_declines(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess('not json at all {{{');
        $this->assertNull((new GeminiDocumentParserService())->parseSCurvePdfSlice($this->sliceImages(1)));
    }

    // F-A thin replies decline (<2 months and <2 summary).
    // Called by: validatePdfCurve() tail.
    public function test_pdf_slice_thin_reply_declines(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [['month_index' => 1, 'planned_progress_percent' => 10]],
            'hasSummaryRow' => false,
        ]));
        $this->assertNull((new GeminiDocumentParserService())->parseSCurvePdfSlice($this->sliceImages(1)));
    }

    // F-A image cap: 4 slices in, 3 image parts on the wire (free-tier guard).
    // Called by: parseSCurvePdfSlice() slice block.
    public function test_pdf_slice_caps_images_at_three(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'hasSummaryRow' => false,
        ]));

        $out = (new GeminiDocumentParserService())->parseSCurvePdfSlice($this->sliceImages(4));
        $this->assertNotNull($out);

        Http::assertSent(function ($request) {
            $parts = $request->data()['contents'][0]['parts'] ?? [];

            return count($parts) === 4;
        });
    }

    // F-A summary adoption: no activity values + ≥2 summary points adopts flagged.
    // Called by: validatePdfCurve() fallback block.
    public function test_pdf_slice_adopts_summary_when_no_activity(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [['month_index' => 1, 'planned_progress_percent' => 10]],
            'summary' => [
                ['month_index' => 1, 'percent' => 45],
                ['month_index' => 2, 'percent' => 100],
            ],
            'hasSummaryRow' => true,
        ]));

        $out = (new GeminiDocumentParserService())->parseSCurvePdfSlice($this->sliceImages(1));

        $this->assertNotNull($out);
        $this->assertSame(45.0, $out['months'][0]['planned_progress_percent']);
        $this->assertSame([], $out['months'][0]['activities']);
        $flagged = false;
        foreach ($out['meta']['warnings'] as $w) {
            if (str_contains($w, 'own summary column')) {
                $flagged = true;
            }
        }
        $this->assertTrue($flagged);
    }

    // PDF path never falls back to Lite (Flash-or-nothing, user 2026-09-11):
    // sustained overload throws the mapped voice with no third (Lite) call.
    // Called by: parseSCurvePdfSlice() via callGeminiWithImages(..., false).
    public function test_pdf_slice_never_falls_back_to_lite(): void
    {
        config([
            'services.gemini.key' => 'main-key',
            'services.gemini.model' => 'lite-model',
            'services.gemini.parser_key' => 'parser-key',
            'services.gemini.parser_model' => 'flash-model',
        ]);

        Http::fake(['*generativelanguage*' => Http::sequence()
            ->push('overloaded', 503, ['Retry-After' => 0])
            ->push('overloaded', 503, ['Retry-After' => 0]),
        ]);

        $service = new GeminiDocumentParserService();

        try {
            $service->parseSCurvePdfSlice($this->sliceImages(1));
            $this->fail('Expected a transport exception, got a decline.');
        } catch (GeminiTransportException $e) {
            $this->assertStringContainsString('busy (overloaded)', $e->getMessage());
        }

        // Initial + one retry only — a Lite rescue would be a third request.
        Http::assertSentCount(2);
        $this->assertNull($service->lastFallbackModel);
    }

    // F-A fence-wrapped payloads still parse.
    // Called by: parseSCurvePdfSlice() via parseJsonObject().
    public function test_pdf_slice_fence_wrapped_json_parses(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess("```json\n" . json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'hasSummaryRow' => false,
        ]) . "\n```");

        $out = (new GeminiDocumentParserService())->parseSCurvePdfSlice($this->sliceImages(1));
        $this->assertNotNull($out);
        $this->assertCount(2, $out['months']);
    }

    // Figures-vs-page (2026-09-12): a monthly peso printed nowhere on the page
    // earns a WARNING, never a decline — the Excel path declines, but on a PDF a
    // month summing several activity rows often has no printed total and throwing
    // the read away would cost more than it saves.
    // Called by: validatePdfCurve() via extractPrintedNumbers().
    public function test_pdf_slice_warns_when_a_monthly_figure_is_not_printed(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 25],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            // Internally consistent (50k → 25%, +150k → 100% of 200k) so the
            // recompute check passes; 50,000 is printed, 150,000 is not.
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 50000],
                ['month_index' => 2, 'pesos' => 150000],
            ],
            'contractAmount' => 200000,
            'hasSummaryRow' => false,
        ]));

        $out = (new GeminiDocumentParserService())->parseSCurvePdfSlice(
            $this->sliceImages(1),
            ['Mobilization Works 50,000 TOTAL 200,000']
        );

        $this->assertNotNull($out, 'an unprintable figure must never decline the read');
        $this->assertCount(2, $out['months'], 'the months must survive the warning');
        $this->assertTrue(
            collect($out['meta']['warnings'])->contains(
                fn ($w) => str_contains($w, 'Month 2') && str_contains($w, 'printed on the page')
            ),
            'the unmatched month must be named'
        );
        $this->assertFalse(
            collect($out['meta']['warnings'])->contains(fn ($w) => str_contains($w, 'Month 1')),
            'a printed figure must not be flagged'
        );
    }

    // No page text (a scanned slice with no readable words) = missing evidence,
    // not evidence of fabrication. The check stays silent.
    // Called by: validatePdfCurve() figures-vs-page branch.
    public function test_pdf_slice_figure_check_stays_silent_without_page_text(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 25],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 50000],
                ['month_index' => 2, 'pesos' => 150000],
            ],
            'contractAmount' => 200000,
            'hasSummaryRow' => false,
        ]));

        $out = (new GeminiDocumentParserService())->parseSCurvePdfSlice($this->sliceImages(1), []);

        $this->assertNotNull($out);
        $this->assertFalse(
            collect($out['meta']['warnings'])->contains(fn ($w) => str_contains($w, 'printed on the page')),
            'with nothing to check against, say nothing'
        );
    }

    // Salvage seam: the layout of an answer whose NUMBERS were refused is handed
    // out so the controller can read the sheet itself. It must be set ONLY by the
    // fabrication guards — never by a malformed reply, or a salvage could ride on
    // an answer that was never understood at all.
    // Called by: SCurveImportController::salvageRejectedAnswer().
    public function test_rejected_layout_is_exposed_only_for_fabricated_numbers(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $service = new GeminiDocumentParserService();

        // Internally consistent but 450000/550000 appear in no cell.
        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 45],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 450000],
                ['month_index' => 2, 'pesos' => 550000],
            ],
            'contractAmount' => 1000000,
            'hasSummaryRow' => false,
            'headerRow' => 1,
            'monthColumns' => [
                ['month_index' => 1, 'columns' => ['B']],
                ['month_index' => 2, 'columns' => ['C']],
            ],
            'descCol' => 'A',
        ]));

        $this->assertNull($service->parseExcelSlice("WORK DETAILS,P1,P2\nWorks,50000,50000"));
        $this->assertIsArray($service->lastRejectedLayout, 'the columns must survive the refusal');
        $this->assertSame('A', $service->lastRejectedLayout['descCol'] ?? null);
    }

    // The other half of that guarantee, in its own test because Http::fake keeps
    // the FIRST matching stub — a second fake in one test would never apply.
    // Called by: SCurveImportController::salvageRejectedAnswer().
    public function test_rejected_layout_stays_null_for_a_malformed_reply(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $service = new GeminiDocumentParserService();

        $this->fakeSuccess('not json at all');

        $this->assertNull($service->parseExcelSlice("WORK DETAILS,P1\nWorks,50000"));
        $this->assertNull($service->lastRejectedLayout, 'a malformed reply must not enable a salvage');
    }

    // GAP 1 ROOT CAUSE — the Excel path sends a responseSchema, and structured
    // output drops every undeclared key, so asking the prompt for "activities"
    // was futile until the schema declared it. Every other test here feeds decoded
    // JSON straight to the validator, which is exactly how this hid for a day:
    // this one inspects what actually goes OUT on the wire.
    // Called by: callGeminiText() via scurveResponseSchema().
    public function test_excel_request_schema_allows_activity_names_on_months_only(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'hasSummaryRow' => false,
        ]));

        (new GeminiDocumentParserService())->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");

        Http::assertSent(function ($request) {
            $schema = $request->data()['generationConfig']['responseSchema'] ?? null;
            if (!is_array($schema)) {
                return false;
            }
            $months = $schema['properties']['months']['items']['properties'] ?? [];
            $amounts = $schema['properties']['monthlyAmounts']['items']['properties'] ?? [];
            $summary = $schema['properties']['summary']['items']['properties'] ?? [];

            return array_key_exists('activities', $months)
                // The other two series share the point builder and must stay clean.
                && !array_key_exists('activities', $amounts)
                && !array_key_exists('activities', $summary)
                // Names are optional: a reply without them is still a valid curve.
                && !in_array('activities', $schema['properties']['months']['items']['required'] ?? [], true);
        });
    }

    // GAP 1 — the Excel prompt now asks for activity names, and names printed in
    // the sheet ride along with their month (parity with the PDF path).
    // Called by: parseExcelSlice() via validateSCurveMonths().
    public function test_excel_slice_keeps_activity_names_printed_in_the_sheet(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50, 'activities' => ['Mobilization Works']],
                ['month_index' => 2, 'planned_progress_percent' => 100, 'activities' => ['Earthworks and Excavation']],
            ],
            'hasSummaryRow' => false,
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice(
            "DESCRIPTION,Month 1,Month 2\nMobilization Works,30000,0\nEarthworks and Excavation,0,30000"
        );

        $this->assertNotNull($out);
        $this->assertSame(['Mobilization Works'], $out['months'][0]['activities']);
        $this->assertSame(['Earthworks and Excavation'], $out['months'][1]['activities']);
    }

    // Names the sheet never prints are dropped; the curve is untouched by the
    // drop (names ride, numbers decide) and the client falls back to generics.
    // Called by: parseExcelSlice() via sanitizeActivityNames().
    public function test_excel_slice_drops_activity_names_absent_from_the_sheet(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $this->fakeSuccess(json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 50, 'activities' => ['Helicopter Landing Pad']],
                ['month_index' => 2, 'planned_progress_percent' => 100, 'activities' => ['Mobilization Works']],
            ],
            'hasSummaryRow' => false,
        ]));

        $out = (new GeminiDocumentParserService())->parseExcelSlice(
            "DESCRIPTION,Month 1,Month 2\nMobilization Works,30000,30000"
        );

        $this->assertNotNull($out);
        $this->assertSame([], $out['months'][0]['activities'], 'invented name must not survive');
        $this->assertSame(['Mobilization Works'], $out['months'][1]['activities']);
        $this->assertSame(50.0, $out['months'][0]['planned_progress_percent'], 'a dropped name must not move the curve');
        $this->assertSame(100.0, $out['months'][1]['planned_progress_percent']);
    }
    /* ─── E4 parser fallback ladder (2026-09-13) ─────────────────────────── */

    // The minimum reply the PDF validator accepts, for the ladder tests below.
    // Two months following their own stated amounts against a real contract.
    private function pdfLadderReply(): string
    {
        return json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 40],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 400000],
                ['month_index' => 2, 'pesos' => 600000],
            ],
            'hasSummaryRow' => false,
            'contractAmount' => 1000000,
        ]);
    }

    private function configureLadder(string $chain): void
    {
        config([
            'services.gemini.key' => 'main-key',
            'services.gemini.model' => 'lite-model',
            'services.gemini.parser_key' => 'parser-key',
            'services.gemini.parser_model' => 'flash-model',
            'services.gemini.parser_fallback_models' => $chain,
        ]);
    }

    // A per-model quota is exactly what a VERSION rung survives: meters are per
    // model, so the next version answers where the primary cannot.
    // Called by: parseSCurvePdfSlice() via callGeminiWithImages() + parserFallbackModels().
    public function test_pdf_slice_falls_back_down_the_parser_ladder(): void
    {
        $this->configureLadder('older-flash');

        Http::fake(['*generativelanguage*' => Http::sequence()
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push(['candidates' => [['content' => ['parts' => [['text' => $this->pdfLadderReply()]]]]]], 200),
        ]);

        $service = new GeminiDocumentParserService();
        $out = $service->parseSCurvePdfSlice($this->sliceImages(1));

        $this->assertNotNull($out, 'the rung answer must flow through the same validation');
        $this->assertCount(2, $out['months']);
        $this->assertSame('older-flash', $service->lastFallbackModel, 'provenance names the rung that answered');

        $sent = Http::recorded();
        $this->assertCount(3, $sent, 'primary + its one retry, then ONE shot at the rung');
        $this->assertStringContainsString('flash-model', $sent[0][0]->url());
        $this->assertStringContainsString('older-flash', $sent[2][0]->url());
        // The whole point of a parser-specific chain: Lite is never reached.
        foreach ($sent as $pair) {
            $this->assertStringNotContainsString('lite-model', $pair[0]->url());
        }
    }

    // A rung that also fails hands off to the next one rather than ending the run.
    // Called by: callGeminiWithImages() ladder loop.
    public function test_parser_ladder_walks_past_a_failing_rung(): void
    {
        $this->configureLadder('older-flash, oldest-flash');

        Http::fake(['*generativelanguage*' => Http::sequence()
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push('overloaded', 503, ['Retry-After' => 0])
            ->push(['candidates' => [['content' => ['parts' => [['text' => $this->pdfLadderReply()]]]]]], 200),
        ]);

        $service = new GeminiDocumentParserService();
        $out = $service->parseSCurvePdfSlice($this->sliceImages(1));

        $this->assertNotNull($out);
        $this->assertSame('oldest-flash', $service->lastFallbackModel);
        // One shot per rung — a ladder that retried every rung would keep the
        // user waiting minutes for an answer that is not coming.
        Http::assertSentCount(4);
    }

    // The lesson the Lite revert was really about: when the whole ladder fails,
    // the ORIGINAL transport failure must still speak. Masking a quota as "the AI
    // found nothing" is what sent the user looking for a parser bug.
    // Called by: callGeminiWithImages() — the final throw.
    public function test_exhausted_parser_ladder_surfaces_the_original_failure(): void
    {
        $this->configureLadder('older-flash');

        Http::fake(['*generativelanguage*' => Http::sequence()
            ->push(json_encode(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'details' => [[
                '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
                'violations' => [['quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier']],
            ]]]]), 429, ['Retry-After' => 0])
            ->push('overloaded', 503, ['Retry-After' => 0]),
        ]);

        $service = new GeminiDocumentParserService();

        try {
            $service->parseSCurvePdfSlice($this->sliceImages(1));
            $this->fail('An exhausted ladder must throw, never decline silently.');
        } catch (GeminiTransportException $e) {
            // The PRIMARY's voice (per-day quota), not the rung's "busy".
            $this->assertStringContainsString('try again tomorrow', $e->getMessage());
        }

        $this->assertNull($service->lastFallbackModel);
        // Per-day quota fails fast (no retry burned), then one shot at the rung.
        Http::assertSentCount(2);
    }

    // Auth failures never walk the ladder: the key is broken, so every rung would
    // fail identically and each attempt is pure latency.
    // Called by: isFallbackEligible() exclusion inside the ladder loop.
    public function test_parser_ladder_is_skipped_on_auth_failure(): void
    {
        $this->configureLadder('older-flash, oldest-flash');

        Http::fake(['*generativelanguage*' => Http::response('bad key', 401)]);

        try {
            (new GeminiDocumentParserService())->parseSCurvePdfSlice($this->sliceImages(1));
            $this->fail('Auth failures must speak, never ladder.');
        } catch (GeminiTransportException $e) {
            $this->assertSame(401, $e->status);
        }

        Http::assertSentCount(1);
    }

    // Configuration hygiene: a chain that repeats the parser model (easy to do by
    // copy-paste) must not make the same model answer twice, and blanks are noise.
    // Called by: parserFallbackModels().
    public function test_parser_ladder_ignores_blanks_and_the_primary_model(): void
    {
        $this->configureLadder(' , flash-model ,, older-flash , older-flash ');

        Http::fake(['*generativelanguage*' => Http::sequence()
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push(['candidates' => [['content' => ['parts' => [['text' => $this->pdfLadderReply()]]]]]], 200),
        ]);

        $service = new GeminiDocumentParserService();
        $this->assertNotNull($service->parseSCurvePdfSlice($this->sliceImages(1)));
        $this->assertSame('older-flash', $service->lastFallbackModel);

        // Exactly one rung existed after cleaning: primary + retry + that rung.
        Http::assertSentCount(3);
    }

    // E4b: the Excel S-curve climbs the SAME ladder as the PDF path. It did not
    // before, so an Excel import died on a per-model quota that a PDF import
    // survived — one feature failing differently depending on the file sent.
    // What must NOT change is the Lite exclusion: the ladder is a VERSION list,
    // and Lite copying author summaries is the failure this track was built around.
    // Called by: parseExcelSlice() via callGeminiText() + climbFallbackLadder().
    public function test_excel_slice_falls_back_down_the_parser_ladder(): void
    {
        $this->configureLadder('older-flash');

        Http::fake(['*generativelanguage*' => Http::sequence()
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'months' => [
                    ['month_index' => 1, 'planned_progress_percent' => 40],
                    ['month_index' => 2, 'planned_progress_percent' => 100],
                ],
                'hasSummaryRow' => false,
                'contractAmount' => 1000000,
            ])]]]]]], 200),
        ]);

        $service = new GeminiDocumentParserService();
        $out = $service->parseExcelSlice("DESCRIPTION,Month 1,Month 2\nWorks,400000,600000");

        $this->assertNotNull($out, 'the rung answer flows through the same validation');
        $this->assertSame('older-flash', $service->lastFallbackModel);

        $sent = Http::recorded();
        $this->assertCount(3, $sent, 'primary + its one retry, then ONE shot at the rung');
        $this->assertStringContainsString('older-flash', $sent[2][0]->url());
        // The half of the old guarantee that still matters, and always will.
        foreach ($sent as $pair) {
            $this->assertStringNotContainsString('lite-model', $pair[0]->url());
        }
    }

    // A rung that refuses the REQUEST (400 — e.g. a model that cannot honour the
    // Excel responseSchema) is not a transport hiccup: every remaining rung would
    // refuse it too. The walk stops and the primary's voice speaks.
    // Called by: climbFallbackLadder() via isFallbackEligible().
    public function test_a_rung_rejecting_the_request_ends_the_ladder(): void
    {
        $this->configureLadder('older-flash, oldest-flash');

        Http::fake(['*generativelanguage*' => Http::sequence()
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push('schema not supported', 400),
        ]);

        $service = new GeminiDocumentParserService();

        try {
            $service->parseExcelSlice("DESCRIPTION,Month 1\nWorks,30000");
            $this->fail('A refused ladder must throw, never decline silently.');
        } catch (GeminiTransportException $e) {
            // The PRIMARY's quota voice, not the rung's request refusal.
            $this->assertStringContainsString('quota', $e->getMessage());
        }

        // The SECOND rung is never tried: 400 is not something a version change fixes.
        Http::assertSentCount(3);
        $this->assertNull($service->lastFallbackModel);
    }

    // Normal-doc keeps Lite, but only as the LAST rung: there is no reason to reach
    // for the weakest model while a computing one is available (user 2026-09-13).
    // Called by: parseProjectSlice() — [...ladder, backupModel()].
    public function test_normal_doc_tries_the_ladder_before_lite(): void
    {
        $this->configureLadder('older-flash');

        Http::fake(['*generativelanguage*' => Http::sequence()
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push('slow down', 429, ['Retry-After' => 0])   // the ladder rung also fails
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'title' => 'Canteen Works Project Title Here',
            ])]]]]]], 200),                                  // ... so Lite still rescues
        ]);

        $service = new GeminiDocumentParserService();
        $out = $service->parseProjectSlice($this->sliceImages());

        $this->assertNotNull($out);
        $this->assertSame('lite-model', $service->lastFallbackModel, 'Lite remains the last resort');

        $sent = Http::recorded();
        $this->assertCount(4, $sent);
        // ORDER is the assertion: the computing model is asked before the weakest one.
        $this->assertStringContainsString('older-flash', $sent[2][0]->url());
        $this->assertStringContainsString('lite-model', $sent[3][0]->url());
    }

    // Provenance must describe THIS answer. lastFallbackModel is public and the
    // service can be reused, so a rescue recorded during a PDF call must not be
    // reported as the provenance of a later Excel one.
    // Called by: callGeminiText() — the reset at the top.
    public function test_fallback_provenance_does_not_leak_between_calls(): void
    {
        $this->configureLadder('older-flash');
        $service = new GeminiDocumentParserService();

        $cleanExcel = json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 40],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'hasSummaryRow' => false,
            'contractAmount' => 1000000,
        ]);

        // ONE fake for BOTH calls — re-calling Http::fake() keeps serving the first
        // stub (lesson banked 2026-09-10, proven via recorded()). The sequence runs:
        // the PDF call's primary + retry fail, its ladder rung answers; then the
        // Excel call succeeds outright.
        Http::fake(['*generativelanguage*' => Http::sequence()
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push('slow down', 429, ['Retry-After' => 0])
            ->push(['candidates' => [['content' => ['parts' => [['text' => $this->pdfLadderReply()]]]]]], 200)
            ->push(['candidates' => [['content' => ['parts' => [['text' => $cleanExcel]]]]]], 200),
        ]);

        $this->assertNotNull($service->parseSCurvePdfSlice($this->sliceImages(1)));
        $this->assertSame('older-flash', $service->lastFallbackModel, 'the PDF call really was rescued');

        // The clean Excel call on the SAME instance must report no fallback at all.
        $this->assertNotNull($service->parseExcelSlice("DESCRIPTION,Month 1,Month 2\nWorks,400000,600000"));
        $this->assertNull($service->lastFallbackModel, 'a stale rescue must not describe a clean answer');
    }

}
