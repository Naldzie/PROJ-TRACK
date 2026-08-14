<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\RecommendationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesProjectWithHistory;
use Tests\TestCase;

/**
 * ============================================================================
 * B6 — RecommendationEngine At-Risk Integration
 * ============================================================================
 *
 * Purpose
 * -------
 * Verifies the B6 corrective rule in RecommendationEngine: when the
 * prediction's class_standing flags a project trailing its similar projects
 * for 2+ consecutive months, a corrective recommendation with the B6 reason
 * fires. Also verifies the honesty gate: no usable pool → no B6 rule fires.
 *
 * Runs
 * ----
 *   php artisan test --filter=RecommendationB6RuleTest
 */
class RecommendationB6RuleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesProjectWithHistory;

    private RecommendationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new RecommendationEngine();
    }

    /**
     * Build a minimal valid prediction payload: ≥3 observations and baselines
     * so generate() reaches assessCorrective() (skips the early guards).
     */
    private function buildPrediction(array $classStanding): array
    {
        return [
            'current_state' => [
                'progress'    => 5.0,
                'month_index' => 3,
            ],
            'assessment' => [
                ['risk_level' => 'Moderate', 'trend' => 'STABLE', 'progress' => 5.0],
            ],
            'regression' => [
                'observation_count' => 5,
                'fit_quality'       => 'Good',
            ],
            'class_standing' => $classStanding,
        ];
    }

    /**
     * T8 — class_behind_run >= 2 → a corrective recommendation with the B6
     * reason fires.
     */
    public function test_at_risk_flag_fires_corrective_recommendation(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [2, 4, 6, 8, 10],
            overrides: ['status' => 'Ongoing']
        );

        $prediction = $this->buildPrediction([
            'usable'         => true,
            'similar_count'  => 5,
            'tier'           => 'below_p25',
            'tier_label'     => 'in the slowest quarter of similar projects',
            'at_risk'        => true,
            'at_risk_run'    => 3,
            'lower_band'     => 12.0,
            'median_band'    => 20.0,
            'upper_band'     => 28.0,
        ]);

        $recommendations = $this->engine->generate($project, $prediction);

        $found = collect($recommendations)->first(fn ($r) =>
            $r['category'] === 'corrective'
            && str_contains($r['reason'], 'below the lower band of similar projects')
            && $r['priority'] === 3
        );

        $this->assertNotNull($found, 'Expected a B6 corrective recommendation but found none.');
        $this->assertStringContainsString('3 consecutive months', $found['message']);
    }

    /**
     * T8b — run < 2 does NOT fire the B6 corrective rule.
     */
    public function test_single_month_trail_does_not_fire_corrective(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [2, 4, 6, 8, 10],
            overrides: ['status' => 'Ongoing']
        );

        $prediction = $this->buildPrediction([
            'usable'         => true,
            'similar_count'  => 5,
            'tier'           => 'below_p25',
            'tier_label'     => 'in the slowest quarter of similar projects',
            'at_risk'        => false,
            'at_risk_run'    => 1,
            'lower_band'     => 12.0,
            'median_band'    => 20.0,
            'upper_band'     => 28.0,
        ]);

        $recommendations = $this->engine->generate($project, $prediction);

        foreach ($recommendations as $recommendation) {
            $this->assertNotContains(
                'below the lower band of similar projects',
                [$recommendation['reason']]
            );
        }
    }

    /**
     * T8c — unusable pool (no similar projects) never fires B6, even with a
     * stale at_risk flag in the payload.
     */
    public function test_unusable_pool_never_fires_corrective(): void
    {
        $project = $this->createProjectWithHistory(
            months: 5,
            actualProgress: [2, 4, 6, 8, 10],
            overrides: ['status' => 'Ongoing']
        );

        $prediction = $this->buildPrediction([
            'usable'         => false,
            'similar_count'  => 0,
            'tier'           => null,
            'tier_label'     => null,
            'at_risk'        => true,
            'at_risk_run'    => 3,
            'lower_band'     => null,
            'median_band'    => null,
            'upper_band'     => null,
        ]);

        $recommendations = $this->engine->generate($project, $prediction);

        foreach ($recommendations as $recommendation) {
            $this->assertNotContains(
                'below the lower band of similar projects',
                [$recommendation['reason']]
            );
        }
    }
}