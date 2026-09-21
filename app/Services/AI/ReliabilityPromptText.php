<?php

namespace App\Services\AI;

/**
 * ============================================================================
 * ReliabilityPromptText
 * ============================================================================
 *
 * Purpose
 * -------
 * Renders the forecast-reliability block (ForecastReliabilityService output,
 * as carried in AIPredictionService's report under 'reliability') into the
 * prompt lines handed to Gemini. This is the trust statement the model is
 * told to use instead of R².
 *
 * Why it exists
 * -------------
 * The analysis prompt (AIExplanationService) and the recommendation prompt
 * (AIRecommendationService) each had their own copy of this renderer. The
 * analysis copy read a shape the service never returns ('next_reliability',
 * 'label', a factors LIST keyed 'backtest_accuracy'…), so the analysis
 * prompt always received a bare heading (found 2026-09-21, plan
 * ai_analysis_recommendation_merge_plan.md F-1). One renderer now serves
 * both, reading the REAL shape:
 *
 *   reliability['next_prediction'|'completion'] = null | [
 *       'level'   => 'High'|'Medium'|'Low',
 *       'score'   => 0-100,
 *       'floors'  => ['blocked' => bool, 'reason' => ?string],
 *       'factors' => ['backtest'|'data'|'horizon'|'agreement' =>
 *                         ['score' => int, 'detail' => string]],
 *   ]
 *
 * Used By
 * -------
 * AIExplanationService::buildPrompt(), AIRecommendationService::buildPrompt()
 */
class ReliabilityPromptText
{
    /**
     * Factor keys in display order, with the title shown to the model.
     */
    private const FACTOR_TITLES = [
        'backtest'  => 'Prediction history',
        'data'      => 'Data',
        'horizon'   => 'Forecast horizon',
        'agreement' => 'Method agreement',
    ];

    /**
     * Line used when there is nothing to report.
     */
    private const NOT_AVAILABLE = '- N/A — insufficient data for a reliability estimate';

    /**
     * ------------------------------------------------------------------------
     * Render
     * ------------------------------------------------------------------------
     *
     * Returns the block as "\n<heading>\n- line\n- line…". Each caller passes
     * its own heading so both prompts keep their existing wording.
     *
     * Lines: next-month score, completion score, then the four weighted
     * factors (taken from the next-month result, falling back to the
     * completion result). A report with neither result — too little data —
     * gets the N/A line instead of a bare heading.
     */
    public static function render(?array $reliability, string $heading): string
    {
        $next       = is_array($reliability['next_prediction'] ?? null) ? $reliability['next_prediction'] : null;
        $completion = is_array($reliability['completion'] ?? null) ? $reliability['completion'] : null;

        // Nothing computed (no block, or both results null) — say so plainly
        // rather than hand the model an empty heading it is told to trust.
        if ($next === null && $completion === null) {
            return "\nFORECAST RELIABILITY:\n" . self::NOT_AVAILABLE;
        }

        $lines = [$heading];

        if ($next !== null) {
            $lines[] = sprintf('- Next-month forecast: %s (%d/100)', $next['level'] ?? 'Unknown', $next['score'] ?? 0);
        }

        if ($completion !== null) {
            $lines[] = sprintf('- Completion estimate: %s (%d/100)', $completion['level'] ?? 'Unknown', $completion['score'] ?? 0);
        }

        // Factor breakdown — one source so the four lines describe one score
        $source = $next ?? $completion;

        foreach (self::FACTOR_TITLES as $key => $title) {
            $factor = $source['factors'][$key] ?? null;
            if (!is_array($factor)) {
                continue;
            }
            $lines[] = sprintf('- %s: %d/100 — %s', $title, $factor['score'] ?? 0, $factor['detail'] ?? '');
        }

        return "\n" . implode("\n", $lines);
    }
}
