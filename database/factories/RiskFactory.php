<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Risk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Risk>
 */
class RiskFactory extends Factory
{
    protected $model = Risk::class;

    /**
     * ------------------------------------------------------------------------
     * Define the model's default state
     * ------------------------------------------------------------------------
     *
     * Generates a realistic forward-looking risk with a valid score
     * computed from the random probability × impact pair, so the DB
     * invariant (risk_score = P × I) always holds.
     */
    public function definition(): array
    {
        $probability = fake()->randomElement(Risk::PROBABILITIES);
        $impact = fake()->randomElement(Risk::IMPACTS);

        return [
            'project_id'    => Project::factory(),
            'risk_name'     => fake()->randomElement([
                'Cement price hike stalls Phase 2',
                'Contractor manpower shortage',
                'Adverse weather delays pouring',
                'Permit approval delayed',
                'Material delivery lead time',
                'Design revision pending',
                'Subcontractor availability risk',
                'Funding release delay',
                'Site access restriction',
                'Equipment breakdown risk',
            ]),
            'description'    => fake()->sentence(10),
            'probability'    => $probability,
            'impact'         => $impact,
            'risk_score'     => Risk::computeScore($probability, $impact),
            'mitigation_plan' => fake()->sentence(8),
            'owner'          => fake()->name(),
            'status'         => 'Open',
            'identified_at'  => now()->toDateString(),
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Open Risk State
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
     * Mitigated Risk State
     * ------------------------------------------------------------------------
     */
    public function mitigated(): static
    {
        return $this->state(fn () => [
            'status'      => 'Mitigated',
            'mitigated_at' => now(),
        ]);
    }

    /**
     * ------------------------------------------------------------------------
     * Closed Risk State
     * ------------------------------------------------------------------------
     */
    public function closed(): static
    {
        return $this->state(fn () => [
            'status'    => 'Closed',
            'closed_at' => now(),
        ]);
    }

    /**
     * ------------------------------------------------------------------------
     * High-Priority Risk State
     * ------------------------------------------------------------------------
     */
    public function high(): static
    {
        return $this->state(fn () => [
            'probability' => 'High',
            'impact'      => 'High',
            'risk_score'  => 9,
        ]);
    }
}
