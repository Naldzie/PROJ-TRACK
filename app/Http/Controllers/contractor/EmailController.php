<?php

namespace App\Http\Controllers\Contractor;

use App\Http\Controllers\Controller;
use App\Models\EmailTracking;
use App\Services\ProjectStatusService;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function index(Request $request)
    {
        $contractor = auth()->user()->contractor;

        $nextAutoSend = app(\App\Services\AIReportSendingService::class)->nextAutoSendAt();

        if (!$contractor) {
            $emails = collect();
            return view('contractor.email', compact('emails', 'nextAutoSend'));
        }

        // Contractors only ever see delivered mail — drafts and in-review
        // reports stay invisible until the send step (or manual send) runs.
        $query = EmailTracking::where('contractor_id', $contractor->id)
            ->whereNotNull('sent_at')
            ->with(['project', 'aiSolution'])
            ->orderBy('sent_at', 'desc');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhereHas('project', fn($p) => $p->where('title', 'like', "%{$search}%"));
            });
        }

        $emails = $query->paginate(15)->withQueryString();

        $recentActivity = EmailTracking::where('contractor_id', $contractor->id)
            ->whereNotNull('sent_at')
            ->with('project')
            ->orderBy('sent_at', 'desc')
            ->take(5)
            ->get();

        if ($request->ajax()) {
            return response()->json([
                'html' => view('contractor.partials.email_list', compact('emails'))->render(),
                'pagination' => $emails->links()->toHtml(),
                'total' => $emails->total(),
            ]);
        }

        return view('contractor.email', compact('emails', 'recentActivity', 'nextAutoSend'));
    }

    public function show(EmailTracking $email)
    {
        $contractor = auth()->user()->contractor;

        if (!$contractor || $email->contractor_id !== $contractor->id) {
            abort(403);
        }

        // Unsent drafts/reviews are staff-only until delivered.
        if ($email->isDraft()) {
            abort(404);
        }

        $email->load(['project', 'aiSolution']);

        if (!$email->is_read) {
            $email->update([
                'is_read' => true,
                'delivered_at' => $email->delivered_at ?? now(),
                'opened_at' => $email->opened_at ?? now(),
            ]);
        }

        $project = $email->project;
        $lastBaseline = $project?->baselineSchedules()->latest('planned_date')->first();
        $planned = $lastBaseline ? (float) $lastBaseline->planned_progress_percent : 0;
        $progress = $project ? $project->getProgressPercentage() : 0;
        $actual = $project && $project->status === 'Completed' ? 100 : (float) $progress;
        $variance = $actual - $planned;
        $delayPct = $planned > 0 ? round((($planned - $actual) / $planned) * 100, 1) : 0;
        $sev = $variance >= 0 ? 'ok' : ($variance >= -15 ? 'warn' : 'crit');

        $statusService = app(ProjectStatusService::class);
        $riskData = $statusService->getRiskLevel(-$variance);

        return response()->json([
            'id' => $email->id,
            'subject' => $email->subject,
            'from' => 'PROJ-TRACK System',
            'project_title' => $project->title ?? 'Unknown',
            'project_location' => $project->location ?? 'N/A',
            'project_status' => $project->status ?? 'N/A',
            'body' => $email->effectiveBody() ?? 'Report analysis not available.',
            'body_html' => app(\App\Services\AiReportParser::class)->renderForDisplay($email->effectiveBody() ?? ''),
            'planned_progress' => number_format($planned, 1) . '%',
            'actual_progress' => number_format($actual, 1) . '%',
            'variance' => $variance,
            'delay_percentage' => $delayPct,
            'risk_level' => $riskData['label'],
            'generated_at' => $email->sent_at?->format('F d, Y h:i A') ?? 'N/A',
            'ai_generated_at' => $email->aiSolution?->created_at?->format('F d, Y h:i A') ?? 'N/A',
            'month' => $email->sent_at?->format('F Y') ?? '',
            'is_read' => true,
            'status' => $email->opened_at ? 'Opened' : ($email->delivered_at ? 'Delivered' : 'Pending'),
            'severity' => $sev,
        ]);
    }
}
