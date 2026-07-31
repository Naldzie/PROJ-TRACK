<?php

namespace App\Services;

use App\Models\Project;
use App\Models\AiSolution;
use App\Models\EmailTracking;
use App\Models\Setting;
use App\Mail\AiAnalysisMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class AIReportSendingService
{
    public function sendToAllProjects(): array
    {
        $projects = Project::whereIn('status', ['Ongoing', 'Completed'])
            ->whereNotNull('contractor_id')
            ->with(['contractor.user', 'baselineSchedules'])
            ->get();

        $sent = 0;
        $errors = 0;
        $logs = [];

        foreach ($projects as $project) {
            $result = $this->sendForProject($project);
            if ($result['success']) {
                $sent++;
            } else {
                $errors++;
            }
            $logs[] = $result;
        }

        return [
            'sent' => $sent,
            'errors' => $errors,
            'total' => $projects->count(),
            'logs' => $logs,
        ];
    }

    public function sendForProject(Project $project): array
    {
        $contractor = $project->contractor;
        if (!$contractor || !$contractor->user?->email) {
            return [
                'success' => false,
                'project' => $project->code,
                'message' => 'No contractor email found',
            ];
        }

        $aiSolution = AiSolution::where('project_id', $project->id)
            ->latest('created_at')
            ->first();

        if (!$aiSolution) {
            return [
                'success' => false,
                'project' => $project->code,
                'message' => 'No AI analysis found',
            ];
        }

        $existing = EmailTracking::where('ai_solution_id', $aiSolution->id)
            ->where('contractor_id', $contractor->id)
            ->exists();

        if ($existing) {
            return [
                'success' => true,
                'project' => $project->code,
                'message' => "Already sent to {$contractor->user->email} — skipped",
            ];
        }

        $tracking = EmailTracking::create([
            'contractor_id'  => $contractor->id,
            'project_id'     => $project->id,
            'ai_solution_id' => $aiSolution->id,
            'recipient_email' => $contractor->user->email,
            'subject'        => 'AI Project Status Report — ' . $project->title,
            'tracking_token' => EmailTracking::generateToken(),
            'sent_at'        => now(),
        ]);

        try {
            Mail::to($contractor->user->email)->send(new AiAnalysisMail($project, $aiSolution, $tracking));
            return [
                'success' => true,
                'project' => $project->code,
                'message' => "Sent to {$contractor->user->email}",
            ];
        } catch (\Throwable $e) {
            $tracking->delete();
            Log::error('AI report send failed', [
                'project' => $project->code,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'project' => $project->code,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function sendForProjectById(int $projectId): array
    {
        $project = Project::whereIn('status', ['Ongoing', 'Completed'])
            ->whereNotNull('contractor_id')
            ->with(['contractor.user', 'baselineSchedules'])
            ->find($projectId);

        if (!$project) {
            return [
                'success' => false,
                'project' => "#{$projectId}",
                'message' => 'Project not found or not eligible',
            ];
        }

        return $this->sendForProject($project);
    }

    public function isAutoSendEnabled(): bool
    {
        return Setting::getValue('auto_send_ai_reports', '1') === '1';
    }

    public function getAutoSendInterval(): int
    {
        return (int) Setting::getValue('auto_send_interval_days', '30');
    }
}
