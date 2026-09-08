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
    /**
     * Review-window workflow timing.
     *
     * Reports are generated on GENERATION_DAY (25th) and held as
     * pending_review drafts for REVIEW_WINDOW_DAYS (5 days) so staff/admin
     * can edit them. The send step only delivers rows whose review_until
     * has passed (i.e. the 30th). A daily send schedule (instead of
     * monthlyOn(30)) keeps short months like February working.
     */
    public const GENERATION_DAY = 25;
    public const REVIEW_WINDOW_DAYS = 5;
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

    /**
     * Stage one project's latest AI analysis for review instead of sending it.
     *
     * Creates a pending_review draft (body left null so the email renders the
     * AI text until someone edits it). Safe to re-run: an existing tracking
     * row for the same analysis + contractor means this cycle was already
     * staged or sent, so it is skipped — never duplicated mid-review.
     */
    public function stageProjectForReview(Project $project): array
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
            ->first();

        if ($existing) {
            return [
                'success' => true,
                'project' => $project->code,
                'message' => $existing->sent_at
                    ? "Already sent to {$contractor->user->email} — skipped"
                    : 'Already staged for review — skipped',
            ];
        }

        // A fresh analysis supersedes any older UNsent staged draft for this
        // project (e.g. someone hit Regenerate mid-review): the newest
        // analysis is what should go out, never two emails for one cycle.
        $superseded = EmailTracking::where('project_id', $project->id)
            ->where('contractor_id', $contractor->id)
            ->whereNull('sent_at')
            ->where('status', EmailTracking::STATUS_PENDING_REVIEW)
            ->delete();

        EmailTracking::create([
            'contractor_id'   => $contractor->id,
            'project_id'      => $project->id,
            'ai_solution_id'  => $aiSolution->id,
            'recipient_email' => $contractor->user->email,
            'subject'         => 'AI Project Status Report — ' . $project->title,
            'status'          => EmailTracking::STATUS_PENDING_REVIEW,
            'review_until'    => now()->addDays(self::REVIEW_WINDOW_DAYS),
            'tracking_token'  => EmailTracking::generateToken(),
            'sent_at'         => null,
        ]);

        return [
            'success' => true,
            'project' => $project->code,
            'message' => $superseded
                ? 'Staged for 5-day review (replaced older staged draft)'
                : 'Staged for 5-day review',
        ];
    }

    /**
     * Send every pending-review draft whose window has ended.
     *
     * When $force is true, all pending-review drafts go out regardless of
     * review_until (manual override / catch-up runs).
     */
    public function sendDueReviews(bool $force = false): array
    {
        $query = EmailTracking::with(['project.contractor.user', 'contractor.user', 'aiSolution'])
            ->whereNull('sent_at')
            ->where('status', EmailTracking::STATUS_PENDING_REVIEW);

        if (!$force) {
            $query->where('review_until', '<=', now());
        }

        $sent = 0;
        $errors = 0;
        $logs = [];

        foreach ($query->get() as $tracking) {
            $result = $this->sendTrackingRow($tracking);
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
            'total' => $sent + $errors,
            'logs' => $logs,
        ];
    }

    /**
     * Deliver one tracking row (draft or staged review) and lock it as sent.
     *
     * Renders through AiAnalysisMail, which prefers the edited body over the
     * raw AI text — so whatever staff last saved in the review window is what
     * goes out. A failed send leaves the row untouched (still editable).
     */
    public function sendTrackingRow(EmailTracking $tracking): array
    {
        $tracking->loadMissing(['project.contractor.user', 'contractor.user', 'aiSolution']);

        if (!$tracking->project) {
            return [
                'success' => false,
                'project' => "#{$tracking->project_id}",
                'message' => 'Project no longer exists',
            ];
        }

        $recipient = $tracking->contractor?->user?->email ?? $tracking->recipient_email;

        if (!$recipient) {
            return [
                'success' => false,
                'project' => $tracking->project->code,
                'message' => 'No contractor email found',
            ];
        }

        try {
            Mail::to($recipient)->send(new AiAnalysisMail($tracking->project, $tracking->aiSolution, $tracking));
        } catch (\Throwable $e) {
            Log::error('AI report send failed', [
                'project' => $tracking->project->code,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'project' => $tracking->project->code,
                'message' => $e->getMessage(),
            ];
        }

        $tracking->update([
            'sent_at' => now(),
            'delivered_at' => null,
            'recipient_email' => $recipient,
            'status' => EmailTracking::STATUS_SENT,
        ]);

        return [
            'success' => true,
            'project' => $tracking->project->code,
            'message' => "Sent to {$recipient}",
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

        // Only delivered rows block a re-send — a staged (pending_review)
        // draft is sent now instead of creating a duplicate row (the
        // ai_solution + contractor pair is unique).
        $sent = EmailTracking::where('ai_solution_id', $aiSolution->id)
            ->where('contractor_id', $contractor->id)
            ->whereNotNull('sent_at')
            ->exists();

        if ($sent) {
            return [
                'success' => true,
                'project' => $project->code,
                'message' => "Already sent to {$contractor->user->email} — skipped",
            ];
        }

        $staged = EmailTracking::where('ai_solution_id', $aiSolution->id)
            ->where('contractor_id', $contractor->id)
            ->whereNull('sent_at')
            ->first();

        if ($staged) {
            return $this->sendTrackingRow($staged);
        }

        $tracking = EmailTracking::create([
            'contractor_id'  => $contractor->id,
            'project_id'     => $project->id,
            'ai_solution_id' => $aiSolution->id,
            'recipient_email' => $contractor->user->email,
            'subject'        => 'AI Project Status Report — ' . $project->title,
            'status'         => EmailTracking::STATUS_SENT,
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

    /**
     * When the next monthly generation batch runs (25th at 08:00).
     *
     * Generation stages reports for review; delivery happens automatically
     * once each report's 5-day review window ends.
     */
    public function nextAutoSendAt(): \Illuminate\Support\Carbon
    {
        $candidate = now()->startOfMonth()->addDays(self::GENERATION_DAY - 1)->setTime(8, 0, 0);

        if (now()->gte($candidate)) {
            $candidate = $candidate->addMonthNoOverflow();
        }

        return $candidate;
    }
}
