<?php

namespace Tests\Feature;

use App\Http\Controllers\SCurveImportController;
use App\Models\Contractor;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\User;
use App\Services\SCurveSourceFinder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ============================================================================
 * SCurveSourcePickerTest
 * ============================================================================
 *
 * Purpose
 * -------
 * textfiles/scurve_import_original_baseline_plan.md:
 *   · SCurveSourceFinder — every Excel/PDF of the project is a candidate, the
 *     document TYPE only ranks it (renaming/removing "Schedule" hides nothing)
 *   · F-3 — a stored document is read only for a user who may view its project
 *   · the project page hands the candidates to the picker (window.__scurveSources),
 *     shows "Import from S-curve" for any candidate, and carries the Edit Original
 *     "Import from S-curve" row in the setup modal
 *
 * Run With
 * --------
 *   php artisan test --filter=SCurveSourcePickerTest
 */
class SCurveSourcePickerTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->project = Project::factory()->create([
            'status' => 'Ongoing',
            'planned_start_date' => '2026-04-01',
            'planned_end_date'   => '2027-03-31',
            'original_planned_start_date' => '2026-04-01',
            'original_planned_end_date'   => '2027-03-31',
        ]);
    }

    // === HELPERS ===

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role) . ' User', 'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'), 'role' => $role, 'is_active' => true,
        ]);
    }

    private function contractorFor(Project $project): User
    {
        $user = $this->user('contractor');
        $contractor = Contractor::create([
            'user_id' => $user->id, 'contractor_id' => 'CON-' . $user->id,
            'company_name' => 'ACME ' . $user->id, 'contractor_name' => $user->name,
        ]);
        $project->update(['contractor_id' => $contractor->id]);

        return $user->fresh();
    }

    /** A stored project document of the given type, uploaded at $when. */
    private function doc(string $name, string $typeName, string $when, ?Project $project = null): Document
    {
        $type = DocumentType::firstOrCreate(['name' => $typeName], ['is_active' => true]);
        $path = 'projects/documents/test/' . uniqid() . '-' . $name;
        Storage::disk('public')->put($path, 'not a real workbook');

        $doc = Document::create([
            'project_id' => ($project ?? $this->project)->id, 'document_type_id' => $type->id,
            'document_name' => $name, 'file_path' => $path, 'uploaded_by' => null,
        ]);
        $doc->created_at = Carbon::parse($when);
        $doc->save();

        return $doc;
    }

    // === SCurveSourceFinder ===

    public function test_every_excel_and_pdf_is_a_candidate_ranked_by_type_and_name(): void
    {
        $this->actingAs($this->user('admin'));
        $this->doc('contract.pdf', 'Contract', '2026-03-01');
        $this->doc('site photo.jpg', 'Schedule', '2026-03-02');           // not an S-curve format
        $this->doc('revised s-curve.xlsx', 'Supporting Document', '2026-06-01');
        $this->doc('preliminary.xlsx', 'Schedule', '2026-03-05');
        $this->doc('budget.xlsx', 'Supporting Document', '2026-03-06');

        $c = app(SCurveSourceFinder::class)->candidatesFor($this->project);

        $this->assertSame(
            ['preliminary.xlsx', 'revised s-curve.xlsx', 'budget.xlsx', 'contract.pdf'],
            array_column($c, 'name')
        );
        $this->assertSame([5, 3, 1, 0], array_column($c, 'score'));
        $this->assertTrue($c[0]['is_schedule_type']);
        $this->assertStringContainsString('/admin/documents/', $c[0]['download_url']);
    }

    public function test_equal_scores_put_the_newest_upload_first(): void
    {
        $this->actingAs($this->user('staff'));
        $this->doc('S-curve preliminary.xlsx', 'Schedule', '2026-03-01');
        $this->doc('S-curve revised.xlsx', 'Schedule', '2026-07-01');

        $this->assertSame(
            ['S-curve revised.xlsx', 'S-curve preliminary.xlsx'],
            array_column(app(SCurveSourceFinder::class)->candidatesFor($this->project), 'name')
        );
    }

    public function test_renaming_the_schedule_type_hides_nothing(): void
    {
        $this->actingAs($this->user('admin'));
        $doc = $this->doc('baseline.xlsx', 'Schedule', '2026-03-01');
        $doc->documentType->update(['name' => 'S-Curve']);

        $c = app(SCurveSourceFinder::class)->candidatesFor($this->project);

        $this->assertSame(['baseline.xlsx'], array_column($c, 'name'));
        $this->assertFalse($c[0]['is_schedule_type']);
    }

    // === F-3: document access ===

    public function test_document_visibility_follows_the_project_policy(): void
    {
        $doc = $this->doc('baseline.xlsx', 'Schedule', '2026-03-01');
        $own = $this->contractorFor($this->project);
        $other = $this->contractorFor(Project::factory()->create());

        $this->assertTrue($doc->isViewableBy($this->user('admin')));
        $this->assertTrue($doc->isViewableBy($this->user('staff')));
        $this->assertTrue($doc->isViewableBy($own));
        $this->assertFalse($doc->isViewableBy($other));
        $this->assertFalse($doc->isViewableBy(null));
    }

    public function test_parse_refuses_a_document_the_caller_may_not_view(): void
    {
        $doc = $this->doc('baseline.xlsx', 'Schedule', '2026-03-01');
        $other = $this->contractorFor(Project::factory()->create());
        $own = $this->contractorFor($this->project);

        $call = function (User $user) use ($doc) {
            $request = Request::create('/s-curve/parse', 'POST', ['document_id' => $doc->id]);
            $request->setUserResolver(fn () => $user);

            return app(SCurveImportController::class)->parseExcel($request)->getData(true);
        };

        // Another project's contractor: refused as if the file did not exist
        $this->assertSame('The selected document could not be found.', $call($other)['message']);
        // The project's own contractor passes the access check (the dummy file then
        // fails to PARSE — a different message)
        $this->assertNotSame('The selected document could not be found.', $call($own)['message']);
    }

    // === Project page wiring ===

    public function test_project_page_offers_import_for_any_candidate_and_lists_them_all(): void
    {
        $this->doc('preliminary s-curve.xlsx', 'Schedule', '2026-03-01');
        $this->doc('revised.xlsx', 'Supporting Document', '2026-07-01');

        $html = $this->actingAs($this->user('admin'))
            ->get(route('admin.projects.detail', $this->project->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('window.__scurveSources =', $html);
        $this->assertStringContainsString('preliminary s-curve.xlsx', $html);
        $this->assertStringContainsString('revised.xlsx', $html);
        $this->assertStringContainsString('id="scurveImportBtnDetail"', $html, 'No baseline yet → the setup import button shows');
        $this->assertStringContainsString('id="setupImportScurveWrap"', $html, 'The Edit Original import row is in the setup modal');
        $this->assertStringContainsString('"originalEndDate":"2027-03-31"', $html);
    }

    public function test_import_button_shows_without_any_schedule_typed_document(): void
    {
        // Project 142's case: the S-curve was filed as a Supporting Document
        $this->doc('S-curve.xlsx', 'Supporting Document', '2026-03-01');

        $this->actingAs($this->user('admin'))
            ->get(route('admin.projects.detail', $this->project->id))
            ->assertOk()
            ->assertSee('id="scurveImportBtnDetail"', false);
    }

    public function test_no_candidates_means_no_import_button(): void
    {
        $this->doc('contract.docx', 'Contract', '2026-03-01');

        $this->actingAs($this->user('admin'))
            ->get(route('admin.projects.detail', $this->project->id))
            ->assertOk()
            ->assertDontSee('id="scurveImportBtnDetail"', false);
    }

    public function test_setup_import_button_is_replaced_by_edit_original_once_a_baseline_exists(): void
    {
        $this->doc('S-curve.xlsx', 'Schedule', '2026-03-01');
        ProjectBaselineSchedule::factory()->create([
            'project_id' => $this->project->id, 'month_label' => '2026-04', 'planned_date' => '2026-04-30',
            'planned_progress_percent' => 100, 'original_planned_progress_percent' => 100,
            'original_planned_date' => '2026-04-30',
        ]);

        $this->actingAs($this->user('admin'))
            ->get(route('admin.projects.detail', $this->project->id))
            ->assertOk()
            ->assertDontSee('id="scurveImportBtnDetail"', false)
            ->assertSee('Edit Original Baseline')
            ->assertSee('window.__scurveSources =', false);
    }

    public function test_contractor_page_gets_no_import_sources(): void
    {
        $this->doc('S-curve.xlsx', 'Schedule', '2026-03-01');
        $contractor = $this->contractorFor($this->project);

        $this->actingAs($contractor)
            ->get(route('projects.show', $this->project->id))
            ->assertOk()
            ->assertDontSee('window.__scurveSources =', false);
    }
}
