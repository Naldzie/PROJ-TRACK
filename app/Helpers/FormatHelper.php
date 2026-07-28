<?php

namespace App\Helpers;

class FormatHelper
{
    /**
     * Format a large number as currency with intelligent magnitude handling.
     * 
     * Examples:
     * - 500 → ₱500.00
     * - 50,000 → ₱50.0K
     * - 50,000,000 → ₱50.0M
     * - 50,000,000,000 → ₱50.0B
     * 
     * @param float|int $value
     * @param int $decimals Number of decimal places to show
     * @return string Formatted currency string with appropriate suffix
     */
    public static function formatCurrency($value, $decimals = 1): string
    {
        $value = (float) $value;
        
        if ($value === 0) {
            return '₱0.00';
        }
        
        if ($value < 0) {
            return '-' . self::formatCurrency(abs($value), $decimals);
        }
        
        // Determine magnitude and format accordingly
        if ($value >= 1_000_000_000) {
            // Billions
            return '₱' . number_format($value / 1_000_000_000, $decimals) . 'B';
        } elseif ($value >= 1_000_000) {
            // Millions
            return '₱' . number_format($value / 1_000_000, $decimals) . 'M';
        } elseif ($value >= 1_000) {
            // Thousands
            return '₱' . number_format($value / 1_000, $decimals) . 'K';
        } else {
            // Regular formatting for values under 1,000
            return '₱' . number_format($value, 2);
        }
    }

    /**
     * Format a number as currency for dashboard metrics (large numbers only).
     * Always shows in millions (M) or billions (B).
     * 
     * @param float|int $value
     * @param int $decimals
     * @return string
     */
    public static function formatCurrencyShort($value, $decimals = 1): string
    {
        $value = (float) $value;
        
        if ($value === 0) {
            return '₱0M';
        }
        
        if ($value < 0) {
            return '-' . self::formatCurrencyShort(abs($value), $decimals);
        }
        
        // For dashboard metrics, show billions if >= 1 billion, otherwise millions
        if ($value >= 1_000_000_000) {
            return '₱' . number_format($value / 1_000_000_000, $decimals) . 'B';
        } else {
            return '₱' . number_format($value / 1_000_000, $decimals) . 'M';
        }
    }
}
