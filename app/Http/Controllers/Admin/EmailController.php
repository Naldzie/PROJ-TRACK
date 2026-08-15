<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTracking;
use App\Models\Project;
use App\Models\Setting;
use App\Services\AIReportSendingService;
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

        $autoSendEnabled = Setting::getValue('auto_send_ai_reports', '1') === '1';
        $lastAutoSend = Setting::getValue('last_auto_send_at');

        $projects = Project::whereIn('status', ['Ongoing', 'Completed'])
            ->whereNotNull('contractor_id')
            ->with('contractor')
            ->orderBy('created_at', 'desc')
            ->get();

        $recentActivity = EmailTracking::with(['project', 'contractor'])
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

        return view('admin.email', compact('emails', 'autoSendEnabled', 'lastAutoSend', 'projects', 'recentActivity', 'nextAutoSend'));
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

    public function markAsRead(EmailTracking $email)
    {
        if (!$email->is_read) {
            $email->update([
                'is_read' => true,
                'opened_at' => $email->opened_at ?? now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function sendNow(AIReportSendingService $service)
    {
        $result = $service->sendToAllProjects();

        $message = "Sent: {$result['sent']}, Errors: {$result['errors']}, Total: {$result['total']}";

        if ($result['errors'] > 0) {
            return back()->with('warning', $message);
        }

        return back()->with('success', "AI reports sent successfully. {$message}");
    }

    public function sendOne(Request $request, AIReportSendingService $service)
    {
        $request->validate(['project_id' => 'required|integer|exists:projects,id']);

        $result = $service->sendForProjectById((int) $request->project_id);

        if ($result['success']) {
            return back()->with('success', "Report sent: {$result['message']}");
        }

        return back()->with('warning', "Failed: {$result['message']}");
    }

    public function toggleAutoSend(Request $request)
    {
        $enabled = $request->boolean('enabled', true);
        Setting::setValue('auto_send_ai_reports', $enabled ? '1' : '0');

        $status = $enabled ? 'enabled' : 'disabled';
        return back()->with('success', "Auto-send AI reports {$status}.");
    }
}
