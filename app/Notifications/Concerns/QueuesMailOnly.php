<?php

namespace App\Notifications\Concerns;

/**
 * ============================================================================
 * QueuesMailOnly
 * ============================================================================
 *
 * Shared by every notification class (which must also `implements ShouldQueue`
 * and `use Queueable`). Queues ONLY the email; the in-app (database) notice is
 * still written immediately.
 *
 * Why (textfiles/notification_monitoring_restudy_plan.md, N-D3, 2026-09-21):
 * sending over Gmail inline cost 1.5-5 s PER RECIPIENT inside the request that
 * triggered it (reporting an issue to 4 people ≈ 6-10 s).
 *
 * The three conditions that keep the August 2026 failure (Bug 7/8) away:
 *   1. every class keeps `use Queueable` — Laravel reads $connection / $queue /
 *      $delay from it once a class implements ShouldQueue (RiskLevelChanged
 *      lacked it then and broke); NotificationQueueContractTest enforces it;
 *   2. only the MAIL channel is queued — 'database' runs on the 'sync'
 *      connection, so the bell/inbox stay instant and NotificationService's
 *      dedupe (which reads the database rows) keeps working;
 *   3. the mail job goes to the app's DEFAULT queue connection + queue, which
 *      `composer dev`'s `queue:listen` processes (the old attempt used an
 *      'emails' queue no worker listened to). Under phpunit
 *      QUEUE_CONNECTION=sync, so tests still send immediately.
 *
 * A worker must run on the server for emails to leave; failures land in
 * failed_jobs after the retries below.
 */
trait QueuesMailOnly
{
    /** Attempts for the queued email before it lands in failed_jobs. */
    public int $tries = 3;

    /** Seconds to wait between attempts (Gmail hiccups are usually brief). */
    public array $backoff = [60, 300];

    /**
     * Per-channel queue connection: in-app notice synchronous, email queued.
     */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            'mail'     => config('queue.default'),
        ];
    }
}
