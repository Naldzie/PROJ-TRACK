<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Project;
use App\Models\ScurveHeaderProposal;
use App\Models\User;
use App\Services\SCurveImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

// E1-1: silent header-block capture from failed S-curve imports.
// Collection is open to admin AND staff (E1b); contractors get 403. Captures never
// affect extraction until E1-2 review.
class ScurveHeaderProposalTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        return User::create([
            'name' => 'Header ' . $role,
            'email' => fake()->unique()->safeEmail(),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function makeWorkbook(string $path, string $descHeader = 'WEIRD DESC HEADER'): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', $descHeader);
        $sheet->setCellValue('B10', 'TIMEFRAME ONE');
        $sheet->setCellValue('A11', 'Some Work');
        $sheet->setCellValue('B11', 100);
        (new Xlsx($spreadsheet))->save($path);
    }

    // Near-miss layout: months use standard labels but the description header
    // is a novel contractor wording ("TASK DESCRIPTION") the strict rules
    // reject — the exact class E1-3 auto-maps are for. Same figures as the
    // SCurveImportTest fixture (contract 400000; M1 87.5 / M2 87.5 / M3 100).
    private function makeNearMissWorkbook(string $path, string $descHeader = 'TASK DESCRIPTION'): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'ITEM');
        $sheet->setCellValue('C10', $descHeader);
        $sheet->setCellValue('F10', 'CONTRACT AMOUNT');
        $sheet->setCellValue('G10', '% WEIGHT');
        $sheet->setCellValue('I10', 'Month 1');
        $sheet->setCellValue('K10', 'Month 2');
        $sheet->setCellValue('M10', 'Month 3');

        $sheet->setCellValue('B12', '1');
        $sheet->setCellValue('C12', 'Mobilization and Demobilization');
        $sheet->setCellValue('F12', 100000);
        $sheet->setCellValue('G12', 25);
        $sheet->setCellValue('I12', 50000);
        $sheet->setCellValue('N12', 50000);

        $sheet->setCellValue('B13', '2');
        $sheet->setCellValue('C13', 'Site Layout and Survey Works');
        $sheet->setCellValue('F13', 300000);
        $sheet->setCellValue('G13', 75);
        $sheet->setCellValue('I13', 150000);
        $sheet->setCellValue('J13', 150000);

        $sheet->setCellValue('C14', 'TOTAL');
        $sheet->setCellValue('F14', 400000);
        $sheet->setCellValue('G14', 100);

        (new Xlsx($spreadsheet))->save($path);
    }

    // Standard layout the strict rules already cover (proves rules-win ties).
    private function makeStandardWorkbook(string $path): void
    {
        $this->makeNearMissWorkbook($path, 'DESCRIPTION');
    }

    // Fourth-family layout (P1-follow-up 2026-09-10): STAGE months + TASKS desc.
    // Both axes miss strict rules AND the old relaxed vocabulary — proves the
    // relaxed-only widening (strict path never learns without evidence).
    private function makeStageWorkbook(string $path): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('C10', 'SCHEDULED TASKS');
        $sheet->setCellValue('F10', 'CONTRACT AMOUNT');
        $sheet->setCellValue('I10', 'STAGE 1');
        $sheet->setCellValue('K10', 'STAGE 2');
        $sheet->setCellValue('C12', 'Mobilization');
        $sheet->setCellValue('F12', 600000);
        $sheet->setCellValue('I12', 100000);
        $sheet->setCellValue('J12', 100000);
        $sheet->setCellValue('K12', 200000);
        $sheet->setCellValue('L12', 200000);
        $sheet->setCellValue('C13', 'TOTAL');
        $sheet->setCellValue('F13', 600000);
        (new Xlsx($spreadsheet))->save($path);
    }

    // Third-family layout (user's real 10-month file, 2026-09-09): months as
    // P1..P10 single columns, description as "WORK DETAILS". Both labels miss
    // the strict rules — the exact double-miss E1-3 widening covers. Figures
    // mirror the file's own summary row (5..100 of 1,000,000).
    private function makeThirdFamilyWorkbook(string $path): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'CONSTRUCTION SCHEDULE AND S-CURVE (THIRD-FAMILY TEST FILE)');
        $sheet->setCellValue('A2', 'ITEM');
        $sheet->setCellValue('B2', 'WORK DETAILS');
        $sheet->setCellValue('C2', 'QTY');
        $sheet->setCellValue('D2', 'UNIT');
        $sheet->setCellValue('E2', 'BUDGET COST');
        // Helper: write by 1-based column index (PhpSpreadsheet 4 has no
        // setCellValueByColumnAndRow — build the A1 address instead).
        $put = function (int $col, int $row, mixed $value) use ($sheet): void {
            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row,
                $value
            );
        };
        foreach (range(1, 10) as $m) {
            $put(5 + $m, 2, 'P' . $m);
        }

        // Month columns are F(6)..O(15); rows 3..6 hold the activities.
        $activities = [
            ['1', 'Mobilization Works', 100000, [1 => 50000, 2 => 50000]],
            ['2', 'Earthworks and Excavation', 400000, [3 => 100000, 4 => 100000, 5 => 100000, 6 => 100000]],
            ['3', 'Concrete and Masonry Works', 300000, [5 => 50000, 6 => 50000, 7 => 150000, 8 => 50000]],
            ['4', 'Finishing and Painting Works', 200000, [8 => 50000, 9 => 100000, 10 => 50000]],
        ];
        $row = 3;
        foreach ($activities as [$code, $name, $cost, $months]) {
            $put(1, $row, $code);
            $put(2, $row, $name);
            $put(5, $row, $cost);
            foreach ($months as $m => $peso) {
                $put(5 + $m, $row, $peso);
            }
            $row++;
        }

        $sheet->setCellValue('B7', 'TOTAL');
        $sheet->setCellValue('E7', 1000000);
        $sheet->setCellValue('B8', 'CUMULATIVE % ACHIEVED');
        foreach ([5, 10, 20, 30, 45, 60, 75, 85, 95, 100] as $i => $pct) {
            $put(6 + $i, 8, $pct);
        }

        (new Xlsx($spreadsheet))->save($path);
    }

    // Store captures from an admin file upload (no contractor → global NULL row).
    // Called by: POST /admin/scurve-header-proposals/collect from scurveCaptureHeaders().
    public function test_admin_can_store_capture(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeWorkbook($path);
        $upload = new UploadedFile($path, 'weird.xlsx', null, null, true);

        $response = $this->actingAs($this->makeUser('admin'))
            ->post('/admin/scurve-header-proposals/collect', ['file' => $upload]);

        $response->assertCreated()->assertJson(['success' => true, 'stored' => 1]);
        $this->assertDatabaseHas('scurve_header_proposals', [
            'status' => 'proposed',
            'source' => 'auto-capture',
            'source_type' => 'excel',
            'occurrences' => 1,
        ]);
        $this->assertNull(ScurveHeaderProposal::first()->contractor_id);

        unlink($path);
    }

    // Repeats bump occurrences; the third confirmation auto-promotes to live.
    // Called by: repeated failures on the same unknown layout.
    public function test_repeats_increment_and_third_promotes_to_live(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeWorkbook($path);

        $staff = $this->makeUser('staff');
        for ($i = 0; $i < 3; $i++) {
            $upload = new UploadedFile($path, 'weird.xlsx', null, null, true);
            $this->actingAs($staff)
                ->post('/staff/scurve-header-proposals/collect', ['file' => $upload])
                ->assertCreated();
        }

        $row = ScurveHeaderProposal::first();
        $this->assertNotNull($row);
        $this->assertSame(3, $row->occurrences);
        $this->assertSame('live', $row->status);

        unlink($path);
    }

    // Contractors never upload, and the endpoint refuses them regardless.
    // Called by: POST to either role collect route as a contractor.
    public function test_contractor_is_forbidden_on_both_routes(): void
    {
        $contractor = $this->makeUser('contractor');

        $this->actingAs($contractor)->postJson('/staff/scurve-header-proposals/collect', [])
            ->assertRedirect(route('unauthorized'));
        $this->actingAs($contractor)->postJson('/admin/scurve-header-proposals/collect', [])
            ->assertRedirect(route('unauthorized'));
        $this->assertDatabaseCount('scurve_header_proposals', 0);
    }

    // Source discipline: exactly one source, Excel only, known contractor ids.
    // Called by: malformed capture payloads.
    public function test_validation_rejects_bad_payloads(): void
    {
        $admin = $this->makeUser('admin');

        // No source at all.
        $this->actingAs($admin)->postJson('/admin/scurve-header-proposals/collect', [])
            ->assertUnprocessable();

        // Unknown contractor id (multipart posts need explicit JSON accept for 422s).
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeWorkbook($path);
        $upload = new UploadedFile($path, 'weird.xlsx', null, null, true);
        $this->actingAs($admin)->post(
            '/admin/scurve-header-proposals/collect',
            ['file' => $upload, 'contractor_id' => 999999],
            ['Accept' => 'application/json']
        )->assertUnprocessable();

        // Non-Excel upload.
        $pdf = UploadedFile::fake()->create('curve.pdf', 100, 'application/pdf');
        $this->actingAs($admin)->post(
            '/admin/scurve-header-proposals/collect',
            ['file' => $pdf],
            ['Accept' => 'application/json']
        )->assertUnprocessable();

        $this->assertDatabaseCount('scurve_header_proposals', 0);
        unlink($path);
    }

    // Stored documents resolve the contractor via Document → project (E1a hybrid scope).
    // Called by: detail-flow captures where the client sends only document_id.
    public function test_document_id_resolves_contractor_via_project(): void
    {
        Storage::fake('public');

        $admin = $this->makeUser('admin');
        $contractorUser = $this->makeUser('contractor');
        $contractor = Contractor::create([
            'user_id' => $contractorUser->id,
            'company_name' => 'Capture Builders',
        ]);
        $project = Project::factory()->create(['contractor_id' => $contractor->id]);
        $type = DocumentType::firstOrCreate(['name' => 'Schedule']);

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeWorkbook($path);
        Storage::disk('public')->put('cap/weird.xlsx', file_get_contents($path));

        $doc = Document::create([
            'project_id' => $project->id,
            'document_type_id' => $type->id,
            'document_name' => 'Weird curve',
            'file_path' => 'cap/weird.xlsx',
            'uploaded_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/scurve-header-proposals/collect', ['document_id' => $doc->id])
            ->assertCreated()->assertJson(['success' => true]);

        $row = ScurveHeaderProposal::first();
        $this->assertNotNull($row);
        $this->assertSame($contractor->id, $row->contractor_id);

        unlink($path);
    }

    // Explicit contractor_id wins over document resolution.
    // Called by: wizard-flow captures carrying the Page-4 contractor choice.
    public function test_explicit_contractor_id_wins(): void
    {
        Storage::fake('public');

        $admin = $this->makeUser('admin');
        $contractorUser = $this->makeUser('contractor');
        $contractor = Contractor::create([
            'user_id' => $contractorUser->id,
            'company_name' => 'Explicit Builders',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeWorkbook($path);
        $upload = new UploadedFile($path, 'weird.xlsx', null, null, true);

        $this->actingAs($admin)->post('/admin/scurve-header-proposals/collect', [
            'file' => $upload,
            'contractor_id' => $contractor->id,
        ])->assertCreated();

        $this->assertSame($contractor->id, ScurveHeaderProposal::first()->contractor_id);

        unlink($path);
    }

    // Staff can review: list shows captures most-seen first, with ?status= filter.
    // Called by: GET .../scurve-header-proposals/all from the review modal.
    public function test_staff_can_list_captures_with_status_filter(): void
    {
        $staff = $this->makeUser('staff');
        ScurveHeaderProposal::create([
            'header_rows_text' => 'WEIRD DESC HEADER', 'header_hash' => hash('sha256', 'a'),
            'occurrences' => 5, 'status' => 'proposed',
        ]);
        ScurveHeaderProposal::create([
            'header_rows_text' => 'OTHER HEADER', 'header_hash' => hash('sha256', 'b'),
            'occurrences' => 1, 'status' => 'live',
        ]);

        $this->actingAs($staff)->getJson('/staff/scurve-header-proposals/all')
            ->assertOk()->assertJsonCount(2);
        $this->actingAs($staff)->getJson('/staff/scurve-header-proposals/all?status=live')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.status', 'live');
    }

    // Approve flips proposed → live (staff may approve per E1b, via staff route).
    // Called by: PATCH .../approve from the review modal Approve button.
    public function test_staff_can_approve_capture(): void
    {
        $staff = $this->makeUser('staff');
        $row = ScurveHeaderProposal::create([
            'header_rows_text' => 'WEIRD DESC HEADER', 'header_hash' => hash('sha256', 'a'),
            'status' => 'proposed',
        ]);

        $this->actingAs($staff)->patchJson("/staff/scurve-header-proposals/{$row->id}/approve")
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame('live', $row->fresh()->status);
    }

    // Reject soft-deletes (history preserved, recoverable).
    // Called by: DELETE from the review modal Reject button.
    public function test_admin_can_reject_capture(): void
    {
        $admin = $this->makeUser('admin');
        $row = ScurveHeaderProposal::create([
            'header_rows_text' => 'WEIRD DESC HEADER', 'header_hash' => hash('sha256', 'a'),
            'status' => 'proposed',
        ]);

        $this->actingAs($admin)->deleteJson("/admin/scurve-header-proposals/{$row->id}")
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSoftDeleted('scurve_header_proposals', ['id' => $row->id]);
    }

    // Contractors get nothing on review routes either (redirect by role middleware).
    // Called by: any contractor request to the review endpoints.
    public function test_contractor_blocked_from_review_endpoints(): void
    {
        $contractor = $this->makeUser('contractor');
        $row = ScurveHeaderProposal::create([
            'header_rows_text' => 'WEIRD DESC HEADER', 'header_hash' => hash('sha256', 'a'),
            'status' => 'proposed',
        ]);

        $this->actingAs($contractor)->getJson('/staff/scurve-header-proposals/all')->assertRedirect(route('unauthorized'));
        $this->actingAs($contractor)->patchJson("/admin/scurve-header-proposals/{$row->id}/approve")->assertRedirect(route('unauthorized'));
        $this->assertSame('proposed', $row->fresh()->status);
    }

    // =====================================================================
    // Phase-A point-and-teach (reviewer-picked letters → validated v1 map).
    // =====================================================================

    private function makeTeachableRow(array $overrides = []): ScurveHeaderProposal
    {
        // NOTE: legend runs A..N — N12 holds 50000 in the real file, so M3 must
        // span M:N. Truncating the text at M once produced a perfect red herring:
        // empty M3 → lastNonZero=1 → force-final pinned M1 at 100.
        $text = "COLUMNS: A,B,C,D,E,F,G,H,I,J,K,L,M,N | META: title | HEADER: labels | DATA: rows | SUMMARY: totals\n"
            . "R1 [HEADER]: ITEM,,TASK DESCRIPTION,,,CONTRACT AMOUNT,% WEIGHT,,Month 1,,Month 2,,Month 3\n"
            . 'R2 [DATA]: ,1,Mobilization,,,100000,25,,50000,,,,,50000';

        return ScurveHeaderProposal::create(array_merge([
            'header_rows_text' => $text,
            'header_hash' => hash('sha256', uniqid()),
            'column_map' => null,
            'occurrences' => 2,
            'status' => 'proposed',
            'source' => 'auto-capture',
        ], $overrides));
    }

    private function teachPayload(array $overrides = []): array
    {
        return array_merge([
            'desc' => 'C',
            'contract' => 'F',
            'weight' => 'G',
            'months' => ['1' => ['I', 'J'], '2' => ['K', 'L'], '3' => ['M', 'N']],
            'header_rows' => [1],
            'mode' => 'variant',
        ], $overrides);
    }

    // Teaching saves a SIBLING variant (original untouched) so alternating
    // contractor formats are never forgotten.
    // Called by: PATCH .../columns from the teach modal (mode variant).
    public function test_teach_variant_creates_sibling_row(): void
    {
        $staff = $this->makeUser('staff');
        $original = $this->makeTeachableRow();

        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$original->id}/columns", $this->teachPayload())
            ->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('mode', 'variant');

        $this->assertSame(2, ScurveHeaderProposal::count());
        $this->assertNull($original->fresh()->column_map);
        $this->assertSame('proposed', $original->fresh()->status);

        $variant = ScurveHeaderProposal::where('source', 'manual-teach')->first();
        $this->assertNotNull($variant);
        $this->assertSame('proposed', $variant->status);
        $this->assertSame(1, $variant->occurrences);
        $map = $variant->column_map;
        $this->assertSame(1, $map['v']);
        $this->assertSame(2, $map['descCol']);
        $this->assertCount(3, $map['monthSpans']);
        $this->assertNotEmpty($map['sig']);
    }

    // A taught variant actually consults: approve it, then the near-miss file
    // parses through the hand-built map with the exact expected curve.
    // Called by: parse() pass 2 via live manual-teach maps.
    public function test_taught_variant_consults_end_to_end(): void
    {
        $staff = $this->makeUser('staff');
        $original = $this->makeTeachableRow();
        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$original->id}/columns", $this->teachPayload())
            ->assertOk();

        $variant = ScurveHeaderProposal::where('source', 'manual-teach')->first();
        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$variant->id}/approve")
            ->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNearMissWorkbook($path);

        $liveMaps = ScurveHeaderProposal::live()->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id,
            'contractor_id' => $r->contractor_id,
            'column_map' => $r->column_map,
        ])->all();
        $result = app(\App\Services\SCurveImportService::class)->parse($path, $liveMaps);

        $this->assertCount(3, $result['months']);
        $this->assertSame(87.5, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][2]['planned_progress_percent']);
        $this->assertTrue($result['meta']['mapUsed']);

        unlink($path);
    }

    // Explicit replace overwrites the map AND drops live → proposed (re-review).
    // Called by: PATCH .../columns with mode replace.
    public function test_teach_replace_drops_live_to_proposed(): void
    {
        $admin = $this->makeUser('admin');
        $row = $this->makeTeachableRow([
            'column_map' => ['v' => 1, 'sig' => [[0, 'x'], [1, 'y']], 'descCol' => 0, 'monthSpans' => [1 => [5], 2 => [6]]],
            'status' => 'live',
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/scurve-header-proposals/{$row->id}/columns", $this->teachPayload(['mode' => 'replace']))
            ->assertOk()->assertJsonPath('mode', 'replace');

        $fresh = $row->fresh();
        $this->assertSame(1, ScurveHeaderProposal::count());
        $this->assertSame('proposed', $fresh->status);
        $this->assertSame(2, $fresh->column_map['descCol']);
    }

    // Validation guards the reader: overlaps, desc-in-span, unknown letters,
    // single-month, and unknown header rows all 422 with plain messages.
    // Called by: teachColumns() guards + buildTaughtMap().
    public function test_teach_validation_rejects_bad_teaching(): void
    {
        $staff = $this->makeUser('staff');

        // Overlapping spans (J claimed by two months).
        $row = $this->makeTeachableRow();
        $this->actingAs($staff)->patchJson(
            "/staff/scurve-header-proposals/{$row->id}/columns",
            $this->teachPayload(['months' => ['1' => ['I', 'J'], '2' => ['J', 'K']]])
        )->assertUnprocessable();

        // Description column inside a month span.
        $row2 = $this->makeTeachableRow();
        $this->actingAs($staff)->patchJson(
            "/staff/scurve-header-proposals/{$row2->id}/columns",
            $this->teachPayload(['desc' => 'I'])
        )->assertUnprocessable();

        // Unknown letter.
        $row3 = $this->makeTeachableRow();
        $this->actingAs($staff)->patchJson(
            "/staff/scurve-header-proposals/{$row3->id}/columns",
            $this->teachPayload(['desc' => 'ZZ'])
        )->assertUnprocessable();

        // Single month (validator min:2).
        $row4 = $this->makeTeachableRow();
        $this->actingAs($staff)->patchJson(
            "/staff/scurve-header-proposals/{$row4->id}/columns",
            $this->teachPayload(['months' => ['1' => ['I', 'J']]])
        )->assertUnprocessable();

        // Unknown header row.
        $row5 = $this->makeTeachableRow();
        $this->actingAs($staff)->patchJson(
            "/staff/scurve-header-proposals/{$row5->id}/columns",
            $this->teachPayload(['header_rows' => [9]])
        )->assertUnprocessable();

        $this->assertSame(0, ScurveHeaderProposal::where('source', 'manual-teach')->count());
    }

    // Contractors stay out of the teach endpoint on both role routes.
    // Called by: ensureReviewer() via role middleware + controller guard.
    public function test_contractor_blocked_from_teach_endpoint(): void
    {
        $contractor = $this->makeUser('contractor');
        $row = $this->makeTeachableRow();

        $this->actingAs($contractor)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns", $this->teachPayload())
            ->assertRedirect(route('unauthorized'));
        $this->actingAs($contractor)
            ->patchJson("/admin/scurve-header-proposals/{$row->id}/columns", $this->teachPayload())
            ->assertRedirect(route('unauthorized'));
        $this->assertNull($row->fresh()->column_map);
    }

    // =====================================================================
    // E1-3 — reader consults live maps (lowest-priority fallback).
    // =====================================================================

    // Capture auto-derives a v:1 map for near-miss layouts (novel description
    // wording, standard months) — user choice: auto, with version flag.
    // Called by: POST .../collect from scurveCaptureHeaders().
    public function test_collect_derives_v1_auto_map_for_near_miss_layout(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNearMissWorkbook($path);
        $upload = new UploadedFile($path, 'nearmiss.xlsx', null, null, true);

        $this->actingAs($this->makeUser('admin'))
            ->post('/admin/scurve-header-proposals/collect', ['file' => $upload])
            ->assertCreated();

        $row = ScurveHeaderProposal::first();
        $this->assertNotNull($row);
        $this->assertIsArray($row->column_map);
        $this->assertSame(1, $row->column_map['v']);
        $this->assertIsInt($row->column_map['descCol']);
        $this->assertCount(3, $row->column_map['monthSpans']);
        $this->assertNotEmpty($row->column_map['sig']);

        unlink($path);
    }

    // Junk with no derivable header still captures (reviewable) but map-less.
    // Called by: POST .../collect with an unrecognizable workbook.
    public function test_collect_stores_null_map_when_no_header_derivable(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeWorkbook($path);
        $upload = new UploadedFile($path, 'weird.xlsx', null, null, true);

        $this->actingAs($this->makeUser('admin'))
            ->post('/admin/scurve-header-proposals/collect', ['file' => $upload])
            ->assertCreated();

        $this->assertNull(ScurveHeaderProposal::first()->column_map);

        unlink($path);
    }

    // Repeats backfill maps onto pre-E1-3 rows captured when maps were null.
    // Called by: POST .../collect for an already-stored (contractor, hash).
    public function test_repeat_backfills_missing_map(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNearMissWorkbook($path);

        $text = app(SCurveImportService::class)->serializeForAi($path, 15);
        $hash = hash('sha256', mb_strtolower(preg_replace('/\s+/', ' ', trim($text))));
        ScurveHeaderProposal::create([
            'header_rows_text' => mb_substr($text, 0, 4000),
            'header_hash' => $hash,
            'column_map' => null,
            'status' => 'proposed',
        ]);

        $upload = new UploadedFile($path, 'nearmiss.xlsx', null, null, true);
        $this->actingAs($this->makeUser('staff'))
            ->post('/staff/scurve-header-proposals/collect', ['file' => $upload])
            ->assertCreated();

        $row = ScurveHeaderProposal::first();
        $this->assertIsArray($row->column_map);
        $this->assertSame(1, $row->column_map['v']);

        unlink($path);
    }

    // The near-miss file 422s under rules but parses via its live map with
    // the exact expected curve + map provenance in meta.
    // Called by: SCurveImportService::parse($path, $liveMaps) pass 2.
    public function test_service_parses_via_live_map_when_rules_fail(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNearMissWorkbook($path);
        $service = app(SCurveImportService::class);

        try {
            $service->parse($path);
            $this->fail('Rules should miss the near-miss layout.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No S-curve table found', $e->getMessage());
        }

        $map = $service->deriveAutoColumnMap($path);
        $this->assertIsArray($map);

        $result = $service->parse($path, [
            ['id' => 7, 'contractor_id' => null, 'column_map' => $map],
        ]);

        $this->assertCount(3, $result['months']);
        $this->assertSame(87.5, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(87.5, $result['months'][1]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][2]['planned_progress_percent']);
        $this->assertSame(400000.0, $result['meta']['contractAmount']);
        $this->assertTrue($result['meta']['mapUsed']);
        $this->assertSame(7, $result['meta']['mapId']);
        $this->assertNotEmpty($result['meta']['warnings']);

        unlink($path);
    }

    // Typo tolerance (user choice): a re-upload with a header typo still
    // matches the stored signature and parses via the same map.
    // Called by: findSignatureRow() fuzzy per-cell matching.
    public function test_typo_variant_matches_live_map(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNearMissWorkbook($path);
        $service = app(SCurveImportService::class);
        $map = $service->deriveAutoColumnMap($path);
        unlink($path);

        $typo = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNearMissWorkbook($typo, 'TASK DESCRIPTON');

        $result = $service->parse($typo, [
            ['id' => 8, 'contractor_id' => null, 'column_map' => $map],
        ]);

        $this->assertCount(3, $result['months']);
        $this->assertSame(100.0, $result['months'][2]['planned_progress_percent']);
        $this->assertTrue($result['meta']['mapUsed']);

        unlink($typo);
    }

    // Rules win ties: a file the rules cover ignores even a matching live map.
    // Called by: parse() pass 1 running before pass 2.
    public function test_rules_win_over_matching_live_map(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeStandardWorkbook($path);
        $service = app(SCurveImportService::class);

        $plain = $service->parse($path);
        $this->assertFalse($plain['meta']['mapUsed']);

        $map = $service->deriveAutoColumnMap($path);
        $this->assertIsArray($map);

        $viaMap = $service->parse($path, [
            ['id' => 9, 'contractor_id' => null, 'column_map' => $map],
        ]);

        $this->assertFalse($viaMap['meta']['mapUsed']);
        $this->assertSame($plain['months'], $viaMap['months']);
        $this->assertSame($plain['meta']['warnings'], $viaMap['meta']['warnings']);

        unlink($path);
    }

    // Version flag: a map from a future span semantics never applies.
    // Called by: findHeaderViaMaps() MAP_VERSION gate.
    public function test_version_mismatch_map_is_ignored(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNearMissWorkbook($path);
        $service = app(SCurveImportService::class);

        $map = $service->deriveAutoColumnMap($path);
        $map['v'] = 999;

        try {
            $service->parse($path, [
                ['id' => 10, 'contractor_id' => null, 'column_map' => $map],
            ]);
            $this->fail('A version-mismatched map must never apply.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No S-curve table found', $e->getMessage());
        }

        unlink($path);
    }

    // A map for another contractor never leaks (E1a hybrid scope, scoped first).
    // Called by: POST /admin/s-curve/parse with contractor_id scoping.
    public function test_parse_endpoint_scopes_maps_by_contractor(): void
    {
        $admin = $this->makeUser('admin');
        $contractorA = Contractor::create([
            'user_id' => $this->makeUser('contractor')->id,
            'company_name' => 'Map Builders A',
        ]);
        $contractorB = Contractor::create([
            'user_id' => $this->makeUser('contractor')->id,
            'company_name' => 'Map Builders B',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNearMissWorkbook($path);
        $map = app(SCurveImportService::class)->deriveAutoColumnMap($path);
        ScurveHeaderProposal::create([
            'contractor_id' => $contractorA->id,
            'header_rows_text' => 'near-miss',
            'header_hash' => hash('sha256', uniqid()),
            'column_map' => $map,
            'status' => 'live',
        ]);

        // Own contractor: map applies.
        $upload = new UploadedFile($path, 'nearmiss.xlsx', null, null, true);
        $this->actingAs($admin)->post('/admin/s-curve/parse', [
            'file' => $upload,
            'contractor_id' => $contractorA->id,
        ])->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('meta.mapUsed', true)
            ->assertJsonCount(3, 'months');

        // Other contractor: honestly 422s (no leak).
        $upload2 = new UploadedFile($path, 'nearmiss.xlsx', null, null, true);
        $this->actingAs($admin)->post('/admin/s-curve/parse', [
            'file' => $upload2,
            'contractor_id' => $contractorB->id,
        ])->assertStatus(422)->assertJsonPath('success', false);

        unlink($path);
    }

    // Global (NULL-contractor) live maps apply when no contractor map matches.
    // Called by: POST /admin/s-curve/parse without contractor scope.
    public function test_parse_endpoint_applies_global_map(): void
    {
        $admin = $this->makeUser('admin');

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNearMissWorkbook($path);
        $map = app(SCurveImportService::class)->deriveAutoColumnMap($path);
        ScurveHeaderProposal::create([
            'contractor_id' => null,
            'header_rows_text' => 'near-miss global',
            'header_hash' => hash('sha256', uniqid()),
            'column_map' => $map,
            'status' => 'live',
        ]);

        $upload = new UploadedFile($path, 'nearmiss.xlsx', null, null, true);
        $this->actingAs($admin)->post('/admin/s-curve/parse', ['file' => $upload])
            ->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('meta.mapUsed', true);

        unlink($path);
    }

    // =====================================================================
    // E1-3 widening 2026-09-09 — third family (P1..P10 + WORK DETAILS).
    // =====================================================================

    // The user's real 10-month file double-misses strict rules (both labels
    // novel) but auto-derives a v:1 map with 10 single-column spans.
    // Called by: POST .../collect from scurveCaptureHeaders().
    public function test_collect_derives_map_for_p1_third_family(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeThirdFamilyWorkbook($path);
        $upload = new UploadedFile($path, 'third.xlsx', null, null, true);

        $this->actingAs($this->makeUser('admin'))
            ->post('/admin/scurve-header-proposals/collect', ['file' => $upload])
            ->assertCreated();

        $row = ScurveHeaderProposal::first();
        $this->assertNotNull($row);
        $this->assertIsArray($row->column_map);
        $this->assertSame(1, $row->column_map['v']);
        $this->assertCount(10, $row->column_map['monthSpans']);

        unlink($path);
    }

    // P1-follow-up: STAGE months + TASKS desc derive via the relaxed path while
    // strict rules still 422 (relaxed-only doctrine — the main path never learns
    // new synonyms without evidence).
    // Called by: deriveAutoColumnMap() relaxed detector.
    public function test_collect_derives_map_for_stage_tasks_layout(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeStageWorkbook($path);
        $service = app(SCurveImportService::class);

        try {
            $service->parse($path);
            $this->fail('Strict rules should miss the STAGE/TASKS layout.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No S-curve table found', $e->getMessage());
        }

        $map = $service->deriveAutoColumnMap($path);
        $this->assertIsArray($map);
        $this->assertSame(1, $map['v']);
        $this->assertCount(2, $map['monthSpans']);

        $result = $service->parse($path, [
            ['id' => 12, 'contractor_id' => null, 'column_map' => $map],
        ]);

        // M1 = I+J pair = 200000, M2 = K+L pair = 400000, contract 600000 →
        // 33.33 / 100.
        $this->assertCount(2, $result['months']);
        $this->assertSame(33.33, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][1]['planned_progress_percent']);
        $this->assertTrue($result['meta']['mapUsed']);

        unlink($path);
    }

    // Rules honestly 422 the P1 file; the live map parses the full 10-month
    // curve matching the file's own summary row (5..100 of 1,000,000).
    // Called by: SCurveImportService::parse($path, $liveMaps) pass 2.
    public function test_service_parses_p1_file_via_live_map(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeThirdFamilyWorkbook($path);
        $service = app(SCurveImportService::class);

        try {
            $service->parse($path);
            $this->fail('Rules should miss the P1/WORK DETAILS layout.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No S-curve table found', $e->getMessage());
        }

        $map = $service->deriveAutoColumnMap($path);
        $this->assertIsArray($map);

        $result = $service->parse($path, [
            ['id' => 11, 'contractor_id' => null, 'column_map' => $map],
        ]);

        $this->assertCount(10, $result['months']);
        $this->assertSame(
            [5.0, 10.0, 20.0, 30.0, 45.0, 60.0, 75.0, 85.0, 95.0, 100.0],
            array_map(fn ($m) => $m['planned_progress_percent'], $result['months'])
        );
        $this->assertSame(1000000.0, $result['meta']['contractAmount']);
        $this->assertTrue($result['meta']['mapUsed']);
        $this->assertSame(11, $result['meta']['mapId']);

        unlink($path);
    }

    // End-to-end version of the user's flow: capture → approve → re-import a
    // global live map parses without the AI-assist fallback.
    // Called by: POST /admin/s-curve/parse with a live global map in scope.
    public function test_parse_endpoint_parses_p1_file_after_approval(): void
    {
        $admin = $this->makeUser('admin');

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeThirdFamilyWorkbook($path);
        $map = app(SCurveImportService::class)->deriveAutoColumnMap($path);
        $this->assertIsArray($map);
        ScurveHeaderProposal::create([
            'contractor_id' => null,
            'header_rows_text' => 'third-family',
            'header_hash' => hash('sha256', uniqid()),
            'column_map' => $map,
            'status' => 'live',
        ]);

        $upload = new UploadedFile($path, 'third.xlsx', null, null, true);
        $this->actingAs($admin)->post('/admin/s-curve/parse', ['file' => $upload])
            ->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('meta.mapUsed', true)
            ->assertJsonCount(10, 'months');

        unlink($path);
    }

    // Role placement on the third-family shape (off-by-one fix 2026-09-10): the
    // user's live report showed R2 [META] + R3 [HEADER] on a file whose labels
    // sit on sheet row 2 — the numeric grid (0-based) was read against
    // letter-keyed roles (1-based sheet rows). Rows here: 1 title, 2 header,
    // 3-6 activities, 7 TOTAL, 8 summary — tags must match exactly.
    public function test_third_family_roles_sit_on_correct_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeThirdFamilyWorkbook($path);

        $csv = app(\App\Services\SCurveImportService::class)->serializeForAi($path, 15);
        $tags = [];
        foreach (explode("\n", $csv) as $line) {
            if (preg_match('/^(R\d+) \[([A-Z]+)\]:/', trim($line), $m)) {
                $tags[$m[1]] = $m[2];
            }
        }

        $this->assertSame('META', $tags['R1'] ?? null);
        $this->assertSame('HEADER', $tags['R2'] ?? null);
        $this->assertSame('DATA', $tags['R3'] ?? null);
        $this->assertSame('SUMMARY', $tags['R7'] ?? null);
        $this->assertSame('SUMMARY', $tags['R8'] ?? null);

        unlink($path);
    }

    // =====================================================================
    // Phase-B learn-from-AI (AI success → proposed layout, never auto-live).
    // =====================================================================

    // A novel layout even relaxed detection misses: undescribed desc wording
    // plus quarter-style months (Q1..Q3 match no month rule at all).
    private function makeNovelWorkbook(string $path): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A2', 'LINE HEADINGS');
        $sheet->setCellValue('B2', 'Q1');
        $sheet->setCellValue('C2', 'Q2');
        $sheet->setCellValue('D2', 'Q3');
        $sheet->setCellValue('A3', 'Ground Works');
        $sheet->setCellValue('B3', 100000);
        $sheet->setCellValue('C3', 100000);
        $sheet->setCellValue('D3', 200000);
        (new Xlsx($spreadsheet))->save($path);
    }

    // Same novel layout, but WITH a cost column and TOTAL row — so a map derived
    // from it has a real denominator. Without one the parse falls back to weights
    // and its curve must not be preferred over the AI's (the gate in
    // applyDerivedLayout). True math here: 100k/100k/200k of 400k = 25/50/100.
    private function makeCostedNovelWorkbook(string $path): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A2', 'LINE HEADINGS');
        $sheet->setCellValue('B2', 'Q1');
        $sheet->setCellValue('C2', 'Q2');
        $sheet->setCellValue('D2', 'Q3');
        $sheet->setCellValue('E2', 'PROJECT COST');
        $sheet->setCellValue('A3', 'Ground Works');
        $sheet->setCellValue('B3', 100000);
        $sheet->setCellValue('C3', 100000);
        $sheet->setCellValue('D3', 200000);
        $sheet->setCellValue('E3', 400000);
        $sheet->setCellValue('A4', 'TOTAL');
        $sheet->setCellValue('E4', 400000);
        (new Xlsx($spreadsheet))->save($path);
    }

    private function novelLayout(array $overrides = []): array
    {
        return array_merge([
            'activityBased' => true,
            'mirrored' => false,
            'headerRow' => 1,
            'monthColumns' => [1 => ['B'], 2 => ['C'], 3 => ['D']],
            'descCol' => 'A',
            'contractCol' => null,
            'weightCol' => null,
            'codeCol' => null,
        ], $overrides);
    }

    // Derivation from AI-reported columns: valid layout on real bytes yields a
    // consultable v1 map (10-point curve math lives downstream, not here).
    // Called by: SCurveImportService::deriveMapFromAiLayout().
    public function test_derive_map_from_ai_layout(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNovelWorkbook($path);
        $service = app(SCurveImportService::class);

        // Rules AND relaxed both miss this file (novel words on both axes).
        try {
            $service->parse($path);
            $this->fail('Rules should miss the novel layout.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No S-curve table found', $e->getMessage());
        }
        $this->assertNull($service->deriveAutoColumnMap($path));

        $map = $service->deriveMapFromAiLayout($this->novelLayout(), $path);
        $this->assertIsArray($map);
        $this->assertSame(1, $map['v']);
        $this->assertSame(0, $map['descCol']);
        $this->assertCount(3, $map['monthSpans']);
        $this->assertNotEmpty($map['sig']);

        unlink($path);
    }

    // E1-PDF / PDF-1 — a PDF capture arrives PRE-READ, because the server owns no
    // PDF extractor: the browser's pdf.js pass is the only reader there is. The
    // file/document_id XOR therefore does not apply to it.
    // Called by: scurveCaptureHeaders('pdf', …) → collect().
    public function test_pdf_capture_stores_a_client_read_slice(): void
    {
        $admin = $this->makeUser('admin');
        $block = "COLUMNS: A,B,C,D | META: title block | HEADER: column labels\n"
            . "R1 [HEADER]: ITEM NO.,DESCRIPTION,SEGMENT 1,SEGMENT 2\n"
            . "R2 [DATA]: 1,Mobilization Works,50000,0";
        // The real client shape: one entry per table page, because these tables
        // are column-split (SEGMENT spreads 5 months over 2 pages).
        $map = ['v' => 1, 'kind' => 'pdf', 'pages' => [
            ['pageHint' => 0, 'sig' => ['item no.', 'description'],
                'descX' => 0.1, 'descRight' => 0.3,
                'monthCols' => [['index' => 1, 'x' => 0.4, 'xEnd' => 0.6], ['index' => 2, 'x' => 0.6, 'xEnd' => 0.9]]],
            ['pageHint' => 1, 'sig' => ['segment 3', 'segment 4'],
                'descX' => 0.0, 'descRight' => 0.2,
                'monthCols' => [['index' => 3, 'x' => 0.2, 'xEnd' => 0.5], ['index' => 4, 'x' => 0.5, 'xEnd' => 0.8]]],
        ]];

        $this->actingAs($admin)->post('/admin/scurve-header-proposals/collect', [
            'source_type' => 'pdf',
            'header_rows_text' => $block,
            'column_map' => json_encode($map),
        ])->assertStatus(201)->assertJsonPath('success', true);

        $row = ScurveHeaderProposal::where('source_type', 'pdf')->first();
        $this->assertNotNull($row);
        $this->assertSame('proposed', $row->status, 'a capture is never live on arrival');
        $this->assertSame('pdf', $row->column_map['kind']);
        $this->assertCount(2, $row->column_map['pages'], 'both table pages must survive the round trip');
        $this->assertCount(2, $row->column_map['pages'][1]['monthCols']);
        $this->assertStringContainsString('SEGMENT 1', $row->header_rows_text);

        // A repeat of the same layout counts rather than duplicating.
        $this->actingAs($admin)->post('/admin/scurve-header-proposals/collect', [
            'source_type' => 'pdf',
            'header_rows_text' => $block,
            'column_map' => json_encode($map),
        ])->assertStatus(201);
        $this->assertSame(1, ScurveHeaderProposal::where('source_type', 'pdf')->count());
        $this->assertSame(2, ScurveHeaderProposal::where('source_type', 'pdf')->first()->occurrences);
    }

    // Discipline on that branch: a capture with no slice teaches nothing, and an
    // Excel-shaped map must never be stored against a PDF row — every reader keys
    // off source_type, so a crossed map would be read by the wrong engine.
    // Called by: collect() PDF branch.
    public function test_pdf_capture_refuses_junk_and_foreign_maps(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->post('/admin/scurve-header-proposals/collect', [
            'source_type' => 'pdf',
            'header_rows_text' => 'not a slice at all',
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->actingAs($admin)->post('/admin/scurve-header-proposals/collect', [
            'source_type' => 'pdf',
        ])->assertStatus(422);

        $this->assertSame(0, ScurveHeaderProposal::count());

        // Valid slice, but the map is an Excel one — stored WITHOUT the map.
        $this->actingAs($admin)->post('/admin/scurve-header-proposals/collect', [
            'source_type' => 'pdf',
            'header_rows_text' => "COLUMNS: A,B\nR1 [HEADER]: DESCRIPTION,MONTH 1",
            'column_map' => json_encode(['v' => 1, 'descCol' => 2, 'monthSpans' => [1 => [3]]]),
        ])->assertStatus(201);

        $row = ScurveHeaderProposal::where('source_type', 'pdf')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->column_map, 'a non-PDF map must not ride on a PDF row');
    }

    // Excel captures are untouched by the PDF branch: still XOR-gated.
    // Called by: collect() Excel path.
    public function test_excel_capture_still_requires_a_source(): void
    {
        $this->actingAs($this->makeUser('admin'))
            ->post('/admin/scurve-header-proposals/collect', ['source_type' => 'excel'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Provide either an uploaded file or a document_id.');
    }

    // GAP 2 (2026-09-12) — an absent headerRow no longer kills derivation.
    // When our detector cannot find the table, serializeForAi tags every row
    // [DATA], the model sees no HEADER line and omits the key — exactly the files
    // that most need a learned layout. The row is now inferred from REAL cells at
    // the columns the model DID name.
    // Called by: deriveMapFromAiLayout() → inferHeaderRowKey().
    public function test_derive_map_infers_header_row_when_ai_reports_none(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNovelWorkbook($path);
        $service = app(SCurveImportService::class);

        $layout = $this->novelLayout();
        unset($layout['headerRow']);

        $map = $service->deriveMapFromAiLayout($layout, $path);

        $this->assertIsArray($map, 'a headerless answer must still teach columns');
        $this->assertSame(0, $map['descCol']);
        $this->assertCount(3, $map['monthSpans']);
        $this->assertNotEmpty($map['sig'], 'signature must come from the inferred label row');

        // Identical to what the model naming the row explicitly produces.
        $this->assertEquals($service->deriveMapFromAiLayout($this->novelLayout(), $path), $map);

        unlink($path);
    }

    // Inference reads bytes, it does not guess: with no row that looks like labels
    // at those columns (every month cell numeric), derivation still refuses.
    // Called by: inferHeaderRowKey() negative path.
    public function test_header_row_inference_refuses_when_no_row_looks_like_labels(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // Two data rows, no label line anywhere.
        $sheet->setCellValue('A2', 'Ground Works');
        $sheet->setCellValue('B2', 100000);
        $sheet->setCellValue('C2', 100000);
        $sheet->setCellValue('D2', 200000);
        $sheet->setCellValue('A3', 'Finishing Works');
        $sheet->setCellValue('B3', 50000);
        $sheet->setCellValue('C3', 50000);
        $sheet->setCellValue('D3', 100000);
        (new Xlsx($spreadsheet))->save($path);

        $layout = $this->novelLayout();
        unset($layout['headerRow']);

        $reason = null;
        $this->assertNull(
            app(SCurveImportService::class)->deriveMapFromAiLayout($layout, $path, $reason)
        );
        $this->assertSame('header-row-unfindable', $reason, 'the refusal must name its gate');

        unlink($path);
    }

    // Structural refusals stay: headerless, single-month, garbage (column trust
    // is proven per-answer, independent of month trust since the 2026-09-10 reversal).
    // Called by: deriveMapFromAiLayout() guard block.
    public function test_derive_map_refuses_untrusted_layouts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNovelWorkbook($path);
        $service = app(SCurveImportService::class);

        $this->assertNull($service->deriveMapFromAiLayout(
            $this->novelLayout(['headerRow' => 9]), $path
        ));
        $this->assertNull($service->deriveMapFromAiLayout(
            $this->novelLayout(['monthColumns' => [1 => ['B']]]), $path
        ));
        $this->assertNull($service->deriveMapFromAiLayout(
            $this->novelLayout(['descCol' => null]), $path
        ));

        unlink($path);
    }

    // Reversal (2026-09-10): mirrored and adopted answers TEACH columns whenever
    // structurally valid — column trust is independent of month trust.
    // Called by: deriveMapFromAiLayout() without the old G1/G2 guards.
    public function test_derive_map_learns_from_mirrored_and_adopted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNovelWorkbook($path);
        $service = app(SCurveImportService::class);

        $mirrored = $service->deriveMapFromAiLayout($this->novelLayout(['mirrored' => true]), $path);
        $this->assertIsArray($mirrored);
        $this->assertSame(1, $mirrored['v']);
        $this->assertCount(3, $mirrored['monthSpans']);

        $adopted = $service->deriveMapFromAiLayout($this->novelLayout(['activityBased' => false]), $path);
        $this->assertIsArray($adopted);
        $this->assertSame(0, $adopted['descCol']);

        unlink($path);
    }

    // Store: backfills a matching null-map row (keeping it proposed, occurrences
    // untouched) or creates a proposed ai-derived row.
    // Called by: ScurveHeaderProposal::storeAiDerived().
    public function test_store_ai_derived_backfills_or_creates(): void
    {
        $map = ['v' => 1, 'sig' => [[0, 'x'], [1, 'y']], 'descCol' => 0, 'monthSpans' => [1 => [1], 2 => [2]]];

        $existing = ScurveHeaderProposal::create([
            'header_rows_text' => 'R1 [HEADER]: LINE HEADINGS,Q1',
            'header_hash' => hash('sha256', 'novel'),
            'column_map' => null,
            'occurrences' => 5,
            'status' => 'live',
            'source' => 'auto-capture',
        ]);
        $row = ScurveHeaderProposal::storeAiDerived(null, hash('sha256', 'novel'), 'ignored text', $map);
        $this->assertSame($existing->id, $row->id);
        // Live + mapless demotes to proposed: unreviewed maps never ride live status.
        $this->assertSame('proposed', $row->fresh()->status);
        $this->assertSame('ai-derived', $row->fresh()->source);
        $this->assertSame($map, $row->fresh()->column_map);
        $this->assertSame(5, $row->fresh()->occurrences);

        $fresh = ScurveHeaderProposal::storeAiDerived(null, hash('sha256', 'other'), 'R1 [HEADER]: OTHER', $map);
        $this->assertSame('proposed', $fresh->status);
        $this->assertSame('ai-derived', $fresh->source);
        $this->assertSame(1, $fresh->occurrences);
    }

    // Absolute rule: ai-derived rows NEVER auto-live, however often re-captured.
    // Called by: collect() auto-promote guard.
    public function test_ai_derived_never_auto_lives(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNovelWorkbook($path);

        // Seed the ai-derived row with the REAL capture hash at 2 sightings, so
        // one more collect repeat hits the auto-promote threshold directly.
        $text = app(SCurveImportService::class)->serializeForAi($path, 15);
        $hash = hash('sha256', mb_strtolower(preg_replace('/\s+/', ' ', trim($text))));
        ScurveHeaderProposal::create([
            'header_rows_text' => mb_substr($text, 0, 4000),
            'header_hash' => $hash,
            'column_map' => ['v' => 1, 'sig' => [[0, 'x']], 'descCol' => 0, 'monthSpans' => [1 => [1], 2 => [2]]],
            'occurrences' => 2,
            'status' => 'proposed',
            'source' => 'ai-derived',
        ]);

        $staff = $this->makeUser('staff');
        $upload = new UploadedFile($path, 'novel.xlsx', null, null, true);
        $this->actingAs($staff)
            ->post('/staff/scurve-header-proposals/collect', ['file' => $upload])
            ->assertCreated();

        $row = ScurveHeaderProposal::where('source', 'ai-derived')->first();
        $this->assertSame(3, $row->occurrences);
        $this->assertSame('proposed', $row->status);

        unlink($path);
    }

    // End-to-end: rules-failed file + faked AI WITH columns → 200 plus a proposed
    // ai-derived map row carrying a consultable map.
    // Called by: POST /admin/s-curve/parse-gemini LearnMap step.
    public function test_parse_gemini_learns_map_from_ai_columns(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $aiText = json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 25],
                ['month_index' => 2, 'planned_progress_percent' => 50],
                ['month_index' => 3, 'planned_progress_percent' => 100],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 100000],
                ['month_index' => 2, 'pesos' => 100000],
                ['month_index' => 3, 'pesos' => 200000],
            ],
            'contractAmount' => 400000,
            'hasSummaryRow' => false,
            'headerRow' => 1,
            'monthColumns' => [
                ['month_index' => 1, 'columns' => ['B']],
                ['month_index' => 2, 'columns' => ['C']],
                ['month_index' => 3, 'columns' => ['D']],
            ],
            'descCol' => 'A',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*generativelanguage*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $aiText]]]],
                ],
            ], 200),
        ]);

        $admin = $this->makeUser('admin');
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNovelWorkbook($path);
        $upload = new UploadedFile($path, 'novel.xlsx', null, null, true);

        $this->actingAs($admin)->post('/admin/s-curve/parse-gemini', ['file' => $upload])
            ->assertOk()->assertJsonPath('success', true);

        $row = ScurveHeaderProposal::where('source', 'ai-derived')->first();
        $this->assertNotNull($row);
        $this->assertSame('proposed', $row->status);
        $this->assertIsArray($row->column_map);
        $this->assertSame(1, $row->column_map['v']);
        $this->assertCount(3, $row->column_map['monthSpans']);

        unlink($path);
    }

    // GAP 1 (2026-09-12) — the model keeps omitting per-month "activities" no
    // matter how the prompt asks (proven live: monthsWithActivities:0 on real
    // runs), so the SERVER reads the names from the sheet using the layout keys
    // the model DOES report. Names come from real cells; the curve is untouched.
    // Called by: parseWithGemini() → fillActivityNames() → namesFromAiLayout().
    public function test_parse_gemini_fills_activity_names_from_the_sheet(): void
    {
        config(['services.gemini.key' => 'test-key']);
        // Deliberately NO "activities" anywhere — the exact shape the live model
        // returns for Excel.
        $aiText = json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 25],
                ['month_index' => 2, 'planned_progress_percent' => 50],
                ['month_index' => 3, 'planned_progress_percent' => 100],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 100000],
                ['month_index' => 2, 'pesos' => 100000],
                ['month_index' => 3, 'pesos' => 200000],
            ],
            'contractAmount' => 400000,
            'hasSummaryRow' => false,
            'headerRow' => 1,
            'monthColumns' => [
                ['month_index' => 1, 'columns' => ['B']],
                ['month_index' => 2, 'columns' => ['C']],
                ['month_index' => 3, 'columns' => ['D']],
            ],
            'descCol' => 'A',
            'contractCol' => 'E',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*generativelanguage*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $aiText]]]],
                ],
            ], 200),
        ]);

        $admin = $this->makeUser('admin');
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeCostedNovelWorkbook($path);
        $upload = new UploadedFile($path, 'novel.xlsx', null, null, true);

        $response = $this->actingAs($admin)
            ->post('/admin/s-curve/parse-gemini', ['file' => $upload])
            ->assertOk()
            ->assertJsonPath('success', true);

        $months = $response->json('months');
        $this->assertNotEmpty($months);
        // 'Ground Works' is the only activity row in the novel workbook, and it
        // carries a value in every month.
        foreach ($months as $month) {
            $this->assertNotEmpty(
                $month['activities'] ?? [],
                "Month {$month['month_index']} should have been named from the sheet"
            );
            $this->assertContains('Ground Works', $month['activities']);
        }
        // The AI's curve is untouched by naming.
        $this->assertSame(25.0, (float) $months[0]['planned_progress_percent']);
        $this->assertSame(100.0, (float) $months[2]['planned_progress_percent']);

        unlink($path);
    }

    // Derived math (user decision 2026-09-12) — when the AI's numbers disagree with
    // the file's own activity rows, the SHEET wins. Live precedent: the 10-month
    // file came back 40/60/70 for months 5-7 where the rows say 50/70/80, and the
    // AI's "Checked" verdict still passed. The AI's verdicts are dropped with its
    // arithmetic; the recompute is announced, never silent.
    // Called by: parseWithGemini() → applyDerivedLayout().
    public function test_parse_gemini_prefers_sheet_math_when_ai_numbers_diverge(): void
    {
        config(['services.gemini.key' => 'test-key']);
        // Wrong curve (true math on this workbook is 25/50/100), right layout.
        $aiText = json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 10],
                ['month_index' => 2, 'planned_progress_percent' => 20],
                ['month_index' => 3, 'planned_progress_percent' => 100],
            ],
            'hasSummaryRow' => false,
            'headerRow' => 1,
            'monthColumns' => [
                ['month_index' => 1, 'columns' => ['B']],
                ['month_index' => 2, 'columns' => ['C']],
                ['month_index' => 3, 'columns' => ['D']],
            ],
            'descCol' => 'A',
            // The denominator the gate requires before sheet math may win.
            'contractCol' => 'E',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*generativelanguage*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $aiText]]]]],
            ], 200),
        ]);

        $admin = $this->makeUser('admin');
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeCostedNovelWorkbook($path);
        $upload = new UploadedFile($path, 'novel.xlsx', null, null, true);

        $response = $this->actingAs($admin)
            ->post('/admin/s-curve/parse-gemini', ['file' => $upload])
            ->assertOk()
            ->assertJsonPath('success', true);

        $months = $response->json('months');
        $this->assertSame(25.0, (float) $months[0]['planned_progress_percent'], 'sheet math must win');
        $this->assertSame(50.0, (float) $months[1]['planned_progress_percent']);
        $this->assertSame(100.0, (float) $months[2]['planned_progress_percent']);

        $warnings = $response->json('meta.warnings');
        $this->assertTrue(
            collect($warnings)->contains(fn ($w) => str_contains($w, 'recomputed from the sheet')),
            'the recompute must be announced'
        );
        $this->assertFalse(
            collect($warnings)->contains(fn ($w) => str_contains($w, 'learned layout')),
            'no approved layout was used, so that note must not appear'
        );

        // Learning still happens: the derived map is banked for approval.
        $this->assertNotNull(ScurveHeaderProposal::where('source', 'ai-derived')->first());

        unlink($path);
    }

    // Salvage (2026-09-12) — an answer whose NUMBERS the fabrication guards refuse
    // used to end the import with "could not read", thrown away before any map
    // work even though its COLUMNS were fine. Now the sheet is read instead, and
    // the refused figures never reach the preview.
    // Called by: parseWithGemini() → salvageRejectedAnswer().
    public function test_parse_gemini_salvages_rejected_numbers_with_sheet_math(): void
    {
        config(['services.gemini.key' => 'test-key']);
        // Internally consistent, but 450000/550000 are printed in no cell of the
        // workbook — the presence audit refuses the numbers. The layout is right.
        $aiText = json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 45],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 450000],
                ['month_index' => 2, 'pesos' => 550000],
            ],
            'contractAmount' => 1000000,
            'hasSummaryRow' => false,
            'headerRow' => 1,
            'monthColumns' => [
                ['month_index' => 1, 'columns' => ['B']],
                ['month_index' => 2, 'columns' => ['C']],
                ['month_index' => 3, 'columns' => ['D']],
            ],
            'descCol' => 'A',
            'contractCol' => 'E',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*generativelanguage*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $aiText]]]]],
            ], 200),
        ]);

        $admin = $this->makeUser('admin');
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeCostedNovelWorkbook($path);
        $upload = new UploadedFile($path, 'novel.xlsx', null, null, true);

        $response = $this->actingAs($admin)
            ->post('/admin/s-curve/parse-gemini', ['file' => $upload])
            ->assertOk()
            ->assertJsonPath('success', true);

        // The sheet's own math, not the refused 45/100.
        $months = $response->json('months');
        $this->assertSame(25.0, (float) $months[0]['planned_progress_percent']);
        $this->assertSame(50.0, (float) $months[1]['planned_progress_percent']);
        $this->assertSame(100.0, (float) $months[2]['planned_progress_percent']);
        $this->assertContains('Ground Works', $months[0]['activities']);

        $warnings = $response->json('meta.warnings');
        $this->assertTrue(
            collect($warnings)->contains(fn ($w) => str_contains($w, 'read from the sheet itself')),
            'the substitution must be announced'
        );

        // The columns earned trust even though the arithmetic did not.
        $this->assertNotNull(ScurveHeaderProposal::where('source', 'ai-derived')->first());

        unlink($path);
    }

    // With no usable layout there is nothing to salvage, so the original refusal
    // stands — the salvage must never invent a baseline out of thin air.
    // Called by: salvageRejectedAnswer() null path.
    public function test_parse_gemini_still_refuses_when_nothing_can_be_salvaged(): void
    {
        config(['services.gemini.key' => 'test-key']);
        // Refused numbers AND no layout keys at all.
        $aiText = json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 45],
                ['month_index' => 2, 'planned_progress_percent' => 100],
            ],
            'monthlyAmounts' => [
                ['month_index' => 1, 'pesos' => 450000],
                ['month_index' => 2, 'pesos' => 550000],
            ],
            'contractAmount' => 1000000,
            'hasSummaryRow' => false,
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*generativelanguage*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $aiText]]]]],
            ], 200),
        ]);

        $admin = $this->makeUser('admin');
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeCostedNovelWorkbook($path);
        $upload = new UploadedFile($path, 'novel.xlsx', null, null, true);

        $this->actingAs($admin)
            ->post('/admin/s-curve/parse-gemini', ['file' => $upload])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        unlink($path);
    }

    // Learned maps consult: approving the ai-derived row lets the novel file parse.
    // Called by: parse() pass 2 via live ai-derived maps.
    public function test_learned_map_consults_after_approval(): void
    {
        $staff = $this->makeUser('staff');
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNovelWorkbook($path);
        $service = app(SCurveImportService::class);

        $map = $service->deriveMapFromAiLayout($this->novelLayout(), $path);
        $this->assertIsArray($map);
        $row = ScurveHeaderProposal::create([
            'header_rows_text' => 'novel',
            'header_hash' => hash('sha256', 'novel-consult'),
            'column_map' => $map,
            'status' => 'proposed',
            'source' => 'ai-derived',
        ]);
        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/approve")
            ->assertOk();

        $liveMaps = ScurveHeaderProposal::live()->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id,
            'contractor_id' => $r->contractor_id,
            'column_map' => $r->column_map,
        ])->all();
        $result = $service->parse($path, $liveMaps);

        $this->assertCount(3, $result['months']);
        $this->assertTrue($result['meta']['mapUsed']);
        $this->assertSame($row->id, $result['meta']['mapId']);

        unlink($path);
    }

    // Refusal reasons (2026-09-10, revised with the reversal): every remaining
    // derivation refusal names its gate so live "learn skipped" lines diagnose
    // themselves. Mirror/adopted cases moved to learns-tests (columns teach
    // regardless of month trust).
    // Called by: deriveMapFromAiLayout() $reason out-param.
    public function test_derive_map_refusal_reasons(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNovelWorkbook($path);
        $service = app(SCurveImportService::class);

        $cases = [
            [$this->novelLayout(['headerRow' => 9]), 'header-row-unresolved'],
            [$this->novelLayout(['monthColumns' => [1 => ['B']]]), 'no-month-columns'],
            [$this->novelLayout(['descCol' => null]), 'no-desc-col'],
            // E3/F3 hold no numbers: structurally valid, proof fails.
            [$this->novelLayout(['monthColumns' => [1 => ['E'], 2 => ['F']]]), 'unproven-spans'],
        ];
        foreach ($cases as [$layout, $expected]) {
            $reason = null;
            $this->assertNull(
                $service->deriveMapFromAiLayout($layout, $path, $reason),
                "Expected refusal for {$expected}"
            );
            $this->assertSame($expected, $reason);
        }

        unlink($path);
    }

    // Reversal end-to-end: a MIRRORED AI answer still teaches a proposed map.
    // Called by: POST /admin/s-curve/parse-gemini LearnMap step.
    public function test_parse_gemini_learns_map_from_mirrored_answer(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $aiText = json_encode([
            'months' => [
                ['month_index' => 1, 'planned_progress_percent' => 25],
                ['month_index' => 2, 'planned_progress_percent' => 50],
                ['month_index' => 3, 'planned_progress_percent' => 100],
            ],
            'summary' => [
                ['month_index' => 1, 'percent' => 25],
                ['month_index' => 2, 'percent' => 50],
                ['month_index' => 3, 'percent' => 100],
            ],
            'hasSummaryRow' => true,
            'headerRow' => 1,
            'monthColumns' => [
                ['month_index' => 1, 'columns' => ['B']],
                ['month_index' => 2, 'columns' => ['C']],
                ['month_index' => 3, 'columns' => ['D']],
            ],
            'descCol' => 'A',
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*generativelanguage*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $aiText]]]],
                ],
            ], 200),
        ]);

        $admin = $this->makeUser('admin');
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNovelWorkbook($path);
        $upload = new UploadedFile($path, 'novel.xlsx', null, null, true);

        // Months mirror the summary (mirror note fires) AND the row still learns.
        $response = $this->actingAs($admin)->post('/admin/s-curve/parse-gemini', ['file' => $upload]);
        $response->assertOk()->assertJsonPath('success', true);
        $row = ScurveHeaderProposal::where('source', 'ai-derived')->first();
        $this->assertNotNull($row);
        $this->assertSame('proposed', $row->status);
        $this->assertCount(3, $row->column_map['monthSpans']);

        unlink($path);
    }
}
