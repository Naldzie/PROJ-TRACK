<?php

namespace Database\Factories;

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Issue>
 */
class IssueFactory extends Factory
{
    protected $model = Issue::class;

    /**
     * ------------------------------------------------------------------------
     * Define the model's default state
     * ------------------------------------------------------------------------
     */
    public function definition(): array
    {
        return [
            'project_id'  => Project::factory(),
            'issue_name'  => fake()->randomElement([
                'Foundation settlement detected',
                'Steel reinforcement delayed',
                'Concrete quality concern',
                'Contractor manpower shortage',
                'Equipment malfunction',
                'Safety violation reported',
                'Permit not yet approved',
                'Design revision pending',
                'Site accessibility issue',
                'Material quality non-compliance',
            ]),
            'description' => fake()->sentence(10),
            'status'      => fake()->randomElement(['Open', 'In Progress', 'Resolved', 'Closed']),
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Open Issue State
     * ------------------------------------------------------------------------
     */
    public function open(): static
    {
        return $this->state(fn () => [
            'status' => 'Open',
        ]);
    }

    /**
     * ------------------------------------------------------------------------
     * Resolved Issue State
     * ------------------------------------------------------------------------
     */
    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'Resolved',
        ]);
    }
}
