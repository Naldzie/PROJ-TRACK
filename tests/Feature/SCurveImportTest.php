<?php

namespace Tests\Feature;

use App\Services\SCurveImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * ============================================================================
 * SCurveImportTest
 * ============================================================================
 *
 * The contractor S-curve Excel importer (Phase 2) reads a
 * "CONSTRUCTION SCHEDULE AND S-CURVE" workbook and produces baseline months
 * (cumulative planned % per month_index 1..N) plus metadata.
 *
 * The fixtures here replicate the confirmed client layout (Layout B —
 * activity-weighted): a two-row header (ITEM / DESCRIPTION / CONTRACT /
 * % WEIGHT / Month 1..12), each month spanning two adjacent columns, and a
 * TOTAL row followed by a summary block.
 *
 * @see app/Services/SCurveImportService.php
 */
class SCurveImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A 3-month client-layout workbook with two activities + a TOTAL row.
     */
    protected function makeFixtureWorkbook(string $path): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('S-CURVE');

        // Metadata block (label in one cell, value in the next)
        $sheet->setCellValue('A1', 'CONSTRUCTION SCHEDULE AND S-CURVE');
        $sheet->setCellValue('A2', 'CONTRACT NAME:');
        $sheet->setCellValue('B2', 'COMPLETION OF A TEST BUILDING');
        $sheet->setCellValue('A3', 'LOCATION:');
        $sheet->setCellValue('B3', 'Somewhere in the Philippines');

        // Two-row header: months at I (Month 1), K (Month 2), M (Month 3)
        $sheet->setCellValue('A10', 'ITEM');
        $sheet->setCellValue('C10', 'DESCRIPTION');
        $sheet->setCellValue('F10', 'CONTRACT');
        $sheet->setCellValue('G10', '% WEIGHT');
        $sheet->setCellValue('I10', 'Month 1');
        $sheet->setCellValue('K10', 'Month 2');
        $sheet->setCellValue('M10', 'Month 3');
        $sheet->setCellValue('B11', '1');

        // Activity 1 — mobilization split between month 1 (odd cell) and
        // month 3 (even cell). Single-cell months are counted as-is.
        $sheet->setCellValue('B12', '1');
        $sheet->setCellValue('C12', 'Mobilization and Demobilization');
        $sheet->setCellValue('F12', 100000);
        $sheet->setCellValue('G12', 25);
        $sheet->setCellValue('I12', 50000);
        $sheet->setCellValue('N12', 50000);

        // Activity 2 — the client's duplicated-pair convention: the month's
        // peso is written TWICE (each cell = half), so the pair must be SUMMED.
        $sheet->setCellValue('B13', '2');
        $sheet->setCellValue('C13', 'Site Layout and Survey Works');
        $sheet->setCellValue('F13', 300000);
        $sheet->setCellValue('G13', 75);
        $sheet->setCellValue('I13', 150000);
        $sheet->setCellValue('J13', 150000);

        // TOTAL row (skipped as data, holds the contract total)
        $sheet->setCellValue('C14', 'TOTAL');
        $sheet->setCellValue('F14', 400000);
        $sheet->setCellValue('G14', 100);
        $sheet->setCellValue('I14', 200000);
        $sheet->setCellValue('J14', 150000);
        $sheet->setCellValue('N14', 50000);

        // Summary block (must be skipped)
        $sheet->setCellValue('C15', '% ACCOMPLISHED');
        $sheet->setCellValue('I15', 0.5);

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
    }

    public function test_parses_client_layout_into_baseline_months(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeFixtureWorkbook($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertCount(3, $result['months']);
        $this->assertSame(400000.0, $result['meta']['contractAmount']);
        $this->assertSame(100.0, $result['meta']['weightSum']);
        $this->assertSame(3, $result['meta']['monthCount']);
        $this->assertSame(2, $result['meta']['activityCount']);
        $this->assertSame([], $result['meta']['warnings']);

        // Month 1 = (50000 mobilization) + (150000 + 150000 site layout pair)
        //        = 350000 / 400000 = 87.5%
        $this->assertSame(1, $result['months'][0]['month_index']);
        $this->assertSame(87.5, $result['months'][0]['planned_progress_percent']);
        $this->assertSame('Mobilization and Demobilization, Site Layout and Survey Works', $result['months'][0]['description']);

        // Month 2 = no peso → cumulative unchanged at 87.5%
        $this->assertSame(2, $result['months'][1]['month_index']);
        $this->assertSame(87.5, $result['months'][1]['planned_progress_percent']);

        // Month 3 = +50000 → 100%; forced final month is 100%
        $this->assertSame(3, $result['months'][2]['month_index']);
        $this->assertSame(100.0, $result['months'][2]['planned_progress_percent']);

        $this->assertSame('COMPLETION OF A TEST BUILDING', $result['meta']['projectName']);
        $this->assertSame('Somewhere in the Philippines', $result['meta']['location']);

        unlink($path);
    }

    public function test_metadata_is_null_when_header_block_is_absent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('A10', 'ITEM');
        $spreadsheet->getActiveSheet()->setCellValue('C10', 'DESCRIPTION');
        $spreadsheet->getActiveSheet()->setCellValue('G10', '% WEIGHT');
        $spreadsheet->getActiveSheet()->setCellValue('I10', 'Month 1');
        $spreadsheet->getActiveSheet()->setCellValue('K10', 'Month 2');
        $spreadsheet->getActiveSheet()->setCellValue('B11', '1');
        $spreadsheet->getActiveSheet()->setCellValue('C11', 'Earthworks');
        $spreadsheet->getActiveSheet()->setCellValue('F11', 100);
        $spreadsheet->getActiveSheet()->setCellValue('G11', 100);
        $spreadsheet->getActiveSheet()->setCellValue('I11', 100);
        $spreadsheet->getActiveSheet()->setCellValue('C12', 'TOTAL');
        $spreadsheet->getActiveSheet()->setCellValue('F12', 100);
        $spreadsheet->getActiveSheet()->setCellValue('G12', 100);
        $spreadsheet->getActiveSheet()->setCellValue('I12', 100);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertSame(null, $result['meta']['projectName']);
        $this->assertSame(null, $result['meta']['location']);

        unlink($path);
    }

    public function test_throws_when_no_curve_table_is_found(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'NOT AN S-CURVE');
        (new Xlsx($spreadsheet))->save($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No S-curve table found');

        app(SCurveImportService::class)->parse($path);

        unlink($path);
    }

    public function test_throws_for_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The file could not be found');

        app(SCurveImportService::class)->parse('/nonexistent/path.xlsx');
    }

    public function test_throws_for_non_excel_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.bin';
        // Corrupt binary that no spreadsheet reader can identify.
        file_put_contents($path, random_bytes(2048));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a valid Excel workbook');

        app(SCurveImportService::class)->parse($path);

        unlink($path);
    }

    public function test_accepts_month_names_header(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'DESCRIPTION');
        $sheet->setCellValue('B10', 'JANUARY');
        $sheet->setCellValue('C10', 'FEBRUARY');
        $sheet->setCellValue('D10', 'MARCH');
        $sheet->setCellValue('E10', 'CONTRACT');
        $sheet->setCellValue('A11', 'Excavation Works');
        $sheet->setCellValue('B11', 30000);
        $sheet->setCellValue('C11', 30000);
        $sheet->setCellValue('A12', 'GRAND TOTAL');
        $sheet->setCellValue('E12', 60000);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertCount(3, $result['months']);
        $this->assertSame(60000.0, $result['meta']['contractAmount']);
        $this->assertSame(1, $result['meta']['activityCount']);
        $this->assertSame(50.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][1]['planned_progress_percent']);
        $this->assertSame([], $result['meta']['warnings']);

        unlink($path);
    }

    public function test_accepts_two_row_split_header(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // Row 1: "MONTH" alone; row 2: the month numbers. Merged → "MONTH 1..3".
        $sheet->setCellValue('A10', 'DESCRIPTION');
        $sheet->setCellValue('B10', 'MONTH');
        $sheet->setCellValue('C10', 'MONTH');
        $sheet->setCellValue('D10', 'MONTH');
        $sheet->setCellValue('E10', 'CONTRACT');
        $sheet->setCellValue('B11', '1');
        $sheet->setCellValue('C11', '2');
        $sheet->setCellValue('D11', '3');
        $sheet->setCellValue('A12', 'Excavation Works');
        $sheet->setCellValue('B12', 10000);
        $sheet->setCellValue('D12', 5000);
        $sheet->setCellValue('A13', 'GRAND TOTAL');
        $sheet->setCellValue('E13', 15000);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertCount(3, $result['months']);
        $this->assertSame(1, $result['months'][0]['month_index']);
        $this->assertSame(3, $result['months'][2]['month_index']);
        $this->assertSame(15000.0, $result['meta']['contractAmount']);
        $this->assertSame(1, $result['meta']['activityCount']);

        unlink($path);
    }

    public function test_weight_column_is_optional(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'ITEM');
        $sheet->setCellValue('B10', 'DESCRIPTION');
        $sheet->setCellValue('C10', 'CONTRACT');
        $sheet->setCellValue('D10', 'Month 1');
        $sheet->setCellValue('E10', 'Month 2');
        $sheet->setCellValue('F10', 'Month 3');
        $sheet->setCellValue('A11', '1');
        $sheet->setCellValue('B11', 'Signage');
        $sheet->setCellValue('C11', 50000);
        $sheet->setCellValue('D11', 25000);
        $sheet->setCellValue('E11', 25000);
        $sheet->setCellValue('B12', 'TOTAL');
        $sheet->setCellValue('C12', 50000);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertCount(3, $result['months']);
        $this->assertSame(0.0, $result['meta']['weightSum']);
        $this->assertSame(50000.0, $result['meta']['contractAmount']);
        $this->assertSame(50.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(1, $result['meta']['activityCount']);
        $this->assertSame([], $result['meta']['warnings']);

        unlink($path);
    }

    public function test_accepts_grand_total_row(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'DESCRIPTION');
        $sheet->setCellValue('B10', 'Month 1');
        $sheet->setCellValue('C10', 'Month 2');
        $sheet->setCellValue('D10', 'CONTRACT');
        $sheet->setCellValue('A11', 'Works');
        $sheet->setCellValue('B11', 20000);
        $sheet->setCellValue('C11', 30000);
        $sheet->setCellValue('A12', 'GRAND TOTAL');
        $sheet->setCellValue('D12', 50000);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertSame(50000.0, $result['meta']['contractAmount']);
        $this->assertSame(1, $result['meta']['activityCount']);
        $this->assertSame(40.0, $result['months'][0]['planned_progress_percent']);

        unlink($path);
    }

    public function test_accepts_bare_number_month_columns(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'DESCRIPTION');
        $sheet->setCellValue('B10', '1');
        $sheet->setCellValue('C10', '2');
        $sheet->setCellValue('D10', '3');
        $sheet->setCellValue('E10', 'CONTRACT');
        $sheet->setCellValue('A11', 'Excavation Works');
        $sheet->setCellValue('B11', 10000);
        $sheet->setCellValue('C11', 10000);
        $sheet->setCellValue('A12', 'GRAND TOTAL');
        $sheet->setCellValue('E12', 20000);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertCount(3, $result['months']);
        $this->assertSame(1, $result['months'][0]['month_index']);
        $this->assertSame(3, $result['months'][2]['month_index']);
        $this->assertSame(50.0, $result['months'][0]['planned_progress_percent']);

        unlink($path);
    }

    public function test_formula_month_cells_are_calculated(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'ITEM');
        $sheet->setCellValue('B10', 'DESCRIPTION');
        $sheet->setCellValue('C10', 'CONTRACT');
        $sheet->setCellValue('D10', 'Month 1');
        $sheet->setCellValue('E10', 'Month 2');
        $sheet->setCellValue('F10', 'Month 3');
        $sheet->setCellValue('A11', '1');
        $sheet->setCellValue('B11', 'Works');
        $sheet->setCellValue('C11', 50000);
        $sheet->setCellValue('D11', 30000);
        $sheet->setCellValue('H11', 20000);
        $sheet->setCellValue('E11', '=H11');
        $sheet->setCellValue('B12', 'GRAND TOTAL');
        $sheet->setCellValue('C12', 50000);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertSame(60.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][1]['planned_progress_percent']);

        unlink($path);
    }

    public function test_parse_endpoint_rejects_missing_source(): void
    {
        $this->actingAs($this->createAdminUser());

        $this->postJson(route('admin.projects.s-curve.parse'), [])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    /**
     * Step 2a: contractor weight-header synonyms ("WEIGHTED %", "ALLOCATION",
     * plus the pre-existing "WEIGHTAGE") all map the weight column — same curve.
     */
    public function test_accepts_weight_synonym_headers(): void
    {
        foreach (['WEIGHTED %', 'ALLOCATION', 'WEIGHTAGE'] as $weightHeader) {
            $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setCellValue('A10', 'DESCRIPTION');
            $sheet->setCellValue('B10', 'Month 1');
            $sheet->setCellValue('C10', 'Month 2');
            $sheet->setCellValue('D10', $weightHeader);
            $sheet->setCellValue('E10', 'CONTRACT');
            $sheet->setCellValue('A11', 'Excavation Works');
            $sheet->setCellValue('B11', 30000);
            $sheet->setCellValue('C11', 30000);
            $sheet->setCellValue('D11', 100);
            $sheet->setCellValue('E11', 60000);
            $sheet->setCellValue('A12', 'GRAND TOTAL');
            $sheet->setCellValue('E12', 60000);

            (new Xlsx($spreadsheet))->save($path);

            $result = app(SCurveImportService::class)->parse($path);

            $this->assertSame(100.0, $result['meta']['weightSum'], "weight header: {$weightHeader}");
            $this->assertSame(50.0, $result['months'][0]['planned_progress_percent'], "weight header: {$weightHeader}");
            $this->assertSame(100.0, $result['months'][1]['planned_progress_percent'], "weight header: {$weightHeader}");
            $this->assertSame([], $result['meta']['warnings'], "weight header: {$weightHeader}");

            unlink($path);
        }
    }

    /**
     * Step 2a: "PARTICULARS" description header (already supported — locked against
     * regression) parses with per-month activities intact.
     */
    public function test_accepts_particulars_description_header(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'ITEM');
        $sheet->setCellValue('C10', 'PARTICULARS');
        $sheet->setCellValue('F10', 'CONTRACT');
        $sheet->setCellValue('I10', 'Month 1');
        $sheet->setCellValue('K10', 'Month 2');
        $sheet->setCellValue('B11', '1');
        $sheet->setCellValue('C11', 'Embankment Works');
        $sheet->setCellValue('F11', 60000);
        $sheet->setCellValue('I11', 30000);
        $sheet->setCellValue('K11', 30000);
        $sheet->setCellValue('C12', 'GRAND TOTAL');
        $sheet->setCellValue('F12', 60000);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertSame(50.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][1]['planned_progress_percent']);
        $this->assertSame(1, $result['meta']['activityCount']);
        $this->assertSame('Embankment Works', $result['months'][0]['activities'][0]['activity_name']);
        $this->assertSame('1', $result['months'][0]['activities'][0]['description']);

        unlink($path);
    }

    /**
     * Step 2a: a "PLANNED CUMULATIVE %" summary row is detected (meta cumulativeRow)
     * without moving the emitted curve — the Step 2b reader consumes the flag later.
     */
    public function test_detects_cumulative_summary_row_without_moving_curve(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'DESCRIPTION');
        $sheet->setCellValue('B10', 'Month 1');
        $sheet->setCellValue('C10', 'Month 2');
        $sheet->setCellValue('E10', 'CONTRACT');
        $sheet->setCellValue('A11', 'Works');
        $sheet->setCellValue('B11', 30000);
        $sheet->setCellValue('C11', 30000);
        $sheet->setCellValue('E11', 60000);
        $sheet->setCellValue('A12', 'GRAND TOTAL');
        $sheet->setCellValue('E12', 60000);
        $sheet->setCellValue('A13', 'PLANNED CUMULATIVE %');
        $sheet->setCellValue('B13', 50);
        $sheet->setCellValue('C13', 100);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertSame(13, $result['meta']['cumulativeRow']);
        $this->assertSame(50.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][1]['planned_progress_percent']);
        $this->assertSame(1, $result['meta']['activityCount']);

        unlink($path);
    }

    /**
     * R2a: orphan subtotal rows — a labeled formula row (months, no contract) followed
     * by a desc-less subtotal row (contract, no months) attributes to the running total.
     */
    public function test_orphan_subtotal_attributes_to_preceding_activity(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'DESCRIPTION');
        $sheet->setCellValue('B10', 'Month 1');
        $sheet->setCellValue('C10', 'Month 2');
        $sheet->setCellValue('D10', 'CONTRACT');
        $sheet->setCellValue('A11', 'Section Works');
        $sheet->setCellValue('B11', 30000);
        $sheet->setCellValue('C11', 30000);
        $sheet->setCellValue('D12', 60000);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertSame(60000.0, $result['meta']['contractAmount']);
        $this->assertSame(50.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][1]['planned_progress_percent']);
        $this->assertSame(1, $result['meta']['activityCount']);

        unlink($path);
    }

    /**
     * R2a: TOTAL label outside the description column still feeds the contract
     * fallback (roof row 31 shape) — and only when the row carries a contract amount.
     */
    public function test_total_label_outside_description_column_feeds_fallback(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'DESCRIPTION');
        $sheet->setCellValue('B10', 'Month 1');
        $sheet->setCellValue('C10', 'Month 2');
        $sheet->setCellValue('D10', 'CONTRACT');
        $sheet->setCellValue('A11', 'Works');
        $sheet->setCellValue('B11', 30000);
        $sheet->setCellValue('C11', 30000);
        $sheet->setCellValue('D12', 60000);
        $sheet->setCellValue('E12', 'TOTAL');

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertSame(60000.0, $result['meta']['contractAmount']);
        $this->assertSame(50.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][1]['planned_progress_percent']);

        unlink($path);
    }
    public function test_parses_roof_family_layout_with_ordinal_months(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // Row 10: group labels + typo description header + contract header.
        $sheet->setCellValue('A10', 'ITEM NO.');
        $sheet->setCellValue('B10', 'DESCRITPION');
        $sheet->setCellValue('F10', 'Unit Cost/Contract Amount');
        $sheet->setCellValue('G10', '60 days');
        $sheet->setCellValue('I10', '7 days');
        // Row 11: ordinal month identities where row 10 is empty.
        $sheet->setCellValue('K11', '1st.');
        $sheet->setCellValue('L11', '2nd.');
        $sheet->setCellValue('M11', '3rd.');
        // One group-level activity (LOT quantity, peso series, contract in F).
        $sheet->setCellValue('A12', '1');
        $sheet->setCellValue('B12', 'Roofing Works');
        $sheet->setCellValue('F12', 187000);
        $sheet->setCellValue('K12', 80000);
        $sheet->setCellValue('L12', 80000);
        $sheet->setCellValue('M12', 27000);
        // TOTAL row.
        $sheet->setCellValue('B13', 'TOTAL');
        $sheet->setCellValue('F13', 187000);
        // Summary block: percentages, cash flow pesos, typo cumulative, durations.
        $sheet->setCellValue('B14', '%');
        $sheet->setCellValue('K14', 42.78);
        $sheet->setCellValue('B15', 'Cash Flow');
        $sheet->setCellValue('K15', 80000);
        $sheet->setCellValue('L15', 80000);
        $sheet->setCellValue('M15', 27000);
        $sheet->setCellValue('B16', 'Commulative %');
        $sheet->setCellValue('B17', 'Project Duration');

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        // Activity-derived curve only: 80000/187000, 160000/187000, 100 forced.
        $this->assertCount(3, $result['months']);
        $this->assertSame(42.78, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(85.56, $result['months'][1]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][2]['planned_progress_percent']);
        $this->assertSame(187000.0, $result['meta']['contractAmount']);
        $this->assertSame(1, $result['meta']['activityCount']);
        $this->assertSame('Roofing Works', $result['months'][0]['activities'][0]['activity_name']);

        unlink($path);
    }

    /**
     * R2a: fuzzy matching stays tight — an unknown header layout still 422s instead
     * of hallucinating columns (NO. OF DAYS is not an item/description/month label).
     */
    public function test_unknown_headers_still_reject_instead_of_hallucinating(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'NO. OF DAYS');
        $sheet->setCellValue('B10', 'RANDOM WORDS HERE');
        $sheet->setCellValue('A11', 'Works');
        $sheet->setCellValue('B11', 100);

        (new Xlsx($spreadsheet))->save($path);

        try {
            app(SCurveImportService::class)->parse($path);
            $this->fail('Expected a RuntimeException for the unknown header layout.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No S-curve table found', $e->getMessage());
        }

        unlink($path);
    }

    /**
     * R2b (reference + warn): the author's cumulative row is read as reference points —
     * fractions scale to percent, values attribute to the interval's last month, gaps past
     * 1.0 warn WITHOUT moving the plan curve. Gap of exactly 1.0 stays silent (boundary).
     */
    public function test_summary_reference_warns_only_past_threshold(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'DESCRIPTION');
        $sheet->setCellValue('B10', 'Month 1');
        $sheet->setCellValue('C10', 'Month 2');
        $sheet->setCellValue('E10', 'CONTRACT');
        $sheet->setCellValue('A11', 'Works');
        $sheet->setCellValue('B11', 30000);
        $sheet->setCellValue('C11', 30000);
        $sheet->setCellValue('E11', 60000);
        $sheet->setCellValue('A12', 'CUMULATIVE % DONE');
        $sheet->setCellValue('B12', 0.49);
        $sheet->setCellValue('C12', 90);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        // Plan curve untouched by the reference read.
        $this->assertSame(50.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][1]['planned_progress_percent']);

        $ref = $result['meta']['summaryReference'];
        $this->assertCount(2, $ref);
        $this->assertSame(['month' => 1, 'summary_percent' => 49.0, 'activity_percent' => 50.0, 'gap' => 1.0], $ref[0]);
        $this->assertSame(['month' => 2, 'summary_percent' => 90.0, 'activity_percent' => 100.0, 'gap' => 10.0], $ref[1]);

        $warnings = implode("\n", $result['meta']['warnings']);
        $this->assertStringNotContainsString('Month 1', $warnings);
        $this->assertStringContainsString('Heads-up (Month 2)', $warnings);
        $this->assertStringContainsString('no action needed', $warnings);

        unlink($path);
    }

    /**
     * R2b: files without a cumulative row report an empty reference and no new warnings.
     */
    public function test_no_summary_row_means_empty_reference(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'DESCRIPTION');
        $sheet->setCellValue('B10', 'Month 1');
        $sheet->setCellValue('C10', 'Month 2');
        $sheet->setCellValue('E10', 'CONTRACT');
        $sheet->setCellValue('A11', 'Works');
        $sheet->setCellValue('B11', 30000);
        $sheet->setCellValue('C11', 30000);
        $sheet->setCellValue('E11', 60000);
        $sheet->setCellValue('A12', 'GRAND TOTAL');
        $sheet->setCellValue('E12', 60000);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertSame([], $result['meta']['summaryReference']);
        $this->assertSame([], $result['meta']['warnings']);
        $this->assertSame(50.0, $result['months'][0]['planned_progress_percent']);

        unlink($path);
    }

    /**
     * R2b-GRID: cumulative values in a parallel display grid (outside month spans)
     * pair positionally ONLY on exact count match — curve still untouched.
     */
    public function test_display_grid_values_pair_positionally_on_exact_count(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'DESCRIPTION');
        $sheet->setCellValue('B10', 'Month 1');
        $sheet->setCellValue('C10', 'Month 2');
        $sheet->setCellValue('E10', 'CONTRACT');
        $sheet->setCellValue('A11', 'Works');
        $sheet->setCellValue('B11', 30000);
        $sheet->setCellValue('C11', 30000);
        $sheet->setCellValue('E11', 60000);
        $sheet->setCellValue('A12', 'CUMULATIVE % DONE');
        $sheet->setCellValue('F12', 48.5);
        $sheet->setCellValue('G12', 99.5);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        // Plan curve untouched; reference paired m1/m2 in order; only m1 warns.
        $this->assertSame(50.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][1]['planned_progress_percent']);
        $ref = $result['meta']['summaryReference'];
        $this->assertCount(2, $ref);
        $this->assertSame(['month' => 1, 'summary_percent' => 48.5, 'activity_percent' => 50.0, 'gap' => 1.5], $ref[0]);
        $this->assertSame(['month' => 2, 'summary_percent' => 99.5, 'activity_percent' => 100.0, 'gap' => 0.5], $ref[1]);
        $warnings = implode("\n", $result['meta']['warnings']);
        $this->assertStringContainsString('Heads-up (Month 1)', $warnings);
        $this->assertStringNotContainsString('Month 2', $warnings);

        unlink($path);
    }

    /**
     * R2b fallback: valueless activity rows + valued summary (≥2 points) adopts the
     * summary as the curve with provenance — the client-practice case.
     */
    public function test_summary_fallback_adopts_when_activity_math_absent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'DESCRIPTION');
        $sheet->setCellValue('B10', 'Month 1');
        $sheet->setCellValue('C10', 'Month 2');
        $sheet->setCellValue('E10', 'CONTRACT');
        $sheet->setCellValue('A11', 'Section Works');
        $sheet->setCellValue('E11', 60000);
        $sheet->setCellValue('A12', 'CUMULATIVE % DONE');
        $sheet->setCellValue('B12', 20);
        $sheet->setCellValue('C12', 80);

        (new Xlsx($spreadsheet))->save($path);

        $result = app(SCurveImportService::class)->parse($path);

        $this->assertSame('summary', $result['meta']['curveSource']);
        $this->assertSame(20.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][1]['planned_progress_percent']);
        $this->assertNull($result['meta']['summaryReference'][0]['activity_percent']);
        $this->assertNull($result['meta']['summaryReference'][0]['gap']);
        $warnings = implode("\n", $result['meta']['warnings']);
        $this->assertStringContainsString('built from the file', $warnings);
        $this->assertStringContainsString('source: summary', $warnings);

        unlink($path);
    }

    /**
     * R2b fallback guards: a single summary point is not a curve (no adoption), and
     * scrambled summary values raise the jumble flag on adoption.
     */
    public function test_summary_fallback_needs_two_points_and_flags_jumble(): void
    {
        // Single point → honest empty curve, activities provenance, no adopt warning.
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'DESCRIPTION');
        $sheet->setCellValue('B10', 'Month 1');
        $sheet->setCellValue('C10', 'Month 2');
        $sheet->setCellValue('E10', 'CONTRACT');
        $sheet->setCellValue('A11', 'Section Works');
        $sheet->setCellValue('E11', 60000);
        $sheet->setCellValue('A12', 'CUMULATIVE % DONE');
        $sheet->setCellValue('B12', 20);

        (new Xlsx($spreadsheet))->save($path);
        $result = app(SCurveImportService::class)->parse($path);

        $this->assertSame('activities', $result['meta']['curveSource']);
        $this->assertSame(0.0, $result['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result['months'][1]['planned_progress_percent']);
        $this->assertStringNotContainsString(
            'built from the file',
            implode("\n", $result['meta']['warnings'])
        );
        unlink($path);

        // Scrambled summary → adopted with clamp + jumble flag.
        $path2 = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $spreadsheet2 = new Spreadsheet();
        $sheet2 = $spreadsheet2->getActiveSheet();
        $sheet2->setCellValue('A10', 'DESCRIPTION');
        $sheet2->setCellValue('B10', 'Month 1');
        $sheet2->setCellValue('C10', 'Month 2');
        $sheet2->setCellValue('E10', 'CONTRACT');
        $sheet2->setCellValue('A11', 'Section Works');
        $sheet2->setCellValue('E11', 60000);
        $sheet2->setCellValue('A12', 'CUMULATIVE % DONE');
        $sheet2->setCellValue('B12', 80);
        $sheet2->setCellValue('C12', 20);

        (new Xlsx($spreadsheet2))->save($path2);
        $result2 = app(SCurveImportService::class)->parse($path2);

        $this->assertSame('summary', $result2['meta']['curveSource']);
        $this->assertSame(80.0, $result2['months'][0]['planned_progress_percent']);
        $this->assertSame(100.0, $result2['months'][1]['planned_progress_percent']);
        $this->assertStringContainsString(
            'not in cumulative order',
            implode("\n", $result2['meta']['warnings'])
        );
        unlink($path2);
    }

    protected function createAdminUser()
    {
        // User::create (not the factory) — the users table has no
        // email_verified_at column in this app's migrations.
        return \App\Models\User::create([
            'name' => 'SCurve Admin',
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    /**
     * E2 endpoint: file-upload success returns AI months with the assisted flag.
     */
    public function test_parse_gemini_accepts_file_upload(): void
    {
        config(['services.gemini.key' => 'test-key']);
        \Illuminate\Support\Facades\Http::fake([
            '*generativelanguage*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'months' => [
                        ['month_index' => 1, 'planned_progress_percent' => 40],
                        ['month_index' => 2, 'planned_progress_percent' => 90],
                    ],
                    'contractAmount' => 60000,
                ])]]]]],
            ], 200),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeFixtureWorkbook($path);
        $upload = new \Illuminate\Http\UploadedFile($path, 'curve.xlsx', null, null, true);

        $response = $this->actingAs($this->createAdminUser())
            ->postJson(route('admin.projects.s-curve.parse-gemini'), ['file' => $upload]);

        $response->assertOk()->assertJsonPath('success', true);
        $response->assertJsonPath('months.0.month_index', 1);
        $response->assertJsonPath('months.1.planned_progress_percent', 100);
        $response->assertJsonPath('meta.aiAssisted', true);

        unlink($path);
    }

    /**
     * E2 endpoint: sustained AI overload surfaces the busy message (not generic).
     */
    public function test_parse_gemini_sustained_overload_speaks_busy(): void
    {
        config(['services.gemini.key' => 'test-key']);
        \Illuminate\Support\Facades\Http::fake([
            '*generativelanguage*' => \Illuminate\Support\Facades\Http::response('overloaded', 503, ['Retry-After' => 0]),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeFixtureWorkbook($path);
        $upload = new \Illuminate\Http\UploadedFile($path, 'curve.xlsx', null, null, true);

        $this->actingAs($this->createAdminUser())
            ->postJson(route('admin.projects.s-curve.parse-gemini'), ['file' => $upload])
            ->assertStatus(422)->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The AI service is busy (overloaded). Please wait a minute and try again.');

        unlink($path);
    }

    /**
     * E4b endpoint: when the parser ladder rescues an Excel read, the response
     * SAYS SO. Without this field the client cannot warn, and an answer from a
     * weaker model would land in a baseline silently — the one thing this whole
     * track refuses to do. Mirrors the PDF endpoint's fallbackModel.
     */
    public function test_parse_gemini_reports_the_fallback_model(): void
    {
        config([
            'services.gemini.key' => 'main-key',
            'services.gemini.model' => 'lite-model',
            'services.gemini.parser_key' => 'parser-key',
            'services.gemini.parser_model' => 'flash-model',
            'services.gemini.parser_fallback_models' => 'older-flash',
        ]);

        // One fake for the whole test: primary + retry fail, the rung answers.
        \Illuminate\Support\Facades\Http::fake([
            '*generativelanguage*' => \Illuminate\Support\Facades\Http::sequence()
                ->push('slow down', 429, ['Retry-After' => 0])
                ->push('slow down', 429, ['Retry-After' => 0])
                ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'months' => [
                        ['month_index' => 1, 'planned_progress_percent' => 40],
                        ['month_index' => 2, 'planned_progress_percent' => 90],
                    ],
                    'hasSummaryRow' => false,
                    'contractAmount' => 60000,
                ])]]]]]], 200),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeFixtureWorkbook($path);
        $upload = new \Illuminate\Http\UploadedFile($path, 'curve.xlsx', null, null, true);

        $this->actingAs($this->createAdminUser())
            ->postJson(route('admin.projects.s-curve.parse-gemini'), ['file' => $upload])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('fallbackModel', 'older-flash');

        unlink($path);
    }

    /**
     * E4b endpoint: a normal Excel read reports NO fallback, so the client's
     * warning stays silent when nothing went wrong.
     */
    public function test_parse_gemini_reports_no_fallback_on_a_clean_read(): void
    {
        config(['services.gemini.key' => 'test-key']);
        \Illuminate\Support\Facades\Http::fake([
            '*generativelanguage*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'months' => [
                        ['month_index' => 1, 'planned_progress_percent' => 40],
                        ['month_index' => 2, 'planned_progress_percent' => 90],
                    ],
                    'contractAmount' => 60000,
                ])]]]]],
            ], 200),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeFixtureWorkbook($path);
        $upload = new \Illuminate\Http\UploadedFile($path, 'curve.xlsx', null, null, true);

        $this->actingAs($this->createAdminUser())
            ->postJson(route('admin.projects.s-curve.parse-gemini'), ['file' => $upload])
            ->assertOk()
            ->assertJsonPath('fallbackModel', null);

        unlink($path);
    }

    /**
     * E2 endpoint: AI miss, missing source, PDFs, and contractors all decline.
     */
    public function test_parse_gemini_decline_paths(): void
    {
        config(['services.gemini.key' => 'test-key']);

        // AI miss → honest 422.
        \Illuminate\Support\Facades\Http::fake([
            '*generativelanguage*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'no json']]]]],
            ], 200),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeFixtureWorkbook($path);
        $upload = new \Illuminate\Http\UploadedFile($path, 'curve.xlsx', null, null, true);

        $this->actingAs($this->createAdminUser())
            ->postJson(route('admin.projects.s-curve.parse-gemini'), ['file' => $upload])
            ->assertStatus(422)->assertJsonPath('success', false);

        // Missing source → 422.
        $this->actingAs($this->createAdminUser())
            ->postJson(route('admin.projects.s-curve.parse-gemini'), [])
            ->assertStatus(422)->assertJsonPath('success', false);

        // PDF upload → 422 (no server renderer by design).
        $pdf = \Illuminate\Http\UploadedFile::fake()->create('curve.pdf', 100, 'application/pdf');
        $this->actingAs($this->createAdminUser())
            ->post(route('admin.projects.s-curve.parse-gemini'), ['file' => $pdf])
            ->assertStatus(422)->assertJsonPath('success', false);

        // No contractor route exists at all → client error, never reachable.
        $contractor = \App\Models\User::create([
            'name' => 'SCurve Contractor',
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'role' => 'contractor',
            'is_active' => true,
        ]);
        $this->actingAs($contractor)
            ->postJson('/contractor/s-curve/parse-gemini', ['document_id' => 1])
            ->assertClientError();

        unlink($path);
    }

    /**
     * E2 endpoint: stored Excel documents resolve via document_id.
     */
    public function test_parse_gemini_accepts_document_id(): void
    {
        config(['services.gemini.key' => 'test-key']);
        \Illuminate\Support\Facades\Http::fake([
            '*generativelanguage*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'months' => [
                        ['month_index' => 1, 'planned_progress_percent' => 50],
                        ['month_index' => 2, 'planned_progress_percent' => 100],
                    ],
                ])]]]]],
            ], 200),
        ]);
        \Illuminate\Support\Facades\Storage::fake('public');

        $admin = $this->createAdminUser();
        $staff = \App\Models\User::create([
            'name' => 'SCurve Staff',
            'email' => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'role' => 'staff',
            'is_active' => true,
        ]);
        $project = \App\Models\Project::factory()->create();
        // The migration seeds a 'Schedule' type — reuse it instead of colliding.
        $type = \App\Models\DocumentType::firstOrCreate(['name' => 'Schedule']);

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeFixtureWorkbook($path);
        \Illuminate\Support\Facades\Storage::disk('public')->put(
            'ai/curve.xlsx',
            file_get_contents($path)
        );

        $doc = \App\Models\Document::create([
            'project_id' => $project->id,
            'document_type_id' => $type->id,
            'document_name' => 'S-curve',
            'file_path' => 'ai/curve.xlsx',
            'uploaded_by' => $admin->id,
        ]);

        $this->actingAs($staff)
            ->postJson(route('staff.projects.s-curve.parse-gemini'), ['document_id' => $doc->id])
            ->assertOk()->assertJsonPath('success', true);

        $this->actingAs($staff)
            ->postJson(route('staff.projects.s-curve.parse-gemini'), ['document_id' => 999999])
            ->assertStatus(422)->assertJsonPath('success', false);

        unlink($path);
    }

    /**
     * E2 serializer: header block + data rows as compact CSV text (never the workbook).
     */
    public function test_serialize_for_ai_emits_compact_csv_text(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A10', 'DESCRIPTION');
        $sheet->setCellValue('B10', 'Month 1');
        $sheet->setCellValue('A11', 'Works');
        $sheet->setCellValue('B11', 30000);

        (new Xlsx($spreadsheet))->save($path);

        $csv = app(SCurveImportService::class)->serializeForAi($path);

        $this->assertStringContainsString('DESCRIPTION', $csv);
        $this->assertStringContainsString('30000', $csv);
        // Compact: capped rows, no raw workbook bytes.
        $this->assertLessThanOrEqual(41, count(explode("\n", trim($csv))));

        unlink($path);
    }

    /**
     * 2026-09-14 — A SUMMARY ROW SURVIVES THE ROW BUDGET.
     *
     * The budget buys the head of the table; the summary block is kept wherever it
     * fell. Without this the reviewer never sees the cumulative row a baseline is
     * adopted from, and the AI slice never sees the totals its own prompt tells it
     * not to copy — on BUHI and the roof workbook the whole summary block sat past
     * the cap and was cut off. Mirrors the PDF builders, which have done this since
     * the teach-grid work.
     */
    public function test_summary_rows_survive_the_row_budget(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'DESCRIPTION');
        $sheet->setCellValue('B1', 'Month 1');
        $sheet->setCellValue('C1', 'Month 2');
        // Twelve activity rows — comfortably past a budget of 5.
        for ($i = 0; $i < 12; $i++) {
            $r = $i + 2;
            $sheet->setCellValue('A' . $r, 'Activity ' . ($i + 1));
            $sheet->setCellValue('B' . $r, 1000);
            $sheet->setCellValue('C' . $r, 2000);
        }
        $sheet->setCellValue('A14', 'TOTAL');
        $sheet->setCellValue('B14', 12000);
        $sheet->setCellValue('A15', 'CUMULATIVE % ACHIEVED');
        $sheet->setCellValue('B15', 50);
        $sheet->setCellValue('C15', 100);
        (new Xlsx($spreadsheet))->save($path);

        $csv = app(SCurveImportService::class)->serializeForAi($path, 5);
        $rows = array_values(array_filter(explode("\n", $csv), fn ($l) => preg_match('/^R\d+ \[/', $l)));

        // The budget still bounds the HEAD: five rows, then the summary block.
        $this->assertCount(7, $rows, "budget of 5 plus 2 summary rows:\n" . implode("\n", $rows));
        $this->assertStringContainsString('TOTAL', $rows[5]);
        $this->assertStringContainsString('CUMULATIVE % ACHIEVED', $rows[6]);
        // Purely additive — the head is untouched and R-numbers do not shift, which
        // matters because keptRowKeys resolves an AI-reported R# back to a sheet row.
        $this->assertStringStartsWith('R1 ', $rows[0]);
        $this->assertStringContainsString('DESCRIPTION', $rows[0]);
        $this->assertStringStartsWith('R5 ', $rows[4]);
        $this->assertStringContainsString('Activity 4', $rows[4]);

        unlink($path);
    }

    /**
     * E2 serializer: missing files throw honestly.
     */
    public function test_serialize_for_ai_rejects_missing_source(): void
    {
        $this->expectException(RuntimeException::class);
        app(SCurveImportService::class)->serializeForAi('/nonexistent/path.xlsx');
    }

    /**
     * Slice reform: rows carry detector roles + a column legend (the flat CSV
     * made copying the summary the rational move — roles remove the excuse).
     */
    public function test_serialize_for_ai_labels_roles_and_columns(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeFixtureWorkbook($path);

        $csv = app(SCurveImportService::class)->serializeForAi($path);

        $this->assertStringContainsString('COLUMNS:', $csv);
        $this->assertStringContainsString('[HEADER]', $csv);
        $this->assertStringContainsString('[DATA]', $csv);
        $this->assertStringContainsString('[SUMMARY]', $csv);
        // 2026-09-14: CUMULATIVE split out of SUMMARY. The legend must define it
        // too — the PDF path emitted this tag for a round while its own legend
        // still listed four roles, so the AI met an undefined vocabulary word.
        $this->assertStringContainsString('[CUMULATIVE]', $csv);
        $this->assertStringContainsString('CUMULATIVE: the printed running curve', $csv);
        $this->assertStringContainsString('DESCRIPTION', $csv);

        unlink($path);
    }

    /**
     * Role placement (off-by-one fix 2026-09-10): tags must sit on the RIGHT
     * rows — the serializer once read a numeric-keyed grid (0-based) against
     * letter-keyed roles (1-based sheet rows), so every line wore the previous
     * row's role (header labeled META, first data row labeled HEADER). Fixture
     * rows: A1-A3 metadata, row 10 header, row 11 code-only, 12-13 activities,
     * 14 TOTAL, 15 summary.
     *
     * 2026-09-14: R9 reads "% ACCOMPLISHED", which isCumulativeRow matches, so it
     * now tags CUMULATIVE while R8's plain TOTAL stays SUMMARY. Keeping both
     * assertions is the point — they prove the branch splits on the label, not on
     * position within the summary block.
     */
    public function test_serialize_for_ai_places_roles_on_correct_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeFixtureWorkbook($path);

        $csv = app(SCurveImportService::class)->serializeForAi($path);
        $tags = [];
        foreach (explode("\n", $csv) as $line) {
            if (preg_match('/^(R\d+) \[([A-Z]+)\]:/', trim($line), $m)) {
                $tags[$m[1]] = $m[2];
            }
        }

        $this->assertSame('META', $tags['R1'] ?? null);
        $this->assertSame('HEADER', $tags['R4'] ?? null);
        $this->assertSame('DATA', $tags['R6'] ?? null);
        $this->assertSame('SUMMARY', $tags['R8'] ?? null);
        $this->assertSame('CUMULATIVE', $tags['R9'] ?? null);

        unlink($path);
    }

    /**
     * 2026-09-14 — the CUMULATIVE tag must survive the row budget.
     *
     * The budget exemption and the tag were built in separate steps, and the
     * failure they combine to prevent is invisible: a file long enough to hit the
     * 15-row cap loses its trailing rows, so the one row carrying the printed
     * curve drops out of the block and the reviewer never sees it — the block
     * simply looks like it has no cumulative row rather than like it is truncated.
     *
     * 20 activity rows guarantee the cap is hit with the curve row last.
     */
    public function test_cumulative_row_survives_the_row_budget(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('C1', 'CONSTRUCTION SCHEDULE');
        $sheet->setCellValue('C3', 'DESCRIPTION');
        $sheet->setCellValue('F3', 'AMOUNT');
        $sheet->setCellValue('G3', '% WEIGHT');
        $sheet->setCellValue('I3', 'Month 1');
        $sheet->setCellValue('K3', 'Month 2');
        for ($i = 0; $i < 20; $i++) {
            $r = 4 + $i;
            $sheet->setCellValue('C' . $r, 'Activity Number ' . ($i + 1));
            $sheet->setCellValue('F' . $r, 50000);
            $sheet->setCellValue('G' . $r, 5);
            $sheet->setCellValue('I' . $r, 50000);
        }
        $sheet->setCellValue('C24', 'TOTAL');
        $sheet->setCellValue('F24', 1000000);
        $sheet->setCellValue('C25', 'CUMULATIVE % ACHIEVED');
        $sheet->setCellValue('I25', 50);
        $sheet->setCellValue('K25', 100);
        (new Xlsx($spreadsheet))->save($path);

        $csv = app(SCurveImportService::class)->serializeForAi($path, 15);

        $tags = [];
        foreach (explode("\n", $csv) as $line) {
            if (preg_match('/^R\d+ \[([A-Z]+)\]: (.*)$/', trim($line), $m)) {
                $tags[] = $m[1];
            }
        }
        // Proof the cap really bit — otherwise the exemption is untested.
        $this->assertLessThan(24, count($tags), 'the 15-row budget must actually truncate here');
        $this->assertContains('CUMULATIVE', $tags, 'the printed curve must outrank the budget');
        $this->assertContains('SUMMARY', $tags, 'so must the TOTAL row');
        $this->assertStringContainsString('CUMULATIVE % ACHIEVED', $csv);

        unlink($path);
    }

    /**
     * Near-miss workbook for prefer-math tests: novel "TASK DETAILS" header
     * (rules miss) with standard months; month weights 50/50 of 400,000.
     */
    protected function makePreferMathWorkbook(string $path): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('C10', 'TASK DETAILS');
        $sheet->setCellValue('F10', 'CONTRACT AMOUNT');
        $sheet->setCellValue('I10', 'Month 1');
        $sheet->setCellValue('K10', 'Month 2');

        $sheet->setCellValue('C12', 'Ground Works');
        $sheet->setCellValue('F12', 400000);
        // Paired-month convention: each month's peso written twice (half per
        // cell), so M1 = I+J = 200000 (50%), M2 = K+L = 200000 (100%).
        $sheet->setCellValue('I12', 100000);
        $sheet->setCellValue('J12', 100000);
        $sheet->setCellValue('K12', 100000);
        $sheet->setCellValue('L12', 100000);

        $sheet->setCellValue('C13', 'TOTAL');
        $sheet->setCellValue('F13', 400000);

        (new Xlsx($spreadsheet))->save($path);
    }

    protected function storeLiveGlobalMap(array $columnMap): void
    {
        \App\Models\ScurveHeaderProposal::create([
            'contractor_id' => null,
            'header_rows_text' => 'prefer-math',
            'header_hash' => hash('sha256', uniqid()),
            'column_map' => $columnMap,
            'status' => 'live',
        ]);
    }

    protected function fakeAiMonths(array $months): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*generativelanguage*' => \Illuminate\Support\Facades\Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'months' => $months,
                ])]]]]],
            ], 200),
        ]);
    }

    /**
     * Prefer-math (user choice 2026-09-09): AI months matching the file's
     * summary are REPLACED with map activity math plus a swap note.
     */
    public function test_parse_gemini_prefers_map_math_on_divergence(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makePreferMathWorkbook($path);
        $map = app(SCurveImportService::class)->deriveAutoColumnMap($path);
        $this->assertIsArray($map);
        $this->storeLiveGlobalMap($map);

        // Map math reads 50/100; the AI parrots a summary-like 45/100.
        $this->fakeAiMonths([
            ['month_index' => 1, 'planned_progress_percent' => 45],
            ['month_index' => 2, 'planned_progress_percent' => 100],
        ]);
        $upload = new \Illuminate\Http\UploadedFile($path, 'curve.xlsx', null, null, true);

        $response = $this->actingAs($this->createAdminUser())
            ->postJson(route('admin.projects.s-curve.parse-gemini'), ['file' => $upload]);

        $response->assertOk()->assertJsonPath('success', true);
        $response->assertJsonPath('months.0.planned_progress_percent', 50);
        $response->assertJsonPath('months.1.planned_progress_percent', 100);
        $response->assertJsonPath('meta.mapPreferred', true);
        $response->assertJsonPath('meta.aiAssisted', true);

        unlink($path);
    }

    /**
     * Prefer-math leaves agreeing AI results untouched (no swap, no note).
     */
    public function test_parse_gemini_keeps_ai_months_when_map_agrees(): void
    {
        config(['services.gemini.key' => 'test-key']);

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makePreferMathWorkbook($path);
        $map = app(SCurveImportService::class)->deriveAutoColumnMap($path);
        $this->assertIsArray($map);
        $this->storeLiveGlobalMap($map);

        $this->fakeAiMonths([
            ['month_index' => 1, 'planned_progress_percent' => 50],
            ['month_index' => 2, 'planned_progress_percent' => 100],
        ]);
        $upload = new \Illuminate\Http\UploadedFile($path, 'curve.xlsx', null, null, true);

        $response = $this->actingAs($this->createAdminUser())
            ->postJson(route('admin.projects.s-curve.parse-gemini'), ['file' => $upload]);

        $response->assertOk()->assertJsonPath('success', true);
        $response->assertJsonPath('months.0.planned_progress_percent', 50);
        $response->assertJsonMissingPath('meta.mapPreferred');

        unlink($path);
    }

    /**
     * Inline workbook part: small files ride along (OLE sniff → legacy MIME),
     * oversize files fall back to null (CSV-only path with a note).
     */
    public function test_build_inline_workbook_part_respects_ceiling(): void
    {
        $controller = new \App\Http\Controllers\SCurveImportController(
            app(SCurveImportService::class),
            app(\App\Services\AI\GeminiDocumentParserService::class)
        );
        $method = new \ReflectionMethod($controller, 'buildInlineWorkbookPart');

        $path = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $this->makeFixtureWorkbook($path);
        $part = $method->invoke($controller, $path);
        $this->assertIsArray($part);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $part['mimeType']
        );
        $this->assertSame(file_get_contents($path), base64_decode($part['data']));
        unlink($path);

        // Legacy OLE magic sniffs to the ms-excel MIME.
        $ole = tempnam(sys_get_temp_dir(), 'scurve_') . '.xls';
        file_put_contents($ole, "\xD0\xCF\x11\xE0" . str_repeat("\x00", 100));
        $olePart = $method->invoke($controller, $ole);
        $this->assertSame('application/vnd.ms-excel', $olePart['mimeType']);
        unlink($ole);

        // Past the ceiling → null (caller falls back to CSV-only).
        $big = tempnam(sys_get_temp_dir(), 'scurve_') . '.xlsx';
        $handle = fopen($big, 'wb');
        fseek($handle, \App\Http\Controllers\SCurveImportController::FILE_INLINE_MAX_BYTES);
        fwrite($handle, 'x');
        fclose($handle);
        $this->assertNull($method->invoke($controller, $big));
        unlink($big);

        $this->assertNull($method->invoke($controller, '/nonexistent/path.xlsx'));
    }
}
