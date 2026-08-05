<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * DevNotificationScanTest
 * ============================================================================
 *
 * Purpose
 * -------
 * Validates the dev-only browser trigger for the notification scan (gaps #12):
 *   - POST /dev/notifications/scan (dev.notifications.scan) runs the scan
 *     in-process and returns the command output as JSON.
 *   - Because the route sits behind the SimulateDate web middleware, the scan
 *     is driven by the session's simulated date — proving the Date Simulator
 *     can trigger deadline notifications end-to-end from the browser.
 *
 * Run With
 * --------
 *   php artisan test --filter=DevNotificationScanTest
 */
class DevNotificationScanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Dev Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $this->contractor = Contractor::create([
            'user_id'         => $this->admin->id,
            'contractor_name' => 'Dev Builders',
            'company_name'    => 'Dev Builders Inc',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dev_scan_route_runs_and_returns_output(): void
    {
        Project::factory()->create([
            'status'           => 'Ongoing',
            'contractor_id'    => $this->contractor->id,
            'planned_end_date' => Carbon::parse('2026-01-20'),
        ]);

        $response = $this->actingAs($this->admin)
            ->withSession(['simulated_date' => '2026-01-05'])
            ->postJson(route('dev.notifications.scan'));

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('output', fn ($out) => is_string($out) && str_contains($out, 'Done.'));

        $this->assertSame(1, $this->admin->notifications()->count());
    }

    public function test_dev_scan_respects_simulated_date(): void
    {
        // 15 days out from the simulated "now" → should fire the '30' bucket.
        // Were the simulated date NOT applied, the real clock (long past
        // 2026-01-20) would instead produce an 'overdue' bucket.
        Project::factory()->create([
            'status'           => 'Ongoing',
            'contractor_id'    => $this->contractor->id,
            'planned_end_date' => Carbon::parse('2026-01-20'),
        ]);

        $this->actingAs($this->admin)
            ->withSession(['simulated_date' => '2026-01-05'])
            ->postJson(route('dev.notifications.scan'))
            ->assertOk();

        $data = $this->admin->notifications()->first()->data;
        $this->assertSame('30', $data['bucket']);
        $this->assertSame(15, $data['days_remaining']);
    }

    public function test_dev_scan_requires_authentication(): void
    {
        $this->postJson(route('dev.notifications.scan'))->assertUnauthorized();
    }

    public function test_dev_clear_deletes_only_current_users_notifications(): void
    {
        Project::factory()->create([
            'status'           => 'Ongoing',
            'contractor_id'    => $this->contractor->id,
            'planned_end_date' => Carbon::parse('2026-01-20'),
        ]);

        $this->actingAs($this->admin)
            ->withSession(['simulated_date' => '2026-01-05'])
            ->postJson(route('dev.notifications.scan'))
            ->assertOk();

        $otherUser = User::create(['name' => 'Other Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $otherUser->notifications()->create([
            'id' => fake()->uuid(),
            'type' => \App\Notifications\DeadlineReminder::class,
            'data' => ['key' => 'deadline:999:30'],
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('dev.notifications.clear'));

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertSame(0, $this->admin->notifications()->count());
        $this->assertSame(1, $otherUser->notifications()->count());
    }

    public function test_dev_clear_requires_authentication(): void
    {
        $this->postJson(route('dev.notifications.clear'))->assertUnauthorized();
    }
}
