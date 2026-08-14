<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\ContractorRating;
use App\Models\Project;
use Illuminate\Http\Request;

class RatingController extends Controller
{
    public function index()
    {
        $contractors = Contractor::with(['user', 'projects' => function ($q) {
            $q->whereIn('status', ['Ongoing', 'Completed'])
              ->with('aiSolutions');
        }])->get();

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

        $project = Project::with('contractor', 'aiSolutions')->findOrFail($request->project_id);
        $aiSolution = $project->aiSolutions()->latest('created_at')->first();

        if (!$aiSolution) {
            return response()->json(['error' => 'No AI analysis found for this project']);
        }

        $snapshot = $aiSolution->regression_snapshot;
        $predictedEnd = $snapshot['predicted_end_progress'] ?? null;
        $plannedEnd = $snapshot['original_planned_progress'] ?? 100;
        $variance = $snapshot['variance'] ?? 0;
        $rSquared = $snapshot['r_squared'] ?? 0;
        $analysisText = $aiSolution->analysis_text ?? '';

        $actual = $project->getProgressPercentage();
        $lastBaseline = $project->baselineSchedules()->latest('planned_date')->first();
        $planned = $lastBaseline ? (float) $lastBaseline->planned_progress_percent : 0;
        $delayPct = $planned > 0 ? round((($planned - $actual) / $planned) * 100, 1) : 0;

        $rating = $this->computeRating($variance, $delayPct, $rSquared);
        $review = $this->generateReview($project, $rating, $variance, $delayPct, $rSquared, $analysisText);

        return response()->json([
            'rating' => $rating,
            'review' => $review,
            'ai_solution_id' => $aiSolution->id,
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

    public function autoRateAll()
    {
        $contractors = Contractor::with(['projects' => function ($q) {
            $q->whereIn('status', ['Ongoing', 'Completed'])->with('aiSolutions');
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

    private function generateReview($project, int $rating, float $variance, float $delayPct, float $rSquared, string $analysisText): string
    {
        $name = $project->contractor->company_name ?? $project->contractor->contractor_name ?? 'Contractor';

        $parts = [
            5 => "Exceptional performance by {$name}. Variance of {$variance}% indicates work ahead of schedule. Model confidence is strong (R² = " . number_format($rSquared, 3) . "). Recommended for future high-priority projects.",
            4 => "Good performance from {$name}. Minimal variance ({$variance}%) with delay of only {$delayPct}%. Model shows reasonable fit (R² = " . number_format($rSquared, 3) . "). Suitable for continued engagement.",
            3 => "Acceptable performance by {$name}. Delay of {$delayPct}% (variance {$variance}%) falls within tolerable range. Model fit is moderate (R² = " . number_format($rSquared, 3) . "). Some process improvements recommended.",
            2 => "Below-average performance. Delay of {$delayPct}% (variance {$variance}%) exceeds acceptable thresholds. Close monitoring and corrective action plan advised before future engagement.",
            1 => "Critical performance issues identified. Delay of {$delayPct}% with variance of {$variance}% indicates serious schedule risks. Thorough review of project management processes required.",
        ];

        return $parts[$rating] ?? $parts[3];
    }
}
