<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AiAnalysisMail;
use App\Models\EmailTracking;
use App\Models\Project;
use App\Models\Setting;
use App\Services\AIReportSendingService;
use App\Services\ProjectStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class EmailController extends Controller
{
    public function index(Request $request)
    {
        $query = EmailTracking::with(['project.contractor', 'contractor', 'aiSolution', 'generatedBy']);

        $sort = $request->get('sort', 'newest');
        $query->orderByRaw('sent_at IS NULL DESC')->orderBy('sent_at', $sort === 'oldest' ? 'asc' : 'desc');

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
            $query->whereNotNull('sent_at')->whereNull('opened_at');
        } elseif ($filter === 'drafts') {
            $query->whereNull('sent_at');
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
        $email->load(['project', 'contractor', 'aiSolution', 'generatedBy', 'editedBy']);

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
            'recipient_email' => $email->recipient_email,
            'contractor_name' => $email->contractor?->contractor_name ?? 'N/A',
            'company_name' => $email->contractor?->company_name ?? 'N/A',
            'project_title' => $project->title ?? 'Unknown',
            'project_location' => $project->location ?? 'N/A',
            'project_status' => $project->status ?? 'N/A',
            'body' => $email->effectiveBody() ?? 'Report analysis not available.',
            'ai_recommendation' => $email->aiSolution?->analysis_text ?? '',
            'planned_progress' => number_format($planned, 1) . '%',
            'actual_progress' => number_format($actual, 1) . '%',
            'variance' => $variance,
            'delay_percentage' => $delayPct,
            'risk_level' => $riskData['label'],
            'generated_at' => $email->sent_at?->format('F d, Y h:i A') ?? 'N/A',
            'status' => $email->isDraft() ? 'Draft' : ($email->opened_at ? 'Opened' : ($email->delivered_at ? 'Delivered' : 'Pending')),
            'is_draft' => $email->isDraft(),
            'is_read' => $email->is_read,
            'is_edited' => $email->is_edited,
            'edited_by_name' => $email->editedBy?->name,
            'edited_at' => $email->edited_at?->format('M d, Y h:i A'),
            'month' => $email->sent_at?->format('F Y') ?? '',
            'tracking_token' => $email->tracking_token,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'project_id' => ['required', Rule::exists('projects', 'id')->whereIn('status', ['Ongoing', 'Completed'])],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        $project = Project::whereIn('status', ['Ongoing', 'Completed'])
            ->whereNotNull('contractor_id')
            ->with('contractor.user')
            ->find($data['project_id']);

        if (!$project?->contractor?->user?->email) {
            return response()->json([
                'success' => false,
                'message' => 'Selected project has no contractor email assigned yet.',
            ], 422);
        }

        $draft = EmailTracking::create([
            'contractor_id' => $project->contractor->id,
            'project_id' => $project->id,
            'ai_solution_id' => null,
            'generated_by' => auth()->id(),
            'recipient_email' => $project->contractor->user->email,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'tracking_token' => EmailTracking::generateToken(),
            'sent_at' => null,
        ]);

        return response()->json(['success' => true, 'message' => 'Draft saved. It can still be edited until you send it.', 'id' => $draft->id]);
    }

    public function edit(EmailTracking $email)
    {
        return response()->json([
            'id' => $email->id,
            'project_id' => $email->project_id,
            'recipient_email' => $email->recipient_email,
            'subject' => $email->subject,
            'body' => $email->effectiveBody(),
            'is_draft' => $email->isDraft(),
        ]);
    }

    public function update(Request $request, EmailTracking $email)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        $wasSent = !$email->isDraft();

        $email->update([
            'subject' => $data['subject'],
            'body' => $data['body'],
            'is_edited' => true,
            'edited_by' => auth()->id(),
            'edited_at' => now(),
        ]);

        if (!$wasSent) {
            return response()->json(['success' => true, 'message' => 'Draft updated. Send it when you are ready.', 'resent' => false]);
        }

        $email->load(['project.contractor.user', 'contractor.user', 'aiSolution']);

        if (!$email->project) {
            return response()->json(['success' => true, 'message' => 'Saved, but could not re-send: the project no longer exists.', 'resent' => false]);
        }

        $recipient = $email->contractor?->user?->email ?? $email->recipient_email;

        if (!$recipient) {
            return response()->json(['success' => true, 'message' => 'Saved, but could not re-send: no contractor email found.', 'resent' => false]);
        }

        try {
            Mail::to($recipient)->send(new AiAnalysisMail($email->project, $email->aiSolution, $email));
        } catch (\Throwable $e) {
            Log::error('Edited email re-send failed', ['email_id' => $email->id, 'error' => $e->getMessage()]);

            return response()->json(['success' => true, 'message' => 'Edits saved, but automatic re-send failed: ' . $e->getMessage(), 'resent' => false]);
        }

        return response()->json(['success' => true, 'message' => "Message updated and automatically re-sent to {$recipient}.", 'resent' => true]);
    }

    public function destroy(EmailTracking $email)
    {
        if (!$email->isDraft()) {
            return response()->json([
                'success' => false,
                'message' => 'Sent messages cannot be deleted.',
            ], 403);
        }

        $email->delete();

        return response()->json(['success' => true, 'message' => 'Draft deleted.']);
    }

    public function sendDraft(EmailTracking $email)
    {
        if (!$email->isDraft()) {
            return response()->json([
                'success' => false,
                'message' => 'This message has already been sent.',
            ], 403);
        }

        $email->load(['project.contractor.user', 'contractor.user', 'aiSolution']);

        if (!$email->project) {
            return response()->json(['success' => false, 'message' => 'The project for this message no longer exists.'], 422);
        }

        $recipient = $email->contractor?->user?->email ?? $email->recipient_email;

        if (!$recipient) {
            return response()->json(['success' => false, 'message' => 'No contractor email found for this project.'], 422);
        }

        // Keep in-memory only; persisted on success so a failed send stays a draft.
        $previousSentAt = $email->sent_at;
        $email->sent_at = now();

        try {
            Mail::to($recipient)->send(new AiAnalysisMail($email->project, $email->aiSolution, $email));
        } catch (\Throwable $e) {
            $email->sent_at = $previousSentAt;
            Log::error('Draft email send failed', ['email_id' => $email->id, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Send failed: ' . $e->getMessage()], 422);
        }

        $email->update(['sent_at' => now(), 'delivered_at' => null, 'recipient_email' => $recipient]);

        return response()->json(['success' => true, "message" => "Message sent to {$recipient}. It is now locked."]);
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
