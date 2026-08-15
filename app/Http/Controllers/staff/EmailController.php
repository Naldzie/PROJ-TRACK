<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\EmailTracking;
use App\Models\Project;
use Illuminate\Http\Request;

class EmailController extends Controller
{
    public function index(Request $request)
    {
        $query = EmailTracking::with(['project.contractor', 'contractor', 'aiSolution', 'generatedBy']);

        $sort = $request->get('sort', 'newest');
        $query->orderBy('sent_at', $sort === 'oldest' ? 'asc' : 'desc');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('recipient_email', 'like', "%{$search}%")
                  ->orWhere('subject', 'like', "%{$search}%")
                  ->orWhereHas('project', fn($p) => $p->where('title', 'like', "%{$search}%"))
                  ->orWhereHas('contractor', fn($c) => $c->where('company_name', 'like', "%{$search}%"));
            });
        }

        $filter = $request->get('filter');
        if ($filter === 'opened') {
            $query->whereNotNull('opened_at');
        } elseif ($filter === 'pending') {
            $query->whereNull('opened_at');
        }

        $emails = $query->paginate(15)->withQueryString();

        $projectsCount = Project::whereIn('status', ['Ongoing', 'Completed'])->count();

        $recentActivity = EmailTracking::with(['contractor', 'project'])
            ->orderBy('sent_at', 'desc')
            ->take(5)
            ->get();

        $nextAutoSend = app(\App\Services\AIReportSendingService::class)->nextAutoSendAt();

        if ($request->ajax()) {
            return response()->json([
                'html' => view('admin.partials.email_list', compact('emails'))->render(),
                'pagination' => $emails->links()->toHtml(),
                'total' => $emails->total(),
            ]);
        }

        return view('staff.email', compact('emails', 'recentActivity', 'projectsCount', 'nextAutoSend'));
    }

    public function show(EmailTracking $email)
    {
        $email->load(['project', 'contractor', 'aiSolution', 'generatedBy']);

        $project = $email->project;
        $lastBaseline = $project?->baselineSchedules()->latest('planned_date')->first();
        $planned = $lastBaseline ? (float) $lastBaseline->planned_progress_percent : 0;
        $progress = $project ? $project->getProgressPercentage() : 0;
        $actual = $project && $project->status === 'Completed' ? 100 : (float) $progress;
        $variance = $actual - $planned;
        $delayPct = $planned > 0 ? round((($planned - $actual) / $planned) * 100, 1) : 0;
        $sev = $variance >= 0 ? 'ok' : ($variance >= -15 ? 'warn' : 'crit');

        return response()->json([
            'id' => $email->id,
            'subject' => $email->subject,
            'recipient_email' => $email->recipient_email,
            'contractor_name' => $email->contractor?->contractor_name ?? 'N/A',
            'company_name' => $email->contractor?->company_name ?? 'N/A',
            'project_title' => $project->title ?? 'Unknown',
            'project_location' => $project->location ?? 'N/A',
            'project_status' => $project->status ?? 'N/A',
            'body' => $email->aiSolution?->analysis_text ?? 'Report analysis not available.',
            'ai_recommendation' => $email->aiSolution?->analysis_text ?? '',
            'planned_progress' => number_format($planned, 1) . '%',
            'actual_progress' => number_format($actual, 1) . '%',
            'variance' => $variance,
            'delay_percentage' => $delayPct,
            'risk_level' => $sev === 'crit' ? 'Critical' : ($sev === 'warn' ? 'Warning' : 'On Track'),
            'generated_at' => $email->sent_at?->format('F d, Y h:i A') ?? 'N/A',
            'status' => $email->opened_at ? 'Opened' : ($email->delivered_at ? 'Delivered' : 'Pending'),
            'is_read' => $email->is_read,
            'month' => $email->sent_at?->format('F Y') ?? '',
            'tracking_token' => $email->tracking_token,
        ]);
    }
}
