<?php

namespace Tests\Feature;

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\RiskController;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Contractor;
use App\Models\Issue;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\Risk;
use App\Models\User;
use App\Notifications\IssueReported;
use App\Notifications\RiskReported;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ============================================================================
 * RiskRegisterTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the Risk Register feature (gap #20):
 *   - store() persists a risk and auto-computes risk_score (probability × impact)
 *   - store() derives the correct level at the Low/Medium/High boundaries
 *   - update() recomputes the score when probability/impact change
 *   - status transitions set mitigated_at / closed_at; reopening clears them
 *   - contractor gets 403 on store/update/destroy (read-only)
 *   - cross-project guard: a risk from another project is rejected on update
 *   - getRisks() returns the expected JSON shape incl. risk_level
 *   - ActivityLog rows are written on store / update / destroy
 *   - scopes open()/mitigated()/closed()/highRisk() filter correctly
 *   - HIGH-level risk creation notifies admins + the project's contractor
 *   - Low/Medium-level risk creation sends NO notification
 *
 * Conventions
 * -----------
 *   - Controllers are invoked DIRECTLY (same pattern as IssueLifecycleTest —
 *     route-based auth does not authenticate in this environment).
 *   - Users via User::create(); contractor linkage via Contractor::create().
 *   - Notifications are synchronous (RiskReported is not queued).
 *
 * Run With
 * --------
 *   php artisan test --filter=RiskRegisterTest
 */
class RiskRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['status' => 'Ongoing']);
    }

    // === HELPERS ===

    private function user(string $role): User
    {
        return User::create([
            'name'      => ucfirst($role),
            'email'     => fake()->unique()->safeEmail(),
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    private function storeRequest(array $payload): Request
    {
        return Request::create("/admin/projects/{$this->project->id}/risks", 'POST', $payload);
    }

    private function updateRequest(Risk $risk, array $payload): Request
    {
        return Request::create(
            "/admin/projects/{$this->project->id}/risks/{$risk->id}",
            'PUT',
            $payload
        );
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'risk_name'       => 'Cement price hike stalls Phase 2',
            'description'     => 'Supplier quotes keep rising.',
            'probability'     => 'Medium',
            'impact'          => 'Medium',
            'mitigation_plan' => 'Lock pricing with alternate suppliers.',
            'status'          => 'Open',
            'identified_at'   => now()->toDateString(),
        ], $overrides);
    }

    private function callStore(Request $request): \Illuminate\Http\JsonResponse
    {
        return app(RiskController::class)->store($request, $this->project);
    }

    private function callUpdate(Request $request, Risk $risk): \Illuminate\Http\JsonResponse
    {
        return app(RiskController::class)->update($request, $this->project, $risk);
    }

    // === STORE + SCORE COMPUTATION ===

    public function test_store_persists_and_computes_risk_score(): void
    {
        $this->actingAs($this->user('admin'));

        $response = $this->callStore($this->storeRequest(
            $this->validPayload(['probability' => 'High', 'impact' => 'Medium'])
        ));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        $this->assertDatabaseHas('risks', [
            'project_id'   => $this->project->id,
            'risk_name'    => 'Cement price hike stalls Phase 2',
            'probability'  => 'High',
            'impact'       => 'Medium',
            // High(3) × Medium(2) = 6
            'risk_score'   => 6,
            'status'       => 'Open',
        ]);
    }

    public function test_store_computes_risk_level_boundaries(): void
    {
        $this->actingAs($this->user('admin'));

        // Low boundary: Low(1) × Low(1) = 1 -> Low
        $this->callStore($this->storeRequest(
            $this->validPayload(['risk_name' => 'Low risk', 'probability' => 'Low', 'impact' => 'Low'])
        ));

        // Medium boundary: Medium(2) × Medium(2) = 4 -> Medium
        $this->callStore($this->storeRequest(
            $this->validPayload(['risk_name' => 'Medium risk', 'probability' => 'Medium', 'impact' => 'Medium'])
        ));

        // High boundary: High(3) × High(3) = 9 -> High
        $this->callStore($this->storeRequest(
            $this->validPayload(['risk_name' => 'High risk', 'probability' => 'High', 'impact' => 'High'])
        ));

        $this->assertSame('Low', Risk::where('risk_name', 'Low risk')->first()->risk_level);
        $this->assertSame('Medium', Risk::where('risk_name', 'Medium risk')->first()->risk_level);
        $this->assertSame('High', Risk::where('risk_name', 'High risk')->first()->risk_level);
    }

    public function test_store_requires_valid_probability_and_impact(): void
    {
        $this->actingAs($this->user('admin'));

        $response = $this->callStore($this->storeRequest(
            $this->validPayload(['probability' => 'Very High'])
        ));

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        $this->assertArrayHasKey('probability', (array) $response->getData()->errors);

        $this->assertDatabaseCount('risks', 0);
    }

    // === UPDATE RECOMPUTES SCORE ===

    public function test_update_recomputes_score_on_probability_impact_change(): void
    {
        $this->actingAs($this->user('admin'));

        $risk = Risk::factory()->create([
            'project_id'  => $this->project->id,
            'probability' => 'Medium',
            'impact'      => 'Medium',
            'risk_score'  => 4,
        ]);

        $response = $this->callUpdate($this->updateRequest($risk, $this->validPayload([
            'probability' => 'High',
            'impact'      => 'High',
        ])), $risk);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        $fresh = $risk->fresh();
        $this->assertSame(9, $fresh->risk_score);
        $this->assertSame('High', $fresh->risk_level);
    }

    // === STATUS TRANSITIONS ===

    public function test_update_sets_mitigated_at_when_mitigated(): void
    {
        $this->actingAs($this->user('admin'));

        $risk = Risk::factory()->create([
            'project_id' => $this->project->id,
            'status'     => 'Open',
        ]);

        $response = $this->callUpdate($this->updateRequest($risk, $this->validPayload([
            'status' => 'Mitigated',
        ])), $risk);

        $this->assertTrue($response->getData()->success);

        $fresh = $risk->fresh();
        $this->assertSame('Mitigated', $fresh->status);
        $this->assertNotNull($fresh->mitigated_at);
        $this->assertNull($fresh->closed_at);
    }

    public function test_update_to_closed_preserves_mitigated_at_and_sets_closed_at(): void
    {
        $this->actingAs($this->user('admin'));

        $mitigatedAt = now()->subDays(5);
        $risk = Risk::factory()->mitigated()->create([
            'project_id'  => $this->project->id,
            'mitigated_at' => $mitigatedAt,
        ]);

        $response = $this->callUpdate($this->updateRequest($risk, $this->validPayload([
            'status' => 'Closed',
        ])), $risk);

        $this->assertTrue($response->getData()->success);

        $fresh = $risk->fresh();
        $this->assertSame('Closed', $fresh->status);
        $this->assertNotNull($fresh->closed_at);
        // Original mitigation date must survive the transition
        $this->assertSame(
            $mitigatedAt->toDateString(),
            $fresh->mitigated_at->toDateString()
        );
    }

    public function test_reopen_clears_lifecycle_timestamps(): void
    {
        $this->actingAs($this->user('admin'));

        $risk = Risk::factory()->closed()->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->callUpdate($this->updateRequest($risk, $this->validPayload([
            'status' => 'Open',
        ])), $risk);

        $this->assertTrue($response->getData()->success);

        $fresh = $risk->fresh();
        $this->assertSame('Open', $fresh->status);
        $this->assertNull($fresh->mitigated_at);
        $this->assertNull($fresh->closed_at);
    }

    // === PERMISSIONS ===

    public function test_contractor_cannot_store_update_or_destroy(): void
    {
        $contractorUser = $this->user('contractor');
        $this->actingAs($contractorUser);

        // Store -> 403
        $store = $this->callStore($this->storeRequest($this->validPayload()));
        $this->assertEquals(403, $store->getStatusCode());
        $this->assertDatabaseCount('risks', 0);

        // Update -> 403
        $risk = Risk::factory()->create(['project_id' => $this->project->id]);
        $update = $this->callUpdate($this->updateRequest($risk, $this->validPayload()), $risk);
        $this->assertEquals(403, $update->getStatusCode());

        // Destroy -> 403
        $destroy = app(RiskController::class)->destroy($this->project, $risk);
        $this->assertEquals(403, $destroy->getStatusCode());
        $this->assertDatabaseCount('risks', 1);
    }

    public function test_update_rejects_risk_from_another_project(): void
    {
        $this->actingAs($this->user('admin'));

        $otherProject = Project::factory()->create();
        $foreignRisk = Risk::factory()->create(['project_id' => $otherProject->id]);

        $response = $this->callUpdate($this->updateRequest($foreignRisk, $this->validPayload()), $foreignRisk);

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
    }

    // === GET RISKS JSON SHAPE ===

    public function test_get_risks_returns_expected_json_shape(): void
    {
        $this->actingAs($this->user('admin'));

        $risk = Risk::factory()->high()->create([
            'project_id'  => $this->project->id,
            'risk_name'   => 'Permit approval delayed',
            'identified_at' => now()->toDateString(),
        ]);

        $response = app(RiskController::class)->getRisks($this->project);

        $this->assertEquals(200, $response->getStatusCode());
        $data = $response->getData();

        $this->assertTrue($data->success);
        $this->assertSame(1, $data->count);

        $first = $data->risks[0];
        $this->assertSame($risk->id, $first->id);
        $this->assertSame('Permit approval delayed', $first->risk_name);
        $this->assertSame('High', $first->probability);
        $this->assertSame('High', $first->impact);
        $this->assertSame(9, $first->risk_score);
        $this->assertSame('High', $first->risk_level);
        $this->assertSame('Open', $first->status);
        $this->assertNotNull($first->identified_at);
        $this->assertNotNull($first->created_at);
        $this->assertObjectHasProperty('mitigation_plan', $first);
        $this->assertObjectHasProperty('description', $first);
        $this->assertObjectHasProperty('mitigated_at', $first);
        $this->assertObjectHasProperty('closed_at', $first);
    }

    // === ACTIVITY LOG ===

    public function test_activity_log_created_on_store_update_destroy(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin);

        // Store
        $this->callStore($this->storeRequest($this->validPayload(['risk_name' => 'Audited risk'])));

        $risk = Risk::first();
        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $this->project->id,
            'action'     => 'Create Risk',
        ]);

        // Update
        $this->callUpdate($this->updateRequest($risk, $this->validPayload([
            'risk_name' => 'Audited risk (edited)',
        ])), $risk);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $this->project->id,
            'action'     => 'Update Risk',
        ]);

        // Destroy
        $destroy = app(RiskController::class)->destroy($this->project, $risk);
        $this->assertTrue($destroy->getData()->success);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $this->project->id,
            'action'     => 'Delete Risk',
        ]);

        $this->assertDatabaseCount('risks', 0);
        $this->assertDatabaseCount('activity_logs', 3);
    }

    // === SCOPES ===

    public function test_scopes_open_mitigated_closed_high_risk(): void
    {
        $open = Risk::factory()->open()->create(['project_id' => $this->project->id, 'risk_score' => 2]);
        $mitigated = Risk::factory()->mitigated()->create(['project_id' => $this->project->id, 'risk_score' => 4]);
        $closed = Risk::factory()->closed()->create(['project_id' => $this->project->id, 'risk_score' => 6]);
        $high = Risk::factory()->high()->create(['project_id' => $this->project->id, 'status' => 'Open', 'risk_score' => 9]);

        $this->assertEqualsCanonicalizing(
            [$open->id, $high->id],
            Risk::open()->pluck('id')->all()
        );
        $this->assertEquals([$mitigated->id], Risk::mitigated()->pluck('id')->all());
        $this->assertEquals([$closed->id], Risk::closed()->pluck('id')->all());
        $this->assertEquals([$high->id], Risk::highRisk()->pluck('id')->all());
    }

    // === HIGH-RISK NOTIFICATION ===

    private function projectWithContractor(): User
    {
        $contractorUser = $this->user('contractor');
        $contractor = Contractor::create([
            'user_id'         => $contractorUser->id,
            'contractor_name' => 'Builders Inc',
            'company_name'    => 'Builders Inc',
        ]);
        $this->project->update(['contractor_id' => $contractor->id]);

        return $contractorUser;
    }

    public function test_high_risk_creation_notifies_admins_and_project_contractor(): void
    {
        $admin = $this->user('admin');
        $contractorUser = $this->projectWithContractor();

        $this->actingAs($admin);
        $response = $this->callStore($this->storeRequest($this->validPayload([
            'risk_name'   => 'Structural collapse risk',
            'probability' => 'High',
            'impact'      => 'High',
        ])));

        $this->assertTrue($response->getData()->success);

        // Admin notified
        $this->assertSame(1, $admin->notifications()->count());
        $adminNotification = $admin->notifications()->first();
        $this->assertSame(RiskReported::class, $adminNotification->type);
        $this->assertSame('High', $adminNotification->data['severity']);
        $this->assertSame('Structural collapse risk', $adminNotification->data['risk_name']);
        $this->assertSame($this->project->id, $adminNotification->data['project_id']);
        $this->assertStringContainsString('risk:'.$this->project->id.':', $adminNotification->data['key']);

        // Project contractor notified
        $this->assertSame(1, $contractorUser->notifications()->count());
        $contractorNotification = $contractorUser->notifications()->first();
        $this->assertSame(RiskReported::class, $contractorNotification->type);
        $this->assertSame('Structural collapse risk', $contractorNotification->data['risk_name']);
    }

    public function test_high_risk_notification_fires_only_once_per_risk(): void
    {
        $admin = $this->user('admin');
        $this->projectWithContractor();

        $this->actingAs($admin);
        $this->callStore($this->storeRequest($this->validPayload([
            'probability' => 'High',
            'impact'      => 'High',
        ])));

        $risk = Risk::first();

        // A second update to the same risk must NOT re-fire the notification
        $this->callUpdate($this->updateRequest($risk, $this->validPayload([
            'probability' => 'High',
            'impact'      => 'High',
            'risk_name'   => 'Renamed risk',
        ])), $risk);

        $this->assertSame(1, $admin->notifications()->count());
    }

    public function test_low_medium_risk_creation_sends_no_notification(): void
    {
        $admin = $this->user('admin');
        $contractorUser = $this->projectWithContractor();

        $this->actingAs($admin);

        // Low risk
        $this->callStore($this->storeRequest($this->validPayload([
            'probability' => 'Low',
            'impact'      => 'Low',
        ])));

        // Medium risk
        $this->callStore($this->storeRequest($this->validPayload([
            'probability' => 'Medium',
            'impact'      => 'Medium',
        ])));

        $this->assertSame(0, $admin->notifications()->count());
        $this->assertSame(0, $contractorUser->notifications()->count());
    }

    // === RISK → ISSUE CONVERSION ===

    private function callConvert(Risk $risk, array $payload = []): \Illuminate\Http\JsonResponse
    {
        $request = Request::create(
            "/admin/projects/{$this->project->id}/risks/{$risk->id}/convert",
            'POST',
            $payload
        );

        return app(RiskController::class)->convertToIssue($request, $this->project, $risk);
    }

    public function test_convert_creates_issue_with_bidirectional_fks(): void
    {
        $this->actingAs($this->user('admin'));

        $risk = Risk::factory()->open()->create(['project_id' => $this->project->id]);

        $response = $this->callConvert($risk, [
            'issue_name'  => 'Converted issue',
            'description' => 'Materialized.',
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->success);

        // Issue created with the link back to the risk
        $issue = Issue::where('source_risk_id', $risk->id)->first();
        $this->assertNotNull($issue);
        $this->assertSame('Converted issue', $issue->issue_name);
        $this->assertSame('Open', $issue->status);

        // Risk marked Realized with the link forward to the issue
        $fresh = $risk->fresh();
        $this->assertSame('Realized', $fresh->status);
        $this->assertSame($issue->id, $fresh->converted_to_issue_id);
    }

    public function test_convert_maps_severity_from_risk_level(): void
    {
        $this->actingAs($this->user('admin'));

        $cases = [
            'Low'    => ['probability' => 'Low',    'impact' => 'Low'],
            'Medium' => ['probability' => 'Medium', 'impact' => 'Medium'],
            'High'   => ['probability' => 'High',   'impact' => 'High'],
        ];

        foreach ($cases as $expectedSeverity => $pair) {
            $risk = Risk::factory()->create([
                'project_id'  => $this->project->id,
                'probability' => $pair['probability'],
                'impact'      => $pair['impact'],
                'risk_score'  => Risk::computeScore($pair['probability'], $pair['impact']),
            ]);
            $this->assertSame($expectedSeverity, $risk->risk_level);

            $this->callConvert($risk, ['issue_name' => $risk->risk_name, 'description' => 'd']);

            $issue = Issue::where('source_risk_id', $risk->id)->first();
            $this->assertSame($expectedSeverity, $issue->severity);
        }
    }

    public function test_convert_carries_baseline_schedule_id_to_issue(): void
    {
        $this->actingAs($this->user('admin'));

        $baseline = ProjectBaselineSchedule::factory()->create(['project_id' => $this->project->id]);
        $risk = Risk::factory()->create([
            'project_id'           => $this->project->id,
            'baseline_schedule_id' => $baseline->id,
        ]);

        $this->callConvert($risk, ['issue_name' => $risk->risk_name, 'description' => 'd']);

        $issue = Issue::where('source_risk_id', $risk->id)->first();
        $this->assertSame($baseline->id, $issue->baseline_schedule_id);
    }

    public function test_convert_applies_editable_fields_from_modal(): void
    {
        $this->actingAs($this->user('admin'));

        $baseline = ProjectBaselineSchedule::factory()->create(['project_id' => $this->project->id]);
        $risk = Risk::factory()->create(['project_id' => $this->project->id]);

        $this->callConvert($risk, [
            'issue_name'   => 'Edited name from modal',
            'description'  => 'Edited description',
            'issue_type'   => 'material',
            'severity'     => 'Critical',
            'baseline_schedule_id' => $baseline->id,
        ]);

        $issue = Issue::where('source_risk_id', $risk->id)->first();
        $this->assertSame('Edited name from modal', $issue->issue_name);
        $this->assertSame('Edited description', $issue->description);
        $this->assertSame('material', $issue->issue_type);
        $this->assertSame('Critical', $issue->severity);
        $this->assertSame($baseline->id, $issue->baseline_schedule_id);
    }

    public function test_convert_transfers_and_relabels_risk_attachments(): void
    {
        Storage::fake('public');
        $this->actingAs($this->user('admin'));

        $risk = Risk::factory()->open()->create(['project_id' => $this->project->id]);

        // Attach risk evidence (context 'risk') to the risk
        $upload = Request::create('/attachments/upload', 'POST', [
            'project_id'      => $this->project->id,
            'attachable_type' => Risk::class,
            'attachable_id'   => $risk->id,
            'context'         => 'risk',
        ]);
        $upload->files->set('file', UploadedFile::fake()->createWithContent(
            'risk-evidence.jpg',
            "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9",
            'image/jpeg'
        ));
        $uploadResponse = app(AttachmentController::class)->upload($upload);
        $this->assertEquals(200, $uploadResponse->getStatusCode());
        $this->assertDatabaseHas('attachments', [
            'attachable_type' => Risk::class,
            'attachable_id'   => $risk->id,
            'context'         => 'risk',
        ]);

        $this->callConvert($risk, ['issue_name' => $risk->risk_name, 'description' => 'd']);

        // Attachment now belongs to the issue, relabelled context 'issue'
        $issue = Issue::where('source_risk_id', $risk->id)->first();
        $this->assertSame(1, Attachment::where('attachable_type', Issue::class)
            ->where('attachable_id', $issue->id)
            ->where('context', 'issue')
            ->count());
        $this->assertDatabaseMissing('attachments', [
            'attachable_type' => Risk::class,
            'attachable_id'   => $risk->id,
        ]);
    }

    public function test_convert_fires_issuereported_notification(): void
    {
        $admin = $this->user('admin');
        $contractorUser = $this->projectWithContractor();
        $this->actingAs($admin);

        $risk = Risk::factory()->create(['project_id' => $this->project->id]);
        $this->callConvert($risk, ['issue_name' => $risk->risk_name, 'description' => 'd']);

        $issue = Issue::first();

        // Admin notified
        $this->assertSame(1, $admin->notifications()->count());
        $adminNotification = $admin->notifications()->first();
        $this->assertSame(IssueReported::class, $adminNotification->type);
        $this->assertSame($issue->id, $adminNotification->data['issue_id']);

        // Project contractor notified
        $this->assertSame(1, $contractorUser->notifications()->count());
        $this->assertSame(IssueReported::class, $contractorUser->notifications()->first()->type);
    }

    public function test_contractor_cannot_convert_risk(): void
    {
        $this->actingAs($this->user('contractor'));

        $risk = Risk::factory()->create(['project_id' => $this->project->id]);
        $response = $this->callConvert($risk, ['issue_name' => $risk->risk_name, 'description' => 'd']);

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertFalse($response->getData()->success);
        $this->assertDatabaseCount('issues', 0);
    }

    public function test_convert_rejects_realized_or_closed_risk(): void
    {
        $this->actingAs($this->user('admin'));

        $realized = Risk::factory()->realized()->create(['project_id' => $this->project->id]);
        $r1 = $this->callConvert($realized, ['issue_name' => 'x', 'description' => 'd']);
        $this->assertEquals(422, $r1->getStatusCode());

        $closed = Risk::factory()->closed()->create(['project_id' => $this->project->id]);
        $r2 = $this->callConvert($closed, ['issue_name' => 'x', 'description' => 'd']);
        $this->assertEquals(422, $r2->getStatusCode());

        $this->assertDatabaseCount('issues', 0);
    }

    // === DELETE-LINK GUARDS ===

    public function test_destroy_rejects_risk_converted_to_issue(): void
    {
        $this->actingAs($this->user('admin'));

        $risk = Risk::factory()->open()->create(['project_id' => $this->project->id]);
        $this->callConvert($risk, ['issue_name' => 'x', 'description' => 'd']);
        $issue = Issue::first();

        $response = app(RiskController::class)->destroy($this->project, $risk->fresh());

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertDatabaseHas('risks', ['id' => $risk->id, 'status' => 'Realized']);
        $this->assertDatabaseHas('issues', ['id' => $issue->id]);
    }

    public function test_destroy_converted_issue_reverts_risk_to_open(): void
    {
        $this->actingAs($this->user('admin'));

        $risk = Risk::factory()->open()->create(['project_id' => $this->project->id]);
        $this->callConvert($risk, ['issue_name' => 'x', 'description' => 'd']);
        $issue = Issue::first();
        $this->assertSame('Realized', $risk->fresh()->status);

        $response = app(IssueController::class)->destroy($this->project, $issue);

        $this->assertTrue($response->getData()->success);

        $fresh = $risk->fresh();
        $this->assertSame('Open', $fresh->status);
        $this->assertNull($fresh->converted_to_issue_id);
    }

    // === REALIZED STATUS BEHAVIOR ===

    public function test_realized_status_excluded_from_open_scope(): void
    {
        $open = Risk::factory()->open()->create(['project_id' => $this->project->id]);
        Risk::factory()->realized()->create(['project_id' => $this->project->id]);

        $this->assertEquals([$open->id], Risk::open()->pluck('id')->all());
    }

    public function test_update_allows_realized_status_and_preserves_lifecycle(): void
    {
        $this->actingAs($this->user('admin'));

        $mitigatedAt = now()->subDays(3);
        $risk = Risk::factory()->create([
            'project_id'   => $this->project->id,
            'status'       => 'Realized',
            'mitigated_at' => $mitigatedAt,
        ]);

        $response = $this->callUpdate($this->updateRequest($risk, $this->validPayload([
            'status'    => 'Realized',
            'risk_name' => 'Realized risk renamed',
        ])), $risk);

        $this->assertTrue($response->getData()->success);

        $fresh = $risk->fresh();
        $this->assertSame('Realized', $fresh->status);
        $this->assertSame('Realized risk renamed', $fresh->risk_name);
        $this->assertSame($mitigatedAt->toDateString(), $fresh->mitigated_at->toDateString());
    }

    // === BASELINE LINK ON RISKS ===

    public function test_store_persists_baseline_schedule_id(): void
    {
        $this->actingAs($this->user('admin'));

        $baseline = ProjectBaselineSchedule::factory()->create(['project_id' => $this->project->id]);

        $response = $this->callStore($this->storeRequest($this->validPayload([
            'baseline_schedule_id' => $baseline->id,
        ])));

        $this->assertTrue($response->getData()->success);
        $this->assertDatabaseHas('risks', [
            'project_id'           => $this->project->id,
            'baseline_schedule_id' => $baseline->id,
        ]);
    }

    public function test_store_rejects_baseline_from_another_project(): void
    {
        $this->actingAs($this->user('admin'));

        $otherProject = Project::factory()->create();
        $foreignBaseline = ProjectBaselineSchedule::factory()->create(['project_id' => $otherProject->id]);

        $response = $this->callStore($this->storeRequest($this->validPayload([
            'baseline_schedule_id' => $foreignBaseline->id,
        ])));

        $this->assertEquals(422, $response->getStatusCode());
        $this->assertDatabaseCount('risks', 0);
    }

    public function test_get_risks_exposes_conversion_payload_fields(): void
    {
        $this->actingAs($this->user('admin'));

        $baseline = ProjectBaselineSchedule::factory()->create(['project_id' => $this->project->id]);
        $risk = Risk::factory()->open()->create([
            'project_id'           => $this->project->id,
            'baseline_schedule_id' => $baseline->id,
        ]);
        $this->callConvert($risk, ['issue_name' => 'Shown as label', 'description' => 'd']);

        $response = app(RiskController::class)->getRisks($this->project);
        $first = $response->getData()->risks[0];

        $this->assertSame($baseline->id, $first->baseline_schedule_id);
        $this->assertSame($baseline->month_display, $first->baseline_label);
        $this->assertSame('Realized', $first->status);
        $this->assertNotNull($first->converted_to_issue_id);
        $this->assertStringContainsString('Shown as label', $first->converted_issue_label);
        $this->assertIsArray($first->attachments);
    }
}
