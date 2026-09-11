<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// F-B: endpoint contract for the Tier-3 PDF S-curve fallback.
// POST /{admin,staff}/s-curve/parse-pdf-gemini accepts page images (1-3) +
// optional pageWords and returns months (+activities) with aiAssisted meta,
// or an honest 422. Contractors never reach it (role middleware).
class SCurvePdfGeminiTest extends TestCase
{
    use RefreshDatabase;

    // Fake generateContent carrying the given raw text.
    private function fakeGemini(string $text, int $status = 200): void
    {
        config(['services.gemini.key' => 'test-key']);

        Http::fake([
            '*generativelanguage*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $text]]]],
                ],
            ], $status),
        ]);
    }

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => 'PDF AI ' . $role,
            'email' => fake()->unique()->safeEmail(),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function slicePayload(array $extra = []): array
    {
        return array_merge([
            'images' => [
                ['data' => base64_encode('fake-pdf-page-png'), 'mimeType' => 'image/png'],
            ],
            'pageWords' => ['SCHEDULED TASKS STAGE 1 Mobilization Works'],
        ], $extra);
    }

    private function pdfReply(): array
    {
        return [
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 5, 'activities' => ['Mobilization Works']],
                ['month_index' => 2, 'planned_progress_percent' => 100, 'activities' => ['Mobilization Works']],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 60000],
                ['month_index' => 2, 'pesos' => 1140000],
            ],
            'hasSummaryRow' => false,
            'contractAmount' => 1200000,
            'monthLabels' => [
                ['month_index' => 1, 'label' => 'STAGE 1'],
            ],
        ];
    }

    // Admin page-images success: months + kept names + aiAssisted meta.
    // Called by: POST /admin/s-curve/parse-pdf-gemini from the F-C button flow.
    public function test_admin_page_images_success_returns_months_shape(): void
    {
        $this->fakeGemini(json_encode($this->pdfReply()));

        $response = $this->actingAs($this->makeUser('admin'))
            ->postJson('/admin/s-curve/parse-pdf-gemini', $this->slicePayload());

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertCount(2, $response->json('months'));
        $this->assertSame(['Mobilization Works'], $response->json('months.0.activities'));
        $response->assertJsonPath('meta.aiAssisted', true);
        $response->assertJsonPath('fallbackModel', null);
        // F-D vocabulary fuel rides along (untrusted until desk approval).
        $this->assertSame('STAGE 1', $response->json('monthLabels.1'));
    }

    // Staff route mirrors admin (Decision L0a roles).
    // Called by: POST /staff/s-curve/parse-pdf-gemini.
    public function test_staff_page_images_success(): void
    {
        $this->fakeGemini(json_encode($this->pdfReply()));

        $this->actingAs($this->makeUser('staff'))
            ->postJson('/staff/s-curve/parse-pdf-gemini', $this->slicePayload())
            ->assertOk()->assertJsonPath('success', true);
    }

    // Slice discipline: none, too many, bad mime, or empty data are 422.
    // Called by: malformed F-C slice payloads.
    public function test_slice_discipline_is_enforced(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->postJson('/admin/s-curve/parse-pdf-gemini', [])
            ->assertUnprocessable()->assertJsonPath('success', false);

        $tooMany = ['images' => []];
        for ($i = 0; $i < 4; $i++) {
            $tooMany['images'][] = ['data' => base64_encode('p' . $i), 'mimeType' => 'image/png'];
        }

        $this->actingAs($admin)->postJson('/admin/s-curve/parse-pdf-gemini', $tooMany)
            ->assertUnprocessable()->assertJsonPath('success', false);

        $this->actingAs($admin)->postJson('/admin/s-curve/parse-pdf-gemini', [
            'images' => [['data' => base64_encode('x'), 'mimeType' => 'image/gif']],
        ])->assertUnprocessable()->assertJsonPath('success', false);

        $this->actingAs($admin)->postJson('/admin/s-curve/parse-pdf-gemini', [
            'images' => [['data' => '', 'mimeType' => 'image/png']],
        ])->assertUnprocessable()->assertJsonPath('success', false);
    }

    // Data-URI slices (canvas.toDataURL shape) are accepted.
    // Called by: the F-C slicer posting data URIs directly.
    public function test_data_uri_slices_accepted(): void
    {
        $this->fakeGemini(json_encode($this->pdfReply()));

        $this->actingAs($this->makeUser('admin'))
            ->postJson('/admin/s-curve/parse-pdf-gemini', [
                'images' => [
                    ['data' => 'data:image/png;base64,' . base64_encode('fake-pdf-page-png')],
                ],
                'pageWords' => ['SCHEDULED TASKS STAGE 1 Mobilization Works'],
            ])
            ->assertOk()->assertJsonPath('success', true);
    }

    // AI miss (thin reply) is an honest 422, never a 500.
    // Called by: pages even Gemini cannot read.
    public function test_ai_miss_returns_422_not_500(): void
    {
        $this->fakeGemini(json_encode([
            'months' => [['month_index' => 1, 'planned_progress_percent' => 10]],
            'hasSummaryRow' => false,
        ]));

        $this->actingAs($this->makeUser('admin'))
            ->postJson('/admin/s-curve/parse-pdf-gemini', $this->slicePayload())
            ->assertUnprocessable()->assertJsonPath('success', false);
    }

    // Hallucinated names drop end-to-end (pageWords wiring proof).
    // Called by: verified answers carrying invented names.
    public function test_hallucinated_names_drop_end_to_end(): void
    {
        $reply = $this->pdfReply();
        $reply['months'][0]['activities'] = ['Mobilization Works', 'Skyhook Assembly'];
        $this->fakeGemini(json_encode($reply));

        $response = $this->actingAs($this->makeUser('admin'))
            ->postJson('/admin/s-curve/parse-pdf-gemini', $this->slicePayload());

        $response->assertOk();
        $this->assertSame(['Mobilization Works'], $response->json('months.0.activities'));
    }

    // Contractors are bounced by role middleware (project convention).
    // Called by: any contractor request to either PDF route.
    public function test_contractor_blocked_on_both_routes(): void
    {
        $contractor = $this->makeUser('contractor');

        $this->actingAs($contractor)->postJson('/staff/s-curve/parse-pdf-gemini', $this->slicePayload())
            ->assertRedirect(route('unauthorized'));
        $this->actingAs($contractor)->postJson('/admin/s-curve/parse-pdf-gemini', $this->slicePayload())
            ->assertRedirect(route('unauthorized'));
    }

    // No Lite rescue on this path (Flash-or-nothing, user 2026-09-11):
    // sustained overload is an honest mapped 422 with no backup provenance.
    // Single mapped sequence fake; Retry-After 0 skips backoff naps.
    // Called by: overloaded parser-model runs (sequence: busy, busy, voice).
    public function test_no_lite_rescue_on_sustained_overload(): void
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

        $response = $this->actingAs($this->makeUser('admin'))
            ->postJson('/admin/s-curve/parse-pdf-gemini', $this->slicePayload());

        $response->assertUnprocessable()->assertJsonPath('success', false);
        $this->assertStringContainsString('busy (overloaded)', $response->json('message'));
        // Initial + one retry only — a Lite rescue would be a third request.
        Http::assertSentCount(2);
    }
}
