<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ============================================================================
 * ContractPriceRevisedTest
 * ============================================================================
 *
 * Locks down the contract-price rename and the new revised amount (phase-47):
 *   - effectiveContractPrice() falls back to the awarded price, and prefers a
 *     revision when one exists
 *   - a revision is NEVER mirrored: a project with no variation order reports
 *     "not revised", which is the one fact the column exists to record
 *   - the DASHBOARD RAW SQL uses the effective amount. This is the part a
 *     column rename cannot verify for itself: selectRaw strings are invisible
 *     to the model, so savings and portfolio totals could silently keep
 *     reading the awarded price forever.
 *   - a DEDUCTIVE variation order (a revision BELOW the award) is accepted,
 *     pinning the decision not to add a revised >= contract_price rule
 *
 * Run With
 * --------
 *   php artisan test --filter=ContractPriceRevisedTest
 *
 * @see textfiles/contract_price_and_revised_amount_plan.md
 */
class ContractPriceRevisedTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'      => 'Contract Price Admin',
            'email'     => fake()->unique()->safeEmail(),
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    // ─── The accessor ────────────────────────────────────────────────────────

    public function test_effective_price_is_the_awarded_price_when_never_revised(): void
    {
        $project = Project::factory()->create([
            'contract_price'         => 4000000.00,
            'revised_contract_price' => null,
        ]);

        $this->assertSame(4000000.00, (float) $project->effectiveContractPrice());
        $this->assertFalse($project->isContractRevised());
    }

    public function test_effective_price_prefers_the_revision_when_one_exists(): void
    {
        $project = Project::factory()->create([
            'contract_price'         => 4000000.00,
            'revised_contract_price' => 4400000.00,
        ]);

        $this->assertSame(4400000.00, (float) $project->effectiveContractPrice());
        $this->assertTrue($project->isContractRevised());
        // The award survives the revision — that is the whole point of the column.
        $this->assertSame(4000000.00, (float) $project->contract_price);
    }

    public function test_a_revision_equal_to_the_award_still_counts_as_revised(): void
    {
        // The case that a mirrored default would have made unreportable: a variation
        // order that nets to the same figure is STILL a revision, and the system must
        // be able to say so.
        $project = Project::factory()->create([
            'contract_price'         => 4000000.00,
            'revised_contract_price' => 4000000.00,
        ]);

        $this->assertTrue($project->isContractRevised());
    }

    // ─── The raw SQL a rename cannot check ───────────────────────────────────

    public function test_dashboard_savings_measures_against_the_revised_amount(): void
    {
        // ABC 5,000,000 awarded at 4,000,000 = 1,000,000 saved, until a variation
        // order raises the contract to 4,400,000 and eats 400,000 of it.
        Project::factory()->create([
            'is_archived'            => false,
            'abc'                    => 5000000.00,
            'contract_price'         => 4000000.00,
            'revised_contract_price' => 4400000.00,
        ]);

        $savings = $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->viewData('totalSavings');

        $this->assertSame(600000.00, (float) $savings,
            'savings must be ABC minus the EFFECTIVE price; 1,000,000 would mean the raw SQL still reads the award');
    }

    public function test_dashboard_savings_is_unchanged_for_an_unrevised_project(): void
    {
        Project::factory()->create([
            'is_archived'            => false,
            'abc'                    => 5000000.00,
            'contract_price'         => 4000000.00,
            'revised_contract_price' => null,
        ]);

        $savings = $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->viewData('totalSavings');

        // COALESCE must fall through cleanly — a NULL revision cannot void the row.
        $this->assertSame(1000000.00, (float) $savings);
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    public function test_a_project_can_be_created_with_a_revised_amount(): void
    {
        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.store'),
            [
                'title'                  => 'Revised on create ' . fake()->unique()->word(),
                'contract_price'         => 500000,
                'revised_contract_price' => 540000,
                'planned_start_date'     => '2026-01-15',
                'planned_end_date'       => '2026-10-15',
                'status'                 => 'Planned',
            ]
        );

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('projects', [
            'contract_price'         => 500000,
            'revised_contract_price' => 540000,
        ]);
    }

    public function test_a_deductive_variation_order_below_the_award_is_accepted(): void
    {
        // Pins a decision, not a preference: a deductive change order legitimately
        // LOWERS a contract, so any "revised must exceed the award" rule would be
        // wrong. If someone adds one later, this test is what should stop them.
        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.store'),
            [
                'title'                  => 'Deductive order ' . fake()->unique()->word(),
                'contract_price'         => 500000,
                'revised_contract_price' => 450000,
                'planned_start_date'     => '2026-01-15',
                'planned_end_date'       => '2026-10-15',
                'status'                 => 'Planned',
            ]
        );

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('projects', ['revised_contract_price' => 450000]);
    }

    public function test_a_negative_revised_amount_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)->postJson(
            route('admin.projects.store'),
            [
                'title'                  => 'Negative revision ' . fake()->unique()->word(),
                'contract_price'         => 500000,
                'revised_contract_price' => -1,
                'planned_start_date'     => '2026-01-15',
                'planned_end_date'       => '2026-10-15',
                'status'                 => 'Planned',
            ]
        );

        // postJson gets the validator's JSON 422, not a session bag.
        $response->assertStatus(422)->assertJsonValidationErrors('revised_contract_price');
        $this->assertDatabaseCount('projects', 0);
    }
}
