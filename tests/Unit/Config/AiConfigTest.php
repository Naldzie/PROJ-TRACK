<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * Validates config/ai.php structure, types, and default values.
 * Foundation test — all config-consuming services depend on this.
 */
class AiConfigTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = config('ai');
    }

    public function test_config_returns_array(): void
    {
        $this->assertIsArray($this->config);
    }

    public function test_all_top_level_keys_exist(): void
    {
        $expected = [
            'cache', 'risk', 'trend', 'fit_quality', 'accuracy',
            'data_sufficiency', 'regression', 'contractor_rating',
            'notifications', 'recommendations', 'reliability',
            'similarity', 'completion',
        ];

        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $this->config, "Missing top-level key: {$key}");
        }
    }

    // ========================================================================
    // cache
    // ========================================================================

    public function test_cache_prediction_ttl_is_int(): void
    {
        $this->assertIsInt($this->config['cache']['prediction_ttl']);
    }

    public function test_cache_prediction_ttl_default(): void
    {
        $this->assertSame(480, $this->config['cache']['prediction_ttl']);
    }

    // ========================================================================
    // risk.gap
    // ========================================================================

    public function test_risk_gap_has_required_keys(): void
    {
        $gap = $this->config['risk']['gap'];
        $this->assertArrayHasKey('ahead', $gap);
        $this->assertArrayHasKey('on_track', $gap);
        $this->assertArrayHasKey('low', $gap);
        $this->assertArrayHasKey('medium', $gap);
    }

    public function test_risk_gap_values_are_numeric(): void
    {
        foreach ($this->config['risk']['gap'] as $key => $value) {
            $this->assertIsNumeric($value, "risk.gap.{$key} should be numeric");
        }
    }

    public function test_risk_gap_ladder_ordering(): void
    {
        $gap = $this->config['risk']['gap'];
        $this->assertLessThan($gap['on_track'], $gap['ahead']);
        $this->assertLessThan($gap['low'], $gap['on_track']);
        $this->assertLessThan($gap['medium'], $gap['low']);
    }

    // ========================================================================
    // trend
    // ========================================================================

    public function test_trend_variance_delta_is_positive(): void
    {
        $this->assertIsNumeric($this->config['trend']['variance_delta']);
        $this->assertGreaterThan(0, $this->config['trend']['variance_delta']);
    }

    // ========================================================================
    // fit_quality
    // ========================================================================

    public function test_fit_quality_keys_exist(): void
    {
        $fq = $this->config['fit_quality'];
        $this->assertArrayHasKey('excellent', $fq);
        $this->assertArrayHasKey('very_good', $fq);
        $this->assertArrayHasKey('good', $fq);
        $this->assertArrayHasKey('moderate', $fq);
    }

    public function test_fit_quality_values_are_floats_between_0_and_1(): void
    {
        foreach ($this->config['fit_quality'] as $key => $value) {
            $this->assertIsFloat((float) $value);
            $this->assertGreaterThanOrEqual(0, $value);
            $this->assertLessThanOrEqual(1, $value);
        }
    }

    public function test_fit_quality_ladder_ordering(): void
    {
        $fq = $this->config['fit_quality'];
        $this->assertGreaterThan($fq['very_good'], $fq['excellent']);
        $this->assertGreaterThan($fq['good'], $fq['very_good']);
        $this->assertGreaterThan($fq['moderate'], $fq['good']);
    }

    // ========================================================================
    // accuracy
    // ========================================================================

    public function test_accuracy_thresholds_are_positive(): void
    {
        $this->assertGreaterThan(0, $this->config['accuracy']['accurate_threshold']);
        $this->assertGreaterThan(0, $this->config['accuracy']['close_threshold']);
    }

    public function test_accurate_threshold_is_less_than_close(): void
    {
        $this->assertLessThan(
            $this->config['accuracy']['close_threshold'],
            $this->config['accuracy']['accurate_threshold']
        );
    }

    // ========================================================================
    // data_sufficiency
    // ========================================================================

    public function test_data_sufficiency_keys_exist(): void
    {
        $ds = $this->config['data_sufficiency'];
        $this->assertArrayHasKey('low', $ds);
        $this->assertArrayHasKey('moderate', $ds);
        $this->assertArrayHasKey('min_for_regression', $ds);
        $this->assertArrayHasKey('min_polynomial', $ds);
        $this->assertArrayHasKey('min_similar_projects', $ds);
    }

    public function test_data_sufficiency_ordering(): void
    {
        $ds = $this->config['data_sufficiency'];
        $this->assertLessThan($ds['moderate'], $ds['low']);
        // min_polynomial (4) is a regression gate, independent of low/moderate labels
        $this->assertGreaterThanOrEqual($ds['min_for_regression'], $ds['min_polynomial']);
    }

    // ========================================================================
    // regression
    // ========================================================================

    public function test_regression_min_observations(): void
    {
        $this->assertIsInt($this->config['regression']['min_observations']);
        $this->assertGreaterThanOrEqual(2, $this->config['regression']['min_observations']);
    }

    public function test_regression_polynomial_threshold(): void
    {
        $this->assertIsInt($this->config['regression']['min_observations_polynomial']);
        $this->assertGreaterThanOrEqual(4, $this->config['regression']['min_observations_polynomial']);
    }

    public function test_regression_slr_polynomial_r2_threshold(): void
    {
        $this->assertIsNumeric($this->config['regression']['slr_polynomial_r2_threshold']);
        $this->assertGreaterThan(0, $this->config['regression']['slr_polynomial_r2_threshold']);
        $this->assertLessThan(1, $this->config['regression']['slr_polynomial_r2_threshold']);
    }

    // ========================================================================
    // contractor_rating
    // ========================================================================

    public function test_contractor_rating_has_tiers_5_through_2(): void
    {
        $cr = $this->config['contractor_rating'];
        $this->assertArrayHasKey(5, $cr);
        $this->assertArrayHasKey(4, $cr);
        $this->assertArrayHasKey(3, $cr);
        $this->assertArrayHasKey(2, $cr);
    }

    public function test_contractor_rating_tier_structure(): void
    {
        foreach ($this->config['contractor_rating'] as $tier => $thresholds) {
            $this->assertArrayHasKey('on_time_pct', $thresholds, "Tier {$tier} missing on_time_pct");
            $this->assertArrayHasKey('avg_delay_days', $thresholds, "Tier {$tier} missing avg_delay_days");
            $this->assertIsNumeric($thresholds['on_time_pct']);
            $this->assertIsNumeric($thresholds['avg_delay_days']);
        }
    }

    public function test_contractor_rating_tier_ordering(): void
    {
        $cr = $this->config['contractor_rating'];
        // Higher tier requires higher on_time_pct and lower avg_delay_days
        $this->assertGreaterThan($cr[4]['on_time_pct'], $cr[5]['on_time_pct']);
        $this->assertLessThan($cr[4]['avg_delay_days'], $cr[5]['avg_delay_days']);
    }

    // ========================================================================
    // recommendations
    // ========================================================================

    public function test_recommendations_has_required_keys(): void
    {
        $rec = $this->config['recommendations'];
        $required = [
            'max_ai', 'max_rule', 'min_observations',
            'systemic_issues_count', 'material_issues_count',
            'contractor_issues_count', 'aging_issues_count',
            'carryover_count', 'deadline_days', 'deadline_progress',
            'at_risk_run',
        ];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $rec, "Missing recommendations key: {$key}");
        }
    }

    public function test_recommendations_max_values_are_positive(): void
    {
        $rec = $this->config['recommendations'];
        $this->assertGreaterThan(0, $rec['max_ai']);
        $this->assertGreaterThan(0, $rec['max_rule']);
    }

    // ========================================================================
    // reliability
    // ========================================================================

    public function test_reliability_has_weights_summing_to_100(): void
    {
        $weights = $this->config['reliability']['weights'];
        $sum = array_sum($weights);
        $this->assertEquals(100, $sum, 'Reliability weights must sum to 100');
    }

    public function test_reliability_threshold_ordering(): void
    {
        $this->assertGreaterThan(
            $this->config['reliability']['medium_threshold'],
            $this->config['reliability']['high_threshold']
        );
    }

    public function test_reliability_data_tiers_sorted_descending(): void
    {
        $tiers = $this->config['reliability']['data_tiers'];
        for ($i = 1; $i < count($tiers); $i++) {
            $this->assertGreaterThanOrEqual(
                $tiers[$i]['score'],
                $tiers[$i - 1]['score'],
                'Data tiers should be sorted by score descending'
            );
        }
    }

    // ========================================================================
    // similarity
    // ========================================================================

    public function test_similarity_weights_are_positive(): void
    {
        foreach ($this->config['similarity']['weights'] as $key => $value) {
            $this->assertGreaterThan(0, $value, "similarity.weights.{$key} should be positive");
        }
    }

    public function test_similarity_blend_k_is_positive(): void
    {
        $this->assertGreaterThan(0, $this->config['similarity']['blend_k']);
    }

    public function test_similarity_scale_ratio_range(): void
    {
        $this->assertLessThan(
            $this->config['similarity']['scale_ratio_max'],
            $this->config['similarity']['scale_ratio_min']
        );
    }

    // ========================================================================
    // completion
    // ========================================================================

    public function test_completion_confidence_r2_ordering(): void
    {
        $c = $this->config['completion'];
        $this->assertGreaterThan($c['medium_confidence_r2'], $c['high_confidence_r2']);
        $this->assertGreaterThan($c['min_r2_for_estimate'], $c['medium_confidence_r2']);
    }

    public function test_completion_obs_ordering(): void
    {
        $c = $this->config['completion'];
        $this->assertGreaterThan($c['medium_confidence_obs'], $c['high_confidence_obs']);
    }
}
