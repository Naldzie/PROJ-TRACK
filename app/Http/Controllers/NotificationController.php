<?php

namespace App\Http\Controllers;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * In-app notifications UI (gaps #12).
 *
 * index()   — full paginated list for the current user.
 * recent()  — latest 5 unread for the topbar bell dropdown.
 * read()    — mark a single notification read (owner-only).
 * readAll() — mark all of the current user's notifications read.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = Auth::user()
            ->notifications()
            ->latest()
            ->paginate(20);

        $view = match (Auth::user()->role) {
            'staff'      => 'notifications.index_staff',
            'contractor' => 'notifications.index_contractor',
            default      => 'notifications.index_admin',
        };

        return view($view, compact('notifications'));
    }

    public function recent()
    {
        $notifications = Auth::user()
            ->unreadNotifications()
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'success'       => true,
            'unread_count'  => Auth::user()->unreadNotifications()->count(),
            'notifications' => $notifications->map(fn ($n) => $this->format($n)),
        ]);
    }

    public function read(DatabaseNotification $notification)
    {
        if ($notification->notifiable_id !== Auth::id()
            || $notification->notifiable_type !== \App\Models\User::class) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $notification->markAsRead();

        return response()->json([
            'success'      => true,
            'unread_count' => Auth::user()->unreadNotifications()->count(),
        ]);
    }

    public function readAll()
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
        ]);
    }

    /**
     * Shape a notification for JSON (dropdown + inline AJAX).
     */
    private function format(DatabaseNotification $n): array
    {
        $data = $n->data;

        return [
            'id'        => $n->id,
            'type'      => $this->humanType($n->type),
            'title'     => $this->title($n->type, $data),
            'meta'      => $data['project_title'] ?? '',
            'read'      => $n->read_at !== null,
            'created'   => $n->created_at?->diffForHumans(),
            'created_at' => $n->created_at?->format('M d, Y h:i A'),
        ];
    }

    private function humanType(string $class): string
    {
        return match ($class) {
            \App\Notifications\DeadlineReminder::class            => 'Deadline Reminder',
            \App\Notifications\OverdueInspectionReminder::class   => 'Overdue Inspection',
            \App\Notifications\ExtensionStatusChanged::class      => 'Extension Update',
            \App\Notifications\IssueReported::class               => 'Issue Reported',
            \App\Notifications\RiskReported::class                => 'Risk Reported',
            default => 'Notification',
        };
    }

    private function title(string $class, array $data): string
    {
        $project = $data['project_title'] ?? '';

        return match ($class) {
            \App\Notifications\DeadlineReminder::class => $data['bucket'] === 'overdue'
                    ? ($project ? "Project {$project} is overdue" : 'Project is overdue')
                    : "Project deadline in {$data['days_remaining']} days",
            \App\Notifications\OverdueInspectionReminder::class => 'Inspection overdue for ' . ($data['month_display'] ?? $data['month_label'] ?? ''),
            \App\Notifications\ExtensionStatusChanged::class => 'Extension ' . ($data['status'] ?? '') . ($data['extension_number'] ? " #{$data['extension_number']}" : ''),
            \App\Notifications\IssueReported::class => 'Issue reported' . ($data['severity'] ? " ({$data['severity']})" : ''),
            \App\Notifications\RiskReported::class => 'High risk: ' . ($data['risk_name'] ?? ''),
            default => 'Notification',
        };
    }
}
