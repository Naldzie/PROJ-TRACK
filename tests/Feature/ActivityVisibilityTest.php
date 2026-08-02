<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\ProjectProgressHistory;
use App\Models\User;
use App\Services\ProjectOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ============================================================================
 * ActivityVisibilityTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the Phase A/B test-fix — Activity Log Visibility:
 *   - Overview feed (project-scoped) shows deletions to staff.
 *   - Dashboard feed hides routine deletions + admin-housekeeping from staff
 *     (Delete Project, Login, Logout, Create Project still shown).
 *   - Admin dashboard feed is unaffected (sees everything).
 *   - Attachment upload/delete logs now carry project_id.
 *
 * Run With
 * --------
 *   php artisan test --filter=ActivityVisibilityTest
 */
class ActivityVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private User $admin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'  => 'Visibility Admin',
            'email' => fake()->unique()->safeEmail(),
            'role'  => 'admin',
        ]);

        $this->staff = User::create([
            'name'  => 'Visibility Staff',
            'email' => fake()->unique()->safeEmail(),
            'role'  => 'staff',
        ]);

        $this->actingAs($this->admin);

        $this->project = Project::factory()->create(['status' => 'Ongoing']);
    }

    private function log(string $action, string $visibility = 'all', ?int $projectId = null, int $minutesAgo = 0): ActivityLog
    {
        $log = ActivityLog::create([
            'user_id'     => $this->admin->id,
            'project_id'  => $projectId,
            'action'      => $action,
            'description' => 'Test ' . $action,
            'ip_address'  => '127.0.0.1',
            'visibility'  => $visibility,
        ]);

        if ($minutesAgo > 0) {
            $log->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
        }

        return $log;
    }

    private function actionsForRole(string $role): array
    {
        return ActivityLog::forDashboard($role)
            ->latest()
            ->get()
            ->pluck('action')
            ->all();
    }

    // === Overview: staff see deletions on a project ===

    public function test_staff_overview_activity_includes_project_deletions(): void
    {
        $this->log('Delete Progress Record', 'all', $this->project->id);
        $this->log('Delete Baseline Schedule', 'all', $this->project->id);

        $this->actingAs($this->staff);

        $activity = app(ProjectOverviewService::class)->getRecentActivity($this->project);

        $this->assertTrue($activity->contains('action', 'Delete Progress Record'));
        $this->assertTrue($activity->contains('action', 'Delete Baseline Schedule'));
    }

    // === Dashboard scope: staff ===

    public function test_staff_dashboard_excludes_routine_deletions_but_keeps_delete_project(): void
    {
        $this->log('Delete Progress Record', 'all', $this->project->id);
        $this->log('Delete Baseline Schedule', 'all', $this->project->id);
        $this->log('Delete Checklist Item', 'all', $this->project->id);
        $this->log('Delete Extension', 'all', $this->project->id);
        $this->log('Delete Document', 'all', $this->project->id);
        $this->log('Delete Attachment', 'all', $this->project->id);
        $this->log('Delete Project', 'all');

        $actions = $this->actionsForRole('staff');

        $this->assertNotContains('Delete Progress Record', $actions);
        $this->assertNotContains('Delete Baseline Schedule', $actions);
        $this->assertNotContains('Delete Checklist Item', $actions);
        $this->assertNotContains('Delete Extension', $actions);
        $this->assertNotContains('Delete Document', $actions);
        $this->assertNotContains('Delete Attachment', $actions);
        $this->assertContains('Delete Project', $actions);
    }

    public function test_staff_dashboard_keeps_project_updates_and_login(): void
    {
        $this->log('Record Monthly Progress', 'all', $this->project->id);
        $this->log('Create Project', 'all');
        $this->log('Login', 'all');
        $this->log('Logout', 'all');

        $actions = $this->actionsForRole('staff');

        $this->assertContains('Record Monthly Progress', $actions);
        $this->assertContains('Create Project', $actions);
        $this->assertContains('Login', $actions);
        $this->assertContains('Logout', $actions);
    }

    public function test_staff_dashboard_excludes_admin_housekeeping(): void
    {
        $this->log('Edit User', 'all');
        $this->log('Password Change', 'all');

        $actions = $this->actionsForRole('staff');

        $this->assertNotContains('Edit User', $actions);
        $this->assertNotContains('Password Change', $actions);
    }

    public function test_staff_dashboard_still_hides_admin_only_entries(): void
    {
        $this->log('staff_created', 'admin_only');
        $this->log('contractor_created', 'admin_only');

        $actions = $this->actionsForRole('staff');

        $this->assertNotContains('staff_created', $actions);
        $this->assertNotContains('contractor_created', $actions);
    }

    // === Dashboard scope: admin ===

    public function test_admin_dashboard_sees_everything(): void
    {
        $this->log('Delete Progress Record', 'all', $this->project->id);
        $this->log('Edit User', 'all');
        $this->log('Password Change', 'all');
        $this->log('staff_created', 'admin_only');

        $actions = $this->actionsForRole('admin');

        $this->assertContains('Delete Progress Record', $actions);
        $this->assertContains('Edit User', $actions);
        $this->assertContains('Password Change', $actions);
        $this->assertContains('staff_created', $actions);
    }

    // === Attachment logs now project-scoped ===

    public function test_attachment_upload_log_carries_project_id(): void
    {
        Storage::fake('public');

        $record = ProjectProgressHistory::factory()->create([
            'project_id' => $this->project->id,
            'month_label' => '2026-08',
            'actual_progress_percent' => 10,
            'inspection_date' => '2026-08-15',
        ]);

        $request = Request::create('/attachments/upload', 'POST', [
            'project_id'      => $this->project->id,
            'attachable_type' => ProjectProgressHistory::class,
            'attachable_id'   => $record->id,
            'context'         => 'inspection',
        ]);
        $request->files->set('file', UploadedFile::fake()->createWithContent(
            'inspection-photo.jpg',
            "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9",
            'image/jpeg'
        ));

        $response = app(\App\Http\Controllers\AttachmentController::class)->upload($request);

        $this->assertTrue($response->getData()->success);

        $log = ActivityLog::where('action', 'Upload Attachment')
            ->where('project_id', $this->project->id)
            ->first();

        $this->assertNotNull($log, 'Attachment upload should create a project-scoped activity log');
    }
}
