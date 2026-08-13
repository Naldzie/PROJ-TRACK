<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\ForecastReliabilityService;
use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 * ForecastReliabilityServiceTest
 * ============================================================================
 *
 * Pure unit tests — NO database.
 *
 * Run With
 * --------
 *   php artisan test --filter=ForecastReliabilityServiceTest
 */
class ForecastReliabilityServiceTest extends TestCase
{
    private ForecastReliabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ForecastReliabilityService();
    }

    private function nextContext(array $overrides = []): array
    {
        return array_merge([
            'r_squared'          => 0.95,
            'real_obs_count'     => 8,
            'prediction_history' => [],
        ], $overrides);
    }

    private function completionContext(array $overrides = []): array
    {
        return array_merge([
            'r_squared'          => 0.95,
            'real_obs_count'     => 8,
            'prediction_history' => [],
            'estimated_index'    => 12.0,
            'current_index'      => 4,
            'agreement'          => 'Moderate',
            'valid_count'        => 3,
            'spread_days'        => 30,
        ], $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | Backtest factor
    |--------------------------------------------------------------------------
    */

    public function test_backtest_neutral_when_no_history(): void
    {
        $result = $this->service->computeForNextPrediction($this->nextContext());

        $this->assertSame(60, $result['factors']['backtest']['score']);
        $this->assertSame('No prediction history yet', $result['factors']['backtest']['detail']);
    }

    public function test_backtest_one_to_two_fulfilled_uses_avg_variance(): void
    {
        $result = $this->service->computeForNextPrediction($this->nextContext([
            'prediction_history' => [
                ['predicted_next' => 50, 'actual_next' => 55],
                ['predicted_next' => 60, 'actual_next' => 68],
            ],
        ]));

        // avg variance = 6.5 → max(0, 100 - 65) = 35
        $this->assertSame(35, $result['factors']['backtest']['score']);
    }

    public function test_backtest_three_plus_fulfilled_uses_accurate_close_math(): void
    {
        // variances: 0.5, 1.0, 1.5, 3.0, 4.0, 9.0
        $history = [
            ['predicted_next' => 50, 'actual_next' => 50.5],
            ['predicted_next' => 55, 'actual_next' => 56],
            ['predicted_next' => 60, 'actual_next' => 61.5],
            ['predicted_next' => 65, 'actual_next' => 68],
            ['predicted_next' => 70, 'actual_next' => 74],
            ['predicted_next' => 75, 'actual_next' => 84],
        ];

        $result = $this->service->computeForNextPrediction($this->nextContext([
            'prediction_history' => $history,
        ]));

        // accurate (<2) = 3, close (<5) = 5, fulfilled = 6
        // score = min(100, 3/6*100 + 5/6*50) = min(100, 50 + 41.67) = 91.67 → 92
        $this->assertSame(92, $result['factors']['backtest']['score']);
    }

    public function test_backtest_score_clamped_to_100(): void
    {
        $history = array_map(fn ($p) => [
            'predicted_next' => $p,
            'actual_next' => $p + 0.5,
        ], [10, 20, 30]);

        $result = $this->service->computeForNextPrediction($this->nextContext([
            'prediction_history' => $history,
        ]));

        // accurate = 3, close = 3 → min(100, 100 + 50) = 100
        $this->assertSame(100, $result['factors']['backtest']['score']);
    }

    public function test_backtest_coverage_blend_when_bands_present(): void
    {
        $history = [
            ['predicted_next' => 50, 'actual_next' => 50, 'predicted_low' => 48, 'predicted_high' => 52],
            ['predicted_next' => 55, 'actual_next' => 55, 'predicted_low' => 53, 'predicted_high' => 57],
            ['predicted_next' => 60, 'actual_next' => 60, 'predicted_low' => 58, 'predicted_high' => 62],
        ];

        $result = $this->service->computeForNextPrediction($this->nextContext([
            'prediction_history' => $history,
        ]));

        // pointScore = 100 (all accurate), coverage = 100% → blended = 100
        $this->assertSame(100, $result['factors']['backtest']['score']);
        $this->assertStringContainsString('actual in range 3 of 3 months', $result['factors']['backtest']['detail']);
    }

    public function test_backtest_coverage_lowers_score_when_actual_outside_band(): void
    {
        $history = [
            ['predicted_next' => 50, 'actual_next' => 50, 'predicted_low' => 48, 'predicted_high' => 52],
            ['predicted_next' => 55, 'actual_next' => 55, 'predicted_low' => 53, 'predicted_high' => 57],
            ['predicted_next' => 60, 'actual_next' => 70, 'predicted_low' => 58, 'predicted_high' => 62],
        ];

        $result = $this->service->computeForNextPrediction($this->nextContext([
            'prediction_history' => $history,
        ]));

        // pointScore: accurate (<2) = 2, close (<5) = 2 (10 off) → min(100, 66.67+33.33)=100
        // coverage = 2/3 = 66.67 → blended = round(100*0.6 + 66.67*0.4) = round(86.67) = 87
        $this->assertSame(87, $result['factors']['backtest']['score']);
    }

    public function test_legacy_point_only_rows_scored_on_accuracy_only(): void
    {
        $history = [
            ['predicted_next' => 50, 'actual_next' => 50],
            ['predicted_next' => 55, 'actual_next' => 55],
            ['predicted_next' => 60, 'actual_next' => 60],
        ];

        $result = $this->service->computeForNextPrediction($this->nextContext([
            'prediction_history' => $history,
        ]));

        // no bands → pure point score 100, no coverage text
        $this->assertSame(100, $result['factors']['backtest']['score']);
        $this->assertStringNotContainsString('actual in range', $result['factors']['backtest']['detail']);
    }

    /*
    |--------------------------------------------------------------------------
    | Data factor (real observation count)
    |--------------------------------------------------------------------------
    */

    public function test_data_factor_each_bucket(): void
    {
        // Completion mode has no realObs floor, so all buckets are observable
        // (next-prediction would block realObs < 2 via the floor).
        $expect = [8 => 100, 6 => 85, 4 => 70, 2 => 40, 1 => 20, 0 => 20];

        foreach ($expect as $realObs => $expectedScore) {
            $result = $this->service->computeForCompletion($this->completionContext([
                'real_obs_count' => $realObs,
            ]));

            $this->assertSame(
                $expectedScore,
                $result['factors']['data']['score'],
                "real_obs_count={$realObs} should score {$expectedScore}"
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Horizon factor
    |--------------------------------------------------------------------------
    */

    public function test_horizon_next_prediction_always_100(): void
    {
        $result = $this->service->computeForNextPrediction($this->nextContext());

        $this->assertSame(100, $result['factors']['horizon']['score']);
        $this->assertSame('1 month ahead', $result['factors']['horizon']['detail']);
    }

    public function test_horizon_completion_each_bucket(): void
    {
        $cases = [
            2  => 100,
            6  => 85,
            12 => 65,
            24 => 45,
            30 => 25,
        ];

        foreach ($cases as $months => $expectedScore) {
            $result = $this->service->computeForCompletion($this->completionContext([
                'estimated_index' => 4 + $months,
                'current_index'   => 4,
            ]));

            $this->assertSame(
                $expectedScore,
                $result['factors']['horizon']['score'],
                "{$months} months to completion should score {$expectedScore}"
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Agreement factor
    |--------------------------------------------------------------------------
    */

    public function test_agreement_high_moderate_low(): void
    {
        foreach (['High' => 100, 'Moderate' => 70, 'Low' => 40] as $agreement => $expected) {
            $result = $this->service->computeForCompletion($this->completionContext([
                'agreement'   => $agreement,
                'valid_count' => 3,
            ]));

            $this->assertSame($expected, $result['factors']['agreement']['score']);
        }
    }

    public function test_agreement_na_or_insufficient_valid_count_is_neutral(): void
    {
        $na = $this->service->computeForCompletion($this->completionContext([
            'agreement' => 'N/A',
            'valid_count' => 3,
        ]));

        $this->assertSame(60, $na['factors']['agreement']['score']);

        $few = $this->service->computeForCompletion($this->completionContext([
            'agreement' => 'High',
            'valid_count' => 1,
        ]));

        $this->assertSame(60, $few['factors']['agreement']['score']);
    }

    public function test_agreement_neutral_for_next_prediction(): void
    {
        $result = $this->service->computeForNextPrediction($this->nextContext());

        $this->assertSame(60, $result['factors']['agreement']['score']);
        $this->assertStringContainsString('N/A', $result['factors']['agreement']['detail']);
    }

    /*
    |--------------------------------------------------------------------------
    | Hard floors
    |--------------------------------------------------------------------------
    */

    public function test_floor_r_squared_below_050_forces_low(): void
    {
        $result = $this->service->computeForNextPrediction($this->nextContext([
            'r_squared' => 0.45,
        ]));

        $this->assertSame('Low', $result['level']);
        $this->assertTrue($result['floors']['blocked']);
        $this->assertSame('Model fit too weak', $result['floors']['reason']);
        $this->assertSame(0, $result['score']);
    }

    public function test_floor_insufficient_real_obs_for_next_prediction(): void
    {
        $result = $this->service->computeForNextPrediction($this->nextContext([
            'real_obs_count' => 1,
        ]));

        $this->assertSame('Low', $result['level']);
        $this->assertTrue($result['floors']['blocked']);
        $this->assertSame('Too little data', $result['floors']['reason']);
    }

    public function test_completion_null_estimate_returns_null(): void
    {
        $result = $this->service->computeForCompletion($this->completionContext([
            'estimated_index' => null,
        ]));

        $this->assertNull($result);
    }

    /*
    |--------------------------------------------------------------------------
    | Honesty cap
    |--------------------------------------------------------------------------
    */

    public function test_honesty_cap_high_needs_fulfilled_3_or_real_obs_6(): void
    {
        // Score would be High (backtest 95 + data 70 + horizon 100 + agreement 60)
        // but fulfilled = 2 (< 3) and realObs = 4 (< 6) → capped to Medium.
        $result = $this->service->computeForNextPrediction($this->nextContext([
            'real_obs_count' => 4,
            'prediction_history' => [
                ['predicted_next' => 50, 'actual_next' => 50.5],
                ['predicted_next' => 55, 'actual_next' => 56],
            ],
        ]));

        $this->assertSame('Medium', $result['level']);
    }

    public function test_honesty_cap_high_allowed_with_three_fulfilled(): void
    {
        $result = $this->service->computeForNextPrediction($this->nextContext([
            'real_obs_count' => 4,
            'prediction_history' => [
                ['predicted_next' => 50, 'actual_next' => 50.5],
                ['predicted_next' => 55, 'actual_next' => 56],
                ['predicted_next' => 60, 'actual_next' => 61.5],
            ],
        ]));

        $this->assertSame('High', $result['level']);
    }

    /*
    |--------------------------------------------------------------------------
    | Level thresholds + full weighted score
    |--------------------------------------------------------------------------
    */

    public function test_weighted_score_at_high_boundary(): void
    {
        // backtest 100 (0.4) + data 100 (0.3) + horizon 100 (0.15) + agreement 100 (0.15) = 100
        $result = $this->service->computeForCompletion($this->completionContext([
            'real_obs_count'     => 8,
            'estimated_index'    => 6,
            'current_index'      => 4,
            'agreement'          => 'High',
            'valid_count'        => 3,
            'spread_days'        => 10,
            'prediction_history' => [
                ['predicted_next' => 50, 'actual_next' => 50],
                ['predicted_next' => 55, 'actual_next' => 55],
                ['predicted_next' => 60, 'actual_next' => 60],
            ],
        ]));

        $this->assertSame(100, $result['score']);
        $this->assertSame('High', $result['level']);
    }

    public function test_weighted_score_medium_boundary(): void
    {
        // backtest 60 (no history) + data 40 (realObs 2) + horizon 100 + agreement 60
        // = 24 + 12 + 15 + 9 = 60 → Medium
        $result = $this->service->computeForNextPrediction($this->nextContext([
            'real_obs_count' => 2,
        ]));

        $this->assertSame(60, $result['score']);
        $this->assertSame('Medium', $result['level']);
    }

    public function test_weighted_score_low_boundary(): void
    {
        // backtest 60 + data 20 (realObs 1) + horizon 25 (30 months) + agreement 40 (Low)
        // = 24 + 6 + 3.75 + 6 = 39.75 → round 40 → Low
        $result = $this->service->computeForCompletion($this->completionContext([
            'real_obs_count'     => 1,
            'estimated_index'    => 34,
            'current_index'      => 4,
            'agreement'          => 'Low',
            'valid_count'        => 3,
            'spread_days'        => 120,
        ]));

        $this->assertSame(40, $result['score']);
        $this->assertSame('Low', $result['level']);
    }

    public function test_result_shape_contract(): void
    {
        $result = $this->service->computeForNextPrediction($this->nextContext());

        $this->assertArrayHasKey('level', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('floors', $result);
        $this->assertArrayHasKey('factors', $result);

        foreach (['backtest', 'data', 'horizon', 'agreement'] as $factor) {
            $this->assertArrayHasKey($factor, $result['factors']);
            $this->assertArrayHasKey('score', $result['factors'][$factor]);
            $this->assertArrayHasKey('detail', $result['factors'][$factor]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 2 (v2.6 §5.5) — blend shrink
    |--------------------------------------------------------------------------
    */

    public function test_blend_not_present_when_class_not_used(): void
    {
        $result = $this->service->computeForNextPrediction($this->nextContext());

        $this->assertArrayNotHasKey('blend', $result);
        // Score unchanged by the absent blend (multiplier 1.0):
        // backtest 60 + data 100 + horizon 100 + agreement 60 = 78.
        $this->assertSame(78, $result['score']);
    }

    public function test_blend_small_class_shrinks_score(): void
    {
        // backtest 100 + data 100 + horizon 100 + agreement 100 = 100.
        // class_count = 2 → ×0.80 → 80.
        $result = $this->service->computeForCompletion($this->completionContext([
            'real_obs_count'     => 8,
            'estimated_index'    => 6,
            'current_index'      => 4,
            'agreement'          => 'High',
            'valid_count'        => 3,
            'spread_days'        => 10,
            'prediction_history' => [
                ['predicted_next' => 50, 'actual_next' => 50],
                ['predicted_next' => 55, 'actual_next' => 55],
                ['predicted_next' => 60, 'actual_next' => 60],
            ],
            'class_used'         => true,
            'class_count'        => 2,
            'class_homogeneity'  => 8,
        ]));

        $this->assertSame(80, $result['score']);
        $this->assertSame(0.8, $result['blend']['multiplier']);
        $this->assertStringContainsString('Shrink: class of 2 curves', $result['blend']['detail']);
    }

    public function test_blend_wide_band_shrinks_score(): void
    {
        // Same perfect 100 baseline; spread 30 → ×0.85 → 85.
        $result = $this->service->computeForCompletion($this->completionContext([
            'real_obs_count'     => 8,
            'estimated_index'    => 6,
            'current_index'      => 4,
            'agreement'          => 'High',
            'valid_count'        => 3,
            'spread_days'        => 10,
            'prediction_history' => [
                ['predicted_next' => 50, 'actual_next' => 50],
                ['predicted_next' => 55, 'actual_next' => 55],
                ['predicted_next' => 60, 'actual_next' => 60],
            ],
            'class_used'         => true,
            'class_count'        => 6,
            'class_homogeneity'  => 30,
        ]));

        $this->assertSame(85, $result['score']);
        $this->assertSame(0.85, $result['blend']['multiplier']);
        $this->assertStringContainsString('wide class band (30%)', $result['blend']['detail']);
    }

    public function test_blend_shrink_multipliers_combine(): void
    {
        // count = 2 (×0.80) and spread = 30 (×0.85) → 0.68 (above the 0.60
        // floor, so it is preserved).
        $result = $this->service->computeForCompletion($this->completionContext([
            'real_obs_count'     => 8,
            'estimated_index'    => 6,
            'current_index'      => 4,
            'agreement'          => 'High',
            'valid_count'        => 3,
            'spread_days'        => 10,
            'prediction_history' => [
                ['predicted_next' => 50, 'actual_next' => 50],
                ['predicted_next' => 55, 'actual_next' => 55],
                ['predicted_next' => 60, 'actual_next' => 60],
            ],
            'class_used'         => true,
            'class_count'        => 2,
            'class_homogeneity'  => 30,
        ]));

        $this->assertSame(0.68, $result['blend']['multiplier']);
        // round(100 × 0.68) = 68
        $this->assertSame(68, $result['score']);
    }

    public function test_blend_healthy_class_no_shrink(): void
    {
        // count = 8, spread = 10 → no discount → multiplier 1.0, no shrink detail.
        $result = $this->service->computeForNextPrediction($this->nextContext([
            'class_used'        => true,
            'class_count'       => 8,
            'class_homogeneity' => 10,
        ]));

        $this->assertSame(1.0, $result['blend']['multiplier']);
        $this->assertSame('Blended with category reference curve', $result['blend']['detail']);
    }
}
