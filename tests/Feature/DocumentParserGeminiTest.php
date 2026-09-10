<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// AI-2: endpoint contract for the Tier-3 normal-document fallback.
// POST /{admin,staff}/document/parse-gemini accepts exactly one source
// (page images | image file | image document_id) and returns the 11-field
// shape or an honest 422. Contractors never reach it (role middleware).
class DocumentParserGeminiTest extends TestCase
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
            'name' => 'AI ' . $role,
            'email' => fake()->unique()->safeEmail(),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function slicePayload(): array
    {
        return [
            'images' => [
                ['data' => base64_encode('fake-page-png'), 'mimeType' => 'image/png'],
            ],
        ];
    }

    // Admin page-images success: 11-field shape comes back.
    // Called by: POST /admin/document/parse-gemini from the AI-3 banner flow.
    public function test_admin_page_images_success_returns_parsed_shape(): void
    {
        $this->fakeGemini(json_encode(['abc' => '4834297.59', 'title' => 'Canteen Works Project Title']));

        $response = $this->actingAs($this->makeUser('admin'))
            ->postJson('/admin/document/parse-gemini', $this->slicePayload());

        $response->assertOk()->assertJsonPath('success', true);
        $response->assertJsonPath('parsed.abc', '4834297.59');
        $this->assertCount(11, $response->json('parsed'));
    }

    // Staff route mirrors admin (Decision L0a roles).
    // Called by: POST /staff/document/parse-gemini.
    public function test_staff_page_images_success(): void
    {
        $this->fakeGemini(json_encode(['duration' => '185']));

        $this->actingAs($this->makeUser('staff'))
            ->postJson('/staff/document/parse-gemini', $this->slicePayload())
            ->assertOk()->assertJsonPath('parsed.duration', '185');
    }

    // Source discipline: none, both, or too many slices are 422 with messages.
    // Called by: malformed AI-3 slice payloads.
    public function test_source_discipline_is_enforced(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->postJson('/admin/document/parse-gemini', [])
            ->assertUnprocessable()->assertJsonPath('success', false);

        $this->actingAs($admin)->postJson('/admin/document/parse-gemini', array_merge(
            $this->slicePayload(),
            ['document_id' => 1]
        ))->assertUnprocessable()->assertJsonPath('success', false);

        $tooMany = ['images' => []];
        for ($i = 0; $i < 4; $i++) {
            $tooMany['images'][] = ['data' => base64_encode('p' . $i), 'mimeType' => 'image/png'];
        }

        $this->actingAs($admin)->postJson('/admin/document/parse-gemini', $tooMany)
            ->assertUnprocessable()->assertJsonPath('success', false);
    }

    // AI miss (null shape) is an honest 422, never a 500.
    // Called by: confusing pages even Gemini cannot read.
    public function test_ai_miss_returns_422_not_500(): void
    {
        $this->fakeGemini(json_encode(array_fill_keys(
            ['title', 'location', 'description', 'budget', 'abc', 'budgetSource', 'category', 'startDate', 'endDate', 'duration', 'contractor'],
            null
        )));

        $this->actingAs($this->makeUser('admin'))
            ->postJson('/admin/document/parse-gemini', $this->slicePayload())
            ->assertUnprocessable()->assertJsonPath('success', false);
    }

    // Contractors are bounced by role middleware (project convention).
    // Called by: any contractor request to either parse-gemini route.
    public function test_contractor_blocked_on_both_routes(): void
    {
        $contractor = $this->makeUser('contractor');

        $this->actingAs($contractor)->postJson('/staff/document/parse-gemini', $this->slicePayload())
            ->assertRedirect(route('unauthorized'));
        $this->actingAs($contractor)->postJson('/admin/document/parse-gemini', $this->slicePayload())
            ->assertRedirect(route('unauthorized'));
    }

    // Multipart image upload works; PDFs are declined with the slice instruction.
    // Called by: photo-upload flows (no PDF renderer on the server by design).
    public function test_file_upload_accepts_images_declines_pdfs(): void
    {
        $this->fakeGemini(json_encode(['title' => 'Photo Read Project Title Here']));
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->post('/admin/document/parse-gemini', [
            'file' => UploadedFile::fake()->image('page.png'),
        ])->assertOk()->assertJsonPath('success', true);

        $this->actingAs($admin)->post('/admin/document/parse-gemini', [
            'file' => UploadedFile::fake()->create('curve.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonPath('success', false);
    }

    // Stored IMAGE documents read via the Storage disk; PDFs and ghosts 422.
    // Called by: project-detail photo flows.
    public function test_document_id_reads_stored_images_only(): void
    {
        Storage::fake('public');
        $this->fakeGemini(json_encode(['title' => 'Stored Photo Project Title Here']));

        $admin = $this->makeUser('admin');
        $project = Project::factory()->create();
        $type = DocumentType::create(['name' => 'Photo']);

        Storage::disk('public')->put('ai/photo.png', 'fake-png-bytes');

        $photo = Document::create([
            'project_id' => $project->id,
            'document_type_id' => $type->id,
            'document_name' => 'Site photo',
            'file_path' => 'ai/photo.png',
            'uploaded_by' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson('/admin/document/parse-gemini', ['document_id' => $photo->id])
            ->assertOk()->assertJsonPath('success', true);

        Storage::disk('public')->put('ai/curve.pdf', 'fake-pdf-bytes');

        $pdf = Document::create([
            'project_id' => $project->id,
            'document_type_id' => $type->id,
            'document_name' => 'Schedule',
            'file_path' => 'ai/curve.pdf',
            'uploaded_by' => $admin->id,
        ]);

        $this->actingAs($admin)->postJson('/admin/document/parse-gemini', ['document_id' => $pdf->id])
            ->assertUnprocessable()->assertJsonPath('success', false);

        $this->actingAs($admin)->postJson('/admin/document/parse-gemini', ['document_id' => 999999])
            ->assertUnprocessable()->assertJsonPath('success', false);
    }

    // Lite fallback provenance: parser-model quota then Lite success returns the
    // shape plus fallbackModel (single sequence fake — re-faking serves stale stubs).
    // Called by: POST /admin/document/parse-gemini through callGeminiWithImages().
    public function test_page_images_fallback_marks_provenance(): void
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
                'title' => 'Fallback Answered Project Title Here',
            ])]]]]]], 200),
        ]);

        $this->actingAs($this->makeUser('admin'))
            ->postJson('/admin/document/parse-gemini', $this->slicePayload())
            ->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('parsed.title', 'Fallback Answered Project Title Here')
            ->assertJsonPath('fallbackModel', 'lite-model');
    }
}
