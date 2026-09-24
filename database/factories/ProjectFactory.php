<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    private static int $codeCounter = 0;

    public function definition(): array
    {
        self::$codeCounter++;

        $plannedStart = fake()->dateTimeBetween('-2 years', '-6 months');
        $plannedEnd = (clone $plannedStart)->modify('+' . fake()->numberBetween(180, 730) . ' days');

        return [
            'code'                     => 'PRJ-' . now()->year . '-' . str_pad((string) self::$codeCounter, 5, '0', STR_PAD_LEFT),
            'title'                    => fake()->sentence(3),
            'location'                 => fake()->city() . ', ' . fake()->state(),
            'description'              => fake()->paragraph(),
            'contract_price'           => fake()->randomFloat(2, 100000, 10000000),
            'planned_start_date'       => $plannedStart,
            'planned_duration_days'    => (int) $plannedStart->diff($plannedEnd)->format('%a'),
            'planned_end_date'         => $plannedEnd,
            'original_planned_start_date' => $plannedStart,
            'original_planned_end_date' => $plannedEnd,
            'extension_count'          => 0,
            'status'                   => 'Ongoing',
            'is_archived'              => false,
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Completed Project State
     * ------------------------------------------------------------------------
     *
     * Sets the project as Completed with actual end dates.
     */
    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $plannedEnd = $attributes['planned_end_date'] ?? now()->subDays(30);
            $delay = fake()->numberBetween(-30, 90);

            return [
                'status'           => 'Completed',
                'actual_start_date' => $attributes['planned_start_date'],
                'actual_end_date'  => (clone $plannedEnd)->modify(($delay >= 0 ? '+' : '') . $delay . ' days'),
                'actual_duration_days' => abs((int) $attributes['planned_start_date']->diff(
                    (clone $plannedEnd)->modify(($delay >= 0 ? '+' : '') . $delay . ' days')
                )->format('%a')),
            ];
        });
    }

    /**
     * ------------------------------------------------------------------------
     * Planned Project State
     * ------------------------------------------------------------------------
     *
     * Sets the project as Planned (not yet started).
     */
    public function planned(): static
    {
        return $this->state(function (array $attributes) {
            $start = now()->addMonth();
            $end = (clone $start)->modify('+' . fake()->numberBetween(180, 730) . ' days');

            return [
                'status'               => 'Planned',
                'planned_start_date'   => $start,
                'planned_end_date'     => $end,
                'planned_duration_days' => (int) $start->diff($end)->format('%a'),
            ];
        });
    }

    /**
     * ------------------------------------------------------------------------
     * With Extension Count
     * ------------------------------------------------------------------------
     *
     * Sets a specific extension count on the project.
     */
    public function withExtensions(int $count): static
    {
        return $this->state(fn () => [
            'extension_count' => $count,
        ]);
    }
}
