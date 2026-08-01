<?php

namespace App\Services\AI;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;

/**
 * ============================================================================
 * RecommendationEngine
 * ============================================================================
 *
 * Purpose
 * -------
 * Generates structured, categorized, and explainable recommendations for
 * project managers based on regression prediction data and project factors.
 *
 * This is a rule-based engine (not AI). Recommendations are derived from
 * multiple project factors including risk level, trend, progress gap,
 * days remaining, extension count, open issues, and regression quality.
 *
 * Each recommendation has:
 *   • message   — The actionable suggestion
 *   • category  — escalation | corrective | monitoring | administrative
 *   • reason    — Explanation of why this recommendation applies
 *   • priority  — 1 (highest) to 5 (lowest)
 *
 * Used By
 * -------
 * AIPredictionPresenter (Phase 7.3)
 *
 * Depends On
 * ----------
 * AIPredictionService — provides the regression prediction pipeline
 * Project model       — for extension count, issues, baseline schedules
 */
class RecommendationEngine
{
    /**
     * ------------------------------------------------------------------------
     * Maximum Recommendations
     * ------------------------------------------------------------------------
     *
     * Caps the total number of recommendations returned to avoid
     * overwhelming project managers with too many suggestions.
     */
    private const MAX_RECOMMENDATIONS = 8;

    /**
     * ------------------------------------------------------------------------
     * Generate Recommendations
     * ------------------------------------------------------------------------
     *
     * Entry point of the Recommendation Engine.
     *
     * Workflow
     * --------
     * 1. Skip completed projects (empty array).
     * 2. Extract factors from project + prediction data.
     * 3. Skip insufficient data (< 2 observations).
     * 4. Run each category rule method.
     * 5. Merge, deduplicate, sort by priority, cap at 8.
     *
     * Used By
     * -------
     * AIPredictionPresenter::presentNormal() (Phase 7.3)
     *
     * Depends On
    ----------
     * extractFactors(), assessEscalation(), assessCorrective(),
     * assessMonitoring(), assessAdministrative(), mergeAndSort()
     */
    public function generate(Project $project, array $prediction): array
    {
        // Skip completed projects — no active monitoring needed
        if ($project->status === 'Completed') {
            return [];
        }

        $factors = $this->extractFactors($project, $prediction);

        // No observations at all — nothing to recommend
        if ($factors['observation_count'] === 0) {
            return [];
        }

        // Very new projects (1-2 obs): return data-focused recommendations only
        // Regression cannot produce meaningful risk/trend analysis with so few data points
        if ($factors['observation_count'] <= 2) {
            return $this->generateDataFocusedRecommendations($factors);
        }

        // No baseline schedules: return only administrative recommendations
        // Without baselines, risk and trend cannot be reliably assessed
        if (!$factors['has_baselines']) {
            $administrative = $this->assessAdministrative($factors);
            return $this->mergeAndSort($administrative);
        }

        // Generate recommendations from each category
        $escalation     = $this->assessEscalation($factors);
        $corrective     = $this->assessCorrective($factors);
        $monitoring     = $this->assessMonitoring($factors);
        $administrative = $this->assessAdministrative($factors);

        return $this->mergeAndSort($escalation, $corrective, $monitoring, $administrative);
    }

    /**
     * ------------------------------------------------------------------------
     * Generate Data-Focused Recommendations
     * ------------------------------------------------------------------------
     *
     * Returns administrative recommendations for very new projects
     * (1-2 observations) where regression cannot yet produce meaningful
     * risk or trend analysis.
     *
     * Used By
     * -------
     * generate()
     */
    private function generateDataFocusedRecommendations(array $factors): array
    {
        return [
            [
                'message'  => 'Continue regular monthly progress inspections',
                'category' => 'administrative',
                'reason'   => 'Standard procedure for all active projects',
                'priority' => 5,
            ],
            [
                'message'  => 'More progress records needed to improve prediction accuracy',
                'category' => 'administrative',
                'reason'   => 'Only ' . $factors['observation_count'] . ' observation(s) — more data improves reliability',
                'priority' => 5,
            ],
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Get Category Color
     * ------------------------------------------------------------------------
     *
     * Returns the hex color associated with each recommendation category
     * for UI badge display.
     *
     * Used By
     * -------
     * ai-prediction.blade.php (Phase 7.4)
     */
    public function getCategoryColor(string $category): string
    {
        return match ($category) {
            'escalation'    => '#ef4444',
            'corrective'    => '#f59e0b',
            'monitoring'    => '#eab308',
            'administrative' => '#3b82f6',
            default         => '#6b7280',
        };
    }

    /**
     * ------------------------------------------------------------------------
     * Extract Factors
     * ------------------------------------------------------------------------
     *
     * Gathers all project and prediction data into a single factors array
     * used by the category rule methods.
     *
     * Sources
     * -------
     * • Prediction assessment — risk level, variance
     * • Prediction regression — fit quality, observation count
     * • Prediction current_state — current progress
     * • Project model — extension count, issues, baseline schedules
     * • Computed — trend, expected progress, speed comparison, days remaining,
     *   has_baselines
     *
     * Used By
     * -------
     * generate()
     *
     * Calls
     * -----
     * calculateDaysElapsed(), calculateDaysTotal(), calculateDaysRemaining(),
     * calculateExpectedProgress(), classifyRiskFromGap(), determineTrend(),
     * calculateSpeedComparison()
     */
    private function extractFactors(Project $project, array $prediction): array
    {
        $assessment       = $prediction['assessment'] ?? [];
        $regression       = $prediction['regression'] ?? [];
        $currentState     = $prediction['current_state'] ?? [];

        $currentProgress  = (float) ($currentState['progress'] ?? 0);
        $observationCount = (int) ($regression['observation_count'] ?? 0);
        $fitQuality       = (string) ($regression['fit_quality'] ?? 'Weak');

        // Derive risk level from the last assessment record (final forecast month)
        $lastAssessment   = !empty($assessment) ? end($assessment) : null;
        $riskLevel        = $lastAssessment['risk_level'] ?? null;

        $daysElapsed      = $this->calculateDaysElapsed($project);
        $daysTotal        = $this->calculateDaysTotal($project);
        $daysRemaining    = $this->calculateDaysRemaining($project);
        $expectedProgress = $this->calculateExpectedProgress($project, $daysElapsed, $daysTotal);
        $progressGap      = $expectedProgress - $currentProgress;

        // Fallback risk classification when no assessment data exists
        if ($riskLevel === null) {
            $riskLevel = $this->classifyRiskFromGap($progressGap);
        }

        $trendResult = $this->determineTrend($assessment);
        $speedResult = $this->calculateSpeedComparison($project, $observationCount);

        // Count only unresolved issues — resolved/closed issues do not affect recommendations
        $openIssueCount = $project->issues()
            ->where('status', 'Open')
            ->count();

        // Per-type breakdown of open issues for type-aware recommendations
        $issuesByType = $project->issues()
            ->where('status', 'Open')
            ->selectRaw('issue_type, COUNT(*) as count')
            ->groupBy('issue_type')
            ->pluck('count', 'issue_type')
            ->toArray();

        // Severity-weighted count (Critical=3, High=2, Medium=1, Low=0.5)
        // Provides a composite severity score for the project's open issues
        $severityWeights = ['Critical' => 3, 'High' => 2, 'Medium' => 1, 'Low' => 0.5];
        $severityScore = $project->issues()
            ->where('status', 'Open')
            ->whereNotNull('severity')
            ->get()
            ->sum(fn ($i) => $severityWeights[$i->severity] ?? 0);

        // Count of High/Critical severity open issues for escalation rules
        $highSeverityCount = $project->issues()
            ->where('status', 'Open')
            ->whereIn('severity', ['High', 'Critical'])
            ->count();

        // Aging: count of issues that have been open for 30+ days
        $agingIssueCount = $project->issues()
            ->whereIn('status', ['Open', 'In Progress'])
            ->where('created_at', '<=', now()->subDays(30))
            ->count();

        // Resolution velocity: issues resolved/closed in the last 30 days
        $resolvedLast30 = $project->issues()
            ->whereIn('status', ['Resolved', 'Closed'])
            ->where('resolved_at', '>=', now()->subDays(30))
            ->count();

        // Detect whether the project has baseline schedules for meaningful comparison
        $hasBaselines = $project->baselineSchedules !== null
            && $project->baselineSchedules->isNotEmpty();

        // Carryover factor 1: count of incomplete activities that have been
        // carried over at least once — indicates systematically deferred work
        $carriedOverCount = $project->activities()
            ->where('is_completed', false)
            ->whereHas('carryovers')
            ->count();

        // Carryover factor 2: count of activities carried over 2+ times
        // (consecutive carryovers) — indicates a chronically deferred activity
        $consecutiveCarryovers = $project->activityCarryovers()
            ->where('carryover_number', '>=', 2)
            ->count();

        // If there are consecutive carryovers, find the name of the worst
        // offender activity for use in escalation recommendations
        $worstCarriedActivityName = null;
        if ($consecutiveCarryovers > 0) {
            $worstCarriedActivity = $project->activities()
                ->whereHas('carryovers', fn ($q) =>
                    $q->where('carryover_number', '>=', 2)
                )
                ->withCount('carryovers')
                ->orderBy('carryovers_count', 'desc')
                ->first();
            $worstCarriedActivityName = $worstCarriedActivity
                ? $worstCarriedActivity->activity_name
                : null;
        }

        return [
            'risk_level'               => $riskLevel,
            'trend'                    => $trendResult['trend'],
            'trend_label'              => $trendResult['label'],
            'progress_gap'             => round($progressGap, 1),
            'days_remaining'           => max(0, $daysRemaining),
            'extension_count'          => (int) ($project->extension_count ?? 0),
            'open_issue_count'          => $openIssueCount,
            'issues_material'           => (int) ($issuesByType['material'] ?? 0),
            'issues_contractor'         => (int) ($issuesByType['contractor'] ?? 0),
            'issues_weather'            => (int) ($issuesByType['weather'] ?? 0),
            'issues_design'             => (int) ($issuesByType['design'] ?? 0),
            'issues_regulatory'         => (int) ($issuesByType['regulatory'] ?? 0),
            'issues_financial'          => (int) ($issuesByType['financial'] ?? 0),
            'issues_other'              => (int) ($issuesByType['other'] ?? 0),
            'issues_high_severity'      => $highSeverityCount,
            'issues_severity_score'     => $severityScore,
            'issues_aging_count'        => $agingIssueCount,
            'issues_resolved_30d'       => $resolvedLast30,
            'fit_quality'              => $fitQuality,
            'observation_count'        => $observationCount,
            'early_speed'              => $speedResult['early_speed'],
            'recent_speed'             => $speedResult['recent_speed'],
            'current_progress'         => round($currentProgress, 1),
            'expected_progress'        => round($expectedProgress, 1),
            'has_baselines'            => $hasBaselines,
            'carried_over_count'       => $carriedOverCount,
            'consecutive_carryovers'   => $consecutiveCarryovers,
            'worst_carried_activity_name' => $worstCarriedActivityName,
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Assess Escalation Recommendations
     * ------------------------------------------------------------------------
     *
     * Generates the highest-priority recommendations for projects that
     * require immediate management attention.
     *
     * Triggers
     * --------
     * • Critical risk level → management escalation
     * • Progress gap > 20% with < 30 days remaining → urgent corrective action
     *
     * Used By
     * -------
     * generate()
     */
    private function assessEscalation(array $factors): array
    {
        $recommendations = [];

        // Critical risk requires immediate management attention
        if ($factors['risk_level'] === 'Critical') {
            $recommendations[] = [
                'message'  => 'Escalate project for management review — critical risk detected',
                'category' => 'escalation',
                'reason'   => 'Critical risk classification from regression assessment',
                'priority' => 1,
            ];
        }

        // Activity carried over multiple times (2+) with High/Critical risk —
        // the same item keeps being deferred; scope or resource issue
        if (($factors['consecutive_carryovers'] ?? 0) >= 1
            && in_array($factors['risk_level'], ['High', 'Critical'], true)
        ) {
            $activityName = $factors['worst_carried_activity_name']
                ?? 'An activity';
            $recommendations[] = [
                'message'  => $activityName
                    . ' — consider scope review',
                'category' => 'escalation',
                'reason'   => 'Activity has been carried over multiple months with '
                    . $factors['risk_level'] . ' risk — may need scope or resource reassessment',
                'priority' => 2,
            ];
        }

        // High-severity issue(s) combined with High/Critical project risk —
        // serious issues need immediate management attention
        if (($factors['issues_high_severity'] ?? 0) >= 1
            && in_array($factors['risk_level'], ['High', 'Critical'], true)
        ) {
            $recommendations[] = [
                'message'  => 'Resolve high-severity issue(s) requiring immediate attention',
                'category' => 'escalation',
                'reason'   => $factors['issues_high_severity']
                    . ' high-severity issue(s) with ' . $factors['risk_level'] . ' project risk',
                'priority' => 1,
            ];
        }

        // Systemic failure to close issues — 5+ issues open for 30+ days
        if (($factors['issues_aging_count'] ?? 0) >= 5) {
            $recommendations[] = [
                'message'  => $factors['issues_aging_count']
                    . ' issues unresolved for 30+ days — escalation recommended',
                'category' => 'escalation',
                'reason'   => 'Systemic failure to resolve issues — '
                    . $factors['issues_aging_count'] . ' issues have been open for over a month',
                'priority' => 2,
            ];
        }

        // Severe gap with imminent deadline — project will not finish on time
        if ($factors['progress_gap'] < -20 && $factors['days_remaining'] < 30) {
            $recommendations[] = [
                'message'  => 'Urgent corrective action needed — project unlikely to finish on time',
                'category' => 'escalation',
                'reason'   => 'Progress gap of ' . abs($factors['progress_gap']) . '% with only ' . $factors['days_remaining'] . ' days remaining',
                'priority' => 1,
            ];
        }

        return $recommendations;
    }

    /**
     * ------------------------------------------------------------------------
     * Assess Corrective Recommendations
     * ------------------------------------------------------------------------
     *
     * Generates recommendations for projects that need schedule or
     * resource adjustments to get back on track.
     *
     * Triggers
     * --------
     * • High risk or Moderate risk with DECELERATING trend → schedule review
     * • Progress gap > 10% with past extensions → additional resources
     * • 3+ open issues → resolve blockers
     *
     * Used By
     * -------
     * generate()
     */
    private function assessCorrective(array $factors): array
    {
        $recommendations = [];

        // High risk or moderate risk that is worsening — schedule review needed
        if ($factors['risk_level'] === 'High' || ($factors['risk_level'] === 'Moderate' && $factors['trend'] === 'DECELERATING')) {
            $recommendations[] = [
                'message'  => 'Review contractor schedule to identify delays and bottlenecks',
                'category' => 'corrective',
                'reason'   => $factors['risk_level'] . ' risk with ' . $factors['trend_label'],
                'priority' => 2,
            ];
        }

        // Behind schedule with a history of extensions — resource intervention needed
        if ($factors['progress_gap'] < -10 && $factors['extension_count'] > 0) {
            $recommendations[] = [
                'message'  => 'Consider additional resources on critical path items',
                'category' => 'corrective',
                'reason'   => 'Project has had ' . $factors['extension_count'] . ' extension(s) and is still behind schedule',
                'priority' => 2,
            ];
        }

        // Material issues >= 3 may indicate supply chain problems
        if (($factors['issues_material'] ?? 0) >= 3) {
            $recommendations[] = [
                'message'  => $factors['issues_material']
                    . ' material issues unresolved — review supply chain',
                'category' => 'corrective',
                'reason'   => $factors['issues_material']
                    . ' material-related issues may be blocking progress',
                'priority' => 3,
            ];
        }

        // Contractor issues >= 2 may indicate performance concerns
        if (($factors['issues_contractor'] ?? 0) >= 2) {
            $recommendations[] = [
                'message'  => $factors['issues_contractor']
                    . ' contractor issues unresolved — address performance',
                'category' => 'corrective',
                'reason'   => $factors['issues_contractor']
                    . ' contractor-related issues may indicate performance problems',
                'priority' => 2,
            ];
        }

        // Design issues >= 2 may require consultant input
        if (($factors['issues_design'] ?? 0) >= 2) {
            $recommendations[] = [
                'message'  => $factors['issues_design']
                    . ' design issues unresolved — consider design review',
                'category' => 'corrective',
                'reason'   => $factors['issues_design']
                    . ' design-related issues may require consultant input',
                'priority' => 3,
            ];
        }

        // Aging issues >= 2 — issues piling up without resolution
        if (($factors['issues_aging_count'] ?? 0) >= 2) {
            $recommendations[] = [
                'message'  => $factors['issues_aging_count']
                    . ' issues unresolved for 30+ days — prioritize resolution',
                'category' => 'corrective',
                'reason'   => $factors['issues_aging_count']
                    . ' issues have been open for over a month',
                'priority' => 2,
            ];
        }

        // Activities repeatedly carried over while behind schedule —
        // contractor systematically deferring work instead of completing it
        if (($factors['carried_over_count'] ?? 0) >= 2 && ($factors['progress_gap'] ?? 0) < -5) {
            $recommendations[] = [
                'message'  => $factors['carried_over_count']
                    . ' activities carried forward — review contractor scheduling',
                'category' => 'corrective',
                'reason'   => $factors['carried_over_count']
                    . ' incomplete activities have been carried over with a progress gap of '
                    . abs($factors['progress_gap']) . '% — work is being deferred rather than completed',
                'priority' => 3,
            ];
        }

        return $recommendations;
    }

    /**
     * ------------------------------------------------------------------------
     * Assess Monitoring Recommendations
     * ------------------------------------------------------------------------
     *
     * Generates recommendations for closer observation when the project
     * shows early warning signs but does not yet require corrective action.
     *
     * Triggers
     * --------
     * • Moderate risk with STABLE trend → could go either way
     * • DECELERATING trend with slowing velocity → verify data
     * • Low risk but DECELERATING → early warning
     *
     * Used By
     * -------
     * generate()
     */
    private function assessMonitoring(array $factors): array
    {
        $recommendations = [];

        // Moderate risk with stable trend — could improve or decline next cycle
        if ($factors['risk_level'] === 'Moderate' && $factors['trend'] === 'STABLE') {
            $recommendations[] = [
                'message'  => 'Monitor progress closely over the next inspection cycle',
                'category' => 'monitoring',
                'reason'   => 'Moderate risk with stable trend — could improve or decline',
                'priority' => 3,
            ];
        }

        // Slowing velocity may indicate actual progress is lower than reported
        if ($factors['trend'] === 'DECELERATING' && $factors['recent_speed'] < $factors['early_speed']) {
            $recommendations[] = [
                'message'  => 'Conduct additional site inspection to verify progress data',
                'category' => 'monitoring',
                'reason'   => 'Recent progress velocity (' . $factors['recent_speed'] . '%) is below early phase (' . $factors['early_speed'] . '%)',
                'priority' => 3,
            ];
        }

        // Low resolution velocity compared to open issue count —
        // issues are accumulating faster than they're being resolved
        $openCount = $factors['open_issue_count'] ?? 0;
        $resolved30d = $factors['issues_resolved_30d'] ?? 0;
        if ($openCount >= 3 && $resolved30d < max(1, $openCount / 3)) {
            $recommendations[] = [
                'message'  => 'Issue resolution rate is low — review issue management process',
                'category' => 'monitoring',
                'reason'   => $resolved30d . ' issues resolved in last 30 days vs '
                    . $openCount . ' currently open',
                'priority' => 3,
            ];
        }

        // Low risk but declining — catch deterioration before it becomes moderate
        if ($factors['risk_level'] === 'Low' && $factors['trend'] === 'DECELERATING') {
            $recommendations[] = [
                'message'  => 'Keep an eye on next inspection — early warning sign detected',
                'category' => 'monitoring',
                'reason'   => 'Low risk but declining trend needs monitoring',
                'priority' => 4,
            ];
        }

        return $recommendations;
    }

    /**
     * ------------------------------------------------------------------------
     * Assess Administrative Recommendations
     * ------------------------------------------------------------------------
     *
     * Generates standard procedural recommendations that apply to all
     * active projects, plus special cases for deadline and data quality.
     *
     * Triggers
     * --------
     * • All active projects → standard inspection reminder
     * • No baseline schedules → setup prompt
     * • Nearing deadline with incomplete progress → extension evaluation
     * • Weak regression fit with few observations → data quality note
     *
     * Used By
     * -------
     * generate()
     */
    private function assessAdministrative(array $factors): array
    {
        $recommendations = [];

        // Always include the standard inspection reminder
        $recommendations[] = [
            'message'  => 'Continue regular monthly progress inspections',
            'category' => 'administrative',
            'reason'   => 'Standard procedure for all active projects',
            'priority' => 5,
        ];

        // No baseline schedules — recommend setting one up for meaningful tracking
        if (!$factors['has_baselines']) {
            $recommendations[] = [
                'message'  => 'Set up baseline schedule to enable progress tracking and risk assessment',
                'category' => 'administrative',
                'reason'   => 'No baseline schedules found — cannot compare actual versus planned progress',
                'priority' => 4,
            ];
        }

        // Project is about to miss its deadline — prepare for extension paperwork
        if ($factors['days_remaining'] < 15 && $factors['current_progress'] < 90) {
            $recommendations[] = [
                'message'  => 'Project nearing deadline with incomplete progress — prepare extension evaluation',
                'category' => 'administrative',
                'reason'   => 'Only ' . $factors['days_remaining'] . ' days remain with ' . $factors['current_progress'] . '% completion',
                'priority' => 4,
            ];
        }

        // Weak model fit due to sparse data — inform user that more data improves accuracy
        if ($factors['fit_quality'] === 'Weak' && $factors['observation_count'] < 6) {
            $recommendations[] = [
                'message'  => 'More progress records needed to improve prediction accuracy',
                'category' => 'administrative',
                'reason'   => 'Only ' . $factors['observation_count'] . ' observations — more data improves reliability',
                'priority' => 5,
            ];
        }

        return $recommendations;
    }

    /**
     * ------------------------------------------------------------------------
     * Merge and Sort Recommendations
     * ------------------------------------------------------------------------
     *
     * Combines recommendations from all four categories, removes
     * duplicates (by message text), sorts by priority ascending,
     * and caps the total at MAX_RECOMMENDATIONS.
     *
     * Used By
     * -------
     * generate()
     */
    private function mergeAndSort(array ...$categories): array
    {
        $all = array_merge(...$categories);

        // Deduplicate by message text to avoid showing the same advice twice
        $seen = [];
        $unique = [];
        foreach ($all as $rec) {
            $key = $rec['message'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $rec;
            }
        }

        // Sort by priority ascending (1 = highest urgency first)
        usort($unique, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        // Cap total recommendations to avoid overwhelming the user
        return array_slice($unique, 0, self::MAX_RECOMMENDATIONS);
    }

    /**
     * ------------------------------------------------------------------------
     * Determine Trend
     * ------------------------------------------------------------------------
     *
     * Classifies the project trend as ACCELERATING, DECELERATING,
     * or STABLE by comparing the last two assessment records' variance.
     *
     * Used By
     * -------
     * extractFactors()
     */
    private function determineTrend(array $assessment): array
    {
        // Need at least 2 assessment records to detect a trend direction
        if (count($assessment) < 2) {
            return [
                'trend' => 'STABLE',
                'label' => 'Steady progress',
            ];
        }

        $latestVariance   = end($assessment)['variance'];
        $previousVariance = prev($assessment)['variance'];
        reset($assessment);

        $delta = $latestVariance - $previousVariance;

        // Variance worsening by 2+ points → falling further behind
        if ($delta <= -2) {
            return [
                'trend' => 'DECELERATING',
                'label' => 'Falling further behind schedule compared to last month',
            ];
        }

        // Variance improving by 2+ points → catching up
        if ($delta >= 2) {
            return [
                'trend' => 'ACCELERATING',
                'label' => 'Catching up to baseline schedule compared to last month',
            ];
        }

        // Variance change is within ±2 — no significant shift
        return [
            'trend' => 'STABLE',
            'label' => 'Steady progress',
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Classify Risk From Progress Gap
     * ------------------------------------------------------------------------
     *
     * Fallback risk classification when no assessment data is available
     * from the regression pipeline (e.g., no baseline schedules).
     *
     * Thresholds
     * ----------
     * progress_gap ≤ 5%   → Low
     * progress_gap < 10%   → Moderate
     * progress_gap < 15%   → High
     * progress_gap ≥ 15%   → Critical
     *
     * Note: Negative gap (ahead of schedule) is always Low.
     *
     * Used By
     * -------
     * extractFactors()
     */
    private function classifyRiskFromGap(float $progressGap): string
    {
        if ($progressGap <= -5) {
            return 'Low';
        }
        if ($progressGap <= 5) {
            return 'Low';
        }
        if ($progressGap < 10) {
            return 'Moderate';
        }
        if ($progressGap < 15) {
            return 'High';
        }
        return 'Critical';
    }

    /**
     * ------------------------------------------------------------------------
     * Calculate Expected Progress
     * ------------------------------------------------------------------------
     *
     * Determines the expected cumulative progress from the project's
     * Effective Baseline. Falls back to a time-based estimate
     * (days elapsed / total days) when no baseline schedules exist.
     *
     * Used By
     * -------
     * extractFactors()
     */
    private function calculateExpectedProgress(
        Project $project,
        int $daysElapsed,
        int $daysTotal
    ): float {
        // Use eager-loaded relationship if available, otherwise query
        $baselineSchedules = $project->baselineSchedules
            ?? ProjectBaselineSchedule::where('project_id', $project->id)
                ->orderBy('planned_date', 'asc')
                ->get();

        if ($baselineSchedules->isNotEmpty()) {
            // Find the latest baseline whose planned date has passed
            $currentMonthTarget = $baselineSchedules
                ->filter(fn ($schedule) =>
                    $schedule->planned_date && $schedule->planned_date->lte(now())
                )
                ->last();

            if ($currentMonthTarget) {
                return (float) $currentMonthTarget->planned_progress_percent;
            }

            // No baseline date has passed yet — use the first baseline target
            return (float) $baselineSchedules->first()->planned_progress_percent;
        }

        // No baseline schedules exist — use time-based linear estimate
        if ($daysTotal > 0) {
            return ($daysElapsed / $daysTotal) * 100;
        }

        return 0;
    }

    /**
     * ------------------------------------------------------------------------
     * Calculate Speed Comparison
     * ------------------------------------------------------------------------
     *
     * Splits the project history in half and compares the average
     * cumulative progress of the early phase against the recent phase.
     *
     * Used By
     * -------
     * extractFactors()
     */
    private function calculateSpeedComparison(
        Project $project,
        int $observationCount
    ): array {
        $progressHistory = $project->progressHistory()
            ->orderBy('month_label', 'asc')
            ->get();

        if ($progressHistory->isEmpty()) {
            return [
                'early_speed'  => 0,
                'recent_speed' => 0,
            ];
        }

        // Split observations into two halves for comparison
        $half = max(1, (int) ceil($observationCount / 2));

        $earlyAvg = $progressHistory->slice(0, $half)
            ->avg(fn ($r) => (float) ($r->actual_progress_percent ?? 0));

        $recentAvg = $progressHistory->slice(-$half)
            ->avg(fn ($r) => (float) ($r->actual_progress_percent ?? 0));

        return [
            'early_speed'  => round($earlyAvg, 1),
            'recent_speed' => round($recentAvg, 1),
        ];
    }

    /**
     * ------------------------------------------------------------------------
     * Calculate Days Elapsed
     * ------------------------------------------------------------------------
     *
     * Returns the number of days since the project's planned start date.
     *
     * Used By
     * -------
     * extractFactors()
     */
    private function calculateDaysElapsed(Project $project): int
    {
        if (!$project->planned_start_date) {
            return 0;
        }
        return (int) $project->planned_start_date->diffInDays(now(), false);
    }

    /**
     * ------------------------------------------------------------------------
     * Calculate Days Total
     * ------------------------------------------------------------------------
     *
     * Returns the total planned duration in days from start to end date.
     *
     * Used By
     * -------
     * extractFactors()
     */
    private function calculateDaysTotal(Project $project): int
    {
        if (!$project->planned_start_date || !$project->planned_end_date) {
            return 0;
        }
        return (int) $project->planned_start_date->diffInDays($project->planned_end_date, false);
    }

    /**
     * ------------------------------------------------------------------------
     * Calculate Days Remaining
     * ------------------------------------------------------------------------
     *
     * Returns the number of days remaining until the planned end date.
     * Clamped to 0 for overdue projects.
     *
     * Used By
     * -------
     * extractFactors()
     */
    private function calculateDaysRemaining(Project $project): int
    {
        if (!$project->planned_end_date) {
            return 0;
        }
        return (int) max(0, floor(now()->diffInDays($project->planned_end_date, false)));
    }
}
