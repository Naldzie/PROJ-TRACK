<?php

namespace Tests\Feature;

use App\Http\Controllers\AttachmentController;
use App\Models\Attachment;
use App\Models\Contractor;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * ============================================================================
 * AttachmentTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the Phase A polymorphic attachments infrastructure:
 *   - Admin/staff can upload files bound to a parent record (progress | issue)
 *   - Contractors are READ-ONLY (view/download) — never upload/delete
 *   - Guests are redirected to login
 *   - Validation: whitelist (jpg/jpeg/png/pdf), context whitelist, and the
 *     parent record must belong to the same project
 *   - view() streams inline; download() forces attachment disposition
 *   - delete() removes the DB row AND the physical file on the public disk
 *
 * Conventions
 * -----------
 *   - Controllers are invoked DIRECTLY (as ProgressDeleteGuardTest does).
 *     Route-based authenticated requests do not authenticate in this test
 *     environment (auth middleware reports Unauthenticated during dispatch),
 *     which is why the whole suite avoids them.
 *   - UploadedFile::create() would yield application/octet-stream content and
 *     fail the mimes rule, so real JPEG/PDF magic bytes are written via
 *     createWithContent() (the GD extension is not installed).
 *
 * Run With
 * --------
 *   php artisan test --filter=AttachmentTest
 */
class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private ProjectProgressHistory $progressRecord;
    private Issue $issue;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->project = Project::factory()->create(['status' => 'Ongoing']);

        $this->progressRecord = ProjectProgressHistory::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $this->issue = Issue::factory()->create([
            'project_id' => $this->project->id,
        ]);
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

    private function staff(): User
    {
        return User::create([
            'name'  => 'Test Staff',
            'email' => fake()->unique()->safeEmail(),
            'role'  => 'staff',
        ]);
    }

    private function contractorFor(Project $project): User
    {
        $user = User::create([
            'name'  => 'Test Contractor',
            'email' => fake()->unique()->safeEmail(),
            'role'  => 'contractor',
        ]);

        Contractor::create([
            'user_id'        => $user->id,
            'contractor_id'  => 'CON-' . $user->id,
            'company_name'   => fake()->company(),
            'contractor_name'=> $user->name,
        ]);

        $project->update(['contractor_id' => $user->contractor->id]);

        return $user;
    }

    /** Minimal valid JPEG (magic bytes only) — GD-free, passes the mimes rule. */
    private function jpegFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'inspection-photo.jpg',
            "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9",
            'image/jpeg'
        );
    }

    /** A Request that mirrors the upload route payload. */
    private function uploadRequest(Project $project, array $overrides = []): Request
    {
        $payload = array_merge([
            'project_id'      => $project->id,
            'attachable_type' => ProjectProgressHistory::class,
            'attachable_id'   => $this->progressRecord->id,
            'context'         => 'inspection',
        ], $overrides);

        $request = Request::create('/attachments/upload', 'POST', $payload);
        $request->files->set('file', $this->jpegFile());

        return $request;
    }

    private function callUpload(Request $request)
    {
        return app(AttachmentController::class)->upload($request);
    }

    /** Create an attachment via a successful direct upload (admin acting). */
    private function createAttachment(User $actor): Attachment
    {
        $this->actingAs($actor);
        $response = $this->callUpload($this->uploadRequest($this->project));
        $this->assertEquals(200, $response->getStatusCode(), 'upload should succeed');

        return Attachment::first();
    }

    // === UPLOAD ===

    public function test_admin_can_upload_attachment(): void
    {
        $this->actingAs($this->admin());

        $response = $this->callUpload($this->uploadRequest($this->project));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        $this->assertDatabaseHas('attachments', [
            'project_id'      => $this->project->id,
            'attachable_type' => ProjectProgressHistory::class,
            'attachable_id'   => $this->progressRecord->id,
            'context'         => 'inspection',
            'original_name'   => 'inspection-photo.jpg',
        ]);

        $attachment = Attachment::first();
        $this->assertTrue($attachment->fileExists(), 'Physical file should exist on the public disk');
        $this->assertStringStartsWith('projects/attachments/' . $this->project->code . '/inspection/', $attachment->file_path);
        $this->assertNotNull($attachment->file_size);
        $this->assertNotNull($attachment->uploaded_by);
    }

    public function test_staff_can_upload_attachment(): void
    {
        $this->actingAs($this->staff());

        $response = $this->callUpload($this->uploadRequest($this->project, [
            'attachable_type' => Issue::class,
            'attachable_id'   => $this->issue->id,
            'context'         => 'issue',
        ]));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        $this->assertDatabaseHas('attachments', [
            'project_id'      => $this->project->id,
            'attachable_type' => Issue::class,
            'attachable_id'   => $this->issue->id,
            'context'         => 'issue',
        ]);
    }

    public function test_contractor_cannot_upload_attachment(): void
    {
        $this->actingAs($this->contractorFor($this->project));

        $response = $this->callUpload($this->uploadRequest($this->project));

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_guest_redirected_from_upload(): void
    {
        $payload = $this->uploadRequest($this->project)->all();
        $payload['file'] = $this->jpegFile();

        $response = $this->post(route('staff.attachments.upload'), $payload);

        $response->assertRedirect(route('login'));
    }

    public function test_upload_rejects_disallowed_file_type(): void
    {
        $this->actingAs($this->admin());

        $request = $this->uploadRequest($this->project);
        $request->files->set('file', UploadedFile::fake()->createWithContent(
            'notes.docx',
            "PK\x03\x04 fake-docx-content",
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ));

        try {
            $this->callUpload($request);
            $this->fail('Expected a ValidationException for a disallowed file type.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
        }

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_upload_rejects_invalid_context(): void
    {
        $this->actingAs($this->admin());

        try {
            $this->callUpload($this->uploadRequest($this->project, ['context' => 'random']));
            $this->fail('Expected a ValidationException for an invalid context.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('context', $e->errors());
        }

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_upload_rejects_attachable_from_another_project(): void
    {
        $this->actingAs($this->admin());

        $otherProject = Project::factory()->create();
        $foreignRecord = ProjectProgressHistory::factory()->create(['project_id' => $otherProject->id]);

        $response = $this->callUpload($this->uploadRequest($this->project, [
            'attachable_id' => $foreignRecord->id,
        ]));

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        $this->assertDatabaseCount('attachments', 0);
    }

    // === VIEW / DOWNLOAD ===

    public function test_admin_can_view_attachment_inline(): void
    {
        $this->actingAs($this->admin());
        $attachment = $this->createAttachment($this->admin());

        $response = app(AttachmentController::class)->view($attachment);

        $this->assertTrue($response->isSuccessful());
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_contractor_can_view_assigned_project_attachment(): void
    {
        $attachment = $this->createAttachment($this->admin());

        $this->actingAs($this->contractorFor($this->project));
        $response = app(AttachmentController::class)->view($attachment);

        $this->assertTrue($response->isSuccessful());
    }

    public function test_contractor_cannot_view_other_project_attachment(): void
    {
        $attachment = $this->createAttachment($this->admin());

        $otherProject = Project::factory()->create();
        $this->actingAs($this->contractorFor($otherProject));

        try {
            app(AttachmentController::class)->view($attachment);
            $this->fail('Expected a 403 HttpException for a cross-project attachment.');
        } catch (HttpException $e) {
            $this->assertEquals(403, $e->getStatusCode());
        }
    }

    public function test_contractor_can_download_assigned_project_attachment(): void
    {
        $attachment = $this->createAttachment($this->admin());

        $this->actingAs($this->contractorFor($this->project));
        $response = app(AttachmentController::class)->download($attachment);

        $this->assertTrue($response->isSuccessful());
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    // === DELETE ===

    public function test_admin_can_delete_attachment_row_and_file(): void
    {
        $this->actingAs($this->admin());
        $attachment = $this->createAttachment($this->admin());
        $filePath = $attachment->file_path;

        $this->assertTrue($attachment->fileExists());

        $response = app(AttachmentController::class)->delete($attachment);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        $this->assertDatabaseCount('attachments', 0);
        Storage::disk('public')->assertMissing($filePath);
    }

    public function test_contractor_cannot_delete_attachment(): void
    {
        $attachment = $this->createAttachment($this->admin());

        $this->actingAs($this->contractorFor($this->project));
        $response = app(AttachmentController::class)->delete($attachment);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertTrue($attachment->fileExists());
    }
}
