<?php

namespace App\Services\AI;

use App\Models\Project;
use Illuminate\Support\Facades\Http;

/**
 * ============================================================================
 * AIRecommendationService
 * ============================================================================
 *
 * Purpose
 * -------
 * Generates context-aware project recommendations using Google Gemini.
 * Leverages contractor performance history, issue severity breakdown,
 * regression data, extension patterns, and cross-project benchmarks
 * to produce more nuanced suggestions than rule-based thresholds.
 *
 * Architecture
 * ------------
 * AI-first with rule-based fallback. This service produces recommendations;
 * when it fails (API error, bad key, timeout, malformed response), it returns
 * an empty array and the RecommendationEngine falls back to its rule-based
 * logic unchanged.
 *
 * The prompt is structured to give Gemini all project context needed for
 * data-backed recommendations. The response is parsed into the same
 * { message, category, reason, priority } format used by the rule engine.
 *
 * Fallback
 * --------
 * Returns empty array on any failure:
 * - Empty/missing API key
 * - HTTP errors (4xx, 5xx)
 * - Timeouts (30s)
 * - Empty response text
 * - Malformed JSON
 * - Missing required fields in recommendations
 *
 * Used By
 * -------
 * RecommendationEngine::generate() — called before rule-based logic
 *
 * Depends On
 * ----------
 * config/services.php — Gemini API key and model name
 * Project model       — project data, issues, extensions, contractor
 */
class AIRecommendationService
{
    /**
     * ------------------------------------------------------------------------
     * Maximum Recommendations
     * ------------------------------------------------------------------------
     *
     * Caps the total number of AI-generated recommendations returned.
     * Fewer than the rule engine's cap (8) — AI produces higher quality
     * per recommendation.
     */
    private const MAX_RECOMMENDATIONS = 6;

    /**
     * ------------------------------------------------------------------------
     * Valid Categories
     * ------------------------------------------------------------------------
     *
     * The four recommendation categories matching the rule engine's schema.
     * Used to validate AI output and filter invalid entries.
     */
    private const VALID_CATEGORIES = [
        'escalation', 'corrective', 'monitoring', 'administrative',
    ];

    /**
     * ------------------------------------------------------------------------
     * Generate AI Recommendations
     * ------------------------------------------------------------------------
     *
     * Entry point. Builds a structured prompt, calls Gemini, parses and
     * validates the response.
     *
     * Workflow
     * --------
     * 1. Build structured prompt with all project context
     * 2. Call Gemini API
     * 3. Parse JSON response (handle markdown code fences)
     * 4. Validate each recommendation has required fields
     * 5. Filter invalid entries, clamp priority, sort by priority
     * 6. Return capped array (or empty on any failure)
     *
     * Used By
     * -------
     * RecommendationEngine::generate()
     *
     * Calls
     * -----
     * buildPrompt(), callGemini(), parseResponse(), validateRecommendation()
     */
    public function generate(Project $project, array $prediction): array
    {
        $prompt = $this->buildPrompt($project, $prediction);
        $raw = $this->callGemini($prompt);

        if (empty($raw)) {
            return [];
        }

        $recs = $this->parseResponse($raw);

        if (empty($recs)) {
            return [];
        }

        // Validate and filter each recommendation
        $valid = [];
        foreach ($recs as $rec) {
            if (!$this->validateRecommendation($rec)) {
                continue;
            }

            // Clamp priority to 1-5
            $rec['priority'] = max(1, min(5, (int) $rec['priority']));

            // Normalize category to lowercase
            $rec['category'] = strtolower($rec['category']);

            $valid[] = $rec;
        }

        if (empty($valid)) {
            return [];
        }

        // Sort by priority ascending (1 = highest urgency)
        usort($valid, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        return array_slice($valid, 0, self::MAX_RECOMMENDATIONS);
    }

    /**
     * ------------------------------------------------------------------------
     * Build AI Prompt
     * ------------------------------------------------------------------------
     *
     * Constructs a structured prompt with all project context for the AI
     * to generate data-backed recommendations.
     *
     * Sections:
     * - Project context (title, status, progress, dates, category)
     * - Risk & regression (risk level, trend, R², fit quality)
     * - Contractor performance (rating, on-time %, avg delay)
     * - Issues breakdown (by type, severity, aging, resolution rate)
     * - Extensions (count, reason breakdown, pattern)
     * - Cross-project context (class standing, carryovers)
     * - Output format specification
     *
     * Used By
     * -------
     * generate()
     *
     * Calls
     * -----
     * buildIssuesSection(), buildExtensionsSection()
     */
    private function buildPrompt(Project $project, array $prediction): string
    {
        $assessment = $prediction['assessment'] ?? [];
        $regression = $prediction['regression'] ?? [];
        $currentState = $prediction['current_state'] ?? [];

        $currentProgress = $currentState['progress'] ?? 0;
        $rSquared = $regression['r_squared'] ?? 0;
        $fitQuality = $regression['fit_quality'] ?? 'Unknown';
        $obsCount = $regression['observation_count'] ?? 0;

        // Risk & trend from last assessment
        $lastAssessment = !empty($assessment) ? end($assessment) : null;
        $riskLevel = $lastAssessment['risk_level'] ?? 'Unknown';
        $trend = $this->determineTrend($assessment);

        // Progress gap
        $expectedProgress = $this->calculateExpectedProgress($project);
        $progressGap = round($expectedProgress - $currentProgress, 1);

        // Days remaining
        $daysRemaining = $this->calculateDaysRemaining($project);

        // Contractor performance from prediction report
        $contractorRating = $prediction['contractor_performance_rating'] ?? null;
        $contractorOnTime = $prediction['contractor_on_time_pct'] ?? null;
        $contractorDelay = $prediction['contractor_avg_delay'] ?? null;

        // Category name
        $categoryName = $project->category->name ?? 'Not specified';

        // Contractor performance context
        $contractorText = 'Not available';
        if ($contractorRating !== null) {
            $contractorText = "Rating: {$contractorRating}/5, "
                . "On-time: {$contractorOnTime}%, "
                . "Avg delay: {$contractorDelay} days late";
        }

        // Extension context
        $extensionsText = $this->buildExtensionsSection($project);

        // Issues breakdown
        $issuesText = $this->buildIssuesSection($project);

        // Class standing
        $classStanding = $prediction['class_standing'] ?? [];
        $classText = 'Not available';
        if (!empty($classStanding['usable'])) {
            $tier = $classStanding['tier_label'] ?? 'Unknown';
            $atRisk = ($classStanding['at_risk'] ?? false)
                ? ' (AT RISK — below peers for 2+ months)'
                : '';
            $similarCount = $classStanding['similar_count'] ?? 0;
            $classText = "{$tier}{$atRisk} (based on {$similarCount} similar projects)";
        }

        // Carryover context
        $carryoverCount = 0;
        $consecutiveCarryovers = 0;
        $worstActivity = null;

        if ($project->relationLoaded('activities') || true) {
            $carryoverCount = $project->activities()
                ->where('is_completed', false)
                ->whereHas('carryovers')
                ->count();

            $consecutiveCarryovers = $project->activityCarryovers()
                ->where('carryover_number', '>=', 2)
                ->count();

            if ($consecutiveCarryovers > 0) {
                $worst = $project->activities()
                    ->whereHas('carryovers', fn ($q) =>
                        $q->where('carryover_number', '>=', 2)
                    )
                    ->withCount('carryovers')
                    ->orderBy('carryovers_count', 'desc')
                    ->first();
                $worstActivity = $worst?->activity_name;
            }
        }

        $carryoverText = "{$carryoverCount} incomplete activities carried over";
        if ($consecutiveCarryovers > 0) {
            $carryoverText .= ", {$consecutiveCarryovers} carried over 2+ times";
            if ($worstActivity) {
                $carryoverText .= " (worst: {$worstActivity})";
            }
        }

        return "You are a construction project management analyst. Given the following project data, generate 3-6 prioritized, actionable recommendations.

Each recommendation MUST reference specific data from the project. Do NOT generate generic advice.

PROJECT CONTEXT:
- Title: {$project->title}
- Status: {$project->status}
- Current Progress: {$currentProgress}%
- Expected Progress: {$expectedProgress}% (gap: {$progressGap}%)
- Days Remaining: {$daysRemaining}
- Category: {$categoryName}

RISK & REGRESSION:
- Risk Level: {$riskLevel}
- Trend: {$trend}
- Regression R-squared: {$rSquared} ({$fitQuality})
- Observations: {$obsCount}

CONTRACTOR PERFORMANCE:
- {$contractorText}

{$issuesText}

{$extensionsText}

CROSS-PROJECT CONTEXT:
- Class Standing: {$classText}
- Carryovers: {$carryoverText}

OUTPUT FORMAT — Return ONLY a valid JSON array (no markdown, no explanation):
[
  {
    \"message\": \"Actionable suggestion (1-2 sentences, specific to this project)\",
    \"category\": \"escalation|corrective|monitoring|administrative\",
    \"reason\": \"Why this applies — reference specific data points\",
    \"priority\": 1
  }
]

RULES:
- Generate 3-6 recommendations (quality over quantity)
- Priority 1 = urgent (Critical risk, imminent deadline miss)
- Priority 2 = important (High risk, performance concerns)
- Priority 3 = notable (Moderate risk, pattern detection)
- Priority 4 = informational (Low risk, best practices)
- Priority 5 = routine (admin, data quality)
- Each recommendation MUST be unique — no duplicates
- Each recommendation MUST reference specific project data
- Do NOT mention AI or that this is AI-generated
- Return ONLY the JSON array, no other text";
    }

    /**
     * ------------------------------------------------------------------------
     * Build Issues Section
     * ------------------------------------------------------------------------
     *
     * Queries the project's open issues and builds a structured text block
     * with counts by type, severity breakdown, aging, and resolution rate.
     *
     * Used By
     * -------
     * buildPrompt()
     */
    private function buildIssuesSection(Project $project): string
    {
        $openIssues = $project->issues()->where('status', 'Open');

        $totalCount = $openIssues->count();

        if ($totalCount === 0) {
            return "ISSUES:\n- No open issues";
        }

        // Count by type
        $byType = $openIssues->clone()
            ->selectRaw('issue_type, COUNT(*) as count')
            ->groupBy('issue_type')
            ->pluck('count', 'issue_type')
            ->toArray();

        // Count by severity
        $bySeverity = $openIssues->clone()
            ->whereNotNull('severity')
            ->selectRaw('severity, COUNT(*) as count')
            ->groupBy('severity')
            ->pluck('count', 'severity')
            ->toArray();

        // Aging: issues open 30+ days
        $agingCount = $project->issues()
            ->whereIn('status', ['Open', 'In Progress'])
            ->where('created_at', '<=', now()->subDays(30))
            ->count();

        // Resolution rate: resolved in last 30 days
        $resolvedLast30 = $project->issues()
            ->whereIn('status', ['Resolved', 'Closed'])
            ->where('resolved_at', '>=', now()->subDays(30))
            ->count();

        $lines = ["ISSUES:"];
        $lines[] = "- Total open: {$totalCount}";

        // Type breakdown
        $typeLines = [];
        foreach ($byType as $type => $count) {
            $typeLines[] = "{$type}: {$count}";
        }
        if (!empty($typeLines)) {
            $lines[] = "- By type: " . implode(', ', $typeLines);
        }

        // Severity breakdown
        $sevLines = [];
        foreach ($bySeverity as $sev => $count) {
            $sevLines[] = "{$sev}: {$count}";
        }
        if (!empty($sevLines)) {
            $lines[] = "- By severity: " . implode(', ', $sevLines);
        }

        $lines[] = "- Aging (30+ days open): {$agingCount}";
        $lines[] = "- Resolved last 30 days: {$resolvedLast30}";

        return implode("\n", $lines);
    }

    /**
     * ------------------------------------------------------------------------
     * Build Extensions Section
     * ------------------------------------------------------------------------
     *
     * Queries the project's approved extensions and builds a structured
     * text block with count, reason breakdown, and pattern detection.
     *
     * Used By
     * -------
     * buildPrompt()
     */
    private function buildExtensionsSection(Project $project): string
    {
        $extensions = $project->extensions()
            ->where('status', 'Approved')
            ->get();

        $count = $extensions->count();

        if ($count === 0) {
            return "EXTENSIONS:\n- No approved extensions";
        }

        // Reason breakdown
        $byReason = $extensions
            ->groupBy('reason_category')
            ->map(fn ($group) => $group->count())
            ->toArray();

        // Total extension days
        $totalDays = $extensions->sum('duration_days');

        // Average days per extension
        $avgDays = $count > 0 ? round($totalDays / $count, 1) : 0;

        $lines = ["EXTENSIONS:"];
        $lines[] = "- Total approved: {$count}";
        $lines[] = "- Total extension days: {$totalDays}";
        $lines[] = "- Average days per extension: {$avgDays}";

        // Reason breakdown
        $reasonLines = [];
        foreach ($byReason as $reason => $reasonCount) {
            $reasonLines[] = "{$reason}: {$reasonCount}";
        }
        if (!empty($reasonLines)) {
            $lines[] = "- Reasons: " . implode(', ', $reasonLines);
        }

        return implode("\n", $lines);
    }

    /**
     * ------------------------------------------------------------------------
     * Determine Trend
     * ------------------------------------------------------------------------
     *
     * Extracts the trend direction from the assessment array.
     * Compares variance between the last two assessment records.
     *
     * Used By
     * -------
     * buildPrompt()
     */
    private function determineTrend(array $assessment): string
    {
        if (count($assessment) < 2) {
            return 'Stable';
        }

        $latest = end($assessment)['variance'];
        $previous = prev($assessment)['variance'];
        reset($assessment);

        $delta = $latest - $previous;

        if ($delta <= -2) {
            return 'Declining (falling further behind)';
        }
        if ($delta >= 2) {
            return 'Improving (catching up)';
        }
        return 'Stable';
    }

    /**
     * ------------------------------------------------------------------------
     * Calculate Expected Progress
     * ------------------------------------------------------------------------
     *
     * Determines expected progress from baseline schedules or time-based
     * linear estimate. Used for progress gap calculation in the prompt.
     *
     * Used By
     * -------
     * buildPrompt()
     */
    private function calculateExpectedProgress(Project $project): float
    {
        $daysElapsed = $this->calculateDaysElapsed($project);
        $daysTotal = $this->calculateDaysTotal($project);

        $baselineSchedules = $project->baselineSchedules
            ?? \App\Models\ProjectBaselineSchedule::where('project_id', $project->id)
                ->orderBy('planned_date', 'asc')
                ->get();

        if ($baselineSchedules->isNotEmpty()) {
            $currentMonthTarget = $baselineSchedules
                ->filter(fn ($schedule) =>
                    $schedule->planned_date && $schedule->planned_date->lte(now())
                )
                ->last();

            if ($currentMonthTarget) {
                return (float) $currentMonthTarget->planned_progress_percent;
            }

            return (float) $baselineSchedules->first()->planned_progress_percent;
        }

        if ($daysTotal > 0) {
            return ($daysElapsed / $daysTotal) * 100;
        }

        return 0;
    }

    /**
     * ------------------------------------------------------------------------
     * Calculate Days Remaining
     * ------------------------------------------------------------------------
     *
     * Returns days until planned end date, clamped to 0 for overdue.
     *
     * Used By
     * -------
     * buildPrompt()
     */
    private function calculateDaysRemaining(Project $project): int
    {
        if (!$project->planned_end_date) {
            return 0;
        }
        return (int) max(0, floor(now()->diffInDays($project->planned_end_date, false)));
    }

    /**
     * ------------------------------------------------------------------------
     * Calculate Days Elapsed
     * ------------------------------------------------------------------------
     *
     * Returns days since planned start date.
     *
     * Used By
     * -------
     * calculateExpectedProgress()
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
     * Returns total planned duration in days.
     *
     * Used By
     * -------
     * calculateExpectedProgress()
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
     * Call Gemini API
     * ------------------------------------------------------------------------
     *
     * Sends the prompt to Google Gemini and returns the generated text.
     * Same pattern as AIExplanationService::callGemini().
     *
     * Handles:
     * - Empty/missing API key → return empty
     * - HTTP errors (4xx, 5xx) → return empty
     * - Timeouts (30s) → return empty
     * - Malformed response → return empty
     *
     * Used By
     * -------
     * generate()
     *
     * Uses: config/services.php (gemini key & model)
     */
    private function callGemini(string $prompt): string
    {
        $apiKey = config('services.gemini.key');
        $model = config('services.gemini.model', 'gemini-1.5-flash');

        if (empty($apiKey)) {
            return '';
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(30)->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
            ]);

            if (!$response->successful()) {
                return '';
            }

            $data = $response->json();

            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * ------------------------------------------------------------------------
     * Parse AI Response
     * ------------------------------------------------------------------------
     *
     * Extracts a JSON array of recommendations from the AI response text.
     * Handles markdown code fences (```json...```) that Gemini commonly adds.
     *
     * Returns empty array on parse failure.
     *
     * Used By
     * -------
     * generate()
     */
    private function parseResponse(string $text): array
    {
        $text = trim($text);

        // Strip markdown code fences if present
        if (str_starts_with($text, '```')) {
            // Remove opening fence (```json or ```)
            $text = preg_replace('/^```(?:json)?\s*\n?/', '', $text);
            // Remove closing fence
            $text = preg_replace('/\n?```\s*$/', '', $text);
            $text = trim($text);
        }

        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            return [];
        }

        // If the AI returned an object with a key wrapping the array
        if (isset($decoded['recommendations']) && is_array($decoded['recommendations'])) {
            return $decoded['recommendations'];
        }

        return $decoded;
    }

    /**
     * ------------------------------------------------------------------------
     * Validate Recommendation
     * ------------------------------------------------------------------------
     *
     * Checks that a recommendation has all required fields with valid values.
     *
     * Required fields:
     * - message: non-empty string
     * - category: one of escalation, corrective, monitoring, administrative
     * - reason: non-empty string
     * - priority: integer 1-5
     *
     * Used By
     * -------
     * generate()
     */
    private function validateRecommendation(mixed $rec): bool
    {
        if (!is_array($rec)) {
            return false;
        }

        // Required fields must exist and be non-empty strings
        if (empty($rec['message']) || !is_string($rec['message'])) {
            return false;
        }

        if (empty($rec['reason']) || !is_string($rec['reason'])) {
            return false;
        }

        // Category must be valid
        if (!isset($rec['category']) || !in_array(strtolower($rec['category']), self::VALID_CATEGORIES, true)) {
            return false;
        }

        // Priority must be numeric
        if (!isset($rec['priority']) || !is_numeric($rec['priority'])) {
            return false;
        }

        return true;
    }
}
