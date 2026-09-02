<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectExtension;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProjectExtension>
 */
class ProjectExtensionFactory extends Factory
{
    protected $model = ProjectExtension::class;

    /**
     * ------------------------------------------------------------------------
     * Define the model's default state
     * ------------------------------------------------------------------------
     */
    public function definition(): array
    {
        $originalEnd = fake()->dateTimeBetween('-6 months', '+3 months');
        $newEnd = (clone $originalEnd)->modify('+' . fake()->numberBetween(30, 180) . ' days');

        return [
            'project_id'        => Project::factory(),
            'extension_number'  => 1,
            'original_end_date' => $originalEnd,
            'new_end_date'      => $newEnd,
            'duration_days'     => (int) $originalEnd->diff($newEnd)->format('%a'),
            'reason'            => fake()->randomElement([
                'Weather delay due to typhoon season',
                'Material shortage from supplier',
                'Contractor manpower constraints',
                'Design changes requiring rework',
                'Site access issues',
                'Permit processing delay',
            ]),
            'status'              => fake()->randomElement(['pending', 'approved', 'rejected']),
            'approved_by_user_id' => null,
            'approved_at'         => null,
            // B1 — reviewer attribution (current actor for approved OR rejected)
            'reviewed_by_user_id' => null,
            'reviewed_at'         => null,
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Approved Extension State
     * ------------------------------------------------------------------------
     */
    public function approved(): static
    {
        return $this->state(fn () => [
            'status'     => 'approved',
            'approved_at' => now(),
            'reviewed_at' => now(),
        ]);
    }

    /**
     * ------------------------------------------------------------------------
     * Rejected Extension State — B1 (reviewer = rejector)
     * ------------------------------------------------------------------------
     */
    public function rejected(): static
    {
        return $this->state(fn () => [
            'status'           => 'rejected',
            'approved_at'      => now(),
            'reviewed_at'      => now(),
            'rejection_reason' => fake()->sentence(12),
        ]);
    }

    /**
     * ------------------------------------------------------------------------
     * Specific Extension Number
     * ------------------------------------------------------------------------
     */
    public function extensionNumber(int $number): static
    {
        return $this->state(fn () => [
            'extension_number' => $number,
        ]);
    }
}
