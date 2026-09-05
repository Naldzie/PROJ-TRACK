<?php

namespace Tests\Feature;

use App\Models\ParserKeywordProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// L1 learning loop: silent collection endpoint for (label → field) proposals.
// Collection is open to admin AND staff (Decision L0a); contractors get 403.
// Proposals never affect extraction until L2 review (no reader code reads this table yet).
class ParserKeywordProposalTest extends TestCase
{
    use RefreshDatabase;

    // Store proposals from an admin correction batch.
    // Called by: POST /admin/parser-keyword-proposals/collect from harvestCorrections().
    public function test_admin_can_store_proposals(): void
    {
        $admin = User::create([
            'name' => 'Loop Admin',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/parser-keyword-proposals/collect', [
            'proposals' => [
                ['field' => 'budget', 'label' => 'Contract Sum', 'excerpt' => 'Contract Sum: Php 5,000,000.00', 'had_disagree' => false],
                ['field' => 'title', 'label' => null, 'excerpt' => null, 'had_disagree' => true],
            ],
        ]);

        $response->assertCreated()->assertJson(['success' => true, 'stored' => 2]);
        $this->assertDatabaseHas('parser_keyword_proposals', [
            'field' => 'budget',
            'label_phrase' => 'Contract Sum',
            'status' => 'proposed',
            'occurrences' => 1,
        ]);
        $this->assertDatabaseHas('parser_keyword_proposals', [
            'field' => 'title',
            'status' => 'proposed',
        ]);
    }

    // Repeats bump occurrences; the third confirmation auto-promotes to live (L2 pickup).
    // Called by: repeated corrections of the same label across files.
    public function test_repeats_increment_and_third_promotes_to_live(): void
    {
        $staff = User::create([
            'name' => 'Loop Staff',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $payload = ['proposals' => [['field' => 'budget', 'label' => 'Contract Sum']]];
        $this->actingAs($staff)->postJson('/staff/parser-keyword-proposals/collect', $payload)->assertCreated();
        $this->actingAs($staff)->postJson('/staff/parser-keyword-proposals/collect', $payload)->assertCreated();
        $this->actingAs($staff)->postJson('/staff/parser-keyword-proposals/collect', $payload)->assertCreated();

        $row = ParserKeywordProposal::where('field', 'budget')->where('label_phrase', 'Contract Sum')->first();
        $this->assertNotNull($row);
        $this->assertSame(3, $row->occurrences);
        $this->assertSame('live', $row->status);
    }

    // Contractors never run autofill, and the endpoint refuses them regardless.
    // Called by: POST to either role collect route as a contractor.
    public function test_contractor_is_forbidden_on_both_routes(): void
    {
        $contractor = User::create([
            'name' => 'Loop Contractor',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'contractor',
            'is_active' => true,
        ]);

        $payload = ['proposals' => [['field' => 'budget', 'label' => 'Contract Sum']]];
        // Role middleware bounces contractors to the unauthorized page (project convention).
        $this->actingAs($contractor)->postJson('/staff/parser-keyword-proposals/collect', $payload)->assertRedirect(route('unauthorized'));
        $this->actingAs($contractor)->postJson('/admin/parser-keyword-proposals/collect', $payload)->assertRedirect(route('unauthorized'));
        $this->assertDatabaseCount('parser_keyword_proposals', 0);
    }

    // Unknown fields and oversized batches are rejected (loop learns labels only, capped).
    // Called by: malformed or abusive collect payloads.
    public function test_validation_rejects_unknown_fields(): void
    {
        $admin = User::create([
            'name' => 'Loop Admin 2',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->postJson('/admin/parser-keyword-proposals/collect', [
            'proposals' => [['field' => 'projectCode', 'label' => 'Code']],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('parser_keyword_proposals', 0);
    }
}
