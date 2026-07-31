<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\AiSolution;
use App\Models\EmailTracking;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SampleAiDataSeeder extends Seeder
{
    public function run(): void
    {
        $projects = Project::whereIn('status', ['Ongoing', 'Completed'])->get();
        $month = now()->startOfMonth()->subMonth();
        $count = 0;

        foreach ($projects as $project) {
            $contractor = $project->contractor;
            if (!$contractor) continue;

            $progress = $project->getProgressPercentage();
            $lastBaseline = $project->baselineSchedules()->latest('planned_date')->first();
            $planned = $lastBaseline ? (float) $lastBaseline->planned_progress_percent : 50;
            $variance = min(100, max(0, $progress)) - $planned;

            $varianceText = $variance > 0
                ? 'ahead of schedule by ' . number_format(abs($variance), 1) . '%.'
                : 'behind schedule by ' . number_format(abs($variance), 1) . '%.';

            $aiText = 'AI Mitigation Suggestion: The project ' . $project->title
                . ' is currently ' . $varianceText
                . ' Based on the regression analysis (R² = 0.95), the projected completion '
                . ($variance < 0 ? 'may require schedule extension.' : 'is on track.')
                . ' Recommended actions include continuous monitoring of critical path activities '
                . 'and ensuring timely submission of accomplishment reports.';

            $snapshot = [
                'current_state' => ['progress' => $progress, 'month_index' => 6],
                'next_prediction' => ['progress' => min(100, $progress + 5)],
                'regression' => [
                    'equation' => 'y = 4.8x - 8.5',
                    'slope' => 4.8,
                    'intercept' => -8.5,
                    'r_squared' => 0.95,
                    'fit_quality' => 'Excellent',
                    'observation_count' => 6,
                ],
                'statistics' => [
                    'observation_count' => 6,
                    'current_month_index' => 6,
                    'first_month' => $month->copy()->subMonths(5)->format('Y-m-d'),
                    'last_month' => $month->format('Y-m-d'),
                ],
            ];

            $aiSolution = AiSolution::create([
                'project_id' => $project->id,
                'regression_snapshot' => $snapshot,
                'model_used' => 'gemini-flash-lite-latest',
                'analysis_text' => $aiText,
            ]);

            $tracking = EmailTracking::create([
                'contractor_id' => $contractor->id,
                'project_id' => $project->id,
                'ai_solution_id' => $aiSolution->id,
                'recipient_email' => $contractor->user->email,
                'subject' => 'AI Project Status Report — ' . $project->title,
                'tracking_token' => EmailTracking::generateToken(),
                'sent_at' => $month->copy()->addDays(1),
                'opened_at' => rand(0, 1) ? $month->copy()->addDays(3) : null,
                'open_count' => rand(0, 2),
            ]);

            $this->command?->info("Created AI + tracking for {$project->code}");
            $count++;
        }

        $this->command?->info("Done. {$count} sample AI analysis records created.");
    }
}
