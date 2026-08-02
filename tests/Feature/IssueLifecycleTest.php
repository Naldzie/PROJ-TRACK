<?php

namespace Tests\Feature;

use App\Http\Controllers\IssueController;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ============================================================================
 * IssueLifecycleTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the Phase C issue lifecycle upgrade:
 *   - resolution_notes are REQUIRED when status is Resolved/Closed (store + update)
 *   - baseline_schedule_id must belong to the project being posted to
 *   - getIssues() JSON exposes resolved_at, resolution_notes, baseline_schedule_id,
 *     month_label, and the issue's attachments[]
 *   - attachments attach to an issue (context 'issue' + 'resolution') and resolve
 *     via the Issue->attachments() morphMany
 *   - ActivityLog rows are written on store / update / destroy (with project_id)
 *   - destroy() cleans up the issue's attachments (rows + physical files)
 *   - scopeOpen() returns Open + In Progress issues
 *
 * Conventions
 * -----------
 *   - Controllers are invoked DIRECTLY (route-based auth does not authenticate
 *     in this environment — see AttachmentTest).
 *   - Users via User::create() (no factory — users table lacks email_verified_at).
 *   - Files via UploadedFile::createWithContent() with real magic bytes (GD not
 *     installed — UploadedFile::fake()->image() would throw).
 *
 * Run With
 * --------
 *   php artisan test --filter=IssueLifecycleTest
 */
class IssueLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->project = Project::factory()->create(['status' => 'Ongoing']);
    }

    // === HELPERS ===

    private function admin(): User
    {
        return User::create([
            'name'  => 'Test Admin',
            'email' => fake()->unique()->safeEmail(),
            'role'  => 'admin',
        ]);
    }

    private function baseline(Project $project): ProjectBaselineSchedule
    {
        return ProjectBaselineSchedule::factory()->create([
            'project_id'   => $project->id,
            'month_label'  => '2026-06',
            'planned_date' => '2026-06-15',
        ]);
    }

    private function storeRequest(Project $project, array $payload): Request
    {
        return Request::create("/admin/projects/{$project->id}/issues", 'POST', $payload);
    }

    private function callStore(Request $request, Project $project)
    {
        return app(IssueController::class)->store($request, $project);
    }

    private function callUpdate(Request $request, Project $project, Issue $issue)
    {
        return app(IssueController::class)->update($request, $project, $issue);
    }

    /** Minimal valid JPEG (magic bytes only) — GD-free, passes the mimes rule. */
    private function jpegFile(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9",
            'image/jpeg'
        );
    }

    /** Create an Attachment row bound to an issue, with a physical file on the fake disk. */
    private function attachFile(Issue $issue, string $context): Attachment
    {
        $path = Attachment::generateStoragePath($this->project->code, $context, 'jpg');
        Storage::disk('public')->put($path, 'binary-content');

        return Attachment::create([
            'project_id'      => $this->project->id,
            'attachable_type' => Issue::class,
            'attachable_id'   => $issue->id,
            'context'         => $context,
            'original_name'   => $context . '-photo.jpg',
            'file_path'       => $path,
            'file_size'       => 12,
            'mime_type'       => 'image/jpeg',
            'uploaded_by'     => 'Test Admin',
        ]);
    }

    // === RESOLUTION NOTES REQUIRED ===

    public function test_store_requires_resolution_notes_when_status_is_resolved(): void
    {
        $this->actingAs($this->admin());

        $response = $this->callStore($this->storeRequest($this->project, [
            'issue_name'  => 'Leak found',
            'description' => 'Water leak in the pump room',
            'status'      => 'Resolved',
        ]), $this->project);

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        $this->assertArrayHasKey('resolution_notes', (array) $response->getData()->errors);

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_store_requires_resolution_notes_when_status_is_closed(): void
    {
        $this->actingAs($this->admin());

        $response = $this->callStore($this->storeRequest($this->project, [
            'issue_name'  => 'Closed issue',
            'description' => 'Already closed',
            'status'      => 'Closed',
        ]), $this->project);

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        $this->assertArrayHasKey('resolution_notes', (array) $response->getData()->errors);

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_store_allows_resolution_notes_when_status_is_resolved(): void
    {
        $this->actingAs($this->admin());

        $response = $this->callStore($this->storeRequest($this->project, [
            'issue_name'       => 'Leak fixed',
            'description'      => 'Water leak in the pump room',
            'status'           => 'Resolved',
            'resolution_notes' => 'Sealant applied and pump tested — no further leakage.',
        ]), $this->project);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        $this->assertDatabaseHas('issues', [
            'project_id'       => $this->project->id,
            'issue_name'       => 'Leak fixed',
            'status'           => 'Resolved',
            'resolution_notes' => 'Sealant applied and pump tested — no further leakage.',
        ]);

        $issue = Issue::first();
        $this->assertNotNull($issue->resolved_at, 'resolved_at should be auto-set');
    }

    public function test_update_requires_resolution_notes_when_transitioning_to_resolved(): void
    {
        $this->actingAs($this->admin());

        $issue = Issue::factory()->create([
            'project_id' => $this->project->id,
            'status'     => 'Open',
        ]);

        $request = Request::create(
            "/admin/projects/{$this->project->id}/issues/{$issue->id}",
            'PUT',
            [
                'issue_name'  => $issue->issue_name,
                'description' => $issue->description,
                'status'      => 'Resolved',
            ]
        );

        $response = $this->callUpdate($request, $this->project, $issue);

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        $this->assertArrayHasKey('resolution_notes', (array) $response->getData()->errors);

        $this->assertNull($issue->fresh()->resolved_at, 'resolved_at should not be set on a failed update');
    }

    // === BASELINE BELONGS TO PROJECT ===

    public function test_store_rejects_baseline_from_another_project(): void
    {
        $this->actingAs($this->admin());

        $otherProject = Project::factory()->create();
        $foreignBaseline = $this->baseline($otherProject);

        $response = $this->callStore($this->storeRequest($this->project, [
            'issue_name'            => 'Cross-project baseline',
            'description'           => 'Should be rejected',
            'status'                => 'Open',
            'baseline_schedule_id'  => $foreignBaseline->id,
        ]), $this->project);

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        $this->assertDatabaseCount('issues', 0);
    }

    public function test_store_accepts_baseline_from_same_project(): void
    {
        $this->actingAs($this->admin());

        $baseline = $this->baseline($this->project);

        $response = $this->callStore($this->storeRequest($this->project, [
            'issue_name'           => 'Linked to June',
            'description'          => 'Belongs to the project baseline',
            'status'               => 'Open',
            'baseline_schedule_id' => $baseline->id,
        ]), $this->project);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        $this->assertDatabaseHas('issues', [
            'project_id'           => $this->project->id,
            'baseline_schedule_id' => $baseline->id,
        ]);
    }

    // === ATTACHMENTS ATTACH TO ISSUE + RESOLUTION ===

    public function test_issue_attachments_resolve_issue_and_resolution_contexts(): void
    {
        $issue = Issue::factory()->create([
            'project_id' => $this->project->id,
            'status'     => 'Open',
        ]);

        $issueAttachment = $this->attachFile($issue, 'issue');
        $resolutionAttachment = $this->attachFile($issue, 'resolution');

        $attachments = $issue->attachments;

        $this->assertCount(2, $attachments);
        $this->assertTrue($attachments->contains('id', $issueAttachment->id));
        $this->assertTrue($attachments->contains('id', $resolutionAttachment->id));
        $this->assertTrue($attachments->contains('context', 'issue'));
        $this->assertTrue($attachments->contains('context', 'resolution'));
    }

    // === GET ISSUES EXPOSES NEW FIELDS ===

    public function test_get_issues_exposes_new_fields(): void
    {
        $baseline = $this->baseline($this->project);

        $issue = Issue::factory()->create([
            'project_id'          => $this->project->id,
            'status'              => 'Resolved',
            'resolved_at'         => now(),
            'resolution_notes'    => 'Fixed and verified.',
            'baseline_schedule_id'=> $baseline->id,
        ]);

        $this->attachFile($issue, 'issue');

        $this->actingAs($this->admin());
        $response = app(IssueController::class)->getIssues($this->project);

        $this->assertEquals(200, $response->getStatusCode());
        $data = $response->getData();

        $this->assertTrue($data->success);
        $this->assertCount(1, $data->issues);

        $first = $data->issues[0];

        $this->assertEquals($issue->id, $first->id);
        $this->assertNotNull($first->resolved_at);
        $this->assertEquals('Fixed and verified.', $first->resolution_notes);
        $this->assertEquals($baseline->id, $first->baseline_schedule_id);
        $this->assertEquals($baseline->month_label, $first->month_label);
        $this->assertIsArray($first->attachments);
        $this->assertCount(1, $first->attachments);
        $this->assertEquals('issue', $first->attachments[0]->context);
    }

    // === ACTIVITY LOG ===

    public function test_activity_log_created_on_store_update_destroy(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        // Store
        $response = $this->callStore($this->storeRequest($this->project, [
            'issue_name'  => 'Audited issue',
            'description' => 'Should be logged',
            'status'      => 'Open',
        ]), $this->project);

        $issue = Issue::first();

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $this->project->id,
            'action'     => 'Create Issue',
        ]);

        // Update
        $request = Request::create(
            "/admin/projects/{$this->project->id}/issues/{$issue->id}",
            'PUT',
            [
                'issue_name'  => 'Audited issue (edited)',
                'description' => 'Updated description',
                'status'      => 'In Progress',
            ]
        );
        $this->callUpdate($request, $this->project, $issue);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $this->project->id,
            'action'     => 'Update Issue',
        ]);

        // Destroy
        $destroyResponse = app(IssueController::class)->destroy($this->project, $issue);

        $this->assertEquals(200, $destroyResponse->getStatusCode());
        $this->assertTrue($destroyResponse->getData()->success);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $this->project->id,
            'action'     => 'Delete Issue',
        ]);

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('activity_logs', 3);
    }

    // === DESTROY CLEANS ATTACHMENTS ===

    public function test_destroy_removes_issue_attachments_and_files(): void
    {
        $this->actingAs($this->admin());

        $issue = Issue::factory()->create([
            'project_id' => $this->project->id,
            'status'     => 'Open',
        ]);

        $attachment = $this->attachFile($issue, 'issue');
        $filePath = $attachment->file_path;

        $this->assertTrue($attachment->fileExists());

        $response = app(IssueController::class)->destroy($this->project, $issue);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        $this->assertDatabaseCount('attachments', 0);
        Storage::disk('public')->assertMissing($filePath);
    }

    // === SCOPE OPEN ===

    public function test_scope_open_returns_open_and_in_progress(): void
    {
        $open = Issue::factory()->create(['project_id' => $this->project->id, 'status' => 'Open']);
        $inProgress = Issue::factory()->create(['project_id' => $this->project->id, 'status' => 'In Progress']);
        $resolved = Issue::factory()->create(['project_id' => $this->project->id, 'status' => 'Resolved']);
        $closed = Issue::factory()->create(['project_id' => $this->project->id, 'status' => 'Closed']);

        $openIds = Issue::open()->pluck('id')->all();

        $this->assertContains($open->id, $openIds);
        $this->assertContains($inProgress->id, $openIds);
        $this->assertNotContains($resolved->id, $openIds);
        $this->assertNotContains($closed->id, $openIds);
    }

    // === ATTACHMENT UPLOAD VIA EXISTING ENDPOINT (context issue + resolution) ===

    public function test_attachments_upload_with_issue_and_resolution_contexts(): void
    {
        $this->actingAs($this->admin());

        $issue = Issue::factory()->create([
            'project_id' => $this->project->id,
            'status'     => 'Open',
        ]);

        foreach (['issue', 'resolution'] as $context) {
            $payload = [
                'project_id'      => $this->project->id,
                'attachable_type' => Issue::class,
                'attachable_id'   => $issue->id,
                'context'         => $context,
            ];
            $request = Request::create('/attachments/upload', 'POST', $payload);
            $request->files->set('file', $this->jpegFile($context . '-photo.jpg'));

            $response = app(\App\Http\Controllers\AttachmentController::class)->upload($request);

            $this->assertEquals(200, $response->getStatusCode(), "upload ({$context}) should succeed");
            $this->assertTrue($response->getData()->success);
        }

        $this->assertCount(2, $issue->attachments);
        $this->assertEquals(['issue', 'resolution'], $issue->attachments->pluck('context')->sort()->values()->all());
    }
}
