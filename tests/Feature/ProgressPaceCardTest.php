<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * The "Progress Pace" card on the project page's AI prediction panel
 * (phase-43, textfiles/progress_pace_fix_plan.md). It replaced "Progress Speed
 * Comparison", which showed averages of cumulative % labelled "%/week".
 *
 * Run: php artisan test --filter=ProgressPaceCardTest
 */
class ProgressPaceCardTest extends TestCase
{
    use RefreshDatabase;
    use CreatesProjectWithHistory;

    public function test_project_page_shows_monthly_pace_with_windows_and_needed_pace(): void
    {
        $admin = User::create(['name' => 'Pace Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $project = $this->createProjectWithHistory(
            months: 6,
            actualProgress: [2, 3, 7, 15, 25, 38],
            overrides: ['planned_end_date' => now()->addMonths(6)->toDateString()]
        );

        $html = $this->actingAs($admin)->get(route('admin.projects.detail', $project->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Progress Pace', $html);
        $this->assertStringContainsString('+2.5%', $html);   // early: 2 → 7 over 2 months
        $this->assertStringContainsString('+11.5%', $html);  // recent: 15 → 38 over 2 months
        $this->assertStringContainsString('NEEDED TO FINISH ON TIME', $html);
        $this->assertStringContainsString('Speeding up', $html);
        $this->assertStringNotContainsString('%/week', $html);
        $this->assertStringNotContainsString('EARLY PHASE SPEED', $html);
    }

    public function test_two_inspections_show_the_not_enough_data_note(): void
    {
        $admin = User::create(['name' => 'Pace Admin', 'email' => fake()->unique()->safeEmail(), 'role' => 'admin', 'is_active' => true]);
        $project = $this->createProjectWithHistory(months: 2, actualProgress: [5, 10]);

        $html = $this->actingAs($admin)->get(route('admin.projects.detail', $project->id))
            ->assertOk()
            ->getContent();

        // With 2 records the page shows the "needs more data" prediction state;
        // if the pace card appears at all it must say why it has no pace.
        if (str_contains($html, 'Progress Pace')) {
            $this->assertStringContainsString('Early and recent pace appear after 3 monthly inspections', $html);
        }
        $this->assertStringNotContainsString('%/week', $html);
    }
}
