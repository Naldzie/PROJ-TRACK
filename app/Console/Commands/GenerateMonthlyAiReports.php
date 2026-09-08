<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\AIReportSendingService;
use App\Services\AI\AIExplanationService;
use Illuminate\Console\Command;

class GenerateMonthlyAiReports extends Command
{
    protected $signature = 'reports:generate-ai-monthly {--force : Skip auto-send and date checks}';
    protected $description = 'Generate monthly AI analyses on the 25th and stage them for the 5-day staff/admin review window (no emails are sent by this step)';

    public function handle(AIReportSendingService $service, AIExplanationService $explanationService)
    {
        if (!$this->option('force')) {
            if (!$service->isAutoSendEnabled()) {
                $this->info('Auto-send is disabled. Use --force to override.');
                return;
            }

            if (now()->day !== AIReportSendingService::GENERATION_DAY) {
                $this->info('Not generation day (25th) — skipping. Next run: ' . $service->nextAutoSendAt()->format('M j, Y g:i A'));
                return;
            }
        }

        $this->info('Generating monthly AI analyses and staging them for review...');

        $projects = Project::whereIn('status', ['Ongoing', 'Completed'])
            ->whereNotNull('contractor_id')
            ->with(['contractor.user', 'baselineSchedules'])
            ->get();

        $staged = 0;
        $skipped = 0;
        $errors = 0;
        $logs = [];

        foreach ($projects as $project) {
            $aiSolution = $project->aiSolutions()->latest('created_at')->first();

            // Generate AI analysis if it doesn't exist (same as before).
            if (!$aiSolution) {
                $this->info("Generating AI analysis for {$project->code}...");
                try {
                    $explanationService->generate($project);
                    $aiSolution = $project->aiSolutions()->latest('created_at')->first();
                    if (!$aiSolution) {
                        $logs[] = ['project' => $project->code, 'message' => 'AI analysis generation failed — skipped'];
                        $skipped++;
                        continue;
                    }
                } catch (\Exception $e) {
                    $logs[] = ['project' => $project->code, 'message' => 'AI analysis generation error: ' . $e->getMessage() . ' — skipped'];
                    $skipped++;
                    continue;
                }
            }

            // Stage for review — never send from this step.
            $result = $service->stageProjectForReview($project);
            $logs[] = $result;
            if ($result['success'] && str_starts_with($result['message'], 'Staged')) {
                $staged++;
            } elseif ($result['success']) {
                $skipped++;
            } else {
                $errors++;
            }
        }

        foreach ($logs as $log) {
            if ($log['success'] ?? false) {
                $this->info("OK: {$log['project']} — {$log['message']}");
            } else {
                $this->warn("SKIP: {$log['project']} — {$log['message']}");
            }
        }

        $this->info("Done. Staged: {$staged}, Skipped: {$skipped}, Errors: {$errors}, Total: {$projects->count()}");
        $this->info('Staged reports sit in the 5-day review window and auto-send once it ends.');
    }
}
