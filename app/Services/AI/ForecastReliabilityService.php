<?php

namespace App\Services\AI;

/**
 * ============================================================================
 * ForecastReliabilityService
 * ============================================================================
 *
 * Purpose
 * -------
 * Computes a weighted, explainable FORECAST RELIABILITY score (0-100) and
 * level (High / Medium / Low) for a forecast, based on four evidence factors
 * plus hard honesty floors. R² is demoted to a model-selection input, a fit
 * floor (R² < 0.50 → Low), and a "Curve Fit Quality" label only — never a
 * "confidence" claim (Phase 14 plan, §4-§5).
 *
 * Pure math / service — NO database queries. All inputs are passed in via a
 * context array so the service is fully unit-testable (SRP).
 *
 * Weights
 * -------
 *   W_BACKTEST  = 40  (real predicted-vs-actual record — strongest)
 *   W_DATA      = 30  (real observation count, synthetic excluded)
 *   W_HORIZON   = 15  (months ahead)
 *   W_AGREEMENT = 15  (completion methods spread; next-month uses neutral)
 *
 * Hard floors (any trigger → level 'Low'):
 *   • R² < 0.50                        → "Model fit too weak"
 *   • next-prediction: realObs < 2     → "Too little data"
 *   • completion: estimate is null     → no reliability (caller N/A)
 *
 * Honesty cap:
 *   High requires (fulfilled >= 3) OR (realObs >= 6).
 *
 * Level thresholds:
 *   weighted score >= 80 → High; >= 55 → Medium; else → Low.
 */
class ForecastReliabilityService
{
    private const W_BACKTEST = 40;

    private const W_DATA = 30;

    private const W_HORIZON = 15;

    private const W_AGREEMENT = 15;

    private const R2_FLOOR = 0.50;

    private const MIN_REAL_OBS = 2;

    private const HIGH_THRESHOLD = 80;

    private const MEDIUM_THRESHOLD = 55;

    private const HONESTY_FULFILLED = 3;

    private const HONESTY_REAL_OBS = 6;

    private const BACKTEST_POINT_WEIGHT = 0.6;

    private const BACKTEST_COVERAGE_WEIGHT = 0.4;

    /**
     * ------------------------------------------------------------------------
     * Compute Reliability for the Next-Month Prediction
     * ------------------------------------------------------------------------
     *
     * Context keys:
     *   'r_squared'         float   R² of the selected regression model.
     *   'real_obs_count'    int     observation_count − synthetic_points_count.
     *   'prediction_history' array   fulfilled rows:
     *       [ 'predicted_next' => float,
     *         'actual_next'    => float,
     *         'predicted_low'  => float|null,
     *         'predicted_high' => float|null ]
     *
     * Horizon is always 1 month → 100. Agreement factor is neutral (60).
     */
    public function computeForNextPrediction(array $context): array
    {
        $rSquared = (float) ($context['r_squared'] ?? 0);
        $realObs = (int) ($context['real_obs_count'] ?? 0);
        $history = $context['prediction_history'] ?? [];

        $floor = $this->checkFloors($rSquared, $realObs, 'next');

        if ($floor['blocked']) {
            return $this->blockedResult($floor['reason']);
        }

        $factors = [
            'backtest'  => $this->scoreBacktest($history),
            'data'      => $this->scoreData($realObs),
            'horizon'   => ['score' => 100, 'detail' => '1 month ahead'],
            'agreement' => ['score' => 60, 'detail' => 'N/A (next-month forecast)'],
        ];

        return $this->finalize($factors, $history, $realObs);
    }

    /**
     * ------------------------------------------------------------------------
     * Compute Reliability for the Completion Estimate
     * ------------------------------------------------------------------------
     *
     * Context keys (same as next-prediction, plus):
     *   'estimated_index'  float|null  estimated completion month index.
     *   'current_index'    int         current month index.
     *   'agreement'        string      High | Moderate | Low | N/A.
     *   'valid_count'      int         number of valid completion methods.
     *   'spread_days'      int         spread across methods (for detail).
     *
     * Returns null (guarded N/A) when no completion estimate exists.
     */
    public function computeForCompletion(array $context): ?array
    {
        $estimatedIndex = $context['estimated_index'] ?? null;

        if ($estimatedIndex === null) {
            return null;
        }

        $rSquared = (float) ($context['r_squared'] ?? 0);
        $realObs = (int) ($context['real_obs_count'] ?? 0);
        $history = $context['prediction_history'] ?? [];

        $floor = $this->checkFloors($rSquared, $realObs, 'completion');

        if ($floor['blocked']) {
            return $this->blockedResult($floor['reason']);
        }

        $currentIndex = (int) ($context['current_index'] ?? 0);
        $monthsToCompletion = max(0, (int) round($estimatedIndex - $currentIndex));

        $factors = [
            'backtest'  => $this->scoreBacktest($history),
            'data'      => $this->scoreData($realObs),
            'horizon'   => $this->scoreHorizon($monthsToCompletion),
            'agreement' => $this->scoreAgreement($context),
        ];

        return $this->finalize($factors, $history, $realObs);
    }

    /**
     * ------------------------------------------------------------------------
     * Hard Honesty Floors
     * ------------------------------------------------------------------------
     *
     * Any triggered floor blocks further scoring and forces level 'Low'.
     */
    private function checkFloors(float $rSquared, int $realObs, string $mode): array
    {
        if ($rSquared < self::R2_FLOOR) {
            return ['blocked' => true, 'reason' => 'Model fit too weak'];
        }

        if ($mode === 'next' && $realObs < self::MIN_REAL_OBS) {
            return ['blocked' => true, 'reason' => 'Too little data'];
        }

        return ['blocked' => false, 'reason' => null];
    }

    private function blockedResult(string $reason): array
    {
        return [
            'level'   => 'Low',
            'score'   => 0,
            'floors'  => ['blocked' => true, 'reason' => $reason],
            'factors' => [
                'backtest'  => ['score' => 0, 'detail' => ''],
                'data'      => ['score' => 0, 'detail' => ''],
                'horizon'   => ['score' => 0, 'detail' => ''],
                'agreement' => ['score' => 0, 'detail' => ''],
            ],
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Backtest Factor (40%)
     * ------------------------------------------------------------------------
     *
     * Scores point accuracy AND coverage (Decision 7):
     *   • pointScore from the plan's accuracy/close formulas,
     *   • coverageScore = % of fulfilled rows whose actual landed inside the
     *     stored band (predicted_low .. predicted_high).
     * When legacy point-only rows exist (no band), the score is the point
     * score alone. When bands exist, point accuracy and coverage are blended
     * (60/40) so a narrow-yet-hit band scores best.
     */
    private function scoreBacktest(array $history): array
    {
        $fulfilled = array_values(array_filter(
            $history,
            fn ($row) => isset($row['actual_next']) && $row['actual_next'] !== null
        ));

        $count = count($fulfilled);

        if ($count === 0) {
            return ['score' => 60, 'detail' => 'No prediction history yet'];
        }

        $variances = array_map(
            fn ($row) => abs($row['actual_next'] - $row['predicted_next']),
            $fulfilled
        );

        $avgError = round(array_sum($variances) / $count, 1);

        $accurate = count(array_filter($variances, fn ($v) => $v < 2));
        $close = count(array_filter($variances, fn ($v) => $v < 5));

        if ($count >= 3) {
            $pointScore = min(100, $accurate / $count * 100 + $close / $count * 50);
        } else {
            $pointScore = max(0, 100 - $avgError * 10);
        }

        $banded = array_values(array_filter(
            $fulfilled,
            fn ($row) => isset($row['predicted_low'], $row['predicted_high'])
                && $row['predicted_low'] !== null
                && $row['predicted_high'] !== null
        ));

        $bandedCount = count($banded);

        $detail = "{$accurate} of {$count} predicted months accurate (avg error {$avgError}%)";

        if ($bandedCount === 0) {
            return ['score' => (int) round($pointScore), 'detail' => $detail];
        }

        $covered = count(array_filter(
            $banded,
            fn ($row) => $row['actual_next'] >= $row['predicted_low']
                && $row['actual_next'] <= $row['predicted_high']
        ));

        $coverageScore = $covered / $bandedCount * 100;

        $score = (int) round(
            $pointScore * self::BACKTEST_POINT_WEIGHT
            + $coverageScore * self::BACKTEST_COVERAGE_WEIGHT
        );

        $detail .= "; actual in range {$covered} of {$bandedCount} months";

        return ['score' => $score, 'detail' => $detail];
    }

    /**
     * ------------------------------------------------------------------------
     * Data Factor (30%) — real observation count (synthetic excluded)
     * ------------------------------------------------------------------------
     */
    private function scoreData(int $realObs): array
    {
        $score = match (true) {
            $realObs >= 8 => 100,
            $realObs >= 6 => 85,
            $realObs >= 4 => 70,
            $realObs >= 2 => 40,
            default       => 20,
        };

        return ['score' => $score, 'detail' => "{$realObs} real progress records"];
    }

    /**
     * ------------------------------------------------------------------------
     * Horizon Factor (15%) — accuracy decays with forecast distance
     * ------------------------------------------------------------------------
     */
    private function scoreHorizon(int $monthsToCompletion): array
    {
        $score = match (true) {
            $monthsToCompletion <= 3  => 100,
            $monthsToCompletion <= 6  => 85,
            $monthsToCompletion <= 12 => 65,
            $monthsToCompletion <= 24 => 45,
            default                   => 25,
        };

        return ['score' => $score, 'detail' => "{$monthsToCompletion} months to completion"];
    }

    /**
     * ------------------------------------------------------------------------
     * Agreement Factor (15%) — completion methods spread
     * ------------------------------------------------------------------------
     *
     * valid_count >= 2 required to trust it; otherwise neutral (60).
     */
    private function scoreAgreement(array $context): array
    {
        $agreement = (string) ($context['agreement'] ?? 'N/A');
        $validCount = (int) ($context['valid_count'] ?? 0);
        $spreadDays = (int) ($context['spread_days'] ?? 0);

        if ($validCount < 2) {
            return ['score' => 60, 'detail' => 'N/A — too few methods to compare'];
        }

        $score = match ($agreement) {
            'High'     => 100,
            'Moderate' => 70,
            'Low'      => 40,
            default    => 60,
        };

        return ['score' => $score, 'detail' => "All {$validCount} methods agree ({$agreement}, spread {$spreadDays} days)"];
    }

    /**
     * ------------------------------------------------------------------------
     * Combine Factors into a Final Score + Level
     * ------------------------------------------------------------------------
     */
    private function finalize(array $factors, array $history, int $realObs): array
    {
        $score = (int) round(
            $factors['backtest']['score'] * self::W_BACKTEST / 100
            + $factors['data']['score'] * self::W_DATA / 100
            + $factors['horizon']['score'] * self::W_HORIZON / 100
            + $factors['agreement']['score'] * self::W_AGREEMENT / 100
        );

        $level = $this->classifyLevel($score);

        $fulfilled = count(array_filter(
            $history,
            fn ($row) => isset($row['actual_next']) && $row['actual_next'] !== null
        ));

        // Honesty cap: High requires (fulfilled >= 3) OR (realObs >= 6).
        if ($level === 'High'
            && !($fulfilled >= self::HONESTY_FULFILLED || $realObs >= self::HONESTY_REAL_OBS)
        ) {
            $level = 'Medium';
        }

        return [
            'level'   => $level,
            'score'   => $score,
            'floors'  => ['blocked' => false, 'reason' => null],
            'factors' => $factors,
        ];
    }

    private function classifyLevel(int $score): string
    {
        if ($score >= self::HIGH_THRESHOLD) {
            return 'High';
        }

        if ($score >= self::MEDIUM_THRESHOLD) {
            return 'Medium';
        }

        return 'Low';
    }
}
