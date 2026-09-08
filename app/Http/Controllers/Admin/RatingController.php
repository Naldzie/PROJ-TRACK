<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\ContractorRating;
use App\Models\Project;
use App\Services\AI\ContractorPerformanceService;
use Illuminate\Http\Request;

class RatingController extends Controller
{
    public function index()
    {
        $contractors = Contractor::with(['user', 'projects' => function ($q) {
            $q->whereIn('status', ['Ongoing', 'Completed'])
              ->with('aiSolutions');
        }])->get();

        // Phase 9: auto-recompute performance for contractors with null metrics
        // (handles existing contractors that completed projects before Phase 9 migration)
        $perfService = app(ContractorPerformanceService::class);
        foreach ($contractors as $contractor) {
            if ($contractor->performance_rating === null
                && $contractor->projects->contains('status', 'Completed')
            ) {
                $perfService->recompute($contractor);
            }
        }

        $ratings = ContractorRating::whereIn('project_id', Project::whereIn('status', ['Ongoing', 'Completed'])->pluck('id'))
            ->get()
            ->keyBy(fn($r) => $r->project_id . '-' . $r->contractor_id);

        $leaderboard = ContractorRating::selectRaw('contractor_id, AVG(rating) as avg_rating, COUNT(*) as project_count')
            ->whereIn('project_id', Project::whereIn('status', ['Ongoing', 'Completed'])->pluck('id'))
            ->groupBy('contractor_id')
            ->orderByDesc('avg_rating')
            ->limit(5)
            ->get();

        $contractorsJson = $contractors->map(fn($c) => [
            'id' => $c->id,
            'name' => $c->company_name ?? $c->contractor_name ?? ($c->user->name ?? 'Contractor #' . $c->id),
            'photo' => $c->user->profile_photo
                ? (str_starts_with($c->user->profile_photo, 'http')
                    ? $c->user->profile_photo
                    : asset('storage/' . $c->user->profile_photo))
                : ($c->user->avatar ?? 'https://ui-avatars.com/api/?name=' . urlencode($c->user->name ?? 'User')),
            'projects' => $c->projects->map(fn($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'status' => $p->status,
                'hasAi' => $p->aiSolutions->isNotEmpty(),
                'key' => $p->id . '-' . $c->id,
                'rating' => $ratings->get($p->id . '-' . $c->id)?->rating ?? 0,
            ]),
            'performance_rating' => $c->performance_rating,
            'on_time_delivery_pct' => $c->on_time_delivery_pct,
            'completed_projects_count' => $c->completed_projects_count,
            'avg_delay_days' => $c->avg_delay_days,
            'last_project_completed_at' => $c->last_project_completed_at
                ? (\Carbon\Carbon::parse($c->last_project_completed_at)->format('M d, Y'))
                : null,
            // Phase 9 additions: extension stats (informational) + project counts
            'total_projects_count' => $c->projects->count(),
            'ongoing_projects_count' => $c->projects->where('status', 'Ongoing')->count(),
            'total_extensions_count' => $c->total_extensions_count ?? 0,
            'total_extension_days' => $c->total_extension_days
                ? round((float) $c->total_extension_days, 1)
                : null,
            'trend' => $perfService->getPerformanceTrend($c),
        ]);

        $ratingsJson = $ratings->map(fn($r) => [
            'contractor_id' => $r->contractor_id,
            'project_id' => $r->project_id,
            'rating' => $r->rating,
            'ai_review_text' => $r->ai_review_text,
        ]);

        return view('admin.rating', compact('contractors', 'ratings', 'leaderboard', 'contractorsJson', 'ratingsJson'));
    }

    public function suggest(Request $request)
    {
        $request->validate(['project_id' => 'required|integer|exists:projects,id']);

        $project = Project::with(['contractor', 'aiSolutions'])->findOrFail($request->project_id);
        $aiSolution = $project->aiSolutions()->latest('created_at')->first();

        $actual = $project->getProgressPercentage();
        $lastBaseline = $project->baselineSchedules()->latest('planned_date')->first();
        $planned = $lastBaseline ? (float) $lastBaseline->planned_progress_percent : 0;
        $delayPct = $planned > 0 ? round((($planned - $actual) / $planned) * 100, 1) : 0;

        // Extension + issue context for a project-specific comment (works with or without AI data).
        $extCount = $project->extensions()->where('status', 'approved')->count();
        $issueCount = method_exists($project, 'issues') ? $project->issues()->count() : 0;

        if ($aiSolution) {
            $snapshot = $aiSolution->regression_snapshot ?? [];
            $predictedEnd = $snapshot['predicted_end_progress'] ?? null;
            $plannedEnd = $snapshot['original_planned_progress'] ?? 100;
            $variance = $snapshot['variance'] ?? 0;
            $rSquared = $snapshot['r_squared'] ?? 0;
            $analysisText = $aiSolution->analysis_text ?? '';

            $rating = $this->computeRating((float) $variance, (float) $delayPct, (float) $rSquared);
            $review = $this->generateReview($project, $rating, (float) $variance, (float) $delayPct, (float) $rSquared, $analysisText, $extCount, $issueCount);

            return response()->json([
                'rating' => $rating,
                'review' => $review,
                'ai_solution_id' => $aiSolution->id,
                'project_title' => $project->title,
                'details' => [
                    'actual' => $actual,
                    'planned' => $planned,
                    'delay_pct' => $delayPct,
                    'variance' => $variance,
                    'r_squared' => $rSquared,
                    'extensions' => $extCount,
                    'issues' => $issueCount,
                    'status' => $project->status,
                    'source' => 'ai',
                ],
            ]);
        }

        // Fallback: no AI solution yet — still analyze this specific project
        // from progress vs baseline + extensions so AI Review always comments.
        $variance = $planned > 0 ? round($actual - $planned, 1) : 0;
        $rSquared = 0;
        $rating = $this->computeRating((float) $variance, (float) $delayPct, 0);
        $review = $this->generateReview($project, $rating, (float) $variance, (float) $delayPct, 0, '', $extCount, $issueCount);

        return response()->json([
            'rating' => $rating,
            'review' => $review,
            'ai_solution_id' => null,
            'project_title' => $project->title,
            'details' => [
                'actual' => $actual,
                'planned' => $planned,
                'delay_pct' => $delayPct,
                'variance' => $variance,
                'r_squared' => null,
                'extensions' => $extCount,
                'issues' => $issueCount,
                'status' => $project->status,
                'source' => 'fallback',
            ],
        ]);
    }

    /**
     * Contractor overview AI comment.
     *
     * POST /admin/rating/contractor-overview
     *
     * Analyzes ALL projects of one contractor (counts, avg star rating,
     * on-time %, delays, extensions, trend) and returns a short AI-style
     * performance comment for the Overview card in rating.blade.php.
     */
    public function contractorOverview(Request $request)
    {
        $request->validate(['contractor_id' => 'required|integer|exists:contractors,id']);

        $contractor = Contractor::with(['projects' => function ($q) {
            $q->whereIn('status', ['Ongoing', 'Completed'])->with('aiSolutions');
        }])->findOrFail($request->contractor_id);

        $name = $contractor->company_name ?? $contractor->contractor_name ?? ($contractor->user->name ?? 'Contractor #' . $contractor->id);

        $total = $contractor->projects->count();
        $completed = $contractor->projects->where('status', 'Completed')->count();
        $ongoing = $contractor->projects->where('status', 'Ongoing')->count();

        $avgRating = ContractorRating::where('contractor_id', $contractor->id)->avg('rating');
        $ratedCount = ContractorRating::where('contractor_id', $contractor->id)->count();
        $avgRating = $avgRating !== null ? round((float) $avgRating, 1) : null;

        $perfRating = $contractor->performance_rating;
        $onTimePct = $contractor->on_time_delivery_pct !== null ? (float) $contractor->on_time_delivery_pct : null;
        $avgDelay = $contractor->avg_delay_days !== null ? (float) $contractor->avg_delay_days : null;
        $extCount = (int) ($contractor->total_extensions_count ?? 0);
        $extDays = $contractor->total_extension_days !== null ? (float) $contractor->total_extension_days : null;
        $avgExtPerProject = $completed > 0 ? round($extCount / $completed, 1) : null;

        $perfService = app(ContractorPerformanceService::class);
        $trend = $perfService->getPerformanceTrend($contractor);
        $trendDir = 'stable';
        $trendDelta = null;
        if (count($trend) >= 2) {
            $trendDelta = round($trend[count($trend) - 1]['on_time_pct'] - $trend[0]['on_time_pct'], 1);
            $trendDir = $trendDelta > 0 ? 'improving' : ($trendDelta < -2 ? 'declining' : 'stable');
        }

        $comment = $this->generateContractorOverview(
            $name, $total, $completed, $ongoing, $avgRating, $ratedCount,
            $perfRating, $onTimePct, $avgDelay, $extCount, $extDays,
            $avgExtPerProject, $trendDir, $trendDelta, count($trend)
        );

        return response()->json([
            'comment' => $comment,
            'metrics' => [
                'total' => $total,
                'completed' => $completed,
                'ongoing' => $ongoing,
                'avg_rating' => $avgRating,
                'rated_count' => $ratedCount,
                'performance_rating' => $perfRating,
                'on_time_pct' => $onTimePct,
                'avg_delay_days' => $avgDelay,
                'extensions' => $extCount,
                'extension_days' => $extDays,
                'trend' => $trendDir,
                'trend_delta' => $trendDelta,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'contractor_id' => 'required|integer|exists:contractors,id',
            'project_id' => 'required|integer|exists:projects,id',
            'rating' => 'required|integer|min:1|max:5',
            'ai_review_text' => 'nullable|string',
            'ai_solution_id' => 'nullable|integer|exists:ai_solutions,id',
        ]);

        $data['created_by'] = auth()->id();

        ContractorRating::updateOrCreate(
            ['contractor_id' => $data['contractor_id'], 'project_id' => $data['project_id']],
            $data
        );

        return response()->json(['success' => true, 'message' => 'Rating saved']);
    }

    /**
     * Auto-rate all unrated COMPLETED projects.
     *
     * Ongoing projects are never auto-rated — their S-curve is still moving,
     * so any rating would be premature. This matches the UI, where the manual
     * Rate button is disabled until a project is Completed.
     */
    public function autoRateAll()
    {
        $contractors = Contractor::with(['projects' => function ($q) {
            $q->where('status', 'Completed')->with('aiSolutions');
        }])->get();

        $rated = 0;
        $skipped = 0;

        foreach ($contractors as $contractor) {
            foreach ($contractor->projects as $project) {
                $existing = ContractorRating::where('contractor_id', $contractor->id)
                    ->where('project_id', $project->id)
                    ->first();

                if ($existing) {
                    $skipped++;
                    continue;
                }

                $aiSolution = $project->aiSolutions()->latest('created_at')->first();
                if (!$aiSolution) {
                    $skipped++;
                    continue;
                }

                $snapshot = $aiSolution->regression_snapshot;
                $variance = $snapshot['variance'] ?? 0;
                $rSquared = $snapshot['r_squared'] ?? 0;

                $actual = $project->getProgressPercentage();
                $lastBaseline = $project->baselineSchedules()->latest('planned_date')->first();
                $planned = $lastBaseline ? (float) $lastBaseline->planned_progress_percent : 0;
                $delayPct = $planned > 0 ? round((($planned - $actual) / $planned) * 100, 1) : 0;

                $rating = $this->computeRating($variance, $delayPct, $rSquared);

                ContractorRating::create([
                    'contractor_id' => $contractor->id,
                    'project_id' => $project->id,
                    'ai_solution_id' => $aiSolution->id,
                    'rating' => $rating,
                    'ai_review_text' => $this->generateReview($project, $rating, $variance, $delayPct, $rSquared, $aiSolution->analysis_text ?? ''),
                    'created_by' => auth()->id(),
                ]);

                $rated++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Auto-rated {$rated} project(s) ({$skipped} skipped — already rated or no AI data)",
        ]);
    }

    /**
     * Phase 9: On-demand recompute of contractor performance metrics.
     *
     * POST /admin/rating/{contractor}/recompute-performance
     *
     * Recalculates on-time delivery %, avg delay days, and performance
     * rating from the contractor's completed project history.
     *
     * Called By
     * ---------
     * Rating UI "Recompute" button (potential future use)
     */
    public function recomputePerformance(Request $request, Contractor $contractor)
    {
        $service = app(ContractorPerformanceService::class);
        $metrics = $service->recompute($contractor);

        return response()->json([
            'success' => true,
            'metrics' => $metrics,
        ]);
    }

    private function computeRating(float $variance, float $delayPct, float $rSquared): int
    {
        if ($variance >= 0 && $delayPct <= 0) {
            return $rSquared > 0.7 ? 5 : 4;
        }
        if ($variance >= -10 && $delayPct <= 15) {
            return $rSquared > 0.5 ? 4 : 3;
        }
        if ($variance >= -20 && $delayPct <= 30) {
            return 3;
        }
        if ($variance >= -35 && $delayPct <= 50) {
            return 2;
        }
        return 1;
    }

    private function generateReview($project, int $rating, float $variance, float $delayPct, float $rSquared, string $analysisText, int $extCount = 0, int $issueCount = 0): string
    {
        $name = $project->contractor->company_name ?? $project->contractor->contractor_name ?? 'Contractor';
        $title = $project->title ?? 'this project';
        $status = $project->status ?? 'Ongoing';
        $actual = $project->getProgressPercentage();

        // Project-specific context clause (extensions / issues).
        $context = '';
        if ($extCount > 0 || $issueCount > 0) {
            $bits = [];
            if ($extCount > 0) {
                $bits[] = "{$extCount} approved extension" . ($extCount === 1 ? '' : 's');
            }
            if ($issueCount > 0) {
                $bits[] = "{$issueCount} recorded issue" . ($issueCount === 1 ? '' : 's');
            }
            $context = ' Project record shows ' . implode(' and ', $bits) . '.';
        }

        $confidence = $rSquared > 0
            ? ' Model confidence is ' . ($rSquared > 0.7 ? 'strong' : ($rSquared > 0.5 ? 'moderate' : 'weak')) . ' (R² = ' . number_format($rSquared, 3) . ').'
            : ' No regression model is available for this project yet, so this assessment is based on actual vs planned progress.';

        $parts = [
            5 => "Exceptional performance by {$name} on \"{$title}\" ({$status}, {$actual}% complete). Variance of {$variance}% indicates work ahead of or on schedule with a delay of {$delayPct}%.{$context}{$confidence} Recommended for future high-priority projects.",
            4 => "Good performance from {$name} on \"{$title}\" ({$status}, {$actual}% complete). Minimal variance ({$variance}%) with a delay of only {$delayPct}%.{$context}{$confidence} Suitable for continued engagement.",
            3 => "Acceptable performance by {$name} on \"{$title}\" ({$status}, {$actual}% complete). Delay of {$delayPct}% (variance {$variance}%) falls within a tolerable range.{$context}{$confidence} Some process improvements recommended.",
            2 => "Below-average performance on \"{$title}\" ({$status}, {$actual}% complete). Delay of {$delayPct}% (variance {$variance}%) exceeds acceptable thresholds.{$context}{$confidence} Close monitoring and a corrective action plan are advised before future engagement.",
            1 => "Critical performance issues on \"{$title}\" ({$status}, {$actual}% complete). Delay of {$delayPct}% with variance of {$variance}% indicates serious schedule risk.{$context}{$confidence} A thorough review of project management processes is required.",
        ];

        return $parts[$rating] ?? $parts[3];
    }

    /**
     * Build the contractor-level AI overview comment.
     *
     * Rule-based narrative over ALL projects of the contractor so the
     * Overview card reads as an AI performance summary, not a static label.
     */
    private function generateContractorOverview(
        string $name, int $total, int $completed, int $ongoing,
        ?float $avgRating, int $ratedCount,
        $perfRating, ?float $onTimePct, ?float $avgDelay,
        int $extCount, ?float $extDays, ?float $avgExtPerProject,
        string $trendDir, ?float $trendDelta, int $trendPoints
    ): string {
        if ($total === 0) {
            return "{$name} has no projects in scope yet, so there is no performance history to assess. Assign a project first, then return here for an AI performance summary.";
        }

        $sentences = [];
        $sentences[] = "{$name} handles {$total} project" . ($total === 1 ? '' : 's')
            . " ({$completed} completed, {$ongoing} ongoing).";

        if ($avgRating !== null && $ratedCount > 0) {
            $tone = $avgRating >= 4.5 ? 'an exceptional track record'
                : ($avgRating >= 3.5 ? 'a solid track record'
                : ($avgRating >= 2.5 ? 'a mixed track record' : 'a concerning track record'));
            $sentences[] = "Across {$ratedCount} rated project" . ($ratedCount === 1 ? '' : 's')
                . ", the average rating is {$avgRating}/5 — {$tone}.";
        } else {
            $sentences[] = 'None of these projects have a star rating yet, so rate at least one completed project to sharpen this assessment.';
        }

        if ($onTimePct !== null && $completed > 0) {
            $delivery = $onTimePct >= 90 ? 'excellent on-time delivery'
                : ($onTimePct >= 80 ? 'reliable delivery'
                : ($onTimePct >= 70 ? 'moderate schedule discipline' : 'weak schedule discipline'));
            $delayTxt = $avgDelay !== null ? " with an average delay of {$avgDelay} days per completed project" : '';
            $sentences[] = "On-time delivery is {$onTimePct}% ({$delivery}){$delayTxt}.";
        } elseif ($completed === 0) {
            $sentences[] = 'All projects are still ongoing, so on-time delivery will be measurable once the first project completes.';
        }

        if ($completed > 0 && $extCount > 0) {
            $reliance = ($avgExtPerProject ?? 0) >= 1 ? 'heavy reliance on extensions'
                : (($avgExtPerProject ?? 0) >= 0.5 ? 'moderate use of extensions' : 'light use of extensions');
            $daysTxt = $extDays !== null ? ", totalling {$extDays} days" : '';
            $sentences[] = "Extension history shows {$extCount} approved extension" . ($extCount === 1 ? '' : 's')
                . " across completed work{$daysTxt} — {$reliance}.";
        } elseif ($completed > 0) {
            $sentences[] = 'No approved extensions on completed work, which signals good initial scheduling.';
        }

        if ($trendPoints >= 2 && $trendDelta !== null) {
            if ($trendDir === 'improving') {
                $sentences[] = "Trend is improving (+{$trendDelta}% on-time over {$trendPoints} completions) — recent projects finish more reliably than earlier ones.";
            } elseif ($trendDir === 'declining') {
                $sentences[] = "Trend is declining ({$trendDelta}% on-time over {$trendPoints} completions) — recent projects are slipping, so closer monitoring is advised.";
            } else {
                $sentences[] = "Trend is stable across {$trendPoints} completions — performance is consistent.";
            }
        }

        // Closing recommendation.
        if (($avgRating ?? 0) >= 4 && ($onTimePct ?? 100) >= 80) {
            $sentences[] = 'Recommendation: prioritize this contractor for upcoming high-priority work.';
        } elseif (($avgRating ?? 3) <= 2 || ($onTimePct ?? 100) < 50) {
            $sentences[] = 'Recommendation: review project management practices before awarding further work.';
        } elseif ($ongoing > 0) {
            $sentences[] = 'Recommendation: keep monitoring the ongoing project(s) — they will decide whether this positive standing holds.';
        } else {
            $sentences[] = 'Recommendation: suitable for continued engagement with standard oversight.';
        }

        return implode(' ', $sentences);
    }
}
