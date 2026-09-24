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
 * Phase 10: AI-first with rule-based fallback. Attempts to generate
 * recommendations via AIRecommendationService (Gemini-powered, context-aware)
 * before falling back to deterministic rule-based logic. The AI produces
 * more nuanced recommendations by considering contractor performance
 * history, issue breakdown, extension patterns, and cross-project benchmarks.
 *
 * When AI is unavailable (API failure, bad key, timeout, malformed response),
 * the rule-based engine takes over unchanged.
 *
 * Rule-based factors include:
 * - Risk level, trend, progress gap, days remaining
 * - Extension count, open issues, regression quality
 * - B5/B6 class-standing (percentile benchmark + at-risk flag)
 * - Phase 9 contractor performance metrics (on-time %, avg delay, rating)
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
 * AIRecommendationService — AI-powered recommendations (Phase 10)
 * AIPredictionService     — provides the regression prediction pipeline
 * Project model           — for extension count, issues, baseline schedules
 */
class RecommendationEngine
{
    /**
     * ------------------------------------------------------------------------
     * Maximum Recommendations
     * ------------------------------------------------------------------------
     *
     * Caps the total number of rule-based recommendations returned to avoid
     * overwhelming project managers with too many suggestions.
     */
    private function maxRecommendations(): int
    {
        return config('ai.recommendations.max_rule', 8);
    }

    /**
     * ------------------------------------------------------------------------
     * AI Recommendation Service
     * ------------------------------------------------------------------------
     *
     * Generates context-aware recommendations using Gemini. Falls back
     * to rule-based logic when this service returns empty.
     */
    private ?AIRecommendationService $aiRecommendationService = null;

    /**
     * ------------------------------------------------------------------------
     * Constructor
     * ------------------------------------------------------------------------
     *
     * Inject AIRecommendationService for AI-first recommendation path.
     * When null (default), runs in rules-only mode for backward compatibility.
     * In production, the DI container injects the service automatically.
     */
    public function __construct(?AIRecommendationService $aiRecommendationService = null)
    {
        $this->aiRecommendationService = $aiRecommendationService;
    }

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
     * 4. Try AI-powered recommendations via AIRecommendationService.
     * 5. If AI succeeds → return AI recommendations.
     * 6. If AI fails → fall back to rule-based logic.
     * 7. Run each category rule method.
     * 8. Merge, deduplicate, sort by priority, cap at 8.
     *
     * Used By
     * -------
     * Anything that wants AI-first recommendations. Page renders use
     * generateRuleBased() instead (merge plan D3, 2026-09-21).
     *
     * Depends On
    ----------
     * AIRecommendationService::generate() (Phase 10)
     * extractFactors(), assessEscalation(), assessCorrective(),
     * assessMonitoring(), assessAdministrative(), mergeAndSort()
     */
    public function generate(Project $project, array $prediction): array
    {
        // AI source is a closure so the Gemini call still happens only after
        // the guards in build() have passed — exactly the old order.
        $aiSource = $this->aiRecommendationService !== null
            ? fn () => $this->aiRecommendationService->generate($project, $prediction)
            : null;

        return $this->build($project, $prediction, $aiSource);
    }

    /**
     * ------------------------------------------------------------------------
     * Generate Rule-Based Recommendations (no AI)
     * ------------------------------------------------------------------------
     *
     * Same guards and same rule-based output as generate(), but never calls
     * Gemini. For paths that must not wait on an external API — a page view
     * above all (merge plan decision D3: rendering the AI Prediction tab used
     * to make an up-to-30 s Gemini call whenever no stored recommendations
     * existed, repeated on every view).
     *
     * Used By
     * -------
     * AIPredictionPresenter::presentNormal()
     */
    public function generateRuleBased(Project $project, array $prediction): array
    {
        return $this->build($project, $prediction, null);
    }

    /**
     * ------------------------------------------------------------------------
     * Generate From AI Candidates
     * ------------------------------------------------------------------------
     *
     * Same guards as generate(), but the AI recommendations are supplied by
     * the caller instead of fetched. Used by the combined analysis prompt,
     * which already has the model's recommendations in its one answer. An
     * empty candidate list falls back to the rule-based engine — never to a
     * second Gemini call (merge plan decision D2).
     *
     * Used By
     * -------
     * AIExplanationService::generate()
     *
     * @param  array  $aiRecs  Validated recs (AIRecommendationService::parseRecommendations()), or []
     */
    public function generateFromAiCandidates(Project $project, array $prediction, array $aiRecs): array
    {
        return $this->build($project, $prediction, fn () => $aiRecs);
    }

    /**
     * ------------------------------------------------------------------------
     * Accepts AI Recommendations
     * ------------------------------------------------------------------------
     *
     * True when build() would reach the AI step for this project — i.e. it is
     * not Completed and has more than the minimum observations. Otherwise the
     * guards answer on their own (nothing, or the data-focused pair) and any
     * AI recommendations would be discarded.
     *
     * Lets AIExplanationService leave the recommendations request out of the
     * combined prompt when it could never be used.
     *
     * Used By
     * -------
     * AIExplanationService::generate()
     */
    public function acceptsAiRecommendations(Project $project, array $prediction): bool
    {
        if ($project->status === 'Completed') {
            return false;
        }

        // Same observation count build() reads via extractFactors()
        $observationCount = (int) (($prediction['regression'] ?? [])['observation_count'] ?? 0);

        return $observationCount > config('ai.recommendations.min_observations');
    }

    /**
     * ------------------------------------------------------------------------
     * Build Recommendations
     * ------------------------------------------------------------------------
     *
     * Shared body of generate() / generateRuleBased(): guards first, then the
     * AI candidates (when a source is given and it returns any), otherwise the
     * rule-based engine.
     *
     * @param  callable|null  $aiSource  Returns AI recommendations ([] = none); null = rules only
     */
    private function build(Project $project, array $prediction, ?callable $aiSource): array
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

        // Insufficient observations — return data-focused recommendations only
        if ($factors['observation_count'] <= config('ai.recommendations.min_observations')) {
            return $this->generateDataFocusedRecommendations($factors);
        }

        // Phase 10: AI-powered recommendations first (when a source is given)
        if ($aiSource !== null) {
            $aiRecs = $aiSource();
            if (!empty($aiRecs)) {
                return $aiRecs;
            }
        }

        // Fallback: rule-based logic (unchanged)

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
     * ProgressPaceService::compare()
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
        // Real pace (points/month) — ProgressPaceService (phase-43; the old
        // calculateSpeedComparison averaged cumulative %, so "recent < early"
        // could never be true and the slowing-pace rule never fired)
        $pace = app(ProgressPaceService::class)->compare(
            $project->progressHistory()->orderBy('month_label')->orderBy('inspection_date')
                ->get(['month_label', 'actual_progress_percent']),
            $currentProgress,
            $project->planned_end_date ? \Carbon\Carbon::parse($project->planned_end_date) : null,
            \App\Support\DateHelper::now()
        );

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
            // Points gained per month (keys kept for compatibility) + the full pace
            'early_speed'              => $pace['early_pace'] ?? 0,
            'recent_speed'             => $pace['recent_pace'] ?? 0,
            'pace'                     => $pace,
            'current_progress'         => round($currentProgress, 1),
            'expected_progress'        => round($expectedProgress, 1),
            'has_baselines'            => $hasBaselines,
            'carried_over_count'       => $carriedOverCount,
            'consecutive_carryovers'   => $consecutiveCarryovers,
            'worst_carried_activity_name' => $worstCarriedActivityName,
            // B5/B6 — banded percentile benchmark + at-risk flag from the
            // class standing block (present when a usable peer pool exists).
            'class_standing'           => $prediction['class_standing'] ?? [
                'usable'      => false,
                'tier'        => null,
                'tier_label'  => null,
                'at_risk'     => false,
                'at_risk_run' => 0,
                'similar_count' => 0,
            ],
            // B6 derived factors consumed by assessCorrective().
            'class_behind'             => (bool) ($prediction['class_standing']['at_risk'] ?? false),
            'class_behind_run'         => (int) ($prediction['class_standing']['at_risk_run'] ?? 0),
            'class_similar_count'      => (int) ($prediction['class_standing']['similar_count'] ?? 0),
            // Phase 9: contractor performance metrics (aggregated across all completed projects).
            'contractor_performance_rating' => $prediction['contractor_performance_rating'] ?? null,
            'contractor_on_time_pct'        => $prediction['contractor_on_time_pct'] ?? null,
            'contractor_avg_delay'          => $prediction['contractor_avg_delay'] ?? null,
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

        // Systemic failure to close issues — N+ issues open for 30+ days
        $recConfig = config('ai.recommendations');
        if (($factors['issues_aging_count'] ?? 0) >= $recConfig['systemic_issues_count']) {
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
        if ($factors['progress_gap'] < $recConfig['escalation_gap'] && $factors['days_remaining'] < $recConfig['escalation_days']) {
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
     * • Recent pace clearly below the pace needed to finish on time → catch-up plan
     * • 3+ open issues → resolve blockers
     *
     * Used By
     * -------
     * generate()
     */
    private function assessCorrective(array $factors): array
    {
        $recommendations = [];
        $recConfig = config('ai.recommendations');

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
        if ($factors['progress_gap'] < $recConfig['progress_gap_for_extension'] && $factors['extension_count'] > 0) {
            $recommendations[] = [
                'message'  => 'Consider additional resources on critical path items',
                'category' => 'corrective',
                'reason'   => 'Project has had ' . $factors['extension_count'] . ' extension(s) and is still behind schedule',
                'priority' => 2,
            ];
        }

        // The recent pace is clearly too slow to reach 100% by the planned end
        // date (phase-43). Required = remaining points ÷ months left; "clearly" =
        // at least 25% and 2 points/month short, so a near-miss is not flagged.
        $pace = $factors['pace'] ?? null;
        if ($pace && !empty($pace['has_pace']) && ($pace['finish_status'] ?? '') === 'behind_pace'
            && $pace['required_pace'] - $pace['recent_pace'] >= 2
            && $pace['recent_pace'] < $pace['required_pace'] * 0.75) {
            $recommendations[] = [
                'message'  => 'Ask the contractor for a catch-up plan — the recent pace will not finish the work on time',
                'category' => 'corrective',
                'reason'   => 'Needs +' . $pace['required_pace'] . '% per month to finish by the planned end date, but the recent pace is +'
                    . $pace['recent_pace'] . '% per month',
                'priority' => 2,
            ];
        }

        // Material issues may indicate supply chain problems
        if (($factors['issues_material'] ?? 0) >= $recConfig['material_issues_count']) {
            $recommendations[] = [
                'message'  => $factors['issues_material']
                    . ' material issues unresolved — review supply chain',
                'category' => 'corrective',
                'reason'   => $factors['issues_material']
                    . ' material-related issues may be blocking progress',
                'priority' => 3,
            ];
        }

        // Contractor issues may indicate performance concerns
        if (($factors['issues_contractor'] ?? 0) >= $recConfig['contractor_issues_count']) {
            $recommendations[] = [
                'message'  => $factors['issues_contractor']
                    . ' contractor issues unresolved — address performance',
                'category' => 'corrective',
                'reason'   => $factors['issues_contractor']
                    . ' contractor-related issues may indicate performance problems',
                'priority' => 2,
            ];
        }

        // Phase 9: contractor with history of delays + open issues
        if (($factors['contractor_performance_rating'] ?? null) !== null
            && $factors['contractor_performance_rating'] <= $recConfig['contractor_rating_max']
            && ($factors['open_issue_count'] ?? 0) >= $recConfig['contractor_issues_count']
        ) {
            $recommendations[] = [
                'message'  => 'Contractor has a history of delays (rating '
                    . $factors['contractor_performance_rating'] . '/5, avg '
                    . $factors['contractor_avg_delay'] . ' days late) with '
                    . $factors['open_issue_count'] . ' open issues — consider closer schedule monitoring',
                'category' => 'corrective',
                'reason'   => 'Contractor performance history indicates elevated risk',
                'priority' => 4,
            ];
        }

        // Design issues may require consultant input
        if (($factors['issues_design'] ?? 0) >= $recConfig['design_issues_count']) {
            $recommendations[] = [
                'message'  => $factors['issues_design']
                    . ' design issues unresolved — consider design review',
                'category' => 'corrective',
                'reason'   => $factors['issues_design']
                    . ' design-related issues may require consultant input',
                'priority' => 3,
            ];
        }

        // Aging issues — issues piling up without resolution
        if (($factors['issues_aging_count'] ?? 0) >= $recConfig['aging_issues_count']) {
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
        if (($factors['carried_over_count'] ?? 0) >= $recConfig['carryover_count'] && ($factors['progress_gap'] ?? 0) < $recConfig['carryover_gap']) {
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

        // B6 — at-risk flag: actual progress has sat below the class lower
        // band (P25) for 2+ consecutive trailing months. Same signal as the
        // gauge badge, fed into the recommendation stream as a corrective.
        $classStanding = $factors['class_standing'] ?? [];
        if (($factors['class_behind'] ?? false)
            && ($factors['class_behind_run'] ?? 0) >= $recConfig['at_risk_run']
            && ($classStanding['usable'] ?? false)
        ) {
            $recommendations[] = [
                'message'  => 'Progress has trailed similar projects for '
                    . $factors['class_behind_run']
                    . ' consecutive months — investigate root cause',
                'category' => 'corrective',
                'reason'   => 'Actual progress has been below the lower band of similar projects '
                    . 'of this type for ' . $factors['class_behind_run']
                    . ' consecutive months',
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
     * • Pace slowing down (points/month, recent vs early) → verify on site
     * • Low risk but DECELERATING → early warning
     *
     * Used By
     * -------
     * generate()
     */
    private function assessMonitoring(array $factors): array
    {
        $recommendations = [];
        $recConfig = config('ai.recommendations');

        // Moderate risk with stable trend — could improve or decline next cycle
        if ($factors['risk_level'] === 'Moderate' && $factors['trend'] === 'STABLE') {
            $recommendations[] = [
                'message'  => 'Monitor progress closely over the next inspection cycle',
                'category' => 'monitoring',
                'reason'   => 'Moderate risk with stable trend — could improve or decline',
                'priority' => 3,
            ];
        }

        // Work has slowed down: fewer points per month recently than earlier
        // (ProgressPaceService direction — needs a real drop, both in ratio and
        // in points). Before phase-43 this compared cumulative averages and
        // could never fire.
        $pace = $factors['pace'] ?? null;
        if ($pace && ($pace['direction'] ?? '') === 'slowing_down') {
            $recommendations[] = [
                'message'  => 'Progress has slowed — verify on site and ask the contractor what is holding the work back',
                'category' => 'monitoring',
                'reason'   => 'Pace fell from +' . $pace['early_pace'] . '% per month (' . $pace['early_window'] . ') to +'
                    . $pace['recent_pace'] . '% per month (' . $pace['recent_window'] . ')',
                'priority' => 3,
            ];
        }

        // Low resolution velocity compared to open issue count —
        // issues are accumulating faster than they're being resolved
        $openCount = $factors['open_issue_count'] ?? 0;
        $resolved30d = $factors['issues_resolved_30d'] ?? 0;
        if ($openCount >= $recConfig['issue_resolution_open_count'] && $resolved30d < max(1, $openCount / 3)) {
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
        $recConfig = config('ai.recommendations');

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
        if ($factors['days_remaining'] < $recConfig['deadline_days'] && $factors['current_progress'] < $recConfig['deadline_progress']) {
            $recommendations[] = [
                'message'  => 'Project nearing deadline with incomplete progress — prepare extension evaluation',
                'category' => 'administrative',
                'reason'   => 'Only ' . $factors['days_remaining'] . ' days remain with ' . $factors['current_progress'] . '% completion',
                'priority' => 4,
            ];
        }

        // Weak model fit due to sparse data — inform user that more data improves accuracy
        if ($factors['fit_quality'] === 'Weak' && $factors['observation_count'] < config('ai.data_sufficiency.moderate')) {
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
        return array_slice($unique, 0, $this->maxRecommendations());
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

        $trendDelta = config('ai.trend.variance_delta', 2);

        // Variance worsening by trend_delta+ points → falling further behind
        if ($delta <= -$trendDelta) {
            return [
                'trend' => 'DECELERATING',
                'label' => 'Falling further behind schedule compared to last month',
            ];
        }

        // Variance improving by trend_delta+ points → catching up
        if ($delta >= $trendDelta) {
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
        $gap = config('ai.risk.gap');

        if ($progressGap <= $gap['ahead']) {
            return 'Low';
        }
        if ($progressGap <= $gap['on_track']) {
            return 'Low';
        }
        if ($progressGap < $gap['low']) {
            return 'Moderate';
        }
        if ($progressGap < $gap['medium']) {
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
