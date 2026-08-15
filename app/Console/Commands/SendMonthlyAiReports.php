<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Setting;
use App\Services\AIReportSendingService;
use Illuminate\Console\Command;

class SendMonthlyAiReports extends Command
{
    protected $signature = 'reports:send-ai-monthly {--force : Skip auto-send and date checks}';
    protected $description = 'Send AI reports on the first day of every month (Aug 1, Sep 1, ...)';

    public function handle(AIReportSendingService $service)
    {
        if (!$this->option('force')) {
            if (!$service->isAutoSendEnabled()) {
                $this->info('Auto-send is disabled. Use --force to override.');
                return;
            }

            if (now()->day !== 1) {
                $this->info('Not the first day of the month — skipping. Next run: ' . $service->nextAutoSendAt()->format('M j, Y g:i A'));
                return;
            }
        }

        $this->info('Checking projects for monthly AI report dispatch...');

        $projects = Project::whereIn('status', ['Ongoing', 'Completed'])
            ->whereNotNull('contractor_id')
            ->with(['contractor.user', 'baselineSchedules'])
            ->get();

        $sent = 0;
        $errors = 0;
        $skipped = 0;
        $logs = [];

        foreach ($projects as $project) {
            $aiSolution = $project->aiSolutions()->latest('created_at')->first();
            if (!$aiSolution) {
                $logs[] = ['project' => $project->code, 'message' => 'No AI analysis — skipped'];
                $skipped++;
                continue;
            }

            $alreadySent = \App\Models\EmailTracking::where('ai_solution_id', $aiSolution->id)
                ->where('contractor_id', $project->contractor_id)
                ->exists();

            if ($alreadySent) {
                $logs[] = ['project' => $project->code, 'message' => 'Already sent — skipped'];
                $skipped++;
                continue;
            }

            $result = $service->sendForProject($project);
            $logs[] = $result;
            if ($result['success']) {
                $sent++;
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

        if ($sent > 0) {
            Setting::setValue('last_auto_send_at', now()->toDateTimeString());
        }

        $this->info("Done. Sent: {$sent}, Skipped: {$skipped}, Errors: {$errors}, Total: {$projects->count()}");
    }
}
