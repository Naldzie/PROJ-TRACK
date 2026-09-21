<?php

namespace App\Services\Reports;

use Carbon\Carbon;

/**
 * ============================================================================
 * ScurveChartRenderer
 * ============================================================================
 *
 * Purpose
 * -------
 * Draws the project S-curve (Original Plan · Adjusted Plan · Actual Progress)
 * for printed reports. The chart GEOMETRY is computed once (geometry()) and
 * painted two ways:
 *   · svg() / svgImgTag() — vector, for the PDF (dompdf renders SVG only as an
 *     <img> data URI, never as inline <svg>)
 *   · png()               — raster via GD, for the Word document (Word, and the
 *     Mammoth preview in the document viewer, both show embedded images; a
 *     native Word chart would be invisible in the preview)
 * so the two formats can never draw different lines.
 *
 * The geometry is the one ReportExportService::buildSvgChart() used since the
 * August R3 rewrite (moved here 2026-09-21, project_report_overhaul_plan.md):
 * linear millisecond-timestamp x-axis spanning ticks and every plotted point,
 * null-y points skipped as gaps (Chart.js spanGaps:false), legend and colours
 * identical to the Progress tab.
 *
 * Input: ProjectChartService::prepareChartData() output (labels,
 * tick_timestamps, frozen_planned_data, adjusted_planned_data, actual_data,
 * has_extension, y_max, ready).
 *
 * Used By
 * -------
 * ReportExportService (PDF), ProjectReportDocxRenderer (Word)
 */
class ScurveChartRenderer
{
    // Canvas size in chart units (SVG user units; PNG pixels before scaling)
    private const WIDTH = 820;
    private const HEIGHT = 340;
    private const PAD_LEFT = 55;
    private const PAD_RIGHT = 25;
    private const PAD_TOP = 45;
    private const PAD_BOTTOM = 45;

    // Series colours — identical to the Progress tab
    private const COLOR_PLAN = '#3b82f6';
    private const COLOR_ACTUAL = '#10b981';
    private const COLOR_ADJUSTED = '#f59e0b';

    /**
     * ------------------------------------------------------------------------
     * Geometry
     * ------------------------------------------------------------------------
     *
     * Everything a painter needs, in chart units: grid lines + their % labels,
     * x-axis labels, legend items and one polyline per series. Returns null
     * when the project has no baseline data to draw.
     *
     * Called by: svg(), png()
     */
    public function geometry(array $chartData): ?array
    {
        if (empty($chartData['ready']) || empty($chartData['labels'])) {
            return null;
        }

        $width = self::WIDTH;
        $height = self::HEIGHT;
        $padLeft = self::PAD_LEFT;
        $padTop = self::PAD_TOP;
        $padBottom = self::PAD_BOTTOM;
        $plotW = $width - $padLeft - self::PAD_RIGHT;
        $plotH = $height - $padTop - $padBottom;

        $labels = $chartData['labels'];

        // Value range 0..100 unless the data exceeds it
        $maxY = max(100, (float) ($chartData['y_max'] ?? 0));

        // Linear timestamp x-axis spanning the baseline ticks AND every plotted
        // point, so an actual record past the last baseline month lands at its
        // true calendar position instead of clamping onto the last column.
        $ticks = $chartData['tick_timestamps'] ?? [];
        $allXs = $ticks;
        foreach (['frozen_planned_data', 'adjusted_planned_data', 'actual_data'] as $seriesKey) {
            foreach (($chartData[$seriesKey] ?? []) as $pt) {
                if (is_array($pt) && isset($pt['x'])) {
                    $allXs[] = (float) $pt['x'];
                }
            }
        }
        $xMin = !empty($allXs) ? min($allXs) : 0;
        $xMax = !empty($allXs) ? max($allXs) : 0;
        if ($xMax <= $xMin) {
            $xMax = $xMin + 1;
        }

        $xFor = fn (float $ts) => $padLeft + (($ts - $xMin) / ($xMax - $xMin)) * $plotW;
        $yFor = fn (float $v) => $padTop + (1 - min(max($v, 0), $maxY) / $maxY) * $plotH;

        // {x: ms, y: value} (or scalar) points → chart coordinates; a null y is
        // a gap (extension months of the frozen plan), never a 0% point.
        $points = function (array $values) use ($xFor, $yFor, $ticks) {
            $pts = [];
            foreach ($values as $i => $v) {
                if ($v === null) {
                    continue;
                }
                if (is_array($v)) {
                    if (!isset($v['y']) || $v['y'] === null) {
                        continue;
                    }
                    $y = (float) $v['y'];
                    $x = $xFor((float) ($v['x'] ?? ($ticks[$i] ?? 0)));
                } else {
                    $y = (float) $v;
                    $x = $xFor((float) ($ticks[$i] ?? 0));
                }
                $pts[] = [round($x, 1), round($yFor($y), 1)];
            }
            return $pts;
        };

        // Horizontal grid: 0, 25, 50, 75, 100 % of the range
        $grid = [];
        for ($g = 0; $g <= 4; $g++) {
            $val = ($maxY / 4) * $g;
            $grid[] = ['y' => $yFor($val), 'label' => round($val) . '%'];
        }

        // X labels at the baseline ticks, plus months that only actual /
        // adjusted / frozen points reach (the extended tail).
        $labelTicks = [];
        foreach ($labels as $i => $label) {
            $labelTicks[] = [$label, isset($ticks[$i]) ? (float) $ticks[$i] : null];
        }
        $lastTickTs = !empty($ticks) ? (float) end($ticks) : null;
        foreach (($chartData['actual_data'] ?? []) as $pt) {
            if (!is_array($pt) || !isset($pt['x'], $pt['month_label']) || $lastTickTs === null) {
                continue;
            }
            if ((float) $pt['x'] > $lastTickTs) {
                $labelTicks[] = [$pt['month_label'], (float) $pt['x']];
            }
        }
        foreach (['adjusted_planned_data', 'frozen_planned_data'] as $seriesKey) {
            foreach (($chartData[$seriesKey] ?? []) as $pt) {
                if (!is_array($pt) || !isset($pt['x']) || $lastTickTs === null || (float) $pt['x'] <= $lastTickTs) {
                    continue;
                }
                $existing = array_map(fn ($lt) => $lt[0], $labelTicks);
                $labelForTs = Carbon::createFromTimestampMs((int) $pt['x'])->format('Y-m');
                if (!in_array($labelForTs, $existing, true)) {
                    $labelTicks[] = [$labelForTs, (float) $pt['x']];
                }
            }
        }
        usort($labelTicks, fn ($a, $b) => ($a[1] ?? 0) <=> ($b[1] ?? 0));

        $xLabels = [];
        $labelCount = count($labelTicks);
        $skipEvery = max(1, intdiv($labelCount, 6));
        foreach ($labelTicks as $i => [$label, $ts]) {
            if ($i % $skipEvery !== 0 && $i !== $labelCount - 1) {
                continue;
            }
            $xLabels[] = [
                'x'     => round($ts !== null ? $xFor($ts) : $padLeft + ($i / max($labelCount - 1, 1)) * $plotW, 1),
                'label' => $this->monthLabel($label),
            ];
        }

        // Legend + series mirror the Progress tab: the adjusted plan only
        // when the project has an extension.
        $hasExtension = (bool) ($chartData['has_extension'] ?? false);
        $hasActual = !empty($chartData['actual_data']);

        $legend = [['color' => self::COLOR_PLAN, 'label' => 'Original Plan', 'width' => 160]];
        if ($hasActual) {
            $legend[] = ['color' => self::COLOR_ACTUAL, 'label' => 'Actual Progress', 'width' => 160];
        }
        if ($hasExtension) {
            $legend[] = ['color' => self::COLOR_ADJUSTED, 'label' => 'Adjusted Plan', 'width' => 150];
        }

        // Draw order: plan, adjusted, actual (actual on top)
        $series = [['color' => self::COLOR_PLAN, 'points' => $points($chartData['frozen_planned_data'] ?? [])]];
        if ($hasExtension) {
            $series[] = ['color' => self::COLOR_ADJUSTED, 'points' => $points($chartData['adjusted_planned_data'] ?? [])];
        }
        $series[] = ['color' => self::COLOR_ACTUAL, 'points' => $points($chartData['actual_data'] ?? [])];

        return [
            'width'    => $width,
            'height'   => $height,
            'pad_left' => $padLeft,
            'plot_right' => $width - self::PAD_RIGHT,
            'axis_label_y' => $height - $padBottom + 18,
            'grid'     => $grid,
            'x_labels' => $xLabels,
            'legend'   => $legend,
            'series'   => array_values(array_filter($series, fn ($s) => !empty($s['points']))),
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * SVG markup (vector)
     * ------------------------------------------------------------------------
     *
     * Returns the raw <svg> document, or null when there is nothing to draw.
     */
    public function svg(array $chartData): ?string
    {
        $g = $this->geometry($chartData);
        if ($g === null) {
            return null;
        }

        $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='{$g['width']}' height='{$g['height']}' viewBox='0 0 {$g['width']} {$g['height']}'>";

        // Legend box
        $legendWidth = array_sum(array_column($g['legend'], 'width'));
        $svg .= "<rect x='" . ($g['pad_left'] - 6) . "' y='8' width='" . ($legendWidth + 10) . "' height='20' rx='4' fill='#f8fafc' stroke='#e2e8f0'/>";
        $x = $g['pad_left'];
        foreach ($g['legend'] as $item) {
            $svg .= "<line x1='{$x}' y1='18' x2='" . ($x + 16) . "' y2='18' stroke='{$item['color']}' stroke-width='3'/>";
            $svg .= "<text x='" . ($x + 22) . "' y='22' font-size='10' fill='#334155' font-family='DejaVu Sans'>" . htmlspecialchars($item['label']) . "</text>";
            $x += $item['width'];
        }

        // Grid + % labels
        foreach ($g['grid'] as $line) {
            $svg .= "<line x1='{$g['pad_left']}' y1='{$line['y']}' x2='{$g['plot_right']}' y2='{$line['y']}' stroke='#e2e8f0' stroke-width='1'/>";
            $svg .= "<text x='" . ($g['pad_left'] - 8) . "' y='" . ($line['y'] + 4) . "' font-size='11' fill='#64748b' text-anchor='end'>{$line['label']}</text>";
        }

        // Series
        foreach ($g['series'] as $s) {
            $pts = implode(' ', array_map(fn ($p) => $p[0] . ',' . $p[1], $s['points']));
            $svg .= "<polyline points='{$pts}' fill='none' stroke='{$s['color']}' stroke-width='2'/>";
        }

        // X labels
        foreach ($g['x_labels'] as $l) {
            $svg .= "<text x='{$l['x']}' y='{$g['axis_label_y']}' font-size='10' fill='#64748b' text-anchor='middle'>" . htmlspecialchars($l['label']) . "</text>";
        }

        return $svg . '</svg>';
    }

    /**
     * ------------------------------------------------------------------------
     * SVG as a dompdf-safe <img> tag
     * ------------------------------------------------------------------------
     *
     * dompdf renders SVG only through <img> (php-svg-lib), so the chart is a
     * base64 data URI sized to the A4 content width. With no data, a
     * .no-chart placeholder div.
     */
    public function svgImgTag(array $chartData, int $fitWidth = 680): string
    {
        $svg = $this->svg($chartData);
        if ($svg === null) {
            return '<div class="no-chart">No baseline schedule data for chart.</div>';
        }

        $fitHeight = (int) round($fitWidth * (self::HEIGHT / self::WIDTH));

        return "<img src='data:image/svg+xml;base64," . base64_encode($svg)
            . "' style='width:{$fitWidth}px;height:{$fitHeight}px;' />";
    }

    /**
     * ------------------------------------------------------------------------
     * PNG (raster, GD)
     * ------------------------------------------------------------------------
     *
     * Paints the same geometry with GD at $scale× resolution (sharp when Word
     * scales it down to the page width). Text uses DejaVu Sans — the TTF that
     * ships with dompdf — so labels render without a system font. Returns
     * the PNG bytes, or null when there is nothing to draw or GD is missing.
     */
    public function png(array $chartData, int $scale = 2): ?string
    {
        $g = $this->geometry($chartData);
        if ($g === null || !function_exists('imagecreatetruecolor')) {
            return null;
        }

        $s = max(1, $scale);
        $img = imagecreatetruecolor($g['width'] * $s, $g['height'] * $s);
        imagefill($img, 0, 0, $this->color($img, '#ffffff'));

        $font = $this->fontPath();
        $text = function (float $x, float $y, float $size, string $hex, string $str, string $align = 'left') use ($img, $s, $font) {
            if ($font === null) {
                imagestring($img, 3, (int) ($x * $s), (int) (($y - 10) * $s), $str, $this->color($img, $hex));
                return;
            }
            $px = $size * 0.75 * $s; // SVG font-size (px) → GD points
            $box = imagettfbbox($px, 0, $font, $str);
            $w = $box[2] - $box[0];
            $dx = match ($align) {
                'end'    => -$w,
                'middle' => -$w / 2,
                default  => 0,
            };
            // Keep the whole label on the canvas (the last month label sits on
            // the right edge; a raster image clips what an SVG viewer shows)
            $left = max(2, min($x * $s + $dx, imagesx($img) - $w - 2));
            imagettftext($img, $px, 0, (int) round($left), (int) round($y * $s), $this->color($img, $hex), $font, $str);
        };

        // Legend box
        $legendWidth = array_sum(array_column($g['legend'], 'width'));
        $lx = ($g['pad_left'] - 6) * $s;
        imagefilledrectangle($img, $lx, 8 * $s, $lx + ($legendWidth + 10) * $s, 28 * $s, $this->color($img, '#f8fafc'));
        imagerectangle($img, $lx, 8 * $s, $lx + ($legendWidth + 10) * $s, 28 * $s, $this->color($img, '#e2e8f0'));
        $x = $g['pad_left'];
        foreach ($g['legend'] as $item) {
            imagesetthickness($img, 3 * $s);
            imageline($img, $x * $s, 18 * $s, ($x + 16) * $s, 18 * $s, $this->color($img, $item['color']));
            $text($x + 22, 22, 10, '#334155', $item['label']);
            $x += $item['width'];
        }

        // Grid + % labels
        imagesetthickness($img, $s);
        foreach ($g['grid'] as $line) {
            imageline($img, $g['pad_left'] * $s, (int) ($line['y'] * $s), $g['plot_right'] * $s, (int) ($line['y'] * $s), $this->color($img, '#e2e8f0'));
            $text($g['pad_left'] - 8, $line['y'] + 4, 11, '#64748b', $line['label'], 'end');
        }

        // Series (2-unit strokes, like the SVG)
        imagesetthickness($img, 2 * $s);
        foreach ($g['series'] as $series) {
            $c = $this->color($img, $series['color']);
            $pts = $series['points'];
            for ($i = 1; $i < count($pts); $i++) {
                imageline($img, (int) ($pts[$i - 1][0] * $s), (int) ($pts[$i - 1][1] * $s), (int) ($pts[$i][0] * $s), (int) ($pts[$i][1] * $s), $c);
            }
            // A lone point (one inspection) still shows as a dot
            if (count($pts) === 1) {
                imagefilledellipse($img, (int) ($pts[0][0] * $s), (int) ($pts[0][1] * $s), 5 * $s, 5 * $s, $c);
            }
        }

        // X labels
        foreach ($g['x_labels'] as $l) {
            $text($l['x'], $g['axis_label_y'], 10, '#64748b', $l['label'], 'middle');
        }

        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /**
     * Pixel size of png() output at the given scale (for image placement).
     */
    public function pngSize(int $scale = 2): array
    {
        return [self::WIDTH * max(1, $scale), self::HEIGHT * max(1, $scale)];
    }

    /**
     * Allocate a GD colour from a #rrggbb hex string.
     */
    private function color($img, string $hex): int
    {
        $hex = ltrim($hex, '#');

        return imagecolorallocate($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    /**
     * DejaVu Sans from the dompdf package (always installed with it), or null.
     */
    private function fontPath(): ?string
    {
        $path = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');

        return is_file($path) ? $path : null;
    }

    /**
     * 'Y-m' → 'F Y' (e.g. 2026-04 → April 2026); other labels unchanged.
     */
    private function monthLabel(string $label): string
    {
        try {
            $parsed = Carbon::createFromFormat('Y-m', $label);
            return $parsed ? $parsed->format('F Y') : $label;
        } catch (\Throwable $e) {
            return $label;
        }
    }
}
