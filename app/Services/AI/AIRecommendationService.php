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
     * Maximum number of individual issues to include in the prompt.
     * Avoids token bloat on projects with many open issues.
     */
    private const MAX_ISSUES_IN_PROMPT = 5;

    /**
     * Maximum number of individual extensions to include in the prompt.
     * Avoids token bloat on projects with many extensions.
     */
    private const MAX_EXTENSIONS_IN_PROMPT = 5;

    /**
     * Maximum characters per open-text field in the prompt.
     * Truncated to keep the prompt compact while preserving meaning.
     */
    private const MAX_TEXT_LENGTH = 200;

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
     * Text quality filter — detects gibberish/placeholder text so the
     * AI prompt only receives meaningful data.
     *
     * @var TextQualityFilter
     */
    private TextQualityFilter $textFilter;

    /**
     * Constructor — inject text quality filter.
     *
     * @param  TextQualityFilter|null  $textFilter  Optional; auto-resolved by container
     */
    public function __construct(?TextQualityFilter $textFilter = null)
    {
        $this->textFilter = $textFilter ?? new TextQualityFilter();
    }

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

            // Tag as AI-generated for UI indicator
            $rec['ai_generated'] = true;

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

        // Progress remarks (3 latest inspections)
        $progressRemarksText = $this->buildProgressRemarksText($project);

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

        // Reliability score — the actual trust indicator for predictions
        $reliability = $prediction['reliability'] ?? null;
        $reliabilityText = $this->buildReliabilityText($reliability);

        return "You are a construction project management analyst. Given the following project data, generate 3-6 prioritized, actionable recommendations.

Each recommendation MUST reference specific data from the project. Do NOT generate generic advice.

IMPORTANT: Do NOT use R-squared (R²) as evidence that predictions are accurate. R² only measures how well the curve fits PAST data points — it says nothing about whether future predictions will be correct. A curve can fit past data almost perfectly (R² = 0.999) yet predict the wrong direction entirely. Use the FORECAST RELIABILITY score instead — it is the honest trust indicator based on backtesting, data sufficiency, forecast horizon, and method agreement.

PROJECT CONTEXT:
- Title: {$project->title}
- Status: {$project->status}
- Current Progress: {$currentProgress}%
- Expected Progress: {$expectedProgress}% (gap: {$progressGap}%)
- Days Remaining: {$daysRemaining}
- Category: {$categoryName}

REGRESSION MODEL:
- Curve Fit Quality (matches past data only): R² = {$rSquared} ({$fitQuality})
- Based on {$obsCount} monthly progress observations
{$reliabilityText}

RISK ASSESSMENT:
- Risk Level: {$riskLevel}
- Trend: {$trend}

CONTRACTOR PERFORMANCE:
- {$contractorText}

{$issuesText}

{$extensionsText}

{$progressRemarksText}

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
- Use FORECAST RELIABILITY (not R²) to assess how much to trust predictions
- If any text field appears to be gibberish or placeholder text (random characters, no meaningful words), ignore it entirely and do not base recommendations on it
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
        $openIssues = $project->issues()->where('status', 'Open')->get();

        $totalCount = $openIssues->count();

        if ($totalCount === 0) {
            return "ISSUES:\n- No open issues";
        }

        // Aggregate breakdown (always included)
        $byType = $openIssues->groupBy('issue_type')
            ->map(fn ($group) => $group->count())
            ->toArray();

        $bySeverity = $openIssues->whereNotNull('severity')
            ->groupBy('severity')
            ->map(fn ($group) => $group->count())
            ->toArray();

        $agingCount = $project->issues()
            ->whereIn('status', ['Open', 'In Progress'])
            ->where('created_at', '<=', now()->subDays(30))
            ->count();

        $resolvedLast30 = $project->issues()
            ->whereIn('status', ['Resolved', 'Closed'])
            ->where('resolved_at', '>=', now()->subDays(30))
            ->count();

        $lines = ["ISSUES:"];
        $lines[] = "- Total open: {$totalCount}";

        $typeLines = [];
        foreach ($byType as $type => $count) {
            $typeLines[] = "{$type}: {$count}";
        }
        if (!empty($typeLines)) {
            $lines[] = "- By type: " . implode(', ', $typeLines);
        }

        $sevLines = [];
        foreach ($bySeverity as $sev => $count) {
            $sevLines[] = "{$sev}: {$count}";
        }
        if (!empty($sevLines)) {
            $lines[] = "- By severity: " . implode(', ', $sevLines);
        }

        $lines[] = "- Aging (30+ days open): {$agingCount}";
        $lines[] = "- Resolved last 30 days: {$resolvedLast30}";

        // Individual issue details — only include meaningful text
        $meaningfulIssues = $openIssues
            ->filter(fn ($issue) =>
                $this->textFilter->isMeaningful($issue->issue_name)
                || $this->textFilter->isMeaningful($issue->description)
            )
            ->take(self::MAX_ISSUES_IN_PROMPT);

        if ($meaningfulIssues->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "OPEN ISSUE DETAILS (name — description):";
            foreach ($meaningfulIssues as $issue) {
                $name = $this->textFilter->isMeaningful($issue->issue_name)
                    ? mb_substr($issue->issue_name, 0, self::MAX_TEXT_LENGTH)
                    : '(no description)';
                $desc = $this->textFilter->isMeaningful($issue->description)
                    ? mb_substr($issue->description, 0, self::MAX_TEXT_LENGTH)
                    : '';
                $sev = $issue->severity ? " [{$issue->severity}]" : '';
                $detail = $desc !== '' ? " — {$desc}" : '';
                $lines[] = "- {$name}{$sev}{$detail}";
            }
        }

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

        // Aggregate breakdown (always included)
        $byReason = $extensions
            ->groupBy('reason_category')
            ->map(fn ($group) => $group->count())
            ->toArray();

        $totalDays = $extensions->sum('duration_days');
        $avgDays = $count > 0 ? round($totalDays / $count, 1) : 0;

        $lines = ["EXTENSIONS:"];
        $lines[] = "- Total approved: {$count}";
        $lines[] = "- Total extension days: {$totalDays}";
        $lines[] = "- Average days per extension: {$avgDays}";

        $reasonLines = [];
        foreach ($byReason as $reason => $reasonCount) {
            $reasonLines[] = "{$reason}: {$reasonCount}";
        }
        if (!empty($reasonLines)) {
            $lines[] = "- Reasons: " . implode(', ', $reasonLines);
        }

        // Individual extension reasons — only include meaningful text
        $meaningfulExts = $extensions
            ->filter(fn ($ext) => $this->textFilter->isMeaningful($ext->reason))
            ->take(self::MAX_EXTENSIONS_IN_PROMPT);

        if ($meaningfulExts->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "EXTENSION DETAILS (days — reason):";
            foreach ($meaningfulExts as $ext) {
                $reason = mb_substr($ext->reason, 0, self::MAX_TEXT_LENGTH);
                $lines[] = "- {$ext->duration_days} days — {$reason}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * ------------------------------------------------------------------------
     * Build Progress Remarks Text
     * ------------------------------------------------------------------------
     *
     * Queries the 3 latest progress inspection records and formats them
     * into a prompt-friendly block showing month, progress, variance, and
     * inspector remarks. Remarks are filtered through TextQualityFilter
     * to skip gibberish dev data.
     *
     * Gives the AI qualitative on-the-ground context that aggregate stats
     * miss — e.g. "typhoon delayed steel delivery" vs just "material: 2".
     *
     * Used By
     * -------
     * buildPrompt()
     */
    private function buildProgressRemarksText(Project $project): string
    {
        $records = $project->progressHistory()
            ->latest('inspection_date')
            ->take(3)
            ->get();

        if ($records->isEmpty()) {
            return "RECENT INSPECTIONS:\n- No inspection records yet";
        }

        $lines = ["RECENT INSPECTIONS (latest 3):"];
        foreach ($records as $record) {
            $month = $record->month_label ?? 'Unknown';
            $actual = $record->actual_progress_percent ?? '?';
            $variance = $record->variance_percent !== null
                ? sprintf('%+.1f%%', $record->variance_percent)
                : 'N/A';
            $remarks = $this->textFilter->isMeaningful($record->remarks)
                ? mb_substr($record->remarks, 0, self::MAX_TEXT_LENGTH)
                : '(no remarks)';
            $lines[] = "- Month: {$month} | Progress: {$actual}% | Variance: {$variance} | Remarks: {$remarks}";
        }

        return implode("\n", $lines);
    }

    /**
     * ------------------------------------------------------------------------
     * Build Reliability Text
     * ------------------------------------------------------------------------
     *
     * Renders the forecast-reliability block into a prompt-friendly summary.
     * This is the actual trust indicator for predictions — NOT R².
     *
     * Format matches AIExplanationService::buildReliabilityText() for
     * consistency across AI prompts.
     *
     * Used By
     * -------
     * buildPrompt()
     */
    private function buildReliabilityText(?array $reliability): string
    {
        if (empty($reliability)) {
            return "\nFORECAST RELIABILITY:\n- N/A — insufficient data for a reliability estimate";
        }

        $lines = ['FORECAST RELIABILITY (use this, NOT R², to assess prediction trust):'];

        if (isset($reliability['next_prediction'])) {
            $next = $reliability['next_prediction'];
            $lines[] = sprintf(
                '- Next-month forecast: %s (%d/100)',
                $next['level'],
                $next['score']
            );
        }

        if (isset($reliability['completion']) && $reliability['completion'] !== null) {
            $comp = $reliability['completion'];
            $lines[] = sprintf(
                '- Completion estimate: %s (%d/100)',
                $comp['level'],
                $comp['score']
            );
        }

        $factorTitles = [
            'backtest'  => 'Prediction history',
            'data'      => 'Data',
            'horizon'   => 'Forecast horizon',
            'agreement' => 'Method agreement',
        ];

        $source = $reliability['next_prediction'] ?? $reliability['completion'] ?? [];

        foreach ($factorTitles as $key => $title) {
            $factor = $source['factors'][$key] ?? null;
            if (!$factor) {
                continue;
            }
            $lines[] = sprintf(
                '- %s: %d/100 — %s',
                $title,
                $factor['score'],
                $factor['detail']
            );
        }

        return "\n" . implode("\n", $lines);
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
