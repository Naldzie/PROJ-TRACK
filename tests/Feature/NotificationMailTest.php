<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\DeadlineReminder;
use App\Notifications\ExtensionStatusChanged;
use App\Notifications\IssueReported;
use App\Notifications\OverdueInspectionReminder;
use App\Notifications\RiskReported;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * NotificationMailTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the mail channel added to the in-app notifications (teammate
 * merge on feature/teammate-layout-design):
 *   - Every notification class advertises the 'mail' channel via via().
 *   - Every toMail() binds the emails.notification view with data that
 *     actually RENDERS (guards against a missing/mistyped view or a payload
 *     key the template can't consume).
 *
 * The mail channel is exercised directly (toMail() + view render) rather
 * than via Notification::fake(), because faking skips channel delivery and
 * would never catch a broken view binding.
 *
 * Run With
 * --------
 *   php artisan test --filter=NotificationMailTest
 */
class NotificationMailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name'      => 'Mail Hook User',
            'email'     => 'mail-hook@example.com',
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_via_channels_include_database_and_mail(): void
    {
        $notifications = [
            new DeadlineReminder(1, 'Demo Project', '30', 30, '2026-12-31'),
            new ExtensionStatusChanged(1, 'Demo Project', 1, 1, ExtensionStatusChanged::APPROVED),
            new IssueReported(1, 'Demo Project', 1, 'Foundation crack', 'High'),
            new OverdueInspectionReminder(1, 'Demo Project', '2026-05', 'May 2026', '2026-05-31'),
            new RiskReported(1, 'Demo Project', 1, 'Slope instability'),
        ];

        foreach ($notifications as $notification) {
            $channels = $notification->via($this->user);
            $this->assertContains('database', $channels);
            $this->assertContains('mail', $channels);
        }
    }

    public function test_every_notification_mail_renders_the_shared_template(): void
    {
        $cases = [
            [
                'notification' => new DeadlineReminder(1, 'Demo Project', 'overdue', -3, '2026-11-30'),
                'needle'       => 'Demo Project',
            ],
            [
                'notification' => new ExtensionStatusChanged(1, 'Demo Project', 2, 1, ExtensionStatusChanged::APPROVED),
                'needle'       => 'Extension Approved',
            ],
            [
                'notification' => new IssueReported(1, 'Demo Project', 7, 'Foundation crack', 'High'),
                'needle'       => 'Foundation crack',
            ],
            [
                'notification' => new OverdueInspectionReminder(1, 'Demo Project', '2026-05', 'May 2026', '2026-05-31'),
                'needle'       => 'May 2026',
            ],
            [
                'notification' => new RiskReported(1, 'Demo Project', 3, 'Slope instability'),
                'needle'       => 'Slope instability',
            ],
        ];

        foreach ($cases as $case) {
            $mail = $case['notification']->toMail($this->user);

            $this->assertNotEmpty($mail->subject, get_class($case['notification']) . ' subject is empty');
            $this->assertSame('emails.notification', $mail->view, get_class($case['notification']) . ' must use the shared emails.notification view');
            $this->assertArrayHasKey('lines', $mail->viewData);

            $html = view($mail->view, $mail->viewData)->render();
            $this->assertStringContainsString($case['needle'], $html, get_class($case['notification']) . ' rendered HTML is missing expected content');
        }
    }

    public function test_rejected_extension_mail_includes_rejection_reason(): void
    {
        $notification = new ExtensionStatusChanged(
            1,
            'Demo Project',
            4,
            2,
            ExtensionStatusChanged::REJECTED,
            'Insufficient justification'
        );

        $mail = $notification->toMail($this->user);
        $html = view($mail->view, $mail->viewData)->render();

        $this->assertStringContainsString('Insufficient justification', $html);
    }

    public function test_overdue_deadline_mail_marks_overdue_status(): void
    {
        $notification = new DeadlineReminder(1, 'Demo Project', 'overdue', -5, '2026-11-30');

        $mail = $notification->toMail($this->user);
        $html = view($mail->view, $mail->viewData)->render();

        $this->assertStringContainsString('Overdue by 5 day(s)', $html);
    }
}
