<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Concerns\QueuesMailOnly;
use App\Notifications\IssueReported;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ============================================================================
 * NotificationQueueContractTest
 * ============================================================================
 *
 * Guards the three conditions that keep the August 2026 queued-notification
 * failure (Bug 7/8 in bugs_and_behavior_adjustments.md) from coming back now
 * that the EMAIL channel is queued (N-D3, 2026-09-21 —
 * textfiles/notification_monitoring_restudy_plan.md):
 *   1. every notification class implements ShouldQueue AND uses Queueable
 *      (RiskLevelChanged lacked the trait in August and broke);
 *   2. only the mail channel is queued — the in-app row is written at once;
 *   3. the mail job goes to the DEFAULT queue the `composer dev` worker reads.
 *
 * Any new class in app/Notifications is picked up automatically.
 *
 * Run: php artisan test --filter=NotificationQueueContractTest
 */
class NotificationQueueContractTest extends TestCase
{
    use RefreshDatabase;

    /** Every concrete notification class under app/Notifications. */
    private function notificationClasses(): array
    {
        $classes = [];
        foreach (glob(app_path('Notifications/*.php')) as $file) {
            $class = 'App\\Notifications\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, Notification::class)
                && !(new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** All traits of a class including its parents'. */
    private function traitsOf(string $class): array
    {
        $traits = [];
        do {
            $traits = array_merge($traits, class_uses($class));
        } while ($class = get_parent_class($class));

        return $traits;
    }

    public function test_every_notification_is_queueable_with_the_mail_only_contract(): void
    {
        $classes = $this->notificationClasses();
        $this->assertGreaterThanOrEqual(13, count($classes), 'expected the 8 original + 5 monitoring classes');

        foreach ($classes as $class) {
            $this->assertTrue(is_subclass_of($class, ShouldQueue::class), "{$class} must implement ShouldQueue");
            $traits = $this->traitsOf($class);
            $this->assertContains(Queueable::class, $traits, "{$class} must use Queueable (Bug 7/8)");
            $this->assertContains(QueuesMailOnly::class, $traits, "{$class} must use QueuesMailOnly");

            $connections = (new \ReflectionClass($class))->newInstanceWithoutConstructor()->viaConnections();
            $this->assertSame('sync', $connections['database'], "{$class}: in-app notice must stay immediate");
            $this->assertSame(config('queue.default'), $connections['mail'], "{$class}: mail on the default connection");
        }
    }

    public function test_with_a_real_queue_the_in_app_row_is_immediate_and_only_the_email_waits(): void
    {
        config(['queue.default' => 'database']);
        $user = User::create(['name' => 'Q User', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);

        app(NotificationService::class)->notifyUnique($user, new IssueReported(1, 'Queue Project', 7, 'Leak', 'High'));

        // In-app notice already there — the worker has not run
        $this->assertSame(1, $user->notifications()->count());

        // Exactly one job, on the DEFAULT queue, carrying the mail channel only
        $jobs = DB::table('jobs')->get();
        $this->assertCount(1, $jobs);
        $this->assertSame('default', $jobs[0]->queue);
        $payload = json_decode($jobs[0]->payload, true);
        $this->assertSame(\Illuminate\Notifications\SendQueuedNotifications::class, $payload['data']['commandName']);
        $command = unserialize($payload['data']['command']);
        $this->assertSame(['mail'], $command->channels);
        $this->assertSame(3, $command->tries);
    }

    public function test_dedupe_still_works_with_the_email_queued(): void
    {
        config(['queue.default' => 'database']);
        $user = User::create(['name' => 'Q User', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $service = app(NotificationService::class);

        $service->notifyUnique($user, new IssueReported(1, 'Queue Project', 7, 'Leak', 'High'));
        $second = $service->notifyUnique($user, new IssueReported(1, 'Queue Project', 7, 'Leak', 'High'));

        $this->assertFalse($second);
        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(1, DB::table('jobs')->count(), 'no second email queued');
    }

    public function test_in_app_only_notices_queue_nothing(): void
    {
        config(['queue.default' => 'database']);
        $user = User::create(['name' => 'Q User', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);

        app(NotificationService::class)->notifyUnique($user, new \App\Notifications\ProjectNotStartedReminder(1, 'P', '2026-09-01', 20));

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }
}
