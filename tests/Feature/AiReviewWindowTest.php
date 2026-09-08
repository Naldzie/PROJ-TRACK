<?php

namespace Tests\Feature;

use App\Mail\AiAnalysisMail;
use App\Models\AiSolution;
use App\Models\Contractor;
use App\Models\EmailTracking;
use App\Models\Project;
use App\Models\User;
use App\Services\AIReportSendingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ============================================================================
 * AiReviewWindowTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the 5-day review window between AI analysis generation (25th)
 * and email delivery (30th / review window end):
 *   - Generate step stages pending_review drafts and sends NO mail.
 *   - Generate is idempotent mid-review (no duplicates, no re-staging).
 *   - Send step ignores reviews whose window has not ended.
 *   - Send step delivers due reviews carrying the EDITED body, then locks them.
 *   - Manual drafts (status draft) are never auto-sent, even with --force.
 *   - In-review reports cannot be deleted, can be edited without sending,
 *     and can be sent early on demand.
 *   - Immediate sends reuse a staged row instead of duplicating it.
 *   - Contractors cannot see or open unsent reviews.
 *
 * NOTE: assertions on sent mail check recipient + tracking identity only —
 * the mailable subject is set in build(), which never runs under Mail::fake.
 *
 * Run With
 * --------
 *   php artisan test --filter=AiReviewWindowTest
 */
class AiReviewWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private User $contractorUser;
    private Contractor $contractor;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Review Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $this->staff = User::create(['name' => 'Review Staff', 'email' => fake()->unique()->safeEmail(), 'role' => 'staff', 'is_active' => true]);
        $this->contractorUser = User::create(['name' => 'Review Contractor', 'email' => fake()->unique()->safeEmail(), 'role' => 'contractor', 'is_active' => true]);

        $this->contractor = Contractor::create([
            'user_id'         => $this->contractorUser->id,
            'contractor_name' => 'Review Builders',
            'company_name'    => 'Review Builders Inc',
        ]);

        $this->project = Project::factory()->create([
            'status'        => 'Ongoing',
            'contractor_id' => $this->contractor->id,
        ]);

        AiSolution::factory()->create([
            'project_id'    => $this->project->id,
            'analysis_text' => 'Original AI analysis text.',
        ]);
    }

    private function stageReview(array $overrides = []): EmailTracking
    {
        $aiSolution = AiSolution::where('project_id', $this->project->id)->latest('created_at')->first();

        return EmailTracking::create(array_merge([
            'contractor_id'   => $this->contractor->id,
            'project_id'      => $this->project->id,
            'ai_solution_id'  => $aiSolution->id,
            'recipient_email' => $this->contractorUser->email,
            'subject'         => 'AI Project Status Report — Review Window',
            'status'          => EmailTracking::STATUS_PENDING_REVIEW,
            'review_until'    => now()->addDays(AIReportSendingService::REVIEW_WINDOW_DAYS),
            'body'            => null,
            'tracking_token'  => EmailTracking::generateToken(),
            'sent_at'         => null,
        ], $overrides));
    }

    public function test_generate_command_stages_reviews_without_sending_mail(): void
    {
        Mail::fake();

        Artisan::call('reports:generate-ai-monthly', ['--force' => true]);

        Mail::assertNothingSent();

        $tracking = EmailTracking::first();
        $this->assertNotNull($tracking);
        $this->assertSame(EmailTracking::STATUS_PENDING_REVIEW, $tracking->status);
        $this->assertNull($tracking->sent_at);
        $this->assertNotNull($tracking->review_until);
        // 5-day window (allow a small margin for execution time).
        $this->assertTrue($tracking->review_until->gt(now()->addDays(4)));
        $this->assertTrue($tracking->isPendingReview());
        $this->assertFalse($tracking->isDueForSending());
    }

    public function test_generate_command_is_idempotent_mid_review(): void
    {
        Mail::fake();

        Artisan::call('reports:generate-ai-monthly', ['--force' => true]);
        Artisan::call('reports:generate-ai-monthly', ['--force' => true]);

        $this->assertCount(1, EmailTracking::all());
        Mail::assertNothingSent();
    }

    public function test_send_command_ignores_reviews_whose_window_has_not_ended(): void
    {
        Mail::fake();
        $tracking = $this->stageReview(['review_until' => now()->addDays(3)]);

        Artisan::call('reports:send-ai-monthly');

        Mail::assertNothingSent();
        $this->assertNull($tracking->fresh()->sent_at);
        $this->assertSame(EmailTracking::STATUS_PENDING_REVIEW, $tracking->fresh()->status);
    }

    public function test_send_command_delivers_due_reviews_with_edited_body_then_locks(): void
    {
        Mail::fake();
        $tracking = $this->stageReview([
            'review_until' => now()->subHour(),
            'body'         => 'Staff edited version of the analysis.',
            'is_edited'    => true,
            'edited_by'    => $this->staff->id,
            'edited_at'    => now(),
        ]);

        Artisan::call('reports:send-ai-monthly');

        Mail::assertSent(AiAnalysisMail::class, function (AiAnalysisMail $mail) use ($tracking) {
            return $mail->hasTo($this->contractorUser->email)
                && $mail->tracking->is($tracking);
        });

        $fresh = $tracking->fresh();
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame(EmailTracking::STATUS_SENT, $fresh->status);
        // The edited body is what the email renders (body wins over AI text).
        $this->assertSame('Staff edited version of the analysis.', $fresh->effectiveBody());

        // A second run does not re-send.
        Mail::assertSent(AiAnalysisMail::class, 1);
        Artisan::call('reports:send-ai-monthly');
        Mail::assertSent(AiAnalysisMail::class, 1);
    }

    public function test_send_command_never_auto_sends_manual_drafts(): void
    {
        Mail::fake();
        EmailTracking::create([
            'contractor_id'   => $this->contractor->id,
            'project_id'      => $this->project->id,
            'generated_by'    => $this->staff->id,
            'recipient_email' => $this->contractorUser->email,
            'subject'         => 'Manual draft stays put',
            'status'          => EmailTracking::STATUS_DRAFT,
            'body'            => 'Hand-written note.',
            'tracking_token'  => EmailTracking::generateToken(),
            'sent_at'         => null,
        ]);

        Artisan::call('reports:send-ai-monthly');
        Artisan::call('reports:send-ai-monthly', ['--force' => true]);

        Mail::assertNothingSent();
        $this->assertSame(EmailTracking::STATUS_DRAFT, EmailTracking::first()->status);
    }

    public function test_in_review_reports_cannot_be_deleted_but_can_be_edited_and_sent_early(): void
    {
        Mail::fake();
        $tracking = $this->stageReview();

        // Delete is blocked (would silently drop the project from the batch).
        $this->actingAs($this->staff)->deleteJson(route('staff.emails.destroy', $tracking))
            ->assertStatus(403);
        $this->actingAs($this->admin)->deleteJson(route('admin.emails.destroy', $tracking))
            ->assertStatus(403);
        $this->assertDatabaseHas('email_trackings', ['id' => $tracking->id]);

        // Edit during the window saves without sending.
        $this->actingAs($this->staff)->putJson(route('staff.emails.update', $tracking), [
            'subject' => 'Reviewed subject',
            'body'    => 'Reviewed body with corrections.',
        ])->assertOk()->assertJsonPath('resent', false);

        Mail::assertNothingSent();
        $this->assertTrue($tracking->fresh()->is_edited);
        $this->assertTrue($tracking->fresh()->isPendingReview());

        // Early send on demand works and locks the row as sent.
        $this->actingAs($this->staff)->postJson(route('staff.emails.send', $tracking))
            ->assertOk()->assertJsonPath('success', true);

        Mail::assertSent(AiAnalysisMail::class, function (AiAnalysisMail $mail) use ($tracking) {
            return $mail->hasTo($this->contractorUser->email)
                && $mail->tracking->is($tracking);
        });
        $this->assertSame(EmailTracking::STATUS_SENT, $tracking->fresh()->status);
    }

    public function test_immediate_send_reuses_staged_row_instead_of_duplicating(): void
    {
        Mail::fake();
        $tracking = $this->stageReview(['review_until' => now()->addDays(3)]);

        $result = app(AIReportSendingService::class)->sendForProject($this->project->fresh());

        $this->assertTrue($result['success']);
        $this->assertCount(1, EmailTracking::all());
        $this->assertSame(EmailTracking::STATUS_SENT, $tracking->fresh()->status);
        Mail::assertSent(AiAnalysisMail::class, 1);
    }

    public function test_staging_skips_projects_without_analysis(): void
    {
        $bare = Project::factory()->create([
            'status'        => 'Ongoing',
            'contractor_id' => $this->contractor->id,
        ]);

        $result = app(AIReportSendingService::class)->stageProjectForReview($bare);

        $this->assertFalse($result['success']);
        $this->assertSame('No AI analysis found', $result['message']);
        $this->assertCount(0, EmailTracking::all());
    }

    public function test_contractors_cannot_see_or_open_unsent_reviews(): void
    {
        $tracking = $this->stageReview();

        $this->actingAs($this->contractorUser)->get(route('contractor.email'))
            ->assertOk()
            ->assertDontSee('AI Project Status Report — Review Window');

        $this->actingAs($this->contractorUser)->getJson(route('contractor.emails.show', $tracking))
            ->assertStatus(404);
    }

    public function test_review_filter_lists_only_staged_reviews(): void
    {        $this->stageReview(['subject' => 'ZZZ staged review row']);
        EmailTracking::create([
            'contractor_id'   => $this->contractor->id,
            'project_id'      => $this->project->id,
            'generated_by'    => $this->staff->id,
            'recipient_email' => $this->contractorUser->email,
            'subject'         => 'QQQ manual draft row',
            'status'          => EmailTracking::STATUS_DRAFT,
            'body'            => 'Manual.',
            'tracking_token'  => EmailTracking::generateToken(),
            'sent_at'         => null,
        ]);

        // Use the AJAX endpoint: it renders only the list partial, excluding
        // the "Recent activity" sidebar that appears on the full page.
        $response = $this->actingAs($this->staff)->getJson(
            route('staff.email', ['filter' => 'review']),
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertStringContainsString('ZZZ staged review row', $response->json('html'));
        $this->assertStringContainsString('data-status="review"', $response->json('html'));
        $this->assertStringNotContainsString('QQQ manual draft row', $response->json('html'));
    }

    public function test_staging_a_fresh_analysis_replaces_the_older_staged_draft(): void
    {
        Mail::fake();
        $service = app(AIReportSendingService::class);

        // First cycle staged.
        $service->stageProjectForReview($this->project->fresh());
        $this->assertCount(1, EmailTracking::all());

        // A regeneration produces a newer analysis…
        $newer = AiSolution::factory()->create([
            'project_id'    => $this->project->id,
            'analysis_text' => 'Regenerated analysis text.',
        ]);
        // Force ordering (created_at is not fillable, so bypass the model).
        AiSolution::where('id', $newer->id)->update(['created_at' => now()->addMinute()]);

        $result = $service->stageProjectForReview($this->project->fresh());

        $this->assertTrue($result['success']);
        // …and the older unsent draft is replaced, never stacked.
        $this->assertCount(1, EmailTracking::all());
        $this->assertSame($newer->id, EmailTracking::first()->ai_solution_id);
        $this->assertSame(EmailTracking::STATUS_PENDING_REVIEW, EmailTracking::first()->status);
        Mail::assertNothingSent();
    }

    public function test_email_tabs_show_draft_and_review_counts(): void
    {        $this->stageReview(['subject' => 'Counted review row']);
        EmailTracking::create([
            'contractor_id'   => $this->contractor->id,
            'project_id'      => $this->project->id,
            'generated_by'    => $this->staff->id,
            'recipient_email' => $this->contractorUser->email,
            'subject'         => 'Counted manual draft',
            'status'          => EmailTracking::STATUS_DRAFT,
            'body'            => 'Manual.',
            'tracking_token'  => EmailTracking::generateToken(),
            'sent_at'         => null,
        ]);

        $content = $this->actingAs($this->staff)
            ->get(route('staff.email'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('In review <span>(1)</span>', $content);
        $this->assertStringContainsString('Drafts <span>(1)</span>', $content);
    }

    public function test_analysis_email_highlights_every_section_including_recommendations(): void
    {
        $aiSolution = AiSolution::where('project_id', $this->project->id)->latest('created_at')->first();
        $aiSolution->update([
            'recommendations' => [
                ['message' => 'Add two crews to the roofing works.', 'category' => 'Schedule', 'reason' => 'Roofing is on the critical path.', 'priority' => 1],
                ['message' => 'Replenish cement stocks.', 'category' => 'Resources', 'reason' => 'Stocks below safety level.', 'priority' => 3],
            ],
        ]);
        $tracking = $this->stageReview();

        $html = (new AiAnalysisMail($this->project->fresh(), $aiSolution->fresh(), $tracking))->render();

        $this->assertStringContainsString('Project Snapshot', $html);
        $this->assertStringContainsString('AI-Generated Analysis', $html);
        $this->assertStringContainsString('Recommended Actions', $html);
        $this->assertStringContainsString('Add two crews to the roofing works.', $html);
        $this->assertStringContainsString('Project Progress Analysis', $html);
        // Priority pills render for both recommendations.
        $this->assertStringContainsString('>P1<', $html);
        $this->assertStringContainsString('>P3<', $html);
    }

    public function test_analysis_email_skips_recommendations_section_when_empty(): void
    {        $tracking = $this->stageReview();
        $aiSolution = AiSolution::where('project_id', $this->project->id)->latest('created_at')->first();

        $html = (new AiAnalysisMail($this->project->fresh(), $aiSolution, $tracking))->render();

        $this->assertStringContainsString('Project Snapshot', $html);
        $this->assertStringNotContainsString('Recommended Actions', $html);
        // Progress section is renumbered to 03 when recommendations are absent.
        $this->assertStringContainsString('<span class="section-num">03</span>', $html);
    }

    public function test_message_overlays_use_highlighted_report_sections(): void
    {        foreach (['admin.email' => $this->admin, 'staff.email' => $this->staff] as $route => $user) {
            $content = $this->actingAs($user)->get(route($route))->assertOk()->getContent();

            // View overlay mirrors the AI email: numbered highlighted sections.
            $this->assertStringContainsString('Project snapshot', $content);
            $this->assertStringContainsString('AI analysis', $content);
            $this->assertStringContainsString('Progress analysis', $content);
            $this->assertStringContainsString('id="v-statuspill"', $content);
            $this->assertStringContainsString('id="v-revised-wrap"', $content);
            // Compose/edit modal sections (shared partial).
            $this->assertStringContainsString('compose-banner', $content);
            $this->assertStringContainsString('>Details<', $content);
            $this->assertStringContainsString('>Message<', $content);
        }
    }

    public function test_view_endpoint_returns_blue_highlighted_section_html(): void
    {        $aiSolution = AiSolution::where('project_id', $this->project->id)->latest('created_at')->first();
        $aiSolution->update([
            'analysis_text' => '[SECTION:Report]Solid month.[/SECTION][SECTION:AI Comment]Stay the course.[/SECTION]',
        ]);
        $tracking = $this->stageReview();

        $response = $this->actingAs($this->staff)
            ->getJson(route('staff.emails.show', $tracking))
            ->assertOk();

        // Raw markers are gone; blue-highlighted headings are served to the overlay.
        $this->assertStringNotContainsString('[SECTION:', $response->json('body_html'));
        $this->assertStringContainsString('AI Comment', $response->json('body_html'));
        $this->assertStringContainsString('color:#2563eb', $response->json('body_html'));
    }

    public function test_contractor_overlay_uses_highlighted_sections(): void
    {
        $content = $this->actingAs($this->contractorUser)
            ->get(route('contractor.email'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Project snapshot', $content);
        $this->assertStringContainsString('AI analysis', $content);
        $this->assertStringContainsString('Progress analysis', $content);
        $this->assertStringContainsString('id="v-statuspill"', $content);
        // Old duplicated raw+parsed renderer is gone.
        $this->assertStringNotContainsString('parseAiAnalysis', $content);
        $this->assertStringNotContainsString('analysis-section-header', $content);
    }

    public function test_contractor_view_returns_highlighted_html_without_markers(): void
    {
        $aiSolution = AiSolution::where('project_id', $this->project->id)->latest('created_at')->first();
        $aiSolution->update([
            'analysis_text' => '[SECTION:Report]Solid month.[/SECTION][SECTION:Mitigation]Add cover.[/SECTION]',
        ]);
        $sent = $this->stageReview([
            'sent_at' => now(),
            'status'  => EmailTracking::STATUS_SENT,
        ]);

        $response = $this->actingAs($this->contractorUser)
            ->getJson(route('contractor.emails.show', $sent))
            ->assertOk();

        $this->assertStringNotContainsString('[SECTION:', $response->json('body_html'));
        $this->assertStringContainsString('Mitigation', $response->json('body_html'));
        $this->assertStringContainsString('color:#2563eb', $response->json('body_html'));
    }
}
