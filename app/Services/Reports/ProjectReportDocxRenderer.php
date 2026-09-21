<?php

namespace App\Services\Reports;

use PhpOffice\PhpWord\Element\Footer;
use PhpOffice\PhpWord\Element\Header;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\ListItem;

/**
 * ============================================================================
 * ProjectReportDocxRenderer
 * ============================================================================
 *
 * Purpose
 * -------
 * Lays out a ProjectReportDataService report as an editable Word document
 * (.docx) — the consultant's recommendation: the PMU can adjust the text in
 * Word before printing or sending it.
 *
 * Layout is built in code (decision R-D3 = A, 2026-09-21): headings, tables,
 * the S-curve image, header and page-numbered footer. The content comes
 * entirely from the report array, so this class decides only how it looks.
 *
 * The S-curve is an embedded PNG (ScurveChartRenderer::png): it shows in
 * Word, LibreOffice and the in-app preview (Mammoth.js, which ignores native
 * Word charts). The numbers behind it are in the Monthly Progress table.
 *
 * Used By
 * -------
 * ReportExportService::projectReportDocx()
 */
class ProjectReportDocxRenderer
{
    // Palette (hex without '#')
    private const BLUE = '1D4ED8';
    private const INK = '0F172A';
    private const MUTED = '64748B';
    private const BORDER = 'CBD5E1';
    private const HEAD_BG = 'E2E8F0';
    private const LABEL_BG = 'F1F5F9';

    // Usable text width on A4 with 2 cm margins, in twips
    private const TEXT_WIDTH = 9638;

    public function __construct(private ScurveChartRenderer $chartRenderer)
    {
    }

    /**
     * ------------------------------------------------------------------------
     * Render to a temporary .docx file
     * ------------------------------------------------------------------------
     *
     * Returns the path of a temp file the caller streams and then deletes.
     * The chart PNG is written to its own temp file for PhpWord and removed
     * before returning.
     */
    public function render(array $report): string
    {
        // Escape &, <, > in every text run (report text is user data)
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord();
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10);
        $word->getDocInfo()
            ->setTitle($report['meta']['title'] . ' — ' . $report['meta']['project_code'] . ' — ' . $report['meta']['period_label'])
            ->setCreator('CSPC PMU Project Monitoring System')
            ->setCompany('Camarines Sur Polytechnic Colleges');

        // Heading 1 = numbered report sections, Heading 2 = sub-sections
        $word->addTitleStyle(1, ['size' => 12, 'bold' => true, 'color' => self::BLUE], ['spaceBefore' => 240, 'spaceAfter' => 100, 'keepNext' => true]);
        $word->addTitleStyle(2, ['size' => 10.5, 'bold' => true, 'color' => self::INK], ['spaceBefore' => 140, 'spaceAfter' => 60, 'keepNext' => true]);

        $section = $word->addSection([
            'paperSize'    => 'A4',
            'marginTop'    => Converter::cmToTwip(2),
            'marginBottom' => Converter::cmToTwip(2),
            'marginLeft'   => Converter::cmToTwip(2),
            'marginRight'  => Converter::cmToTwip(2),
        ]);

        $this->headerFooter($section, $report['meta']);
        $this->titleBlock($section, $report['meta']);

        $chartFile = null;
        try {
            $this->projectInformation($section, $report['project']);
            $this->statusAtGlance($section, $report['status'], $report['meta']);
            $chartFile = $this->scurve($section, $report['chart']);
            $this->monthlyProgress($section, $report['progress']);
            $this->workAccomplished($section, $report['work'], $report['meta']);
            $this->issues($section, $report['issues']);
            $this->extensions($section, $report['extensions']);
            $this->forecast($section, $report['forecast']);
            $this->analysis($section, $report['analysis']);
            $this->recommendations($section, $report['recommendations']);
            $this->closing($section, $report['closing']);

            $path = tempnam(sys_get_temp_dir(), 'pmu_report_') . '.docx';
            IOFactory::createWriter($word, 'Word2007')->save($path);

            return $path;
        } finally {
            if ($chartFile && is_file($chartFile)) {
                @unlink($chartFile);
            }
        }
    }

    /*
    |==========================================================================
    | Sections
    |==========================================================================
    */

    /**
     * Page header (office, from page 2 — page 1 has it in the title block)
     * and footer (project, period, page X of Y) on every page.
     */
    private function headerFooter(Section $section, array $meta): void
    {
        // An empty first-page header switches Word to "different first page"
        $section->addHeader(Header::FIRST);

        $section->addHeader()->addText(
            $meta['office'],
            ['size' => 8, 'color' => self::MUTED],
            ['alignment' => Jc::RIGHT, 'spaceAfter' => 0]
        );

        // The footer is needed on the first page too (Word keeps a separate
        // first-page footer once "different first page" is on)
        $footerText = $meta['project_code'] . '  ·  ' . $meta['title'] . '  ·  ' . $meta['period_label'] . '  ·  Page {PAGE} of {NUMPAGES}';
        foreach ([Footer::FIRST, Footer::AUTO] as $type) {
            $section->addFooter($type)->addPreserveText(
                $footerText,
                ['size' => 8, 'color' => self::MUTED],
                ['alignment' => Jc::CENTER]
            );
        }
    }

    /**
     * Title block: office, report title, project, period.
     */
    private function titleBlock(Section $section, array $meta): void
    {
        $section->addText(mb_strtoupper($meta['office']), ['size' => 8, 'bold' => true, 'color' => self::BLUE], ['spaceAfter' => 40]);
        $section->addText($meta['title'], ['size' => 20, 'bold' => true, 'color' => self::INK], ['spaceAfter' => 60]);
        $section->addText($meta['project_title'], ['size' => 13, 'bold' => true, 'color' => self::INK], ['spaceAfter' => 60]);

        $run = $section->addTextRun(['spaceAfter' => 60]);
        $run->addText('Reporting period: ', ['color' => self::MUTED]);
        $run->addText($meta['period_label'], ['bold' => true]);
        $run->addText('   ·   As of: ', ['color' => self::MUTED]);
        $run->addText($meta['as_of'], ['bold' => true]);
        $run->addText('   ·   Project code: ', ['color' => self::MUTED]);
        $run->addText($meta['project_code'], ['bold' => true]);

        if (!$meta['has_inspection']) {
            $section->addText('No inspection has been recorded for this period yet.', ['italic' => true, 'color' => 'B45309']);
        }

        // Rule under the title block
        $section->addText('', [], ['borderBottomSize' => 12, 'borderBottomColor' => self::BLUE, 'spaceAfter' => 120]);
    }

    /**
     * 1 — Project information as a two-column label/value table.
     */
    private function projectInformation(Section $section, array $rows): void
    {
        $section->addTitle('1. Project Information', 1);

        $table = $this->table($section);
        foreach ($rows as $row) {
            $table->addRow();
            $this->cell($table, 3400, ['bgColor' => self::LABEL_BG])->addText($row['label'], ['bold' => true, 'size' => 9, 'color' => '334155']);
            $this->cell($table, self::TEXT_WIDTH - 3400)->addText($row['value']);
        }
    }

    /**
     * 2 — Status at a glance: two label rows over value rows, four columns.
     */
    private function statusAtGlance(Section $section, array $status, array $meta): void
    {
        $section->addTitle('2. Status at a Glance', 1);

        $cells = [
            [['Planned Progress', ReportFormat::pct($status['planned']), null],
             ['Actual Progress', ReportFormat::pct($status['actual']), null],
             ['Slippage', ReportFormat::signedPct($status['slippage']), ReportFormat::slippageColor($status['slippage'])],
             ['Schedule Status', $status['label'], ReportFormat::levelColor($status['level'])]],
            [['Time Elapsed', ReportFormat::pct($status['time_elapsed']), null],
             ['Days Remaining', ReportFormat::daysRemaining($status['days_remaining']), null],
             ['Approved Extensions', $status['extensions_count'] . ($status['extension_days'] ? ' (' . $status['extension_days'] . ' days)' : ''), null],
             ['Open Issues', (string) $status['open_issues'], null]],
        ];

        $table = $this->table($section);
        $w = (int) (self::TEXT_WIDTH / 4);
        foreach ($cells as $group) {
            $table->addRow();
            foreach ($group as [$label]) {
                $this->cell($table, $w, ['bgColor' => self::LABEL_BG])->addText($label, ['bold' => true, 'size' => 8, 'color' => '334155']);
            }
            $table->addRow();
            foreach ($group as [, $value, $color]) {
                $this->cell($table, $w)->addText($value, ['bold' => true, 'size' => 11, 'color' => $color ?? self::INK]);
            }
        }

        if ($status['time_elapsed'] !== null && $status['actual'] !== null) {
            $section->addText(
                'Time elapsed compares the calendar days used so far with the whole contract period; progress should keep pace with it.',
                ['size' => 8, 'italic' => true, 'color' => self::MUTED],
                ['spaceBefore' => 60]
            );
        }
    }

    /**
     * 3 — S-curve image (PNG). Returns the temp file to delete after saving.
     */
    private function scurve(Section $section, array $chart): ?string
    {
        $section->addTitle('3. Progress S-Curve', 1);

        $png = $this->chartRenderer->png($chart, 2);
        if ($png === null) {
            $this->muted($section, 'No baseline schedule has been set, so there is no S-curve yet.');
            return null;
        }

        $file = tempnam(sys_get_temp_dir(), 'pmu_scurve_') . '.png';
        file_put_contents($file, $png);

        [$pxW, $pxH] = $this->chartRenderer->pngSize(2);
        $widthPt = 480; // ≈ 16.9 cm, the A4 text width
        $section->addImage($file, [
            'unit'      => 'pt', // PhpWord defaults to px (0.75 pt), which would shrink the chart
            'width'     => $widthPt,
            'height'    => (int) round($widthPt * $pxH / $pxW),
            'alignment' => Jc::CENTER,
        ]);
        $this->muted($section, 'Original Plan is the approved baseline; Adjusted Plan appears when an extension was approved; Actual Progress is from the monthly inspections.');

        return $file;
    }

    /**
     * 4 — every inspection up to the period.
     */
    private function monthlyProgress(Section $section, array $rows): void
    {
        $section->addTitle('4. Monthly Progress', 1);

        if (empty($rows)) {
            $this->muted($section, 'No inspection has been recorded yet.');
            return;
        }

        $cols = [['Month', 1500], ['Inspection Date', 1800], ['Planned', 1100], ['Actual', 1100], ['Slippage', 1100], ['Remarks', self::TEXT_WIDTH - 6600]];
        $table = $this->table($section);
        $this->headRow($table, $cols);

        foreach ($rows as $r) {
            $table->addRow();
            $this->cell($table, $cols[0][1])->addText($r['month']);
            $this->cell($table, $cols[1][1])->addText(ReportFormat::text($r['inspection_date']));
            $this->cell($table, $cols[2][1])->addText(ReportFormat::pct($r['planned']), [], ['alignment' => Jc::RIGHT]);
            $this->cell($table, $cols[3][1])->addText(ReportFormat::pct($r['actual']), [], ['alignment' => Jc::RIGHT]);
            $this->cell($table, $cols[4][1])->addText(ReportFormat::signedPct($r['slippage']), ['color' => ReportFormat::slippageColor($r['slippage'])], ['alignment' => Jc::RIGHT]);
            $this->cell($table, $cols[5][1])->addText($r['remarks']);
        }
    }

    /**
     * 5 — the period's activity checklist and activities carried into it.
     */
    private function workAccomplished(Section $section, array $work, array $meta): void
    {
        $section->addTitle('5. Work Accomplished — ' . $meta['period_label'], 1);

        if ($work['total'] === 0) {
            $this->muted($section, 'No activities are scheduled for this month.');
        } else {
            $section->addText($work['done_count'] . ' of ' . $work['total'] . ' scheduled ' . ($work['total'] === 1 ? 'activity' : 'activities') . ' completed.', ['bold' => true], ['spaceAfter' => 80]);

            $cols = [['Activity', self::TEXT_WIDTH - 1800], ['Status', 1800]];
            $table = $this->table($section);
            $this->headRow($table, $cols);
            foreach ($work['activities'] as $a) {
                $table->addRow();
                $cell = $this->cell($table, $cols[0][1]);
                $cell->addText($a['name'], ['bold' => true]);
                if (!empty($a['description'])) {
                    $cell->addText($a['description'], ['size' => 9, 'color' => self::MUTED]);
                }
                $this->cell($table, $cols[1][1])->addText($a['done'] ? 'Completed' : 'Not yet completed', ['color' => $a['done'] ? '16A34A' : 'B45309']);
            }
        }

        if (!empty($work['carried'])) {
            $section->addTitle('Carried over into this month', 2);
            foreach ($work['carried'] as $c) {
                $section->addListItem(
                    $c['name'] . ' — from ' . $c['from'] . ($c['times'] > 1 ? ' (carried over ' . $c['times'] . ' times)' : ''),
                    0,
                    [],
                    ['listType' => ListItem::TYPE_BULLET_FILLED]
                );
            }
        }
    }

    /**
     * 6 — issues open at the end of the period, then those resolved in it.
     */
    private function issues(Section $section, array $issues): void
    {
        $section->addTitle('6. Issues and Problems Encountered', 1);

        $section->addTitle('Open at the end of the period (' . count($issues['open']) . ')', 2);
        if (empty($issues['open'])) {
            $this->muted($section, 'No open issues.');
        } else {
            $cols = [['Issue', 3600], ['Type', 1300], ['Severity', 1200], ['Reported', 1900], ['Status', self::TEXT_WIDTH - 8000]];
            $table = $this->table($section);
            $this->headRow($table, $cols);
            foreach ($issues['open'] as $i) {
                $table->addRow();
                $cell = $this->cell($table, $cols[0][1]);
                $cell->addText($i['name'], ['bold' => true]);
                if ($i['description'] !== '—') {
                    $cell->addText($i['description'], ['size' => 9, 'color' => self::MUTED]);
                }
                $this->cell($table, $cols[1][1])->addText($i['type']);
                $this->cell($table, $cols[2][1])->addText($i['severity']);
                $this->cell($table, $cols[3][1])->addText(ReportFormat::text($i['reported']));
                $this->cell($table, $cols[4][1])->addText($i['status']);
            }
        }

        $section->addTitle('Resolved during the period (' . count($issues['resolved']) . ')', 2);
        if (empty($issues['resolved'])) {
            $this->muted($section, 'No issues were resolved this month.');
        } else {
            $cols = [['Issue', 3400], ['Resolved On', 1900], ['Action Taken / Resolution', self::TEXT_WIDTH - 5300]];
            $table = $this->table($section);
            $this->headRow($table, $cols);
            foreach ($issues['resolved'] as $i) {
                $table->addRow();
                $this->cell($table, $cols[0][1])->addText($i['name'], ['bold' => true]);
                $this->cell($table, $cols[1][1])->addText(ReportFormat::text($i['resolved_on']));
                $this->cell($table, $cols[2][1])->addText($i['resolution']);
            }
        }
    }

    /**
     * 7 — time extensions filed by the period end.
     */
    private function extensions(Section $section, array $rows): void
    {
        $section->addTitle('7. Time Extensions', 1);

        if (empty($rows)) {
            $this->muted($section, 'No time extension has been requested.');
            return;
        }

        $cols = [['No.', 600], ['Days', 800], ['Reason', 3600], ['Filed', 1500], ['Status', 1300], ['New Completion', self::TEXT_WIDTH - 7800]];
        $table = $this->table($section);
        $this->headRow($table, $cols);
        foreach ($rows as $e) {
            $table->addRow();
            $this->cell($table, $cols[0][1])->addText((string) $e['number']);
            $this->cell($table, $cols[1][1])->addText((string) $e['days'], [], ['alignment' => Jc::RIGHT]);
            $cell = $this->cell($table, $cols[2][1]);
            $cell->addText($e['reason']);
            if ($e['category'] !== '—') {
                $cell->addText($e['category'], ['size' => 9, 'color' => self::MUTED]);
            }
            $this->cell($table, $cols[3][1])->addText(ReportFormat::text($e['filed']));
            $statusCell = $this->cell($table, $cols[4][1]);
            $statusCell->addText($e['status']);
            if ($e['decided'] && $e['decided'] !== '—') {
                $statusCell->addText('on ' . $e['decided'], ['size' => 9, 'color' => self::MUTED]);
            }
            $this->cell($table, $cols[5][1])->addText(ReportFormat::text($e['new_end']));
        }
    }

    /**
     * 8 — plain-language forecast (latest period only).
     */
    private function forecast(Section $section, array $forecast): void
    {
        $section->addTitle('8. Forecast', 1);

        if (!$forecast['available']) {
            $this->muted($section, $forecast['note']);
            return;
        }

        foreach ($forecast['lines'] as $line) {
            $section->addListItem($line, 0, [], ['listType' => ListItem::TYPE_BULLET_FILLED]);
        }
        $this->muted($section, 'Forecasts are estimates from the inspection records so far and change as new inspections are recorded.');
    }

    /**
     * 9 — the stored analysis, one sub-heading per section.
     */
    private function analysis(Section $section, array $analysis): void
    {
        $section->addTitle('9. Analysis', 1);

        if (!$analysis['available']) {
            $this->muted($section, 'No analysis has been generated for this period.');
            return;
        }

        $this->muted($section, 'System-generated analysis dated ' . $analysis['generated_at'] . '. Review it before acting on it.');

        foreach ($analysis['sections'] as $s) {
            $section->addTitle($s['title'], 2);
            foreach ($s['blocks'] as $block) {
                if ($block['type'] === 'list') {
                    foreach ($block['items'] as $item) {
                        $section->addListItem($item, 0, [], ['listType' => $block['ordered'] ? ListItem::TYPE_NUMBER : ListItem::TYPE_BULLET_FILLED]);
                    }
                } else {
                    $section->addText($block['text'], [], ['spaceAfter' => 100, 'alignment' => Jc::BOTH]);
                }
            }
        }
    }

    /**
     * 10 — recommendations: priority, category, action (+ reason), source.
     */
    private function recommendations(Section $section, array $recs): void
    {
        $section->addTitle('10. Recommendations', 1);

        if (empty($recs)) {
            $this->muted($section, 'No recommendations for this period.');
            return;
        }

        $cols = [['Priority', 900], ['Category', 1500], ['Recommended Action', self::TEXT_WIDTH - 3900], ['Source', 1500]];
        $table = $this->table($section);
        $this->headRow($table, $cols);
        foreach ($recs as $r) {
            $table->addRow();
            $this->cell($table, $cols[0][1])->addText(ReportFormat::text($r['priority']), ['bold' => true], ['alignment' => Jc::CENTER]);
            $this->cell($table, $cols[1][1])->addText($r['category']);
            $cell = $this->cell($table, $cols[2][1]);
            $cell->addText($r['action']);
            if ($r['reason'] !== '') {
                $cell->addText($r['reason'], ['size' => 9, 'italic' => true, 'color' => self::MUTED]);
            }
            $this->cell($table, $cols[3][1])->addText($r['source'], ['size' => 9, 'color' => self::MUTED]);
        }
        $this->muted($section, 'Priority 1 is the most urgent and 5 the least.');
    }

    /**
     * Closing statement — system-generated, no signatories (R-D6).
     */
    private function closing(Section $section, array $closing): void
    {
        $section->addText('', [], ['borderBottomSize' => 6, 'borderBottomColor' => self::BORDER, 'spaceBefore' => 240, 'spaceAfter' => 80]);
        $section->addText($closing['statement'], ['size' => 9, 'italic' => true, 'color' => self::MUTED]);
        $section->addText($closing['generated'], ['size' => 9, 'italic' => true, 'color' => self::MUTED]);
    }

    /*
    |==========================================================================
    | Helpers
    |==========================================================================
    */

    /**
     * A bordered full-width table.
     */
    private function table(Section $section)
    {
        $table = $section->addTable([
            'borderSize'  => 4,
            'borderColor' => self::BORDER,
            'cellMargin'  => 70,
            'width'       => self::TEXT_WIDTH,
            'unit'        => 'dxa',
            // Fixed: the column widths given per cell hold; long text wraps
            // inside them instead of widening the table past the margin
            'layout'      => \PhpOffice\PhpWord\Style\Table::LAYOUT_FIXED,
        ]);

        return $table;
    }

    /**
     * Add a cell that WRAPS its text. PhpWord's cell style defaults to
     * noWrap = true, which in Word keeps each cell on one line — a long
     * description or reason would push the table off the page.
     */
    private function cell($table, int $width, array $style = [])
    {
        return $table->addCell($width, array_merge(['noWrap' => false], $style));
    }

    /**
     * Shaded header row that repeats on each page.
     */
    private function headRow($table, array $cols): void
    {
        $table->addRow(null, ['tblHeader' => true]);
        foreach ($cols as [$label, $width]) {
            $this->cell($table, $width, ['bgColor' => self::HEAD_BG])->addText($label, ['bold' => true, 'size' => 9, 'color' => '1E293B']);
        }
    }

    /**
     * Small grey note paragraph.
     */
    private function muted(Section $section, string $text): void
    {
        $section->addText($text, ['size' => 8.5, 'italic' => true, 'color' => self::MUTED], ['spaceBefore' => 40, 'spaceAfter' => 80]);
    }
}
