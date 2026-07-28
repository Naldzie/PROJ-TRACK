<?php

namespace Tests\Unit\Services\AI;

use App\Models\Project;
use App\Services\AI\HistoricalSimilarityService;
use PHPUnit\Framework\TestCase;

class HistoricalSimilarityServiceTest extends TestCase
{
    private HistoricalSimilarityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HistoricalSimilarityService();
    }

    // shouldSupplement — no supplement for 0 observations (project hasn't started)
    public function test_shouldSupplement_returns_none_for_zero_observations(): void
    {
        $project = $this->makeProject('Ongoing', 360);
        $result = $this->invokeShouldSupplement($project, 0);
        $this->assertSame('none', $result);
    }

    public function test_shouldSupplement_returns_supplement_for_one_observation(): void
    {
        $project = $this->makeProject('Ongoing', 360);
        $result = $this->invokeShouldSupplement($project, 1);
        $this->assertSame('supplement', $result);
    }

    // shouldSupplement — supplement mode (some data but < 1/3 of planned months)
    public function test_shouldSupplement_returns_supplement_when_below_threshold(): void
    {
        // 12 planned months → threshold = ceil(12/3) = 4
        // 3 observations < 4 → supplement
        $project = $this->makeProject('Ongoing', 365);
        $result = $this->invokeShouldSupplement($project, 3);
        $this->assertSame('supplement', $result);
    }

    // shouldSupplement — none mode (enough data)
    public function test_shouldSupplement_returns_none_when_at_threshold(): void
    {
        // 12 planned months → threshold = ceil(12/3) = 4
        // 4 observations >= 4 → none
        $project = $this->makeProject('Ongoing', 365);
        $result = $this->invokeShouldSupplement($project, 4);
        $this->assertSame('none', $result);
    }

    public function test_shouldSupplement_returns_none_when_above_threshold(): void
    {
        $project = $this->makeProject('Ongoing', 365);
        $result = $this->invokeShouldSupplement($project, 10);
        $this->assertSame('none', $result);
    }

    // shouldSupplement — completed project always none
    public function test_shouldSupplement_returns_none_for_completed_project(): void
    {
        $project = $this->makeProject('Completed', 365);
        $result = $this->invokeShouldSupplement($project, 0);
        $this->assertSame('none', $result);
    }

    // shouldSupplement — short project (minimum threshold is 2)
    public function test_shouldSupplement_has_minimum_threshold_of_two(): void
    {
        // Very short project: 3 planned months → threshold = ceil(3/3) = 1, clamped to 2
        // 1 observation < 2 → supplement
        $project = $this->makeProject('Ongoing', 90);
        $result = $this->invokeShouldSupplement($project, 1);
        $this->assertSame('supplement', $result);
    }

    // shouldSupplement — returns none when no duration and zero obs (project hasn't started)
    public function test_shouldSupplement_returns_none_with_no_duration_and_zero_obs(): void
    {
        $project = $this->makeProject('Ongoing', 0);
        $result = $this->invokeShouldSupplement($project, 0);
        $this->assertSame('none', $result);
    }

    public function test_shouldSupplement_returns_none_when_no_duration_and_sufficient_obs(): void
    {
        $project = $this->makeProject('Ongoing', 0);
        $result = $this->invokeShouldSupplement($project, 4);
        $this->assertSame('none', $result);
    }

    // generateSyntheticPoints — returns empty for empty aggregate
    public function test_generateSyntheticPoints_returns_empty_for_empty_aggregate(): void
    {
        $project = $this->makeProject('Ongoing', 365);
        $result = $this->invokeGenerateSyntheticPoints([], $project, 5);
        $this->assertSame([], $result);
    }

    // generateSyntheticPoints — returns empty for zero target
    public function test_generateSyntheticPoints_returns_empty_for_zero_target(): void
    {
        $project = $this->makeProject('Ongoing', 365);
        $aggregate = $this->makeAggregateCurve();
        $result = $this->invokeGenerateSyntheticPoints($aggregate, $project, 0);
        $this->assertSame([], $result);
    }

    // generateSyntheticPoints — returns correct count of points
    public function test_generateSyntheticPoints_returns_correct_count(): void
    {
        $project = $this->makeProject('Ongoing', 365);
        $aggregate = $this->makeAggregateCurve();
        $actualX = [1.0, 2.0];
        $actualY = [5.0, 12.0];
        $result = $this->invokeGenerateSyntheticPoints($aggregate, $project, 3, $actualX, $actualY);
        $this->assertCount(3, $result);
    }

    // generateSyntheticPoints — points have ascending month_index
    public function test_generateSyntheticPoints_months_are_ascending(): void
    {
        $project = $this->makeProject('Ongoing', 365);
        $aggregate = $this->makeAggregateCurve();
        $actualX = [1.0, 2.0];
        $actualY = [5.0, 12.0];
        $result = $this->invokeGenerateSyntheticPoints($aggregate, $project, 5, $actualX, $actualY);
        for ($i = 1; $i < count($result); $i++) {
            $this->assertGreaterThan(
                $result[$i - 1]['month_index'],
                $result[$i]['month_index']
            );
        }
    }

    // generateSyntheticPoints — progress values are clamped 0-100
    public function test_generateSyntheticPoints_progress_is_in_range(): void
    {
        $project = $this->makeProject('Ongoing', 365);
        $aggregate = $this->makeAggregateCurve();
        $actualX = [1.0, 2.0];
        $actualY = [5.0, 12.0];
        $result = $this->invokeGenerateSyntheticPoints($aggregate, $project, 10, $actualX, $actualY);
        foreach ($result as $point) {
            $this->assertGreaterThanOrEqual(0, $point['progress']);
            $this->assertLessThanOrEqual(100, $point['progress']);
        }
    }

    // generateFallbackDataset — skipped in unit test (requires DB)
    // Verified via AIIntegrationTest instead
    public function test_fallbackDataset_requires_db(): void
    {
        $this->markTestSkipped('DB-dependent. Verified via AIIntegrationTest.');
    }

    // Helper methods

    private function makeProject(string $status, int $durationDays): Project
    {
        $project = new Project();
        $project->status = $status;
        $project->planned_duration_days = $durationDays;
        $project->category_id = 1;
        return $project;
    }

    private function invokeShouldSupplement(Project $project, int $obsCount): string
    {
        // Override getTotalPlannedMonths behavior by setting planned_duration_days
        // The service will use planned_duration_days / 30.44 when baselines are empty
        return $this->service->shouldSupplement($project, $obsCount);
    }

    private function invokeGenerateSyntheticPoints(array $aggregate, Project $project, int $target, array $actualX = [], array $actualY = []): array
    {
        $ref = new \ReflectionMethod($this->service, 'generateSyntheticPoints');
        $ref->setAccessible(true);
        return $ref->invoke($this->service, $aggregate, $project, $target, $actualX, $actualY);
    }

    private function makeAggregateCurve(): array
    {
        return [
            0   => ['median' => 0.0,   'lower' => 0.0,   'upper' => 0.0],
            5   => ['median' => 0.5,   'lower' => 0.3,   'upper' => 0.8],
            10  => ['median' => 2.0,   'lower' => 1.5,   'upper' => 3.0],
            15  => ['median' => 5.0,   'lower' => 3.5,   'upper' => 7.0],
            20  => ['median' => 10.0,  'lower' => 8.0,   'upper' => 12.0],
            25  => ['median' => 16.0,  'lower' => 13.0,  'upper' => 19.0],
            30  => ['median' => 22.0,  'lower' => 18.0,  'upper' => 26.0],
            35  => ['median' => 29.0,  'lower' => 24.0,  'upper' => 34.0],
            40  => ['median' => 36.0,  'lower' => 30.0,  'upper' => 42.0],
            45  => ['median' => 44.0,  'lower' => 37.0,  'upper' => 50.0],
            50  => ['median' => 52.0,  'lower' => 44.0,  'upper' => 59.0],
            55  => ['median' => 60.0,  'lower' => 51.0,  'upper' => 68.0],
            60  => ['median' => 67.0,  'lower' => 58.0,  'upper' => 74.0],
            65  => ['median' => 74.0,  'lower' => 65.0,  'upper' => 81.0],
            70  => ['median' => 80.0,  'lower' => 72.0,  'upper' => 86.0],
            75  => ['median' => 85.0,  'lower' => 78.0,  'upper' => 91.0],
            80  => ['median' => 90.0,  'lower' => 83.0,  'upper' => 94.0],
            85  => ['median' => 94.0,  'lower' => 88.0,  'upper' => 97.0],
            90  => ['median' => 97.0,  'lower' => 93.0,  'upper' => 99.0],
            95  => ['median' => 99.0,  'lower' => 97.0,  'upper' => 100.0],
            100 => ['median' => 100.0, 'lower' => 100.0, 'upper' => 100.0],
        ];
    }
}
