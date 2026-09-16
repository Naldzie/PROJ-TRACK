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
    //
    // The near-miss workbook is used deliberately: it DERIVES a map, so this pins
    // the >=3 rule on a row that promotion can actually make readable. It used to
    // use makeWorkbook, whose map derives null — so it was pinning a live row the
    // reader has nothing to read with, which is the defect the unreadable guard
    // fixes (2026-09-14). A map-less row's behaviour is pinned separately by
    // test_a_map_less_capture_never_auto_lives.
    // Called by: repeated failures on the same unknown layout.
    public function test_repeats_increment_and_third_promotes_to_live(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNearMissWorkbook($path);

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

    // 2026-09-14 — REPETITION CANNOT DERIVE A LAYOUT EITHER.
    //
    // The Excel twin of the unnumbered-PDF rule, found on the SEGMENT specimen.
    // When the rules cannot find the header — GAP 2, every row tags [DATA], which
    // is precisely the layout most in need of teaching — the capture stores
    // column_map null. Promoting that at three occurrences produced a row badged
    // live that the reader has nothing to read with, so the user approved nothing
    // and the next import failed anyway.
    // Called by: collect() auto-live rule.
    public function test_a_map_less_capture_never_auto_lives(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        // Deliberately underivable: a novel description header and ONE month column.
        $this->makeWorkbook($path);

        $staff = $this->makeUser('staff');
        for ($i = 0; $i < 4; $i++) {
            $upload = new UploadedFile($path, 'weird.xlsx', null, null, true);
            $this->actingAs($staff)
                ->post('/staff/scurve-header-proposals/collect', ['file' => $upload])
                ->assertCreated();
        }

        $row = ScurveHeaderProposal::first();
        $this->assertNull($row->column_map, 'the fixture must stay underivable for this to mean anything');
        $this->assertSame(4, $row->occurrences, 'repeats still count');
        $this->assertSame('proposed', $row->status,
            'a capture with no map must stay proposed however often it is seen');

        unlink($path);
    }

    // 2026-09-14 — AN EXCEL TEACH REFUSAL NAMES THE CLASH.
    //
    // buildTaughtMap returned bare null from eight different gates and the endpoint
    // printed one catch-all sentence, so a reviewer who put a column in two places
    // was told only that the columns "do not form a usable layout" and had to find
    // it by eye. Verified live on the SEGMENT specimen: the user had assigned the
    // weight column to month 1 as well.
    // Called by: teachColumns() Excel branch -> explainTaughtMapRefusal().
    public function test_an_excel_teach_refusal_names_the_clashing_column(): void
    {
        $staff = $this->makeUser('staff');
        $row = ScurveHeaderProposal::create([
            'contractor_id' => null, 'source_type' => 'excel',
            'header_rows_text' => "COLUMNS: A,B,C,D,E | HEADER: column labels\n"
                . "R1 [HEADER]: ITEM,DESCRIPTION,% WEIGHT,SEGMENT 1,SEGMENT 2\n"
                . "R2 [DATA]: 1,Mobilization,5,50000,",
            'header_hash' => hash('sha256', 'excel-refusal-fixture'),
            'column_map' => null, 'occurrences' => 1,
            'status' => 'proposed', 'source' => 'auto-capture',
        ]);
        $base = ['desc' => 'B', 'weight' => 'C', 'header_rows' => [1], 'mode' => 'variant'];

        // C is the weight column AND part of month 1 — the exact live mis-click.
        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $base + ['months' => [1 => ['C', 'D'], 2 => ['E']]])
            ->assertStatus(422)
            ->assertJsonPath('message',
                'Column C is both the weight column and part of month 1 — give it to one or the other.');

        // One column claimed by two months names the column, not the rule.
        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $base + ['months' => [1 => ['D'], 2 => ['D']]])
            ->assertStatus(422)
            ->assertJsonPath('message',
                'Column D is assigned to two different months — each column belongs to one month.');

        // Right-to-left months are named as such.
        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $base + ['months' => [1 => ['E'], 2 => ['D']]])
            ->assertStatus(422)
            ->assertJsonPath('message',
                'The months do not run left to right across the grid — check their order.');

        // And a clean teaching of the same row still saves, so the gates above are
        // refusing the mistake rather than the layout.
        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $base + ['months' => [1 => ['D'], 2 => ['E']]])
            ->assertOk()->assertJsonPath('success', true);
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
        // The map is real because this test is about WHO may approve, not about
        // what happens to an unreadable row — that is pinned separately by
        // test_approving_an_unreadable_capture_is_refused (2026-09-14).
        $row = ScurveHeaderProposal::create([
            'header_rows_text' => 'WEIRD DESC HEADER', 'header_hash' => hash('sha256', 'a'),
            'status' => 'proposed',
            'column_map' => ['v' => 1, 'descCol' => 2, 'monthSpans' => [1 => [7], 2 => [8]], 'sig' => [[2, 'description']]],
        ]);

        $this->actingAs($staff)->patchJson("/staff/scurve-header-proposals/{$row->id}/approve")
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame('live', $row->fresh()->status);
    }

    // 2026-09-14 — APPROVING SOMETHING UNREADABLE IS A NO-OP, SO IT IS REFUSED.
    //
    // Two captures reach the desk with nothing the reader can use: an Excel one
    // whose header the rules could not find (column_map null), and an ambiguous PDF
    // one whose months carry no numbering. Both used to flip to live on a click and
    // change nothing — the next import still answered "could not read the S-curve",
    // so the reviewer was told the opposite of what actually happened.
    // Called by: approve().
    public function test_approving_an_unreadable_capture_is_refused(): void
    {
        $staff = $this->makeUser('staff');

        $mapLess = ScurveHeaderProposal::create([
            'header_rows_text' => 'COLUMNS: A,B | HEADER: column labels',
            'header_hash' => hash('sha256', 'map-less'), 'status' => 'proposed',
            'column_map' => null,
        ]);
        $this->actingAs($staff)->patchJson("/staff/scurve-header-proposals/{$mapLess->id}/approve")
            ->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame('proposed', $mapLess->fresh()->status, 'refused means NOT promoted');

        $unnumbered = ScurveHeaderProposal::create([
            'source_type' => 'pdf',
            'header_rows_text' => 'COLUMNS: A,B | HEADER: column labels',
            'header_hash' => hash('sha256', 'unnumbered'), 'status' => 'proposed',
            'column_map' => ['v' => 1, 'kind' => 'pdf', 'pages' => [
                ['monthCols' => [], 'unnumbered' => true,
                    'pseudoCols' => [['letter' => 'A', 'x' => 0.0, 'xEnd' => 0.5]]],
            ]],
        ]);
        $this->actingAs($staff)->patchJson("/staff/scurve-header-proposals/{$unnumbered->id}/approve")
            ->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame('proposed', $unnumbered->fresh()->status);

        // Once taught, the SAME row approves — the guard refuses the state, not the row.
        $unnumbered->update(['column_map' => ['v' => 1, 'kind' => 'pdf', 'pages' => [
            ['monthCols' => [['index' => 1, 'x' => 0.5, 'xEnd' => 0.7], ['index' => 2, 'x' => 0.7, 'xEnd' => 0.9]]],
        ]]]);
        $this->actingAs($staff)->patchJson("/staff/scurve-header-proposals/{$unnumbered->id}/approve")
            ->assertOk();
        $this->assertSame('live', $unnumbered->fresh()->status);
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

    // Row-role teaching (2026-09-14) needs rows worth pointing at: a summary row
    // with a novel wording the skip vocabulary misses ("Cash Position"), a printed
    // curve with a novel wording isCumulativeRow misses ("RUNNING TOTAL %"), and a
    // numbers-only row that cannot be recognised again in the live file.
    private function makeRoleTeachableRow(array $overrides = []): ScurveHeaderProposal
    {
        $text = "COLUMNS: A,B,C,D,E,F,G,H,I,J,K,L,M,N | META: title | HEADER: labels | DATA: rows | SUMMARY: totals | CUMULATIVE: the printed running curve\n"
            . "R1 [HEADER]: ITEM,,TASK DESCRIPTION,,,CONTRACT AMOUNT,% WEIGHT,,Month 1,,Month 2,,Month 3\n"
            . "R2 [DATA]: ,1,Mobilization,,,100000,25,,50000,,,,,50000\n"
            . "R3 [DATA]: ,2,Cash Position,,,,,,120000,,,,,90000\n"
            . "R4 [DATA]: ,3,RUNNING TOTAL %,,,,,,25,,,,,100\n"
            . 'R5 [DATA]: ,,,,,,,,1,,2,,3';

        return $this->makeTeachableRow(array_merge(['header_rows_text' => $text], $overrides));
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

    // 2026-09-16 (D2): a layout longer than twelve months can be taught (the
    // endpoint and the map builder both stopped at 12), and the new ceiling holds.
    // Called by: PATCH .../columns -> teachColumns() -> buildTaughtMap().
    public function test_teach_accepts_more_than_twelve_months_up_to_the_ceiling(): void
    {
        $staff = $this->makeUser('staff');
        $letters = [];
        for ($i = 0; $i < 16; $i++) {
            $letters[] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
        }
        $monthLabels = [];
        $months = [];
        for ($m = 1; $m <= 14; $m++) {
            $monthLabels[] = "Month {$m}";
            $months[(string) $m] = [$letters[$m + 1]];   // C..P
        }
        $text = 'COLUMNS: ' . implode(',', $letters) . " | META: title | HEADER: labels | DATA: rows | SUMMARY: totals\n"
            . 'R1 [HEADER]: DESCRIPTION,CONTRACT,' . implode(',', $monthLabels) . "\n"
            . 'R2 [DATA]: Works,140000,' . implode(',', array_fill(0, 14, 10000));
        $row = $this->makeTeachableRow(['header_rows_text' => $text]);

        $this->actingAs($staff)->patchJson("/staff/scurve-header-proposals/{$row->id}/columns", [
            'desc' => 'A', 'contract' => 'B', 'months' => $months, 'header_rows' => [1], 'mode' => 'variant',
        ])->assertOk();
        $variant = ScurveHeaderProposal::where('source', 'manual-teach')->first();
        $this->assertCount(14, $variant->column_map['monthSpans']);

        // Past the ceiling: refused by the map builder, with nothing stored.
        $row2 = $this->makeTeachableRow(['header_rows_text' => $text]);
        $this->actingAs($staff)->patchJson("/staff/scurve-header-proposals/{$row2->id}/columns", [
            'desc' => 'A', 'contract' => 'B',
            'months' => ['1' => ['C'], (string) (\App\Services\SCurveImportService::MAX_MONTHS + 1) => ['D']],
            'header_rows' => [1], 'mode' => 'variant',
        ])->assertUnprocessable();
        $this->assertSame(1, ScurveHeaderProposal::where('source', 'manual-teach')->count());
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

    // =====================================================================
    // Row-role teaching (2026-09-14): "not an activity" + "the cumulative row".
    // Stored as TEXT signatures, never R-numbers — see rowSignature's docblock.
    // =====================================================================

    // The whole point: a summary row whose wording the hardcoded vocabulary misses
    // ("Cash Position") can be named by a reviewer, and the pick survives into the
    // stored map as text.
    public function test_taught_exclusions_are_stored_as_text_signatures(): void
    {
        $row = $this->makeRoleTeachableRow();

        $this->actingAs($this->makeUser('staff'))
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->teachPayload(['excluded_rows' => [3]]))
            ->assertOk()->assertJsonPath('success', true);

        $map = ScurveHeaderProposal::orderByDesc('id')->first()->column_map;
        $this->assertSame(['cash position'], $map['excludeSigs'] ?? null);
        // R-numbers must NOT be stored: they are kept-ordinals over the captured
        // slice and shift the moment a contractor inserts a row.
        $this->assertStringNotContainsString('3', json_encode($map['excludeSigs']));
        $this->assertArrayNotHasKey('cumulativeSig', $map);
    }

    // An untaught save must produce exactly the map it produced before row roles
    // existed. This is what lets the feature ship without re-teaching every layout.
    public function test_untaught_rows_leave_the_map_shape_unchanged(): void
    {
        $row = $this->makeRoleTeachableRow();

        $this->actingAs($this->makeUser('staff'))
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns", $this->teachPayload())
            ->assertOk();

        $map = ScurveHeaderProposal::orderByDesc('id')->first()->column_map;
        $this->assertArrayNotHasKey('excludeSigs', $map, 'an untaught map must carry no row-role keys at all');
        $this->assertArrayNotHasKey('cumulativeSig', $map);
    }

    // Header-row memory (display-only): the R-lines the reviewer picked are
    // stored on the taught map so the modal can tick them back on reopen.
    public function test_taught_header_rows_are_remembered(): void
    {
        $row = $this->makeRoleTeachableRow();

        $this->actingAs($this->makeUser('staff'))
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns", $this->teachPayload(['header_rows' => [1]]))
            ->assertOk();

        $map = ScurveHeaderProposal::orderByDesc('id')->first()->column_map;
        $this->assertSame([1], $map['headerRows'] ?? null, 'the picked header R must be remembered for reopen');
    }

    // Marking a row cumulative IMPLIES excluding it. One click, not two: the skip
    // vocabulary already lumps cumulat\w* in with cost and cash flow, so a
    // cumulative row that still summed would contradict the rule beside it.
    public function test_marking_a_row_cumulative_also_excludes_it(): void
    {
        $row = $this->makeRoleTeachableRow();

        $this->actingAs($this->makeUser('staff'))
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->teachPayload(['cumulative_row' => 4]))
            ->assertOk();

        $map = ScurveHeaderProposal::orderByDesc('id')->first()->column_map;
        $this->assertSame('running total %', $map['cumulativeSig'] ?? null);
        $this->assertContains('running total %', $map['excludeSigs'] ?? [], 'the curve row is never summed as work');
    }

    // A row of nothing but numbers cannot be recognised again in the live file, and
    // an empty signature would match every blank row there is. Refused loudly.
    public function test_a_row_with_no_label_text_is_refused(): void
    {
        $row = $this->makeRoleTeachableRow();

        $this->actingAs($this->makeUser('staff'))
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->teachPayload(['excluded_rows' => [5]]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1, ScurveHeaderProposal::count(), 'a refusal must write nothing');
    }

    // The header row is not summed as an activity in the first place, so marking it
    // means the reviewer has misread the control.
    public function test_a_header_row_cannot_also_be_a_role_row(): void
    {
        $row = $this->makeRoleTeachableRow();

        $this->actingAs($this->makeUser('staff'))
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->teachPayload(['excluded_rows' => [1]]))
            ->assertStatus(422);
    }

    // PDF rows are refused EXPLICITLY rather than having the fields dropped. A save
    // that reports success and changes nothing is the exact half-wired failure that
    // cost four acceptance rounds; the PDF reader finds its curve by shape and has
    // nothing to learn here.
    public function test_row_roles_are_refused_on_a_pdf_row(): void
    {
        $row = $this->makeRoleTeachableRow(['source_type' => 'pdf']);

        $this->actingAs($this->makeUser('staff'))
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->teachPayload(['cumulative_row' => 4]))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            // Asserting the MESSAGE, not just the status: a map-less PDF row already
            // 422s inside buildTaughtPdfMap, so a status-only assertion would pass
            // whether or not this guard exists at all.
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'Excel layouts only'));
    }

    // Two rows that normalize to the same text cannot be told apart at read time,
    // so excluding one would silently exclude both.
    public function test_two_rows_reading_the_same_are_refused(): void
    {
        $row = $this->makeRoleTeachableRow([
            'header_rows_text' => "COLUMNS: A,B,C,D,E,F,G,H,I,J,K,L,M,N | HEADER: labels\n"
                . "R1 [HEADER]: ITEM,,TASK DESCRIPTION,,,CONTRACT AMOUNT,% WEIGHT,,Month 1,,Month 2,,Month 3\n"
                . "R2 [DATA]: ,1,Mobilization,,,100000,25,,50000,,,,,50000\n"
                . "R3 [SUMMARY]: ,,Cash Position,,,,,,120000\n"
                . 'R4 [SUMMARY]: ,,CASH   position,,,,,,90000',
        ]);

        $this->actingAs($this->makeUser('staff'))
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->teachPayload(['excluded_rows' => [3, 4]]))
            ->assertStatus(422);
    }

    // 2026-09-14 — CLOSING A COVERAGE GAP BEFORE BUILDING ON IT.
    //
    // Every existing test of the activity-loop skip logic and of
    // readSummaryReference goes through the RULE path (parse($path) with no maps).
    // NOTHING exercised either through a LIVE MAP — which is precisely the path the
    // row-role binding work will touch, and the map tests that do exist run on
    // workbooks with no summary block at all. So the two behaviours that binding
    // must not disturb were, until now, pinned by nothing on the path it changes.
    //
    // This fixture deliberately misses the rules (novel "TASK DESCRIPTION" header)
    // AND carries the full summary vocabulary, so the map path has something to
    // skip and something to compare.
    private function makeMapPathSummaryWorkbook(string $path): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'ITEM');
        $sheet->setCellValue('C10', 'TASK DESCRIPTION');   // novel: rules miss
        $sheet->setCellValue('F10', 'CONTRACT AMOUNT');
        $sheet->setCellValue('G10', '% WEIGHT');
        $sheet->setCellValue('I10', 'Month 1');
        $sheet->setCellValue('K10', 'Month 2');

        $sheet->setCellValue('B12', '1');
        $sheet->setCellValue('C12', 'Mobilization Works');
        $sheet->setCellValue('F12', 100000);
        $sheet->setCellValue('G12', 25);
        $sheet->setCellValue('I12', 100000);

        $sheet->setCellValue('B13', '2');
        $sheet->setCellValue('C13', 'Structural Works');
        $sheet->setCellValue('F13', 300000);
        $sheet->setCellValue('G13', 75);
        $sheet->setCellValue('K13', 300000);

        $sheet->setCellValue('C14', 'TOTAL');
        $sheet->setCellValue('F14', 400000);
        $sheet->setCellValue('G14', 100);
        // The rows the skip vocabulary exists for. Each carries numbers under the
        // month spans, so a failure to skip shows up as an inflated denominator.
        $sheet->setCellValue('C15', 'CUMULATIVE % ACHIEVED');
        $sheet->setCellValue('I15', 20);
        $sheet->setCellValue('K15', 100);
        $sheet->setCellValue('C16', 'Cash Flow');
        $sheet->setCellValue('I16', 100000);
        $sheet->setCellValue('K16', 400000);
        $sheet->setCellValue('C17', 'Project Duration');
        $sheet->setCellValue('I17', 30);
        $sheet->setCellValue('K17', 60);

        (new Xlsx($spreadsheet))->save($path);
    }

    // The skip vocabulary must hold on the MAP path exactly as on the rule path.
    // If a Cash Flow or duration row were summed as an activity the contract
    // denominator would inflate and EVERY month would read low — silently, because
    // the curve would still be monotonic and still end at 100.
    // Called by: parse() -> buildMonths() activity loop, reached via findHeaderViaMaps.
    public function test_map_path_skips_summary_rows_like_the_rule_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeMapPathSummaryWorkbook($path);
        $service = app(SCurveImportService::class);

        try {
            $service->parse($path);
            $this->fail('Rules should miss this layout, or the test proves nothing about maps.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No S-curve table found', $e->getMessage());
        }

        $map = $service->deriveAutoColumnMap($path);
        $this->assertIsArray($map, 'the auto-derived map is the vehicle for this path');

        $result = $service->parse($path, [['id' => 3, 'contractor_id' => null, 'column_map' => $map]]);

        $this->assertTrue($result['meta']['mapUsed'], 'this must be the MAP path, not the rules');
        // Two activities — the TOTAL, cumulative, cash-flow and duration rows are
        // all skipped. Three would mean the vocabulary failed on this path.
        $this->assertSame(2, $result['meta']['activityCount']);
        // The denominator is the TOTAL row's 400,000, not 400,000 plus the
        // cash-flow row's half-million.
        $this->assertSame(400000.0, $result['meta']['contractAmount']);
        $this->assertSame(25.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][1]['planned_progress_percent']);

        unlink($path);
    }

    // readSummaryReference must also work through a live map: the author's printed
    // cumulative row is found, compared against the activity math, and reported.
    // The binding work will pin WHICH row this reads, so its current behaviour has
    // to be nailed down first.
    // Called by: parse() -> buildMonths() -> readSummaryReference, via a live map.
    public function test_map_path_still_reads_the_summary_reference(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeMapPathSummaryWorkbook($path);
        $service = app(SCurveImportService::class);

        $map = $service->deriveAutoColumnMap($path);
        $result = $service->parse($path, [['id' => 4, 'contractor_id' => null, 'column_map' => $map]]);

        $this->assertTrue($result['meta']['mapUsed']);
        $ref = $result['meta']['summaryReference'] ?? [];
        $this->assertNotEmpty($ref, 'the cumulative row must be found on the map path too');

        // The author says 20 / 100; the activity rows say 25 / 100. The gap on
        // month 1 is 5 points, so it is reported AND warned about, while month 2
        // agrees exactly and stays silent.
        $byMonth = [];
        foreach ($ref as $e) { $byMonth[$e['month']] = $e; }
        $this->assertArrayHasKey(1, $byMonth);
        $this->assertSame(20.0, (float) $byMonth[1]['summary_percent']);
        $this->assertSame(25.0, (float) $byMonth[1]['activity_percent']);
        $this->assertEqualsWithDelta(5.0, (float) $byMonth[1]['gap'], 0.01);
        // The curve itself is untouched by the reference — activity math wins.
        $this->assertSame(25.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame('activities', $result['meta']['curveSource']);

        unlink($path);
    }

    // =====================================================================
    // Row roles reaching the READ (2026-09-14, step 6). The fixture above is
    // reused on purpose: its Cash Flow / Project Duration rows are already
    // caught by the hardcoded vocabulary, so a test that a TAUGHT exclusion
    // works needs a row the vocabulary MISSES. makeNovelSummaryWorkbook is
    // that file — "Site Establishment Fund" looks exactly like an activity.
    // =====================================================================

    private function makeNovelSummaryWorkbook(string $path): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'ITEM');
        $sheet->setCellValue('C10', 'TASK DESCRIPTION');
        $sheet->setCellValue('F10', 'CONTRACT AMOUNT');
        $sheet->setCellValue('G10', '% WEIGHT');
        $sheet->setCellValue('I10', 'Month 1');
        $sheet->setCellValue('K10', 'Month 2');

        $sheet->setCellValue('B12', '1');
        $sheet->setCellValue('C12', 'Mobilization Works');
        $sheet->setCellValue('F12', 100000);
        $sheet->setCellValue('G12', 25);
        $sheet->setCellValue('I12', 100000);

        $sheet->setCellValue('B13', '2');
        $sheet->setCellValue('C13', 'Structural Works');
        $sheet->setCellValue('F13', 300000);
        $sheet->setCellValue('G13', 75);
        $sheet->setCellValue('K13', 300000);

        // The trap: no summary vocabulary anywhere in the label, a weight AND a
        // contract amount, so every existing guard treats it as work.
        $sheet->setCellValue('C14', 'Site Establishment Fund');
        $sheet->setCellValue('F14', 200000);
        $sheet->setCellValue('G14', 50);
        $sheet->setCellValue('I14', 200000);

        $sheet->setCellValue('C15', 'TOTAL');
        $sheet->setCellValue('F15', 400000);

        // A printed curve whose wording isCumulativeRow does NOT match.
        $sheet->setCellValue('C16', 'RUNNING TOTAL %');
        $sheet->setCellValue('I16', 25);
        $sheet->setCellValue('K16', 100);

        (new Xlsx($spreadsheet))->save($path);
    }

    private function mapWithRoles(string $path, array $extra): array
    {
        $map = app(SCurveImportService::class)->deriveAutoColumnMap($path);
        $this->assertIsArray($map, 'the fixture must be derivable or the test proves nothing');

        return array_merge($map, $extra);
    }

    // THE HEADLINE CASE. Untaught, "Site Establishment Fund" is summed as work and
    // the curve is wrong in a way nothing reports: monotonic, ends at 100, and
    // every month low. Taught, it is excluded and the curve is right.
    public function test_a_taught_exclusion_stops_a_row_being_summed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNovelSummaryWorkbook($path);
        $service = app(SCurveImportService::class);

        // Untaught: FOUR activities — the trap row, and also "RUNNING TOTAL %",
        // which the vocabulary misses too and which has month values and a
        // description, so the residue rule counts it. Contract inflates to 600,000
        // and month 1 reads 50%. Nothing warns.
        $plain = $service->parse($path, [['id' => 1, 'contractor_id' => null, 'column_map' => $this->mapWithRoles($path, [])]]);
        $this->assertSame(4, $plain['meta']['activityCount']);
        $this->assertSame(600000.0, $plain['meta']['contractAmount']);
        $this->assertSame(50.0, $plain['months'][0]['planned_progress_percent']);

        $taught = $service->parse($path, [['id' => 2, 'contractor_id' => null, 'column_map' => $this->mapWithRoles($path, [
            'excludeSigs' => ['site establishment fund', 'running total %'],
        ])]]);

        $this->assertTrue($taught['meta']['mapUsed']);
        $this->assertSame(2, $taught['meta']['activityCount']);
        $this->assertSame(400000.0, $taught['meta']['contractAmount']);
        $this->assertSame(25.0, $taught['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $taught['months'][1]['planned_progress_percent']);

        unlink($path);
    }

    // Worth its own test: excluding only ONE of the two intruders leaves month 1 at
    // 25.01, not 25. The curve's own numbers are the only place that stray 0.01
    // shows up — it is the printed percentage of the un-excluded "RUNNING TOTAL %"
    // row leaking into the month's PESO total. This is what "fails silently" looks
    // like in practice, and why the notes name every exclusion.
    public function test_a_partially_taught_file_still_leaks_and_the_leak_is_visible(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNovelSummaryWorkbook($path);

        $result = app(SCurveImportService::class)->parse($path, [['id' => 3, 'contractor_id' => null, 'column_map' => $this->mapWithRoles($path, [
            'excludeSigs' => ['site establishment fund'],
        ])]]);

        $this->assertSame(3, $result['meta']['activityCount']);
        $this->assertSame(400000.0, $result['meta']['contractAmount']);
        $this->assertEqualsWithDelta(25.01, $result['months'][0]['planned_progress_percent'], 0.001);

        unlink($path);
    }

    // THE CONSTRAINT THAT DROVE THE PLACEMENT. Excluding the TOTAL row must not
    // remove the contract denominator: the skip sits AFTER the TOTAL branch, so
    // $totalRowContract is already recorded. Skipping earlier would have let one
    // tick drop the denominator to zero and switch the curve onto the weights
    // path — a change of basis with no visible symptom.
    public function test_excluding_the_total_row_does_not_destroy_the_denominator(): void
    {
        // The fixture is built so the TOTAL row is the ONLY source of the
        // denominator: the activities carry month values but NO contract amounts,
        // so the activity sum is zero and :1669 must fall back to the TOTAL row.
        // On makeNovelSummaryWorkbook this test would prove nothing, because the
        // activity sum alone already supplies a denominator.
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('C10', 'TASK DESCRIPTION');
        $sheet->setCellValue('F10', 'CONTRACT AMOUNT');
        $sheet->setCellValue('G10', '% WEIGHT');
        $sheet->setCellValue('I10', 'Month 1');
        $sheet->setCellValue('K10', 'Month 2');
        $sheet->setCellValue('C12', 'Mobilization Works');
        $sheet->setCellValue('I12', 100000);
        $sheet->setCellValue('C13', 'Structural Works');
        $sheet->setCellValue('K13', 300000);
        $sheet->setCellValue('C14', 'TOTAL');
        $sheet->setCellValue('F14', 400000);
        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path, [['id' => 5, 'contractor_id' => null, 'column_map' => $this->mapWithRoles($path, [
            'excludeSigs' => ['total'],
        ])]]);

        // Had the skip been placed before the TOTAL branch, $totalRowContract would
        // be null here, the denominator would be zero, and month 1 would read 0 with
        // the final month forced to 100 — a plausible-looking curve on a different
        // basis entirely.
        $this->assertSame(400000.0, $result['meta']['contractAmount']);
        $this->assertSame(25.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][1]['planned_progress_percent']);
        $this->assertStringNotContainsString(
            'Could not determine the total contract amount',
            implode(' ', $result['meta']['warnings'] ?? [])
        );

        unlink($path);
    }

    // A taught cumulative row pins what readSummaryReference reads, including a
    // wording the regex would never find.
    public function test_a_taught_cumulative_row_is_the_one_that_is_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNovelSummaryWorkbook($path);
        $service = app(SCurveImportService::class);

        // Untaught: "RUNNING TOTAL %" matches neither cumulat\w* nor commulat\w*,
        // so the author's printed curve is invisible.
        $plain = $service->parse($path, [['id' => 6, 'contractor_id' => null, 'column_map' => $this->mapWithRoles($path, [])]]);
        $this->assertEmpty($plain['meta']['summaryReference'] ?? []);

        $taught = $service->parse($path, [['id' => 7, 'contractor_id' => null, 'column_map' => $this->mapWithRoles($path, [
            'excludeSigs' => ['site establishment fund', 'running total %'],
            'cumulativeSig' => 'running total %',
        ])]]);

        $ref = $taught['meta']['summaryReference'] ?? [];
        $this->assertNotEmpty($ref, 'the taught row must be found where the regex failed');
        $byMonth = [];
        foreach ($ref as $e) { $byMonth[$e['month']] = $e; }
        $this->assertSame(25.0, (float) $byMonth[1]['summary_percent']);
        // meta and the reader must name the SAME row, or the meta describes a row
        // nobody read.
        $this->assertSame(16, $taught['meta']['cumulativeRow']);
        // Reference only — the curve still comes from the activities.
        $this->assertSame('activities', $taught['meta']['curveSource']);

        unlink($path);
    }

    // A signature that hits several rows means the reviewer's label is ambiguous in
    // this file. All matches are excluded — picking an arbitrary one would be a coin
    // flip — and the notes say it happened.
    public function test_an_ambiguous_signature_excludes_every_match_and_says_so(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('C10', 'TASK DESCRIPTION');
        $sheet->setCellValue('F10', 'CONTRACT AMOUNT');
        $sheet->setCellValue('G10', '% WEIGHT');
        $sheet->setCellValue('I10', 'Month 1');
        $sheet->setCellValue('K10', 'Month 2');
        $sheet->setCellValue('C12', 'Mobilization Works');
        $sheet->setCellValue('F12', 100000);
        $sheet->setCellValue('G12', 50);
        $sheet->setCellValue('I12', 100000);
        $sheet->setCellValue('C13', 'Structural Works');
        $sheet->setCellValue('F13', 100000);
        $sheet->setCellValue('G13', 50);
        $sheet->setCellValue('K13', 100000);
        // Two rows reading the same, differing only in numbers.
        $sheet->setCellValue('C14', 'Provisional Sum');
        $sheet->setCellValue('F14', 50000);
        $sheet->setCellValue('G14', 10);
        $sheet->setCellValue('I14', 50000);
        $sheet->setCellValue('C15', 'Provisional Sum');
        $sheet->setCellValue('F15', 70000);
        $sheet->setCellValue('G15', 10);
        $sheet->setCellValue('K15', 70000);
        $sheet->setCellValue('C16', 'TOTAL');
        $sheet->setCellValue('F16', 200000);
        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path, [['id' => 8, 'contractor_id' => null, 'column_map' => $this->mapWithRoles($path, [
            'excludeSigs' => ['provisional sum'],
        ])]]);

        $this->assertSame(2, $result['meta']['activityCount'], 'both matches must go, not just the first');
        $this->assertSame(200000.0, $result['meta']['contractAmount']);
        $notes = implode(' ', $result['meta']['warnings'] ?? []);
        $this->assertStringContainsString('Excluded 2 rows', $notes);
        $this->assertStringContainsString('provisional sum', $notes);

        unlink($path);
    }

    // A taught role that matches nothing is reported rather than ignored: it means
    // the layout moved under the teaching, and silence would leave the reviewer
    // believing a row was excluded when it was not.
    public function test_a_taught_role_matching_nothing_is_reported(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeNovelSummaryWorkbook($path);

        $result = app(SCurveImportService::class)->parse($path, [['id' => 9, 'contractor_id' => null, 'column_map' => $this->mapWithRoles($path, [
            'excludeSigs' => ['a row that is not in this file'],
        ])]]);

        $notes = implode(' ', $result['meta']['warnings'] ?? []);
        $this->assertStringContainsString('matched no row in this file', $notes);
        $this->assertSame(4, $result['meta']['activityCount'], 'nothing was excluded');

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
    // 2026-09-14: R8 reads "CUMULATIVE % ACHIEVED" and now earns its own tag. R7
    // is a plain TOTAL and stays SUMMARY — the pair is what proves the branch
    // splits on the label rather than on position in the summary block.
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
        $this->assertSame('CUMULATIVE', $tags['R8'] ?? null);

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
        $this->assertSame([1], $map['headerRows'] ?? null, 'the AI-read header R must be remembered for reopen');
        $this->assertSame(0, $map['descCol']);
        $this->assertCount(3, $map['monthSpans']);
        $this->assertNotEmpty($map['sig']);

        unlink($path);
    }

// PDF-3 — a layout learned from a verified AI read follows the Excel
    // learn-from-AI rules exactly: proposed, ai-derived, and NEVER auto-live,
    // because a machine-made map must not self-trust into baselines.
    // Called by: scurveHarvestPdfLayout() → collect().
    public function test_ai_derived_pdf_layout_is_stored_but_never_live(): void
    {
        $admin = $this->makeUser('admin');
        $block = "COLUMNS: A,B,C,D | META: title block | HEADER: column labels\n"
            . "R1 [HEADER]: NO.,Amount,30 days,30 days\n"
            . "R2 [DATA]: A,ROOFING,4000,4000";
        $map = ['v' => 1, 'kind' => 'pdf', 'pages' => [
            ['pageHint' => 0, 'sig' => ['no.', 'amount', '30 days'],
                'descX' => 0.05, 'descRight' => 0.3,
                'monthCols' => [['index' => 1, 'x' => 0.4, 'xEnd' => 0.6], ['index' => 2, 'x' => 0.6, 'xEnd' => 0.9]]],
        ]];

        $payload = [
            'source_type' => 'pdf',
            'source' => 'ai-derived',
            'header_rows_text' => $block,
            'column_map' => json_encode($map),
        ];

        // Three identical posts: occurrences must NOT accumulate into auto-live.
        foreach (range(1, 3) as $ignored) {
            $this->actingAs($admin)
                ->post('/admin/scurve-header-proposals/collect', $payload)
                ->assertStatus(201)
                ->assertJsonPath('success', true);
        }

        $rows = ScurveHeaderProposal::where('source_type', 'pdf')->get();
        $this->assertCount(1, $rows, 'repeats must not multiply rows');
        $this->assertSame('ai-derived', $rows[0]->source);
        $this->assertSame('proposed', $rows[0]->status, 'a machine-made map must never auto-live');
        $this->assertSame('pdf', $rows[0]->column_map['kind']);
    }

    // 2026-09-14 — SEEING AN AMBIGUOUS LAYOUT AGAIN CANNOT NUMBER IT.
    //
    // The >=3 rule corroborates WHERE a layout's columns sit. It cannot corroborate
    // WHICH month each one is, because the roof prints "30 days" six times however
    // many times you import it. Promoting one produced a row badged live and Mapped
    // that usableLayoutMap still refuses, so the reviewer approved nothing and got
    // "could not read the S-curve" anyway.
    // Called by: collect() auto-live rule.
    public function test_an_unnumbered_capture_never_auto_lives(): void
    {
        $admin = $this->makeUser('admin');
        $block = "COLUMNS: A,B,C,D | META: title block | HEADER: column labels\n"
            . "R1 [HEADER]: NO.,Amount,30 days,30 days\n"
            . "R2 [DATA]: A,ROOFING,16%,17%";
        // Geometry kept, months unnumbered — exactly what an ambiguous capture stores.
        $map = ['v' => 1, 'kind' => 'pdf', 'pages' => [
            ['pageHint' => 0, 'sig' => ['no.', 'amount', '30 days'],
                'descX' => 0.05, 'descRight' => 0.3, 'monthCols' => [], 'unnumbered' => true,
                'pseudoCols' => [['letter' => 'A', 'x' => 0.05, 'xEnd' => 0.3]]],
        ]];
        $payload = [
            'source_type' => 'pdf', 'source' => 'auto-capture',
            'header_rows_text' => $block, 'column_map' => json_encode($map),
        ];

        foreach (range(1, 4) as $ignored) {
            $this->actingAs($admin)
                ->post('/admin/scurve-header-proposals/collect', $payload)
                ->assertStatus(201);
        }

        $row = ScurveHeaderProposal::where('source_type', 'pdf')->firstOrFail();
        $this->assertSame(4, $row->occurrences, 'repeats still count');
        $this->assertSame('proposed', $row->status,
            'an unnumbered layout must stay proposed however often it is seen');
    }

    // A NUMBERED capture still promotes — the guard must not disable the rule it
    // narrows.
    // Called by: collect() auto-live rule.
    public function test_a_numbered_capture_still_auto_lives_at_three(): void
    {
        $admin = $this->makeUser('admin');
        $block = "COLUMNS: A,B,C,D | META: title block | HEADER: column labels\n"
            . "R1 [HEADER]: NO.,Amount,Month 1,Month 2\n"
            . "R2 [DATA]: A,ROOFING,4000,4000";
        $map = ['v' => 1, 'kind' => 'pdf', 'pages' => [
            ['pageHint' => 0, 'sig' => ['no.', 'amount', 'month 1'],
                'descX' => 0.05, 'descRight' => 0.3,
                'monthCols' => [['index' => 1, 'x' => 0.4, 'xEnd' => 0.6], ['index' => 2, 'x' => 0.6, 'xEnd' => 0.9]]],
        ]];
        $payload = [
            'source_type' => 'pdf', 'source' => 'auto-capture',
            'header_rows_text' => $block, 'column_map' => json_encode($map),
        ];

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($admin)->post('/admin/scurve-header-proposals/collect', $payload)->assertStatus(201);
        }

        $this->assertSame('live', ScurveHeaderProposal::where('source_type', 'pdf')->firstOrFail()->status);
    }

    // 2026-09-15 — A CAPTURE THAT CANNOT READ ITS OWN FILE NEVER AUTO-LIVES.
    //
    // The one-page BUHI's capture is numbered, so the >=3 rule promoted it — but its
    // label-start bands are exactly the misread the read guard refuses, and live it
    // shadowed the reviewer's correct layout. The browser now checks the capture against
    // its own file and sends capture_check; anything but "reads" stays proposed (still
    // stored, so it can be taught). A capture that reads still promotes, and one sent
    // without the field keeps the old rule.
    // Called by: collect() auto-live rule.
    public function test_a_capture_whose_own_reading_fails_never_auto_lives(): void
    {
        $admin = $this->makeUser('admin');
        $map = ['v' => 1, 'kind' => 'pdf', 'pages' => [
            ['pageHint' => 0, 'sig' => ['no.', 'amount', 'month 1'],
                'descX' => 0.05, 'descRight' => 0.3,
                'monthCols' => [['index' => 1, 'x' => 0.4, 'xEnd' => 0.6], ['index' => 2, 'x' => 0.6, 'xEnd' => 0.9]]],
        ]];
        // Three distinct blocks, one per verdict, so each is its own row.
        $send = function (string $label, ?string $check) use ($admin, $map) {
            $payload = [
                'source_type' => 'pdf', 'source' => 'auto-capture',
                'header_rows_text' => "COLUMNS: A,B,C,D | HEADER: column labels\nR1 [HEADER]: NO.,Amount,Month 1,Month 2\nR2 [DATA]: A,{$label},4000,4000",
                'column_map' => json_encode($map),
            ];
            if ($check !== null) {
                $payload['capture_check'] = $check;
            }
            foreach (range(1, 3) as $ignored) {
                $this->actingAs($admin)->post('/admin/scurve-header-proposals/collect', $payload)->assertStatus(201);
            }
            return ScurveHeaderProposal::where('header_rows_text', 'like', "%{$label}%")->firstOrFail();
        };

        $refused = $send('REFUSED ROW', 'refused');
        $this->assertSame(3, $refused->occurrences, 'repeats still count');
        $this->assertSame('proposed', $refused->status, 'a capture its own file refuses must stay proposed');

        $this->assertSame('proposed', $send('UNREAD ROW', 'unread')->status, 'a capture that reads nothing must stay proposed');
        $this->assertSame('live', $send('READS ROW', 'reads')->status, 'a capture that reads its file still promotes');
        $this->assertSame('live', $send('UNCHECKED ROW', null)->status, 'no check sent: the old rule holds');

        $this->actingAs($admin)->post('/admin/scurve-header-proposals/collect', [
            'source_type' => 'pdf', 'header_rows_text' => "COLUMNS: A,B\nR1 [HEADER]: X,Y", 'capture_check' => 'maybe',
        ])->assertStatus(302);
    }

    // 2026-09-14 — THE AI'S NUMBERING MUST REACH AN UNNUMBERED CAPTURE.
    //
    // Once the ai-derived block became the same merged grid the capture builds, the
    // two hash IDENTICALLY, so storeAiDerived started landing on the roof's own
    // auto-capture row. Under the old `column_map === null` test it returned that row
    // untouched and the AI's month numbers — the ONE thing the capture cannot derive
    // — were discarded in silence. Backfilling drops the row to proposed, because a
    // machine-made numbering still needs a person to approve it.
    // Called by: ScurveHeaderProposal::storeAiDerived().
    public function test_an_ai_numbering_backfills_an_unnumbered_capture(): void
    {
        $hash = hash('sha256', 'roof-shaped-block');
        $unnumbered = ScurveHeaderProposal::create([
            'contractor_id' => null, 'source_type' => 'pdf',
            'header_rows_text' => 'COLUMNS: A,B | HEADER: column labels',
            'header_hash' => $hash,
            'column_map' => ['v' => 1, 'kind' => 'pdf', 'pages' => [
                ['monthCols' => [], 'unnumbered' => true,
                    'pseudoCols' => [['letter' => 'A', 'x' => 0.0, 'xEnd' => 0.5]]],
            ]],
            'occurrences' => 3, 'status' => 'live', 'source' => 'auto-capture',
        ]);

        $numbered = ['v' => 1, 'kind' => 'pdf', 'pages' => [
            ['monthCols' => [['index' => 1, 'x' => 0.5, 'xEnd' => 0.7], ['index' => 2, 'x' => 0.7, 'xEnd' => 0.9]]],
        ]];
        // 'pdf': the PDF caller names its type since the 2026-09-15 scoping fix.
        ScurveHeaderProposal::storeAiDerived(null, $hash, 'ignored text', $numbered, 'pdf');

        $this->assertSame(1, ScurveHeaderProposal::where('header_hash', $hash)->count(),
            'the AI numbering joins the existing row rather than forking it');
        $row = $unnumbered->fresh();
        $this->assertCount(2, $row->column_map['pages'][0]['monthCols'], 'the months arrived');
        $this->assertSame('ai-derived', $row->source);
        $this->assertSame('proposed', $row->status, 'a machine numbering must be re-approved');
    }

    // A capture whose months ARE numbered is still left alone — the AI has nothing
    // to add, and overwriting a working map with a machine guess is the failure the
    // never-overwrite rule exists to prevent.
    // Called by: ScurveHeaderProposal::storeAiDerived().
    public function test_a_numbered_capture_is_not_overwritten_by_the_ai(): void
    {
        $hash = hash('sha256', 'already-numbered');
        $original = ['v' => 1, 'kind' => 'pdf', 'pages' => [
            ['monthCols' => [['index' => 1, 'x' => 0.4, 'xEnd' => 0.6], ['index' => 2, 'x' => 0.6, 'xEnd' => 0.8]]],
        ]];
        $row = ScurveHeaderProposal::create([
            'contractor_id' => null, 'source_type' => 'pdf',
            'header_rows_text' => 'COLUMNS: A,B | HEADER: column labels',
            'header_hash' => $hash, 'column_map' => $original,
            'occurrences' => 3, 'status' => 'live', 'source' => 'auto-capture',
        ]);

        ScurveHeaderProposal::storeAiDerived(null, $hash, 'ignored', ['v' => 1, 'kind' => 'pdf', 'pages' => [
            ['monthCols' => [['index' => 1, 'x' => 0.1, 'xEnd' => 0.2]]],
        ]], 'pdf');

        $fresh = $row->fresh();
        $this->assertSame($original, $fresh->column_map, 'a working map is never replaced');
        $this->assertSame('live', $fresh->status);
        $this->assertSame('auto-capture', $fresh->source);
    }

    // 2026-09-15 — AN AI-DERIVED PDF LAYOUT MUST NEVER LAND ON AN EXCEL ROW.
    //
    // storeAiDerived matched on (header_hash, contractor_id) with no type, while
    // collect() scoped by type precisely so Excel and PDF rows never merge — and the
    // PDF branch then patched source_type onto whatever came back. A hash collision
    // therefore backfilled a PDF map into a map-less EXCEL row and flipped it to PDF
    // for good. The fixture forces the collision by giving the Excel row the exact
    // hash collect() computes for the posted block.
    // Called by: collect() ai-derived branch -> ScurveHeaderProposal::storeAiDerived().
    public function test_an_ai_derived_pdf_layout_never_hijacks_an_excel_row(): void
    {
        $block = "COLUMNS: A,B,C | HEADER: column labels\n"
            . "R1 [HEADER]: DESCRIPTION,MONTH 1,MONTH 2\n"
            . "R2 [DATA]: Roofing,4000,4000";
        $hash = hash('sha256', mb_strtolower(preg_replace('/\s+/', ' ', trim($block))));
        $excel = ScurveHeaderProposal::create([
            'contractor_id' => null, 'source_type' => 'excel',
            'header_rows_text' => $block, 'header_hash' => $hash,
            'column_map' => null, 'occurrences' => 2, 'status' => 'proposed', 'source' => 'auto-capture',
        ]);
        $pdfMap = ['v' => 1, 'kind' => 'pdf', 'pages' => [
            ['pageHint' => 0, 'sig' => ['description', 'month 1'], 'descX' => 0.05, 'descRight' => 0.3,
                'monthCols' => [['index' => 1, 'x' => 0.4, 'xEnd' => 0.6], ['index' => 2, 'x' => 0.6, 'xEnd' => 0.9]]],
        ]];

        $this->actingAs($this->makeUser('admin'))
            ->post('/admin/scurve-header-proposals/collect', [
                'source_type' => 'pdf', 'source' => 'ai-derived',
                'header_rows_text' => $block, 'column_map' => json_encode($pdfMap),
            ])
            ->assertStatus(201);

        $fresh = $excel->fresh();
        $this->assertSame('excel', $fresh->source_type, 'the Excel row must stay an Excel row');
        $this->assertNull($fresh->column_map, 'and must not receive a PDF map');
        $this->assertSame('auto-capture', $fresh->source);
        $pdf = ScurveHeaderProposal::where('header_hash', $hash)->where('source_type', 'pdf')->first();
        $this->assertNotNull($pdf, 'the PDF layout is stored as its own row');
        $this->assertSame('ai-derived', $pdf->source);
        $this->assertSame('pdf', $pdf->column_map['kind']);
    }

    // The map is the whole point of an AI-derived row, so one without it is
    // refused rather than stored as an empty promise.
    // Called by: collect() ai-derived branch.
    public function test_ai_derived_pdf_layout_requires_its_map(): void
    {
        $this->actingAs($this->makeUser('admin'))
            ->post('/admin/scurve-header-proposals/collect', [
                'source_type' => 'pdf',
                'source' => 'ai-derived',
                'header_rows_text' => "COLUMNS: A,B\nR1 [HEADER]: DESCRIPTION,MONTH 1",
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, ScurveHeaderProposal::count());
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
    /* ─── PDF-4 teach-path gating (2026-09-13) ───────────────────────────── */

    // A PDF capture as the browser stores it: the COLUMNS block plus a PDF-shaped
    // map whose pseudoCols are the bridge from letters back to page bands.
    private function makePdfRow(array $pages = null): ScurveHeaderProposal
    {
        $pseudo = [
            ['letter' => 'A', 'x' => 0.05, 'xEnd' => 0.30],
            ['letter' => 'B', 'x' => 0.30, 'xEnd' => 0.38],
            ['letter' => 'C', 'x' => 0.38, 'xEnd' => 0.60],
            ['letter' => 'D', 'x' => 0.60, 'xEnd' => 0.82],
        ];
        $page = [
            'pageHint' => 0,
            'lineHint' => 2,
            'sig' => ['description', 'amount', 'stint 1', 'stint 2'],
            'descX' => 0.05,
            'descRight' => 0.30,
            'monthCols' => [
                ['index' => 1, 'x' => 0.38, 'xEnd' => 0.60],
                ['index' => 2, 'x' => 0.60, 'xEnd' => 0.82],
            ],
            'pseudoCols' => $pseudo,
            'headerLine' => 'DESCRIPTION AMOUNT STINT 1 STINT 2',
        ];

        return ScurveHeaderProposal::create([
            'contractor_id' => null,
            'source_type' => 'pdf',
            'header_rows_text' => "COLUMNS: A,B,C,D | HEADER: column labels\n"
                . "R1 [HEADER]: DESCRIPTION,AMOUNT,STINT 1,STINT 2\n"
                . "R2 [DATA]: Roofing,1000,60%,40%",
            'header_hash' => hash('sha256', 'pdf-teach-fixture-' . ($pages === null ? 'single' : 'multi')),
            'column_map' => ['v' => 1, 'kind' => 'pdf', 'pages' => $pages ?? [$page]],
            'occurrences' => 1,
            'status' => 'proposed',
            'source' => 'auto-capture',
        ]);
    }

    private function pdfTeachPayload(array $overrides = []): array
    {
        // The contract column is taught because a reviewer looking at
        // DESCRIPTION | AMOUNT | STINT 1 | STINT 2 would say AMOUNT is the peso
        // column — and because it BOUNDS the description band (2026-09-14): a
        // taught desc now runs to the nearest column the reviewer gave to
        // something else, so leaving AMOUNT unassigned would widen desc over it.
        return array_merge([
            'desc' => 'A',
            'contract' => 'B',
            'months' => [1 => ['C'], 2 => ['D']],
            'header_rows' => [1],
        ], $overrides);
    }

    // The bug this whole item exists for: a taught PDF row used to be handed an
    // EXCEL map ({v, sig, cols}), which the PDF reader ignores outright because
    // usableLayoutMap gates on kind === 'pdf'. The reviewer saw "saved" and
    // nothing ever changed. The taught map must now be PDF-shaped: bands as
    // page-width fractions, recovered from the capture's own pseudoCols.
    // Called by: teachColumns() -> buildTaughtPdfMap().
    public function test_teaching_a_pdf_row_produces_a_pdf_shaped_map(): void
    {
        $staff = $this->makeUser('staff');
        $row = $this->makePdfRow();

        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns", $this->pdfTeachPayload())
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('mode', 'variant');

        $variant = ScurveHeaderProposal::where('source', 'manual-teach')->first();
        $this->assertNotNull($variant);
        $this->assertSame('pdf', $variant->source_type, 'a taught PDF row stays a PDF row');

        $map = $variant->column_map;
        $this->assertSame('pdf', $map['kind'] ?? null, 'an Excel map here is the defect being fixed');
        $this->assertArrayNotHasKey('cols', $map, 'no Excel column indices may ride on a PDF map');
        $this->assertCount(1, $map['pages']);

        $page = $map['pages'][0];
        // Letters converted back into the exact bands the capture recorded.
        $this->assertSame(0.05, $page['descX']);
        $this->assertSame(0.30, $page['descRight']);
        $this->assertSame([
            ['index' => 1, 'x' => 0.38, 'xEnd' => 0.60],
            ['index' => 2, 'x' => 0.60, 'xEnd' => 0.82],
        ], $page['monthCols']);
        // What identifies the PAGE survives, so the taught map still matches it.
        $this->assertSame(['description', 'amount', 'stint 1', 'stint 2'], $page['sig']);
        $this->assertNotEmpty($page['pseudoCols'], 'pseudoCols must survive so the row can be re-taught');
    }

    // Page-edge marking (2026-09-15): a PDF capture whose print cut a cell off at the
    // page edge stores an "EDGE: R2:D" line right under the legend. The server's block
    // reader (parseStoredSlice) must skip it like any non-row line, so such a row
    // teaches to the SAME map as one without the line — header row R1 still found,
    // nothing refused.
    // Called by: teachColumns() -> parseStoredSlice() + buildTaughtPdfMap().
    public function test_an_edge_line_in_a_pdf_block_does_not_change_teaching(): void
    {
        $staff = $this->makeUser('staff');
        $plain = $this->makePdfRow();

        $marked = $plain->replicate();
        $marked->header_rows_text = "COLUMNS: A,B,C,D | HEADER: column labels\n"
            . "EDGE: R2:D\n"
            . "R1 [HEADER]: DESCRIPTION,AMOUNT,STINT 1,STINT 2\n"
            . "R2 [DATA]: Roofing,1000,60%,40%";
        $marked->header_hash = hash('sha256', 'pdf-teach-fixture-edge');
        $marked->save();

        foreach ([$plain, $marked] as $row) {
            $this->actingAs($staff)
                ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns", $this->pdfTeachPayload())
                ->assertOk()->assertJsonPath('success', true);
        }

        $maps = ScurveHeaderProposal::where('source', 'manual-teach')->orderBy('id')->get()->pluck('column_map');
        $this->assertCount(2, $maps);
        $this->assertSame($maps[0], $maps[1], 'the EDGE line is display-only: the taught map is identical');
    }

    // THE 2026-09-14 REGRESSION, under test.
    //
    // §2 split the roof's left block into ITEM NO. | DESCRIPTION | UNIT | QTY,
    // where the old rule had collapsed all four into ONE band. A taught desc was
    // still exactly one pseudo-column wide, so it no longer reached the numbers —
    // and descRight does not mean "where the names end", it means "where the
    // figures begin". Measured on the real roof PDF: a one-column desc read
    // 2.48/10.8/… whichever column the reviewer picked, while any band reaching
    // the first numbered column read the true 6.32/24.31/….
    // Called by: buildTaughtPdfMap() description widening.
    public function test_a_taught_description_widens_to_the_first_numbered_column(): void
    {
        $staff = $this->makeUser('staff');
        // Roof-shaped: four narrow label columns, then the peso column, then months.
        $row = $this->makePdfRow([[
            'pageHint' => 0,
            'lineHint' => 2,
            'sig' => ['no.', 'amount', '30 days', '30 days'],
            'descX' => 0.04,
            'descRight' => 0.33,
            'monthCols' => [],
            'unnumbered' => true,
            'pseudoCols' => [
                ['letter' => 'A', 'x' => 0.04, 'xEnd' => 0.07],   // ITEM NO.
                ['letter' => 'B', 'x' => 0.07, 'xEnd' => 0.23],   // DESCRIPTION
                ['letter' => 'C', 'x' => 0.23, 'xEnd' => 0.27],   // UNIT
                ['letter' => 'D', 'x' => 0.27, 'xEnd' => 0.32],   // QTY
                ['letter' => 'E', 'x' => 0.32, 'xEnd' => 0.42],   // Amount
                ['letter' => 'F', 'x' => 0.42, 'xEnd' => 0.52],   // month 1
                ['letter' => 'G', 'x' => 0.52, 'xEnd' => 0.63],   // month 2
            ],
            'headerLine' => 'NO. Amount 30 days 30 days',
        ]]);

        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns", [
                'desc' => 'A', 'contract' => 'E',
                'months' => [1 => ['F'], 2 => ['G']],
                'header_rows' => [1],
            ])->assertOk();

        $page = ScurveHeaderProposal::where('source', 'manual-teach')->first()->column_map['pages'][0];
        $this->assertSame(0.04, $page['descX'], 'the band still starts where the reviewer pointed');
        $this->assertSame(0.32, $page['descRight'], 'and runs to the peso column they identified');
    }

    // The same widening with NO contract taught: the first MONTH bounds it, which
    // is the only other thing the reviewer told us about where numbers start.
    // Called by: buildTaughtPdfMap() description widening.
    public function test_a_taught_description_stops_at_the_first_month_when_no_contract_is_given(): void
    {
        $staff = $this->makeUser('staff');
        $row = $this->makePdfRow();

        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->pdfTeachPayload(['contract' => null]))
            ->assertOk();

        $page = ScurveHeaderProposal::where('source', 'manual-teach')->first()->column_map['pages'][0];
        $this->assertSame(0.38, $page['descRight'], 'widened to where month 1 begins, never past it');
    }

    // Widening must never REACH a month band — that would let one printed cell be
    // read as both a work name and a monthly figure.
    // Called by: buildTaughtPdfMap() description widening + overlap guard.
    public function test_widening_never_overlaps_a_month(): void
    {
        $staff = $this->makeUser('staff');
        $row = $this->makePdfRow();

        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->pdfTeachPayload(['contract' => null]))
            ->assertOk();

        $page = ScurveHeaderProposal::where('source', 'manual-teach')->first()->column_map['pages'][0];
        foreach ($page['monthCols'] as $mc) {
            $this->assertTrue(
                $page['descRight'] <= $mc['x'] + 1e-9,
                "description runs into month {$mc['index']}"
            );
        }
    }

    // Two letters on one month widen that month's band to span both.
    // Called by: buildTaughtPdfMap() month span merging.
    public function test_a_month_taught_two_columns_spans_both(): void
    {
        $staff = $this->makeUser('staff');
        $row = $this->makePdfRow();

        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->pdfTeachPayload(['contract' => null, 'months' => [1 => ['B', 'C'], 2 => ['D']]]))
            ->assertOk();

        $page = ScurveHeaderProposal::where('source', 'manual-teach')->first()->column_map['pages'][0];
        $this->assertSame(['index' => 1, 'x' => 0.30, 'xEnd' => 0.60], $page['monthCols'][0]);
    }

    // The grid renders the FIRST printed page only, so a reviewer cannot see the
    // months on later pages. Saving what they can see would teach a SHORT
    // schedule — the same defect that made single-page capture unsafe — so it is
    // refused. Crucially the refusal happens BEFORE any write.
    // Called by: buildTaughtPdfMap() multi-page guard.
    // A column-split print is ONE table cut down the middle, and the desk now renders
    // both halves as one grid with the letters running on across the seam. Teaching
    // it must therefore produce a MULTI-PAGE map: the reviewer points at one table,
    // and the months land on whichever printed page their columns belong to.
    // Refusing this was correct only while the grid could show page one alone.
    // Called by: buildTaughtPdfMap() page grouping.
    public function test_a_multi_page_layout_teaches_into_a_multi_page_map(): void
    {
        $staff = $this->makeUser('staff');
        $pageOne = $this->makePdfRow()->column_map['pages'][0];
        // Letters continue across the seam, exactly as derivePdfLayout numbers them.
        $pageTwo = array_merge($pageOne, [
            'pageHint' => 1,
            'monthCols' => [],
            'pseudoCols' => [
                ['letter' => 'E', 'x' => 0.20, 'xEnd' => 0.60],
                ['letter' => 'F', 'x' => 0.60, 'xEnd' => 1.00],
            ],
        ]);
        ScurveHeaderProposal::query()->delete();
        $row = $this->makePdfRow([$pageOne, $pageTwo]);

        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->pdfTeachPayload(['months' => [1 => ['C'], 2 => ['D'], 3 => ['E'], 4 => ['F']]]))
            ->assertOk()->assertJsonPath('success', true);

        $map = ScurveHeaderProposal::where('source', 'manual-teach')->first()->column_map;
        $this->assertCount(2, $map['pages'], 'the seam is preserved — one page per printed sheet');
        $this->assertSame([1, 2], array_column($map['pages'][0]['monthCols'], 'index'));
        $this->assertSame([3, 4], array_column($map['pages'][1]['monthCols'], 'index'),
            'months taught from page-two letters land on page two');
        // The description was pointed at on page one, so only page one carries it —
        // a continuation page legitimately has no names, as its capture does not.
        $this->assertSame(0.05, $map['pages'][0]['descX']);
        $this->assertArrayNotHasKey('unnumbered', $map['pages'][1],
            'teaching is exactly what removes the unnumbered mark');
    }

    // A month cannot be spread across two printed pages: its columns live on one
    // sheet, and accepting letters from both would produce a band that exists
    // nowhere. Refused before anything is written.
    // Called by: buildTaughtPdfMap() page grouping.
    public function test_a_month_spanning_two_pages_is_refused_without_writing(): void
    {
        $staff = $this->makeUser('staff');
        $pageOne = $this->makePdfRow()->column_map['pages'][0];
        $pageTwo = array_merge($pageOne, [
            'pageHint' => 1,
            'monthCols' => [],
            'pseudoCols' => [['letter' => 'E', 'x' => 0.20, 'xEnd' => 0.60]],
        ]);
        ScurveHeaderProposal::query()->delete();
        $row = $this->makePdfRow([$pageOne, $pageTwo]);
        $before = $row->column_map;

        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->pdfTeachPayload(['mode' => 'replace', 'months' => [1 => ['C'], 2 => ['D', 'E']]]))
            ->assertStatus(422)->assertJsonPath('success', false);

        // REPLACE used to overwrite first and refuse later — the destructive half of
        // the original bug. A refusal must still leave the map exactly as it was.
        $this->assertSame($before, $row->fresh()->column_map, 'a refusal must not destroy the map');
        $this->assertSame('proposed', $row->fresh()->status);
        $this->assertSame(1, ScurveHeaderProposal::count(), 'no variant is created either');
    }

    // An AMBIGUOUS capture (positional wording — nothing in the bytes says which
    // month a column is) stores column_map NULL, and pseudoCols goes with it. Its
    // letters have no coordinates, so there is nothing to convert; say so rather
    // than invent geometry.
    // Called by: buildTaughtPdfMap() no-geometry guard.
    public function test_a_pdf_row_without_recorded_positions_refuses_honestly(): void
    {
        $staff = $this->makeUser('staff');
        $row = $this->makePdfRow();
        $row->update(['column_map' => null]);

        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns", $this->pdfTeachPayload())
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertNull($row->fresh()->column_map);
        $this->assertSame(1, ScurveHeaderProposal::count());
    }

    // Geometry that is not a table is refused on the PDF side too — the same
    // spirit as buildTaughtMap's gates on the Excel side.
    // Called by: buildTaughtPdfMap() overlap + disjoint guards.
    public function test_overlapping_or_swallowed_columns_are_refused(): void
    {
        $staff = $this->makeUser('staff');
        $row = $this->makePdfRow();

        // Month 2 assigned LEFT of month 1 — months must run left to right.
        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->pdfTeachPayload(['months' => [1 => ['D'], 2 => ['C']]]))
            ->assertStatus(422)->assertJsonPath('success', false);

        // The description column cannot also be a month column.
        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->pdfTeachPayload(['desc' => 'C']))
            ->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame(1, ScurveHeaderProposal::count(), 'nothing was saved by either refusal');
    }

    // A letter the capture never recorded cannot become a band.
    // Called by: buildTaughtPdfMap() bandFor().
    public function test_an_unknown_letter_is_refused_on_the_pdf_path(): void
    {
        $staff = $this->makeUser('staff');
        $row = $this->makePdfRow();

        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$row->id}/columns",
                $this->pdfTeachPayload(['months' => [1 => ['C'], 2 => ['Z']]]))
            ->assertStatus(422)->assertJsonPath('success', false);

        $this->assertSame(1, ScurveHeaderProposal::count());
    }

    // The Excel path must be entirely unaffected by the new branch — it is gated
    // on source_type, and Excel rows are the overwhelming majority.
    // Called by: teachColumns() excel branch (unchanged).
    public function test_excel_teaching_still_produces_an_excel_map(): void
    {
        $staff = $this->makeUser('staff');
        $original = $this->makeTeachableRow();

        $this->actingAs($staff)
            ->patchJson("/staff/scurve-header-proposals/{$original->id}/columns", $this->teachPayload())
            ->assertOk();

        $map = ScurveHeaderProposal::where('source', 'manual-teach')->first()->column_map;
        $this->assertArrayNotHasKey('kind', $map, 'an Excel map carries no PDF kind');
        $this->assertSame(2, $map['descCol']);
        $this->assertNotEmpty($map['monthSpans']);
    }

}
