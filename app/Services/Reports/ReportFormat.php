<?php

namespace App\Services\Reports;

/**
 * ============================================================================
 * ReportFormat
 * ============================================================================
 *
 * Display formatting shared by the Word and PDF project-report renderers, so
 * the two files print every number the same way.
 *
 * Used By
 * -------
 * ProjectReportDocxRenderer, resources/views/reports/project-report.blade.php
 */
class ReportFormat
{
    /**
     * 47.6 → '47.6%', 40.0 → '40%', null → '—'.
     */
    public static function pct($value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $value, 2), '0'), '.') . '%';
    }

    /**
     * Slippage with its sign: 1.5 → '+1.5%', -2 → '-2%', 0 → '0%'.
     */
    public static function signedPct($value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $v = (float) $value;

        return ($v > 0 ? '+' : '') . self::pct($v);
    }

    /**
     * Days remaining in words: 197 → '197 days', -12 → '12 days overdue'.
     */
    public static function daysRemaining($days): string
    {
        if ($days === null) {
            return '—';
        }

        $d = (int) $days;
        $abs = abs($d);
        $word = $abs === 1 ? 'day' : 'days';

        return $d < 0 ? "{$abs} {$word} overdue" : "{$abs} {$word}";
    }

    /**
     * Value or an em dash for empty.
     */
    public static function text($value): string
    {
        return ($value === null || $value === '') ? '—' : (string) $value;
    }

    /**
     * Schedule-status level → hex colour (no '#'), matching the app's badges.
     */
    public static function levelColor(?string $level): string
    {
        return match ($level) {
            'ahead', 'on_track' => '16A34A',
            'low_risk'          => 'CA8A04',
            'medium_risk'       => 'EA580C',
            'high_risk', 'overdue' => 'DC2626',
            default             => '64748B',
        };
    }

    /**
     * Slippage → hex colour: behind red, ahead/on plan green.
     */
    public static function slippageColor($value): string
    {
        if ($value === null) {
            return '64748B';
        }

        return (float) $value < 0 ? 'DC2626' : '16A34A';
    }
}
