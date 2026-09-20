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

    // F-D: the loop also banks PDF S-curve month-header words (AI-sourced,
    // approved in the same desk before the PDF reader consults them).
    // Called by: POST /admin/parser-keyword-proposals/collect from the PDF AI merge.
    public function test_scurve_month_field_is_learnable(): void
    {
        $admin = User::create([
            'name' => 'Loop Admin FD',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/parser-keyword-proposals/collect', [
            'proposals' => [
                ['field' => 'scurveMonth', 'label' => 'SEGMENT 1', 'excerpt' => 'AI S-curve PDF month header', 'source' => 'AI-assisted'],
            ],
        ]);

        $response->assertCreated()->assertJson(['success' => true, 'stored' => 1]);
        $this->assertDatabaseHas('parser_keyword_proposals', [
            'field' => 'scurveMonth',
            'label_phrase' => 'SEGMENT 1',
            'status' => 'proposed',
            'source' => 'AI-assisted',
        ]);
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

    // Staff can review: list shows proposals most-seen first, with ?status= filter.
    // Called by: GET .../parser-keyword-proposals/all from the review modal.
    public function test_staff_can_list_proposals_with_status_filter(): void
    {
        $staff = User::create([
            'name' => 'Loop Reviewer',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'staff',
            'is_active' => true,
        ]);
        ParserKeywordProposal::create(['field' => 'budget', 'label_phrase' => 'Contract Sum', 'occurrences' => 5, 'status' => 'proposed']);
        ParserKeywordProposal::create(['field' => 'title', 'label_phrase' => 'Project Title', 'occurrences' => 1, 'status' => 'live']);

        $this->actingAs($staff)->getJson('/staff/parser-keyword-proposals/all')
            ->assertOk()->assertJsonCount(2);
        $this->actingAs($staff)->getJson('/staff/parser-keyword-proposals/all?status=live')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.field', 'title');
    }

    // Approve flips proposed → live (staff may approve per Decision L0a, via staff route).
    // Called by: PATCH .../approve from the review modal Approve button.
    public function test_staff_can_approve_proposal(): void
    {
        $staff = User::create([
            'name' => 'Loop Approver',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $row = ParserKeywordProposal::create(['field' => 'budget', 'label_phrase' => 'Contract Sum', 'status' => 'proposed']);

        $this->actingAs($staff)->patchJson("/staff/parser-keyword-proposals/{$row->id}/approve")
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame('live', $row->fresh()->status);
    }

    // Reject soft-deletes (history preserved, recoverable).
    // Called by: DELETE from the review modal Reject button.
    public function test_admin_can_reject_proposal(): void
    {
        $admin = User::create([
            'name' => 'Loop Rejecter',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $row = ParserKeywordProposal::create(['field' => 'budget', 'label_phrase' => 'Bad Label', 'status' => 'proposed']);

        $this->actingAs($admin)->deleteJson("/admin/parser-keyword-proposals/{$row->id}")
            ->assertOk()->assertJson(['success' => true]);

        // 2026-09-20: rejecting RECORDS A VERDICT, it does not delete the row. A
        // soft-deleted row is invisible to the ordinary query, so collect() could not
        // see that a phrase had already been refused and the same bad wording was
        // re-proposed and re-judged forever with no memory of the decision.
        $this->assertDatabaseHas('parser_keyword_proposals', ['id' => $row->id, 'status' => 'rejected']);
        $this->assertNotSoftDeleted('parser_keyword_proposals', ['id' => $row->id]);

        // And the verdict holds: three more sightings must NOT promote it back.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($admin)->postJson('/admin/parser-keyword-proposals/collect', [
                'proposals' => [['field' => 'budget', 'label' => 'Bad Label']],
            ])->assertSuccessful();
        }
        $after = $row->fresh();
        $this->assertSame('rejected', $after->status, 'a rejected phrase must never climb back to live');
        $this->assertGreaterThan(1, $after->occurrences, 'the count is still evidence and should keep rising');
    }

    public function test_a_wordless_row_never_goes_live_and_cannot_be_approved(): void
    {
        $admin = User::create([
            'name' => 'Loop L2',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // L-2: an UNANCHORED correction still records a proposal, with label null, so
        // the COUNT is kept as evidence — but the reader skips empty phrases, so such a
        // row can teach nothing and must never present itself as a learned rule.
        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($admin)->postJson('/admin/parser-keyword-proposals/collect', [
                'proposals' => [['field' => 'contractor', 'label' => null]],
            ])->assertSuccessful();
        }

        $row = ParserKeywordProposal::where('field', 'contractor')->whereNull('label_phrase')->first();
        $this->assertNotNull($row, 'the row is still created — the count is the point');
        $this->assertSame(4, $row->occurrences, 'and it keeps rising');
        $this->assertSame('proposed', $row->status, 'but four sightings must NOT promote it');

        // Nor by hand: approving an empty phrase would add a rule that cannot match.
        $this->actingAs($admin)->patchJson('/admin/parser-keyword-proposals/' . $row->id . '/approve')
            ->assertStatus(422);
        $this->assertSame('proposed', $row->fresh()->status);
    }

    // Contractors get nothing on review routes either (redirect by role middleware).
    // Called by: any contractor request to the review endpoints.
    public function test_contractor_blocked_from_review_endpoints(): void
    {
        $contractor = User::create([
            'name' => 'Loop Contractor 2',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'contractor',
            'is_active' => true,
        ]);
        $row = ParserKeywordProposal::create(['field' => 'budget', 'label_phrase' => 'Contract Sum', 'status' => 'proposed']);

        $this->actingAs($contractor)->getJson('/staff/parser-keyword-proposals/all')->assertRedirect(route('unauthorized'));
        $this->actingAs($contractor)->patchJson("/admin/parser-keyword-proposals/{$row->id}/approve")->assertRedirect(route('unauthorized'));
        $this->assertSame('proposed', $row->fresh()->status);
    }

    // Multi-label desk: one correction may post up to 3 distinct rows for one field.
    // Called by: harvestCorrections() posting the ranked survey list (cap 3 per box).
    public function test_multi_label_batch_for_one_field_is_accepted(): void
    {
        $admin = User::create([
            'name' => 'Loop Multi',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/parser-keyword-proposals/collect', [
            'proposals' => [
                ['field' => 'abc', 'label' => 'Approved Budget for the Contract', 'excerpt' => 'line one'],
                ['field' => 'abc', 'label' => 'Total Estimated Project Cost', 'excerpt' => 'line two'],
                ['field' => 'abc', 'label' => 'Budget Reserve', 'excerpt' => 'line three'],
            ],
        ]);

        $response->assertCreated()->assertJson(['success' => true, 'stored' => 3]);
        $this->assertDatabaseCount('parser_keyword_proposals', 3);
    }

    // Batches beyond 11 fields × 3 labels are rejected (multi-label cap is 33).
    // Called by: abusive collect payloads.
    public function test_batch_over_multi_label_cap_is_rejected(): void
    {
        $admin = User::create([
            'name' => 'Loop Cap',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $proposals = [];
        for ($i = 0; $i < 34; $i++) {
            $proposals[] = ['field' => 'budget', 'label' => 'Label ' . $i];
        }

        $this->actingAs($admin)->postJson('/admin/parser-keyword-proposals/collect', [
            'proposals' => $proposals,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('parser_keyword_proposals', 0);
    }

    // AI-4 provenance: AI-kept rows post with source AI-assisted; omitted source
    // defaults to correction; the review list exposes the badge value.
    // Called by: harvestCorrections() stamping window._autofillAiAssisted fields.
    public function test_source_tagging_defaults_and_lists(): void
    {
        $admin = User::create([
            'name' => 'Loop Source',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->postJson('/admin/parser-keyword-proposals/collect', [
            'proposals' => [
                ['field' => 'abc', 'label' => 'AI Found Wording', 'source' => 'AI-assisted'],
                ['field' => 'abc', 'label' => 'Human Fixed Wording'],
                ['field' => 'abc', 'label' => 'Bogus Origin', 'source' => 'model-guess'],
            ],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('parser_keyword_proposals', 0);

        $this->actingAs($admin)->postJson('/admin/parser-keyword-proposals/collect', [
            'proposals' => [
                ['field' => 'abc', 'label' => 'AI Found Wording', 'source' => 'AI-assisted'],
                ['field' => 'abc', 'label' => 'Human Fixed Wording'],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('parser_keyword_proposals', [
            'field' => 'abc',
            'label_phrase' => 'AI Found Wording',
            'source' => 'AI-assisted',
        ]);
        $this->assertDatabaseHas('parser_keyword_proposals', [
            'field' => 'abc',
            'label_phrase' => 'Human Fixed Wording',
            'source' => 'correction',
        ]);

        $staff = User::create([
            'name' => 'Loop Source Reader',
            'email' => fake()->unique()->safeEmail(),
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->actingAs($staff)->getJson('/staff/parser-keyword-proposals/all')
            ->assertOk()
            ->assertJsonPath('0.source', 'AI-assisted');
    }
}
