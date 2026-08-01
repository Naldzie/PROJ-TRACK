<?php

namespace App\Services\AI;

use App\Models\Project;
use Illuminate\Support\Collection;

class HistoricalSimilarityService
{
    private const SIMILARITY_WEIGHT_CATEGORY  = 30;
    private const SIMILARITY_WEIGHT_BUDGET     = 25;
    private const SIMILARITY_WEIGHT_DURATION   = 20;
    private const SIMILARITY_WEIGHT_CONTRACTOR = 15;
    private const SIMILARITY_WEIGHT_WORKERS    = 10;

    private const BUDGET_TOLERANCE   = 0.20;
    private const DURATION_TOLERANCE = 0.30;
    private const WORKERS_TOLERANCE  = 0.40;

    private const MIN_OBSERVATIONS_FOR_CURVE = 4;

    private const AGGREGATE_INTERVAL = 5;

    private ?Collection $similarProjects = null;

    private ?array $aggregateCurve = null;

    public function __construct()
    {
    }

    // Determine whether and how to supplement the project's data
    public function shouldSupplement(Project $project, int $observationCount): string
    {
        if ($project->status === 'Completed') {
            return 'none';
        }

        if ($observationCount === 0) {
            return 'none';
        }

        if ($observationCount === 1) {
            return 'supplement';
        }

        $totalPlannedMonths = $this->getTotalPlannedMonths($project);

        if ($totalPlannedMonths <= 0) {
            return 'none';
        }

        $threshold = max(2, (int) ceil($totalPlannedMonths / 3));

        if ($observationCount < $threshold) {
            return 'supplement';
        }

        return 'none';
    }

    // Find similar completed projects sorted by similarity score
    public function findSimilarProjects(Project $project): Collection
    {
        if ($this->similarProjects !== null) {
            return $this->similarProjects;
        }

        $candidates = Project::where('status', 'Completed')
            ->where('id', '!=', $project->id)
            ->where('category_id', $project->category_id)
            ->whereNotNull('planned_duration_days')
            ->get();

        $scored = $candidates->map(function ($candidate) use ($project) {
            $score = $this->computeSimilarityScore($project, $candidate);
            return ['project' => $candidate, 'score' => $score];
        })->filter(fn ($item) => $item['score'] > 0);

        $sorted = $scored->sortByDesc('score')->values();

        $result = $sorted->map(fn ($item) => $item['project']);

        $this->similarProjects = $result;

        return $result;
    }

    // Count how many similar projects were found
    public function getSimilarProjectsCount(): int
    {
        $projects = $this->findSimilarProjects(new Project());
        return $projects->count();
    }

    public function getSimilarProjectsCountFor(Project $project): int
    {
        return $this->findSimilarProjects($project)->count();
    }

    // Build aggregate curve from similar completed projects
    public function buildAggregateCurve(Project $project): array
    {
        if ($this->aggregateCurve !== null) {
            return $this->aggregateCurve;
        }

        $similar = $this->findSimilarProjects($project);

        $validCurves = $similar->filter(function ($candidate) {
            $obsCount = $candidate->progressHistory()->count();
            return $obsCount >= self::MIN_OBSERVATIONS_FOR_CURVE;
        });

        if ($validCurves->isEmpty()) {
            $this->aggregateCurve = [];
            return [];
        }

        $percentiles = range(0, 100, self::AGGREGATE_INTERVAL);

        $allCurves = $validCurves->map(function ($candidate) use ($percentiles) {
            return $this->normalizeToPercentile($candidate, $percentiles);
        })->filter(fn ($curve) => !empty($curve));

        if ($allCurves->isEmpty()) {
            $this->aggregateCurve = [];
            return [];
        }

        $aggregate = [];
        foreach ($percentiles as $pct) {
            $values = $allCurves->map(fn ($curve) => $curve[$pct] ?? null)
                ->filter(fn ($v) => $v !== null)
                ->values()
                ->toArray();

            if (empty($values)) {
                $aggregate[$pct] = ['median' => null, 'lower' => null, 'upper' => null];
                continue;
            }

            sort($values);
            $count = count($values);
            $median = $this->medianOfArray($values);
            $lower  = $values[(int) floor($count * 0.25)];
            $upper  = $values[(int) floor($count * 0.75)];

            $aggregate[$pct] = [
                'median' => round($median, 2),
                'lower'  => round($lower, 2),
                'upper'  => round($upper, 2),
            ];
        }

        $this->aggregateCurve = $aggregate;

        return $aggregate;
    }

    // Generate synthetic dataset points from the aggregate curve
    public function generateSyntheticPoints(
        array $aggregateCurve,
        Project $project,
        int $targetCount,
        array $actualX = [],
        array $actualY = []
    ): array {
        if (empty($aggregateCurve) || $targetCount <= 0) {
            return [];
        }

        $totalPlannedMonths = $this->getTotalPlannedMonths($project);

        if ($totalPlannedMonths <= 0) {
            return [];
        }

        // Accept pre-built x/y arrays, or query from the project
        if (empty($actualX) || empty($actualY)) {
            $history = $project->progressHistory()
                ->orderBy('month_label')
                ->get();

            foreach ($history->values() as $index => $record) {
                $actualX[] = (float) ($index + 1);
                $actualY[] = (float) $record->actual_progress_percent;
            }
        }

        // Compute scale ratio: how the project's actual progress compares
        // to the aggregate median at each observed month's percentile
        $ratios = [];
        foreach ($actualX as $i => $x) {
            $pct = ($x / $totalPlannedMonths) * 100;
            $aggValue = $this->interpolateAggregate($aggregateCurve, $pct);
            if ($aggValue !== null && $aggValue > 0 && $actualY[$i] > 0) {
                $ratios[] = $actualY[$i] / $aggValue;
            }
        }

        // Use median ratio to scale aggregate curve
        $scaleRatio = !empty($ratios) ? $this->medianOfArray($ratios) : 1.0;

        // Clamp scale ratio to reasonable range (0.2 to 5.0)
        $scaleRatio = max(0.2, min(5.0, $scaleRatio));

        $syntheticPoints = [];
        for ($i = 1; $i <= $targetCount; $i++) {
            $pct = ($i / $totalPlannedMonths) * 100;
            $aggValue = $this->interpolateAggregate($aggregateCurve, $pct);

            if ($aggValue === null) {
                continue;
            }

            $scaled = $aggValue * $scaleRatio;

            $syntheticPoints[] = [
                'month_index' => $i,
                'progress'    => round(max(0, min(100, $scaled)), 2),
            ];
        }

        return $syntheticPoints;
    }

    // Compute a similarity score between two projects (0.0 to 1.0)
    private function computeSimilarityScore(Project $target, Project $candidate): float
    {
        $score = 0.0;

        // Category match (mandatory — already filtered in query)
        $score += self::SIMILARITY_WEIGHT_CATEGORY;

        // Budget proximity
        $score += $this->proximityScore(
            (float) ($target->approved_budget ?? 0),
            (float) ($candidate->approved_budget ?? 0),
            self::BUDGET_TOLERANCE,
            self::SIMILARITY_WEIGHT_BUDGET
        );

        // Duration proximity
        $score += $this->proximityScore(
            (float) ($target->planned_duration_days ?? 0),
            (float) ($candidate->planned_duration_days ?? 0),
            self::DURATION_TOLERANCE,
            self::SIMILARITY_WEIGHT_DURATION
        );

        // Contractor match (bonus)
        if (
            $target->contractor_id !== null
            && $candidate->contractor_id !== null
            && $target->contractor_id === $candidate->contractor_id
        ) {
            $score += self::SIMILARITY_WEIGHT_CONTRACTOR;
        }

        // Workers proximity (if both non-null)
        if (
            $target->estimated_workers !== null
            && $candidate->estimated_workers !== null
            && $target->estimated_workers > 0
            && $candidate->estimated_workers > 0
        ) {
            $score += $this->proximityScore(
                (float) $target->estimated_workers,
                (float) $candidate->estimated_workers,
                self::WORKERS_TOLERANCE,
                self::SIMILARITY_WEIGHT_WORKERS
            );
        }

        return $score;
    }

    // Calculate proximity score: full weight if within tolerance, partial beyond
    private function proximityScore(float $target, float $candidate, float $tolerance, float $weight): float
    {
        if ($target <= 0 || $candidate <= 0) {
            return 0;
        }

        $ratio = $candidate / $target;

        if ($ratio >= (1 - $tolerance) && $ratio <= (1 + $tolerance)) {
            return $weight;
        }

        // Partial score for being close but outside tolerance
        $distance = $ratio > 1 ? ($ratio - (1 + $tolerance)) : ((1 - $tolerance) - $ratio);
        $penalty = $distance / $tolerance;

        return max(0, $weight * (1 - $penalty));
    }

    // Normalize a project's progress history to percentile-based timeline
    private function normalizeToPercentile(Project $project, array $percentiles): array
    {
        $records = $project->progressHistory()
            ->orderBy('month_label')
            ->get();

        if ($records->isEmpty()) {
            return [];
        }

        $totalMonths = max(1, $records->count());
        $result = [];

        foreach ($percentiles as $pct) {
            $targetMonth = ($pct / 100) * $totalMonths;
            $result[$pct] = $this->interpolateRecordAt($records, $targetMonth);
        }

        return $result;
    }

    // Interpolate progress at a fractional month index
    private function interpolateRecordAt(Collection $records, float $targetIndex): ?float
    {
        $floor = (int) floor($targetIndex);
        $ceil  = (int) ceil($targetIndex);

        $values = $records->values();

        if ($floor < 0) {
            return (float) ($values[0]->actual_progress_percent ?? 0);
        }

        if ($floor >= $values->count() - 1) {
            $last = $values->last();
            return (float) ($last ? $last->actual_progress_percent : 0);
        }

        $lower = $values[$floor];
        $upper = $values[$ceil];

        $lowerVal = (float) ($lower->actual_progress_percent ?? 0);
        $upperVal = (float) ($upper->actual_progress_percent ?? 0);

        $fraction = $targetIndex - $floor;

        return $lowerVal + ($upperVal - $lowerVal) * $fraction;
    }

    // Interpolate aggregate curve value at a given percentile
    private function interpolateAggregate(array $aggregateCurve, float $percentile): ?float
    {
        $keys = array_keys($aggregateCurve);
        sort($keys);

        if (empty($keys)) {
            return null;
        }

        if ($percentile <= $keys[0]) {
            return $aggregateCurve[$keys[0]]['median'];
        }

        if ($percentile >= $keys[count($keys) - 1]) {
            return $aggregateCurve[$keys[count($keys) - 1]]['median'];
        }

        $lowerKey = null;
        $upperKey = null;

        foreach ($keys as $key) {
            if ($key <= $percentile) {
                $lowerKey = $key;
            }
            if ($key >= $percentile && $upperKey === null) {
                $upperKey = $key;
            }
        }

        if ($lowerKey === null || $upperKey === null || $lowerKey === $upperKey) {
            return $aggregateCurve[$lowerKey ?? $upperKey]['median'];
        }

        $lowerVal = $aggregateCurve[$lowerKey]['median'];
        $upperVal = $aggregateCurve[$upperKey]['median'];

        if ($lowerVal === null || $upperVal === null) {
            return null;
        }

        $fraction = ($percentile - $lowerKey) / ($upperKey - $lowerKey);

        return $lowerVal + ($upperVal - $lowerVal) * $fraction;
    }

    // Get total planned months from baseline schedules
    private function getTotalPlannedMonths(Project $project): int
    {
        // Use planned_duration_days as primary source (no DB query needed)
        if ($project->planned_duration_days && $project->planned_duration_days > 0) {
            return (int) ceil($project->planned_duration_days / 30.44);
        }

        // Fall back to baseline schedules count if available
        if ($project->relationLoaded('baselineSchedules')) {
            $count = $project->baselineSchedules->count();
            if ($count > 0) {
                return $count;
            }
        }

        return 0;
    }

    // Calculate median of a numeric array
    private function medianOfArray(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $mid = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }

        return $values[$mid];
    }

    // Estimate supplement target: how many total observations the dataset should have
    public function getSupplementTarget(Project $project): int
    {
        $totalMonths = $this->getTotalPlannedMonths($project);
        return max(2, (int) ceil($totalMonths / 3));
    }

    // Generate full fallback dataset (when project has < 2 own observations)
    public function generateFallbackDataset(Project $project): ?array
    {
        $aggregate = $this->buildAggregateCurve($project);

        if (empty($aggregate)) {
            return null;
        }

        $totalMonths = $this->getTotalPlannedMonths($project);

        if ($totalMonths <= 0) {
            return null;
        }

        // Generate synthetic points for all planned months
        $synthetic = $this->generateSyntheticPoints($aggregate, $project, $totalMonths);

        if (empty($synthetic)) {
            return null;
        }

        $x = [];
        $y = [];

        foreach ($synthetic as $point) {
            $x[] = (float) $point['month_index'];
            $y[] = $point['progress'];
        }

        $similarCount = $this->findSimilarProjects($project)->count();

        return [
            'x' => $x,
            'y' => $y,
            'statistics' => [
                'observation_count'   => count($x),
                'current_month_index' => count($x),
            ],
            'data_source'           => 'similar_projects',
            'similar_projects_count' => $similarCount,
            'synthetic_points_count' => count($x),
        ];
    }

    // Generate supplemental points to augment the real dataset
    public function generateSupplementDataset(Project $project, array $realDataset): ?array
    {
        $aggregate = $this->buildAggregateCurve($project);

        if (empty($aggregate)) {
            return null;
        }

        $realCount = $realDataset['statistics']['observation_count'] ?? 0;
        $target = $this->getSupplementTarget($project);
        $needed = $target - $realCount;

        if ($needed <= 0) {
            return null;
        }

        $synthetic = $this->generateSyntheticPoints($aggregate, $project, $needed);

        if (empty($synthetic)) {
            return null;
        }

        // Prepend synthetic points to real data
        $x = array_merge(
            array_map(fn ($p) => (float) $p['month_index'], $synthetic),
            $realDataset['x']
        );

        $y = array_merge(
            array_map(fn ($p) => $p['progress'], $synthetic),
            $realDataset['y']
        );

        $similarCount = $this->findSimilarProjects($project)->count();

        $combinedCount = count($x);

        return [
            'x'                     => $x,
            'y'                     => $y,
            'records'               => $realDataset['records'] ?? collect(),
            'statistics'            => [
                'observation_count'   => $combinedCount,
                'current_month_index' => $realDataset['statistics']['current_month_index'],
            ],
            'data_source'           => 'hybrid',
            'similar_projects_count' => $similarCount,
            'synthetic_points_count' => count($synthetic),
        ];
    }
}
