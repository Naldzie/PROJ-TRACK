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

    // Minimum number of USABLE similar curves required before the aggregate
    // is trusted. One or two curves are not a real reference-class pattern.
    private const MIN_SIMILAR_PROJECTS = 3;

    // Minimum similarity score (before the recency multiplier) required for a
    // completed project to be considered "similar". Scored on the factors BOTH
    // projects record (v2.6 4.3: a field missing on either side is omitted
    // from the denominator, so lack of metadata never silently tanks a match).
    // A category match alone is never enough — the candidate must also match
    // on budget, duration, workers, or contractor (v2.6 4.3). Recency only
    // reorders candidates (it never disqualifies).
    private const MIN_SIMILARITY_SCORE = 50;

    // Floors/caps for scaling the borrowed curve onto the project's own
    // trajectory. The floor prevents a tiny first observation from
    // flattening the whole curve into a meaningless near-flat line.
    private const MIN_SCALE_RATIO = 0.5;
    private const MAX_SCALE_RATIO = 5.0;

    private const AGGREGATE_INTERVAL = 5;

    // Phase 2 (v2.6 §5.3) blend schedule: w = obs / (obs + k). The class
    // reference enters as a BAYESIAN-style prior/shinkage: a sparse project's
    // forecast is mostly the class curve, a rich project's forecast is mostly
    // its own regression. k = 4 means w reaches 0.50 at 4 real observations
    // (Decision 4 — refined later via the C7 backtest).
    private const BLEND_K = 4;

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

        // A4 (v2.5 §3 A4 / v2.6 §5.6): a brand-new project with 0 observations
        // can still show a clearly-labeled CATEGORY BASELINE estimate when it
        // has a planned duration (the pool-quality gate lives downstream — if
        // fewer than MIN_SIMILAR_PROJECTS usable curves exist,
        // buildAggregateCurve() returns [] and generateFallbackDataset()
        // returns null, so the pipeline falls through to the existing
        // Insufficient Data path). No planned duration → nothing to anchor a
        // baseline curve to → 'none'.
        if ($observationCount === 0) {
            return $this->getTotalPlannedMonths($project) > 0 ? 'fallback' : 'none';
        }

        if ($observationCount === 1) {
            return 'supplement';
        }

        // Phase 2 (v2.6 §5.1/§5.3, Decision 8): every project with >= 2 real
        // observations is now handled by the reference-class BLEND — its own
        // regression fits on REAL points only, and predictions are pulled
        // toward the class curve by w = obs/(obs+k). No synthetic rows are
        // injected for obs >= 2 (the old '2 .. threshold' supplement path is
        // intentionally retired in favor of the blend). The blend activates
        // in AIPredictionService when a usable class reference exists; with
        // no pool it falls back to project_history. Only the 0-obs 'fallback'
        // and 1-obs 'supplement' (hybrid) paths keep their synthetic datasets.
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
            $breakdown = $this->computeSimilarityBreakdown($project, $candidate);
            $earned    = (float) array_sum($breakdown);

            // v2.6 4.3 + Step 5: a category match alone is NEVER enough — the
            // candidate must earn points on at least one comparable non-category
            // factor (budget/duration/workers/contractor). Without this, the
            // denominator shrink below would admit a pure category match.
            if (($earned - (float) $breakdown['category']) <= 0) {
                return null;
            }

            // v2.6 4.3 null-safe scoring: the score is earned points divided by
            // the weight of factors BOTH projects actually record (fields
            // missing on either side are omitted from the denominator). Missing
            // metadata therefore never silently tanks a genuine match — but a
            // weak candidate still can't clear the minimum.
            $rawScore = $this->computeSimilarityScore($project, $candidate);

            // Step 5 safety valve: require a minimum similarity score. Filtering
            // on the raw score BEFORE the recency multiplier keeps old-but-
            // perfect matches eligible; recency only reorders, never disqualifies.
            if ($rawScore < self::MIN_SIMILARITY_SCORE) {
                return null;
            }

            $score = $rawScore * $this->recencyFactor($candidate);
            return ['project' => $candidate, 'score' => $score];
        })->filter(fn ($item) => $item !== null);

        $sorted = $scored->sortByDesc('score')->values();

        $result = $sorted->map(fn ($item) => $item['project']);

        $this->similarProjects = $result;

        return $result;
    }

    // Audit details for every similar project: name, final score, and the
    // per-factor score breakdown + recency multiplier. Used by the dev
    // console.log on the AI Prediction tab when cross-project data activates.
    public function getSimilarProjectsDetails(Project $project): array
    {
        $details = [];

        foreach ($this->findSimilarProjects($project) as $candidate) {
            $breakdown = $this->computeSimilarityBreakdown($project, $candidate);
            $rawScore  = $this->computeSimilarityScore($project, $candidate);
            $recency   = $this->recencyFactor($candidate);

            $details[] = [
                'id'        => $candidate->id,
                'code'      => $candidate->code,
                'title'     => $candidate->title,
                'score'     => round($rawScore * $recency, 2),
                'recency'   => round($recency, 3),
                'usable'    => $candidate->progressHistory()->count() >= self::MIN_OBSERVATIONS_FOR_CURVE,
                'breakdown' => [
                    'category'   => round($breakdown['category'], 2),
                    'budget'     => round($breakdown['budget'], 2),
                    'duration'   => round($breakdown['duration'], 2),
                    'contractor' => round($breakdown['contractor'], 2),
                    'workers'    => round($breakdown['workers'], 2),
                ],
            ];
        }

        return $details;
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

        // Safety valve (C8): refuse to borrow when too few usable curves
        // exist. Both dataset generators build from this aggregate, so they
        // return null here and AIPredictionService falls back to the
        // project's own data (data_source stays 'project_history').
        if ($validCurves->count() < self::MIN_SIMILAR_PROJECTS) {
            $this->aggregateCurve = [];
            return [];
        }

        $percentiles = range(0, 100, self::AGGREGATE_INTERVAL);

        // v2.6 Phase 2 §5.2 (kNN-style weighting): each usable curve carries
        // its similarity weight (final score = raw × recency), so stronger
        // matches dominate the aggregate. Pairs of [percentile => value] with
        // their weight are gathered, then the weighted median/P25/P75 is
        // computed per percentile.
        $weightedCurves = $validCurves->map(function ($candidate) use ($percentiles, $project) {
            $curve = $this->normalizeToPercentile($candidate, $percentiles);

            if (empty($curve)) {
                return null;
            }

            $weight = $this->computeSimilarityScore($project, $candidate)
                * $this->recencyFactor($candidate);

            return [
                'curve'  => $curve,
                'weight' => max(0.01, $weight),
            ];
        })->filter(fn ($entry) => $entry !== null);

        if ($weightedCurves->isEmpty()) {
            $this->aggregateCurve = [];
            return [];
        }

        $aggregate = [];
        foreach ($percentiles as $pct) {
            $weightedValues = $weightedCurves
                ->map(fn ($entry) => [
                    'value'  => $entry['curve'][$pct] ?? null,
                    'weight' => $entry['weight'],
                ])
                ->filter(fn ($v) => $v['value'] !== null)
                ->values()
                ->toArray();

            if (empty($weightedValues)) {
                $aggregate[$pct] = ['median' => null, 'lower' => null, 'upper' => null];
                continue;
            }

            $aggregate[$pct] = [
                'median' => round($this->weightedPercentile($weightedValues, 0.50), 2),
                'lower'  => round($this->weightedPercentile($weightedValues, 0.25), 2),
                'upper'  => round($this->weightedPercentile($weightedValues, 0.75), 2),
            ];
        }

        $this->aggregateCurve = $aggregate;

        return $aggregate;
    }

    // Weighted percentile (kNN-style, v2.6 §5.2): sorts (value, weight) pairs
    // by value, walks cumulative weight, and returns the value whose
    // cumulative weight reaches `fraction` of the total. Equal weights
    // reproduce the earlier equal-weight median/quartile behaviour.
    private function weightedPercentile(array $weightedValues, float $fraction): ?float
    {
        if (empty($weightedValues)) {
            return null;
        }

        uasort($weightedValues, fn ($a, $b) => $a['value'] <=> $b['value']);
        $weightedValues = array_values($weightedValues);

        $totalWeight = array_sum(array_column($weightedValues, 'weight'));

        if ($totalWeight <= 0) {
            return null;
        }

        $target = $totalWeight * $fraction;

        $cumulative = 0.0;

        foreach ($weightedValues as $index => $pair) {
            $previousCumulative = $cumulative;
            $cumulative += $pair['weight'];

            if ($cumulative >= $target || $index === count($weightedValues) - 1) {
                $prevValue = $weightedValues[$index - 1]['value'] ?? $pair['value'];

                // Interpolate within the crossing interval for a smooth
                // percentile (matches equal-weight median-of-array rounding).
                if ($cumulative > $previousCumulative) {
                    $fractionWithin = ($target - $previousCumulative)
                        / ($cumulative - $previousCumulative);

                    return $prevValue
                        + ($pair['value'] - $prevValue) * max(0, min(1, $fractionWithin));
                }

                return $pair['value'];
            }
        }

        return ($weightedValues[count($weightedValues) - 1]['value'] ?? null);
    }

    // Phase 2 (v2.6 §5.3): partial-pooling shrink weight w = obs / (obs + k).
    // A project with 0 real observations → w = 0 (pure class); 4 real obs →
    // w = 0.50 (half/half); 20 obs → w ≈ 0.83 (mostly own). k tuned via the
    // C7 backtest (Decision 4, default 4).
    public function blendWeight(int $observationCount): float
    {
        $obs = max(0, $observationCount);

        return $obs / ($obs + self::BLEND_K);
    }

    // Phase 2 (v2.6 §5.2/§5.4): build the reference-class curve as a
    // month-aligned SCALED band [median/lower/upper at every planned month]
    // that the prediction pipeline blends against. Returns [] (no class) when
    // fewer than MIN_SIMILAR_PROJECTS usable curves exist or the project has
    // no planned duration.
    //
    // The raw aggregate is percentile-based (0..100% of plan). We map each
    // planned month to its percentile, interpolate median/lower/upper, then
    // SCALE the whole band onto the project's own trajectory using the same
    // median actual-vs-class ratio as generateSyntheticPoints() — so a small
    // project's class reference sits near its own progress level instead of
    // being an absolute % of an unrelated category.
    public function buildClassReference(Project $project, int $totalPlannedMonths): array
    {
        $aggregate = $this->buildAggregateCurve($project);

        if (empty($aggregate) || $totalPlannedMonths <= 0) {
            return [];
        }

        // Real observations for the scale ratio (same as generateSyntheticPoints)
        $history = $project->progressHistory()
            ->orderBy('month_label')
            ->get();

        $actualX = [];
        $actualY = [];

        foreach ($history->values() as $index => $record) {
            $actualX[] = (float) ($index + 1);
            $actualY[] = (float) $record->actual_progress_percent;
        }

        $ratios = [];
        foreach ($actualX as $i => $x) {
            $pct = ($x / $totalPlannedMonths) * 100;
            $aggValue = $this->interpolateAggregate($aggregate, $pct);
            if ($aggValue !== null && $aggValue > 0 && $actualY[$i] > 0) {
                $ratios[] = $actualY[$i] / $aggValue;
            }
        }

        $scaleRatio = !empty($ratios) ? $this->medianOfArray($ratios) : 1.0;
        $scaleRatio = max(self::MIN_SCALE_RATIO, min(self::MAX_SCALE_RATIO, $scaleRatio));

        $reference = [
            'median' => [],
            'lower'  => [],
            'upper'  => [],
        ];

        for ($i = 1; $i <= $totalPlannedMonths; $i++) {
            $pct = ($i / $totalPlannedMonths) * 100;
            $band = $this->interpolateAggregateBand($aggregate, $pct);

            if ($band === null) {
                continue;
            }

            $reference['median'][$i] = round(max(0, min(100, $band['median'] * $scaleRatio)), 2);
            $reference['lower'][$i]  = round(max(0, min(100, ($band['lower'] ?? $band['median']) * $scaleRatio)), 2);
            $reference['upper'][$i]  = round(max(0, min(100, ($band['upper'] ?? $band['median']) * $scaleRatio)), 2);
        }

        // Class homogeneity (spread): mean band width across the plan's months.
        // Wide band / small pool → lower reliability (v2.6 §5.5).
        $spread = $this->aggregateBandSpread($reference);

        $reference['similar_projects_count'] = $validCount = $this->countUsableCurves($project);

        // Group homogeneity (mean P25-P75 width as % of progress) for the
        // reliability shrink.
        $reference['homogeneity'] = $spread;

        return $reference;
    }

    // Mean of (upper - lower) across a month-aligned reference band. Used by
    // buildClassReference() and consumed by reliability for the §5.5 shrink.
    public function aggregateBandSpread(array $reference): ?float
    {
        $widths = [];

        foreach (($reference['median'] ?? []) as $month => $median) {
            $lower = $reference['lower'][$month] ?? null;
            $upper = $reference['upper'][$month] ?? null;

            if ($lower !== null && $upper !== null) {
                $widths[] = $upper - $lower;
            }
        }

        return empty($widths) ? null : round(array_sum($widths) / count($widths), 2);
    }

    // Number of similar completed projects whose curve is usable for the
    // aggregate (>= MIN_OBSERVATIONS_FOR_CURVE) — the honest "N similar
    // projects" reported to the UI.
    public function countUsableCurves(Project $project): int
    {
        return $this->findSimilarProjects($project)
            ->filter(fn ($candidate) =>
                $candidate->progressHistory()->count() >= self::MIN_OBSERVATIONS_FOR_CURVE
            )
            ->count();
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

        // Clamp scale ratio to reasonable range (MIN_SCALE_RATIO to MAX_SCALE_RATIO)
        // The floor stops a tiny first observation from flattening the curve.
        $scaleRatio = max(self::MIN_SCALE_RATIO, min(self::MAX_SCALE_RATIO, $scaleRatio));

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

    // Compute a similarity score between two projects (0.0 to 100.0),
    // normalized to the factors BOTH projects actually record (v2.6 4.3).
    // earned points / available weight * 100. When data is absent on either
    // side, that factor is omitted from the denominator, so missing metadata
    // neither fakes a match nor silently tanks a genuine one.
    private function computeSimilarityScore(Project $target, Project $candidate): float
    {
        $earned = (float) array_sum($this->computeSimilarityBreakdown($target, $candidate));
        $available = $this->availableSimilarityWeight($target, $candidate);

        if ($available <= 0) {
            return 0.0;
        }

        return round($earned / $available * 100, 2);
    }

    // Total weight of factors that BOTH projects actually record. Fields that
    // are absent (null / <= 0) on either side are excluded, so the score is
    // always compared against what was actually comparable (v2.6 4.3).
    private function availableSimilarityWeight(Project $target, Project $candidate): int
    {
        $available = self::SIMILARITY_WEIGHT_CATEGORY;

        if ((float) ($target->approved_budget ?? 0) > 0 && (float) ($candidate->approved_budget ?? 0) > 0) {
            $available += self::SIMILARITY_WEIGHT_BUDGET;
        }

        if ((float) ($target->planned_duration_days ?? 0) > 0 && (float) ($candidate->planned_duration_days ?? 0) > 0) {
            $available += self::SIMILARITY_WEIGHT_DURATION;
        }

        if ($target->contractor_id !== null && $candidate->contractor_id !== null) {
            $available += self::SIMILARITY_WEIGHT_CONTRACTOR;
        }

        if ((int) ($target->estimated_workers ?? 0) > 0 && (int) ($candidate->estimated_workers ?? 0) > 0) {
            $available += self::SIMILARITY_WEIGHT_WORKERS;
        }

        return $available;
    }

    // Per-factor similarity breakdown (category/budget/duration/contractor/workers).
    // Shared by computeSimilarityScore() (sums the points) and
    // getSimilarProjectsDetails() (exposes the audit breakdown for the dev log).
    private function computeSimilarityBreakdown(Project $target, Project $candidate): array
    {
        // Category match (mandatory — already filtered in query)
        $category = self::SIMILARITY_WEIGHT_CATEGORY;

        // Budget proximity
        $budget = $this->proximityScore(
            (float) ($target->approved_budget ?? 0),
            (float) ($candidate->approved_budget ?? 0),
            self::BUDGET_TOLERANCE,
            self::SIMILARITY_WEIGHT_BUDGET
        );

        // Duration proximity
        $duration = $this->proximityScore(
            (float) ($target->planned_duration_days ?? 0),
            (float) ($candidate->planned_duration_days ?? 0),
            self::DURATION_TOLERANCE,
            self::SIMILARITY_WEIGHT_DURATION
        );

        // Contractor match (bonus)
        $contractor = (
            $target->contractor_id !== null
            && $candidate->contractor_id !== null
            && $target->contractor_id === $candidate->contractor_id
        ) ? self::SIMILARITY_WEIGHT_CONTRACTOR : 0;

        // Workers proximity (if both non-null)
        $workers = 0;
        if (
            $target->estimated_workers !== null
            && $candidate->estimated_workers !== null
            && $target->estimated_workers > 0
            && $candidate->estimated_workers > 0
        ) {
            $workers = $this->proximityScore(
                (float) $target->estimated_workers,
                (float) $candidate->estimated_workers,
                self::WORKERS_TOLERANCE,
                self::SIMILARITY_WEIGHT_WORKERS
            );
        }

        return [
            'category'   => (float) $category,
            'budget'     => (float) $budget,
            'duration'   => (float) $duration,
            'contractor' => (float) $contractor,
            'workers'    => (float) $workers,
        ];
    }

    // Compute a recency multiplier (1.0 to 0.5) from a completed project's
    // actual_end_date. Full weight within FULL_WEIGHT_YEARS; decays linearly
    // to 0.5 at DECAY_END_YEARS. Missing date → full weight.
    // Used by: findSimilarProjects() to order which candidates feed the
    // aggregate curve (newer projects are treated as more representative).
    private function recencyFactor(Project $candidate): float
    {
        $constFullWeightYears = 2.0;
        $constDecayEndYears    = 5.0;

        $endDate = $candidate->actual_end_date;

        if (!$endDate) {
            return 1.0;
        }

        $yearsOld = max(0, $endDate->diffInYears(\Illuminate\Support\Carbon::now()));

        if ($yearsOld <= $constFullWeightYears) {
            return 1.0;
        }

        if ($yearsOld >= $constDecayEndYears) {
            return 0.5;
        }

        $span = $constDecayEndYears - $constFullWeightYears;

        return 1.0 - (($yearsOld - $constFullWeightYears) / $span) * 0.5;
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

    // Interpolate the FULL aggregate band (median/lower/upper) at a given
    // percentile. Used by buildClassReference() (Phase 2 §5.2/§5.4) so the
    // blend has a P25/P75 range, not just a median point.
    private function interpolateAggregateBand(array $aggregateCurve, float $percentile): ?array
    {
        $keys = array_keys($aggregateCurve);
        sort($keys);

        if (empty($keys)) {
            return null;
        }

        if ($percentile <= $keys[0]) {
            return $aggregateCurve[$keys[0]];
        }

        if ($percentile >= $keys[count($keys) - 1]) {
            return $aggregateCurve[$keys[count($keys) - 1]];
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
            return $aggregateCurve[$lowerKey ?? $upperKey];
        }

        $fraction = ($percentile - $lowerKey) / ($upperKey - $lowerKey);

        $result = [];

        foreach (['median', 'lower', 'upper'] as $key) {
            $lowerVal = $aggregateCurve[$lowerKey][$key] ?? null;
            $upperVal = $aggregateCurve[$upperKey][$key] ?? null;

            if ($lowerVal === null || $upperVal === null) {
                $result[$key] = $lowerVal ?? $upperVal;
            } else {
                $result[$key] = $lowerVal + ($upperVal - $lowerVal) * $fraction;
            }
        }

        return $result;
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

    // Public wrapper for the planned-month count, consumed by
    // AIPredictionService to build the class reference band (§5.2).
    public function getPlannedMonths(Project $project): int
    {
        return $this->getTotalPlannedMonths($project);
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
                // A4: a 0-obs project is at the START of its plan (month 1),
                // NOT at the end. The full 1..P synthetic curve still spans
                // the whole plan so the shape renders; current month 1 makes
                // downstream read current progress = y[0] (class curve at
                // month 1) and forecast from month 1 forward.
                'current_month_index' => 1,
            ],
            'data_source'           => 'similar_projects',
            'similar_projects_count' => $similarCount,
            'similar_projects_details' => $this->getSimilarProjectsDetails($project),
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

        if ($realCount <= 0) {
            return null;
        }

        $totalPlannedMonths = $this->getTotalPlannedMonths($project);

        if ($totalPlannedMonths <= 0) {
            return null;
        }

        // v2.6 §4.1: build the FULL planned span 1..P, keeping the X-axis
        // monotonic. Synthetic rows fill every planned month; real
        // observations then OVERWRITE their own month so month 1 is never
        // duplicated and the forecast tail stays ordered. (No more prepend
        // of synthetic 1..needed in front of a real dataset that restarts
        // at 1 — the old merged X was non-monotonic with month 1 doubled.)
        $synthetic = $this->generateSyntheticPoints($aggregate, $project, $totalPlannedMonths);

        if (empty($synthetic)) {
            return null;
        }

        $progressByMonth = [];
        foreach ($synthetic as $point) {
            $progressByMonth[(int) $point['month_index']] = (float) $point['progress'];
        }

        // Overwrite with the real observations at their own month index.
        $realX = $realDataset['x'] ?? [];
        $realY = $realDataset['y'] ?? [];
        foreach ($realX as $i => $monthIndex) {
            $progressByMonth[(int) $monthIndex] = (float) ($realY[$i] ?? 0);
        }

        ksort($progressByMonth);

        // Monotonic X = 1..P; synthetic count = months that real data did
        // not cover (used by reliability to derive real-only observations).
        $x = array_keys($progressByMonth);
        $y = array_values($progressByMonth);
        $combinedCount = count($x);
        $syntheticCount = max(0, $combinedCount - $realCount);

        $similarCount = $this->findSimilarProjects($project)->count();

        return [
            'x'                     => array_map(fn ($m) => (float) $m, $x),
            'y'                     => array_map(fn ($v) => (float) $v, $y),
            'records'               => $realDataset['records'] ?? collect(),
            'statistics'            => [
                'observation_count'   => $combinedCount,
                'current_month_index' => $realDataset['statistics']['current_month_index'],
            ],
            'data_source'           => 'hybrid',
            'similar_projects_count' => $similarCount,
            'similar_projects_details' => $this->getSimilarProjectsDetails($project),
            'synthetic_points_count' => $syntheticCount,
        ];
    }
}
