<?php

namespace Tests\Feature;

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\ProjectProgressController;
use App\Models\Attachment;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ============================================================================
 * ProgressAttachmentTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates Phase D progress-record attachments:
 *   - recording monthly progress exposes an `attachments` key in its JSON
 *   - uploading inspection evidence binds to a newly recorded progress record
 *     (attachable_type = App\Models\ProjectProgressHistory, context 'inspection')
 *   - editing a progress record exposes its existing attachment ids
 *   - deleting an attachment removes the row AND the physical file
 *   - deleting the LATEST progress record cleans up its attachment rows + files
 *   - contractors are rejected from uploading/deleting progress attachments
 *
 * Conventions (mirrors AttachmentTest / IssueLifecycleTest)
 * -----------------------------------------------------------
 *   - Controllers are invoked DIRECTLY (route-based auth does not authenticate
 *     in this environment — see AttachmentTest).
 *   - Users via User::create() (no factory).
 *   - Files via UploadedFile::createWithContent() with real JPEG magic bytes
 *     (GD not installed — fake()->image() would throw).
 *
 * Run With
 * --------
 *   php artisan test --filter=ProgressAttachmentTest
 */
class ProgressAttachmentTest extends TestCase
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

    /** Record monthly progress through the controller and return the record. */
    private function recordMonthlyProgress(Project $project, array $overrides = []): ProjectProgressHistory
    {
        $payload = array_merge([
            'inspection_date'         => '2026-06-15',
            'month_label'             => '2026-06',
            'actual_progress_percent' => 35,
            'remarks'                 => 'Phase D test',
        ], $overrides);

        $request = Request::create('/progress', 'POST', $payload);
        $response = app(ProjectProgressController::class)->recordMonthlyProgress($request, $project);

        $this->assertEquals(200, $response->getStatusCode(), 'recordMonthlyProgress should succeed');
        $data = $response->getData();
        $this->assertTrue($data->success, 'recordMonthlyProgress success flag');

        return ProjectProgressHistory::find($data->progress_record->id);
    }

    /** A Request that mirrors the upload route payload. */
    private function uploadRequest(Project $project, int $attachableId, array $overrides = []): Request
    {
        $payload = array_merge([
            'project_id'      => $project->id,
            'attachable_type' => ProjectProgressHistory::class,
            'attachable_id'   => $attachableId,
            'context'         => 'inspection',
        ], $overrides);

        $request = Request::create('/attachments/upload', 'POST', $payload);
        $request->files->set('file', $this->jpegFile());

        return $request;
    }

    /** Successful upload while acting as $actor; returns the created attachment. */
    private function uploadAs(User $actor, Project $project, int $attachableId): Attachment
    {
        $this->actingAs($actor);
        $response = app(AttachmentController::class)->upload($this->uploadRequest($project, $attachableId));
        $this->assertEquals(200, $response->getStatusCode(), 'upload should succeed');

        return Attachment::where('attachable_id', $attachableId)->firstOrFail();
    }

    // === RECORD RESPONSE ===

    public function test_record_response_exposes_attachments_key(): void
    {
        $this->actingAs($this->admin());

        $request = Request::create('/progress', 'POST', [
            'inspection_date'         => '2026-06-15',
            'month_label'             => '2026-06',
            'actual_progress_percent' => 35,
        ]);

        $response = app(ProjectProgressController::class)->recordMonthlyProgress($request, $this->project);

        $this->assertTrue($response->getData()->success);
        $this->assertIsArray($response->getData()->attachments);
        $this->assertNotEmpty($response->getData()->progress_record->id);
    }

    // === UPLOAD ===

    public function test_upload_binds_to_newly_recorded_progress(): void
    {
        $this->actingAs($this->admin());
        $record = $this->recordMonthlyProgress($this->project);

        $this->actingAs($this->admin());
        $response = app(AttachmentController::class)->upload($this->uploadRequest($this->project, $record->id));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        $this->assertDatabaseHas('attachments', [
            'project_id'      => $this->project->id,
            'attachable_type' => ProjectProgressHistory::class,
            'attachable_id'   => $record->id,
            'context'         => 'inspection',
            'original_name'   => 'inspection-photo.jpg',
        ]);

        $attachment = Attachment::where('attachable_id', $record->id)->first();
        $this->assertTrue($attachment->fileExists(), 'Physical file should exist on the public disk');
        $this->assertStringStartsWith('projects/attachments/' . $this->project->code . '/inspection/', $attachment->file_path);
    }

    public function test_contractor_cannot_upload_progress_attachment(): void
    {
        $this->actingAs($this->admin());
        $record = $this->recordMonthlyProgress($this->project);

        $this->actingAs($this->contractorFor($this->project));
        $response = app(AttachmentController::class)->upload($this->uploadRequest($this->project, $record->id));

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        $this->assertDatabaseCount('attachments', 0);
    }

    // === EDIT RESPONSE ===

    public function test_edit_response_exposes_existing_attachment_ids(): void
    {
        $this->actingAs($this->admin());
        $record = $this->recordMonthlyProgress($this->project);
        $attachment = $this->uploadAs($this->admin(), $this->project, $record->id);

        $this->actingAs($this->admin());
        $request = Request::create("/progress/{$record->id}/edit", 'PUT', [
            'inspection_date'    => '2026-06-20',
            'progress_percentage'=> 45,
        ]);

        $response = app(ProjectProgressController::class)->editProgressRecord($request, $this->project, $record->id);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);
        $this->assertContains($attachment->id, $response->getData()->attachments);
    }

    public function test_record_morph_many_loads_attachments(): void
    {
        $this->actingAs($this->admin());
        $record = $this->recordMonthlyProgress($this->project);

        $this->actingAs($this->admin());
        app(AttachmentController::class)->upload($this->uploadRequest($this->project, $record->id));

        $loaded = ProjectProgressHistory::find($record->id)->load('attachments');

        $this->assertCount(1, $loaded->attachments);
        $this->assertEquals('inspection', $loaded->attachments->first()->context);
    }

    // === DELETE (deferred-flush server side) ===

    public function test_delete_attachment_removes_row_and_file(): void
    {
        $this->actingAs($this->admin());
        $record = $this->recordMonthlyProgress($this->project);
        $attachment = $this->uploadAs($this->admin(), $this->project, $record->id);
        $filePath = $attachment->file_path;

        $this->assertTrue($attachment->fileExists());

        $this->actingAs($this->admin());
        $response = app(AttachmentController::class)->delete($attachment);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        $this->assertDatabaseCount('attachments', 0);
        Storage::disk('public')->assertMissing($filePath);
    }

    public function test_contractor_cannot_delete_progress_attachment(): void
    {
        $this->actingAs($this->admin());
        $record = $this->recordMonthlyProgress($this->project);
        $attachment = $this->uploadAs($this->admin(), $this->project, $record->id);

        $this->actingAs($this->contractorFor($this->project));
        $response = app(AttachmentController::class)->delete($attachment);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        $this->assertDatabaseCount('attachments', 1);
        $this->assertTrue($attachment->fileExists());
    }

    // === DESTROY PROGRESS CLEANUP ===

    public function test_destroy_progress_cleans_attachments(): void
    {
        $this->actingAs($this->admin());
        $record = $this->recordMonthlyProgress($this->project);
        $attachment = $this->uploadAs($this->admin(), $this->project, $record->id);
        $filePath = $attachment->file_path;

        $this->assertTrue($attachment->fileExists());

        $this->actingAs($this->admin());
        $response = app(ProjectProgressController::class)->destroyProgress($this->project, $record->id);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        $this->assertDatabaseMissing('project_progress_history', ['id' => $record->id]);
        $this->assertDatabaseCount('attachments', 0);
        Storage::disk('public')->assertMissing($filePath);
    }

    public function test_contractor_cannot_destroy_progress(): void
    {
        $this->actingAs($this->admin());
        $record = $this->recordMonthlyProgress($this->project);

        $this->actingAs($this->contractorFor($this->project));
        $response = app(ProjectProgressController::class)->destroyProgress($this->project, $record->id);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        $this->assertDatabaseHas('project_progress_history', ['id' => $record->id]);
    }
}