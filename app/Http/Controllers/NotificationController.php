<?php

namespace App\Http\Controllers;

use App\Services\NotificationPresenter;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * In-app notifications UI (gaps #12; filters + clear + presenter 2026-09-21,
 * restudy P-5/P-6 — textfiles/notification_monitoring_restudy_plan.md).
 *
 * index()      — paginated inbox for the current user, with filters:
 *                ?status=unread, ?type=<slug>, ?project=<id>.
 * recent()     — latest 5 unread + unread count for the topbar bell (the bell
 *                polls it, so it must stay cheap).
 * read()       — mark a single notification read (owner-only).
 * readAll()    — mark all of the current user's notifications read.
 * clearRead()  — hide every READ notification from the inbox (cleared_at; the
 *                rows stay so dedupe never re-sends them).
 *
 * All wording comes from App\Services\NotificationPresenter.
 */
class NotificationController extends Controller
{
    public function __construct(private NotificationPresenter $presenter)
    {
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $status = $request->query('status') === 'unread' ? 'unread' : 'all';
        $typeSlug = $request->query('type');
        $typeClass = NotificationPresenter::classForSlug($typeSlug);
        $projectId = $request->filled('project') ? (int) $request->query('project') : null;

        // Base query: never show cleared rows
        $query = $user->notifications()->whereNull('cleared_at');
        if ($status === 'unread') {
            $query->whereNull('read_at');
        }
        if ($typeClass) {
            $query->where('type', $typeClass);
        }
        if ($projectId) {
            $query->where('data->project_id', $projectId);
        }

        $notifications = $query->latest()->paginate(20)->withQueryString();

        // Filter choices: only the types / projects this user actually has
        $visible = $user->notifications()->whereNull('cleared_at')->get(['type', 'data']);
        $typeOptions = collect(NotificationPresenter::typeOptions())
            ->filter(fn ($label, $slug) => $visible->contains('type', NotificationPresenter::classForSlug($slug)))
            ->all();
        $projectOptions = $visible
            ->filter(fn ($n) => !empty($n->data['project_id']))
            ->mapWithKeys(fn ($n) => [(int) $n->data['project_id'] => $n->data['project_title'] ?? ('Project #' . $n->data['project_id'])])
            ->sort()
            ->all();

        $filters = [
            'status'  => $status,
            'type'    => $typeClass ? $typeSlug : null,
            'project' => $projectId,
        ];
        $readCount = $user->notifications()->whereNull('cleared_at')->whereNotNull('read_at')->count();

        $view = match ($user->role) {
            'staff'      => 'notifications.index_staff',
            'contractor' => 'notifications.index_contractor',
            default      => 'notifications.index_admin',
        };

        return view($view, compact('notifications', 'filters', 'typeOptions', 'projectOptions', 'readCount'));
    }

    public function recent()
    {
        $user = Auth::user();
        $notifications = $user
            ->unreadNotifications()
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'success'       => true,
            'unread_count'  => $user->unreadNotifications()->count(),
            'notifications' => $notifications->map(fn ($n) => $this->format($n, $user->role)),
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
     * Hide every read notification of the current user from the inbox. Unread
     * ones are never touched. Rows are kept (cleared_at) so NotificationService's
     * dedupe still sees them and daily scans do not re-send them.
     */
    public function clearRead()
    {
        $cleared = Auth::user()->notifications()
            ->whereNotNull('read_at')
            ->whereNull('cleared_at')
            ->update(['cleared_at' => now()]);

        return response()->json([
            'success' => true,
            'cleared' => $cleared,
            'message' => $cleared . ' read ' . ($cleared === 1 ? 'notification' : 'notifications') . ' cleared',
        ]);
    }

    /**
     * Shape a notification for JSON (bell polling + inline AJAX).
     */
    private function format(DatabaseNotification $n, string $role): array
    {
        $p = $this->presenter->present($n, $role);

        return [
            'id'         => $p['id'],
            'type'       => $p['type'],
            'title'      => $p['title'],
            'label'      => $p['label'],
            'meta'       => $p['meta'],
            'url'        => $p['url'],
            'read_url'   => route($role . '.notifications.read', $n->id),
            'read'       => $p['read'],
            'created'    => $p['created'],
            'created_at' => $p['created_at'],
        ];
    }
}
