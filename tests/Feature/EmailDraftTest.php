<?php

namespace Tests\Feature;

use App\Mail\AiAnalysisMail;
use App\Models\Contractor;
use App\Models\EmailTracking;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ============================================================================
 * EmailDraftTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the interactive messaging feature on the staff/admin Email pages:
 *   - Staff/admin can compose a new message saved as an unsent draft.
 *   - Drafts remain fully editable/deletable until deliberately sent.
 *   - Sending a draft delivers the mail once, then locks it.
 *   - Editing an already-sent message marks it "Edited" (by whom, when) and
 *     automatically re-sends it to the contractor.
 *   - Sent messages can never be deleted or manually re-sent.
 *
 * Run With
 * --------
 *   php artisan test --filter=EmailDraftTest
 */
class EmailDraftTest extends TestCase
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

        $this->admin = User::create(['name' => 'Email Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $this->staff = User::create(['name' => 'Email Staff', 'email' => fake()->unique()->safeEmail(), 'role' => 'staff', 'is_active' => true]);
        $this->contractorUser = User::create(['name' => 'Email Contractor', 'email' => fake()->unique()->safeEmail(), 'role' => 'contractor', 'is_active' => true]);

        $this->contractor = Contractor::create([
            'user_id'         => $this->contractorUser->id,
            'contractor_name' => 'Email Builders',
            'company_name'    => 'Email Builders Inc',
        ]);

        $this->project = Project::factory()->create([
            'status'        => 'Ongoing',
            'contractor_id' => $this->contractor->id,
        ]);
    }

    private function createMessage(array $overrides = []): EmailTracking
    {
        return EmailTracking::create(array_merge([
            'contractor_id'   => $this->contractor->id,
            'project_id'      => $this->project->id,
            'generated_by'    => $this->staff->id,
            'recipient_email' => $this->contractorUser->email,
            'subject'         => 'Unsent draft subject',
            'body'            => 'Unsent draft body text',
            'tracking_token'  => EmailTracking::generateToken(),
            'sent_at'         => null,
        ], $overrides));
    }

    public function test_staff_can_compose_a_draft_message(): void
    {
        $response = $this->actingAs($this->staff)->postJson(route('staff.emails.store'), [
            'project_id' => $this->project->id,
            'subject'    => 'Heads up on schedule',
            'body'       => 'Please review the revised timeline.',
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('email_trackings', [
            'project_id'      => $this->project->id,
            'subject'         => 'Heads up on schedule',
            'body'            => 'Please review the revised timeline.',
            'recipient_email' => $this->contractorUser->email,
            'sent_at'         => null,
            'is_edited'       => false,
        ]);
    }

    public function test_admin_can_compose_a_draft_message(): void
    {
        $this->actingAs($this->admin)->postJson(route('admin.emails.store'), [
            'project_id' => $this->project->id,
            'subject'    => 'Admin notice',
            'body'       => 'Body from admin.',
        ])->assertOk();

        $this->assertDatabaseHas('email_trackings', [
            'subject' => 'Admin notice',
            'sent_at' => null,
        ]);
    }

    public function test_compose_requires_subject_body_and_eligible_project(): void
    {
        $this->actingAs($this->staff)->postJson(route('staff.emails.store'), [
            'project_id' => $this->project->id,
        ])->assertStatus(422);

        $this->actingAs($this->staff)->postJson(route('staff.emails.store'), [
            'project_id' => 999999,
            'subject'    => 'X',
            'body'       => 'Y',
        ])->assertStatus(422);
    }

    public function test_draft_edit_saves_without_sending_mail(): void
    {
        Mail::fake();
        $draft = $this->createMessage();

        $this->actingAs($this->staff)->putJson(route('staff.emails.update', $draft), [
            'subject' => 'Revised draft subject',
            'body'    => 'Revised draft body.',
        ])->assertOk()->assertJsonPath('resent', false);

        Mail::assertNothingSent();

        $this->assertDatabaseHas('email_trackings', [
            'id'        => $draft->id,
            'subject'   => 'Revised draft subject',
            'body'      => 'Revised draft body.',
            'is_edited' => true,
            'edited_by' => $this->staff->id,
        ]);

        $this->assertNotNull($draft->fresh()->edited_at);
        $this->assertTrue($draft->fresh()->isDraft());
    }

    public function test_deleting_a_draft_removes_it(): void
    {
        Mail::fake();
        $draft = $this->createMessage();

        $this->actingAs($this->staff)->deleteJson(route('staff.emails.destroy', $draft))
            ->assertOk();

        $this->assertDatabaseMissing('email_trackings', ['id' => $draft->id]);
        Mail::assertNothingSent();
    }

    public function test_sending_a_draft_mails_the_contractor_then_locks_it(): void
    {
        Mail::fake();
        $draft = $this->createMessage();

        $this->actingAs($this->staff)->postJson(route('staff.emails.send', $draft))
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(AiAnalysisMail::class, function (AiAnalysisMail $mail) use ($draft) {
            return $mail->hasTo($this->contractorUser->email)
                && $mail->tracking->is($draft)
                && $mail->subject === 'Unsent draft subject';
        });

        $this->assertNotNull($draft->fresh()->sent_at);

        // Already-sent messages cannot be sent again through the draft action.
        $this->actingAs($this->staff)->postJson(route('staff.emails.send', $draft))
            ->assertStatus(403);
    }

    public function test_admin_can_send_a_draft(): void
    {
        Mail::fake();
        $draft = $this->createMessage();

        $this->actingAs($this->admin)->postJson(route('admin.emails.send', $draft))
            ->assertOk();

        Mail::assertSent(AiAnalysisMail::class, fn (AiAnalysisMail $mail) => $mail->hasTo($this->contractorUser->email));
    }

    public function test_editing_a_sent_message_marks_it_edited_and_auto_resends(): void
    {
        Mail::fake();
        $sent = $this->createMessage([
            'subject' => 'Original monthly report',
            'body'    => null,
            'sent_at' => now(),
        ]);

        $response = $this->actingAs($this->staff)->putJson(route('staff.emails.update', $sent), [
            'subject' => 'Corrected monthly report',
            'body'    => 'The figures below supersede the previous email.',
        ]);

        $response->assertOk()->assertJsonPath('resent', true);

        fwrite(STDERR, "\nDEBUG RESPONSE: " . $response->getContent() . "\n");
        fwrite(STDERR, "DEBUG MAIL SENT: " . print_r(Mail::sent(AiAnalysisMail::class), true) . "\n");

        $this->assertDatabaseHas('email_trackings', [
            'id'        => $sent->id,
            'subject'   => 'Corrected monthly report',
            'body'      => 'The figures below supersede the previous email.',
            'is_edited' => true,
            'edited_by' => $this->staff->id,
        ]);

        // Automatic re-send happened exactly once, carrying the revised content.
        Mail::assertSent(AiAnalysisMail::class, 1);
        Mail::assertSent(AiAnalysisMail::class, function (AiAnalysisMail $mail) use ($sent) {
            return $mail->hasTo($this->contractorUser->email)
                && $mail->tracking->is($sent)
                && $mail->subject === 'Corrected monthly report';
        });

        $this->assertTrue($sent->fresh()->is_edited);
        $this->assertFalse($sent->fresh()->isDraft());
    }

    public function test_admin_edit_of_sent_message_also_auto_resends(): void
    {
        Mail::fake();
        $sent = $this->createMessage([
            'subject' => 'Sent before admin fix',
            'body'    => null,
            'sent_at' => now(),
        ]);

        $this->actingAs($this->admin)->putJson(route('admin.emails.update', $sent), [
            'subject' => 'Sent after admin fix',
            'body'    => 'Admin-corrected content.',
        ])->assertOk()->assertJsonPath('resent', true);

        Mail::assertSent(AiAnalysisMail::class, 1);
    }

    public function test_sent_messages_cannot_be_deleted(): void
    {
        Mail::fake();
        $sent = $this->createMessage([
            'subject' => 'Keep me forever',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->staff)->deleteJson(route('staff.emails.destroy', $sent))
            ->assertStatus(403);
        $this->actingAs($this->admin)->deleteJson(route('admin.emails.destroy', $sent))
            ->assertStatus(403);

        $this->assertDatabaseHas('email_trackings', ['id' => $sent->id]);
        Mail::assertNothingSent();
    }

    public function test_edit_endpoint_returns_current_content_for_any_message(): void
    {
        $sent = $this->createMessage([
            'subject' => 'Editable even when sent',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->staff)->getJson(route('staff.emails.edit', $sent))
            ->assertOk()
            ->assertJsonPath('is_draft', false)
            ->assertJsonPath('subject', 'Editable even when sent');
    }

    public function test_drafts_tab_lists_only_unsent_messages(): void
    {
        $draft = $this->createMessage(['subject' => 'ZZZ unique draft line']);
        $this->createMessage(['subject' => 'QQQ unique sent line', 'body' => null, 'sent_at' => now()]);

        $response = $this->actingAs($this->staff)->get(route('staff.email', ['filter' => 'drafts']));
        $content = $response->getContent();

        $response->assertOk();
        $this->assertStringContainsString('ZZZ unique draft line', $content);
        // Sent rows are excluded entirely (sidebar "Recent activity" may still mention them).
        $this->assertStringNotContainsString('data-status="pending"', $content);
        $this->assertStringContainsString('data-status="draft"', $content);

        $this->assertTrue($draft->fresh()->isDraft());
    }

    public function test_list_page_displays_edited_label_after_an_edit(): void
    {
        $sent = $this->createMessage([
            'subject' => 'Will be labelled edited',
            'body'    => null,
            'sent_at' => now(),
        ]);

        $this->actingAs($this->staff)->putJson(route('staff.emails.update', $sent), [
            'subject' => 'Labelled after revision',
            'body'    => 'Updated content.',
        ])->assertOk();

        $this->actingAs($this->staff)->get(route('staff.email'))
            ->assertOk()
            ->assertSee('Labelled after revision')
            ->assertSee('Edited')
            ->assertSee('Edited by Email Staff');
    }

    public function test_view_endpoint_exposes_edited_metadata(): void
    {
        $sent = $this->createMessage(['subject' => 'Meta check', 'body' => null, 'sent_at' => now(), 'is_edited' => true, 'edited_by' => $this->staff->id, 'edited_at' => now()]);

        $this->actingAs($this->admin)->getJson(route('admin.emails.show', $sent))
            ->assertOk()
            ->assertJsonPath('is_edited', true)
            ->assertJsonPath('edited_by_name', 'Email Staff')
            ->assertJsonPath('is_draft', false);
    }

    public function test_other_roles_cannot_use_staff_or_admin_email_actions(): void
    {
        $payload = ['project_id' => $this->project->id, 'subject' => 'Nope', 'body' => 'Nope'];

        $this->actingAs($this->contractorUser)->postJson(route('staff.emails.store'), $payload)
            ->assertStatus(302);

        $this->actingAs($this->contractorUser)->postJson(route('admin.emails.store'), $payload)
            ->assertStatus(302);

        $this->assertDatabaseMissing('email_trackings', ['subject' => 'Nope']);
    }
}
