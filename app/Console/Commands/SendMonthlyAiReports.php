<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\AIReportSendingService;
use Illuminate\Console\Command;

class SendMonthlyAiReports extends Command
{
    protected $signature = 'reports:send-ai-monthly {--force : Send all staged reviews even if their review window has not ended, and skip date checks}';
    protected $description = 'Send staged AI reports whose 5-day review window has ended (runs daily; the 25th generation step stages them)';

    public function handle(AIReportSendingService $service)
    {
        if (!$service->isAutoSendEnabled() && !$this->option('force')) {
            $this->info('Auto-send is disabled. Use --force to override.');
            return;
        }

        $force = (bool) $this->option('force');

        $this->info($force
            ? 'Sending ALL staged AI reports (forced — review windows ignored)...'
            : 'Sending AI reports whose review window has ended...');

        $result = $service->sendDueReviews($force);

        foreach ($result['logs'] as $log) {
            if ($log['success'] ?? false) {
                $this->info("OK: {$log['project']} — {$log['message']}");
            } else {
                $this->warn("SKIP: {$log['project']} — {$log['message']}");
            }
        }

        if ($result['sent'] > 0) {
            Setting::setValue('last_auto_send_at', now()->toDateTimeString());
        }

        if ($result['total'] === 0) {
            $this->info('Nothing due — no staged reports past their review window.');
        }

        $this->info("Done. Sent: {$result['sent']}, Errors: {$result['errors']}");
    }
}
