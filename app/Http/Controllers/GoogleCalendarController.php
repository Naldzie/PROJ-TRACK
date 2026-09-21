<?php

namespace App\Http\Controllers;

use App\Jobs\SyncProjectCalendar;
use App\Models\Project;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * === GOOGLE CALENDAR CONNECTION + PROJECT CALENDAR ACTIONS ===
 *
 * Login already grants calendar consent for new logins (see
 * AuthController). These endpoints cover everyone else: already-logged-in
 * users connect without re-logging in, anyone can check status or
 * disconnect, and each project offers a manual re-sync plus a no-OAuth
 * .ics download fallback.
 */
class GoogleCalendarController extends Controller
{
    // Start an incremental calendar-consent flow for the logged-in user.
    // No logout/login — tokens are linked to Auth::user() on callback.
    public function connect(Request $request)
    {
        $user = Auth::user();
        $state = 'calendar|' . $user->id . '|' . Str::random(32);
        session(['google_calendar_state' => $state]);

        return Socialite::driver('google')
            ->scopes(GoogleCalendarService::scopes())
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent select_account',
                'state' => $state,
            ])
            ->redirect();
    }

    // OAuth callback for the connect flow (login itself is untouched).
    public function callback(Request $request)
    {
        $state = (string) $request->query('state', '');
        $parts = explode('|', $state);

        $userId = ($parts[0] ?? '') === 'calendar' && isset($parts[1]) && is_numeric($parts[1])
            ? (int) $parts[1]
            : (int) (Auth::id() ?? 0);

        $user = $userId ? User::find($userId) : null;
        if (! $user) {
            return redirect()->route('login')->with('error', 'Could not link Google Calendar: user not found.');
        }

        if (session('google_calendar_state') && $state !== session('google_calendar_state')) {
            return $this->backToDashboard($user)->with('error', 'Google Calendar link failed: invalid state.');
        }
        session()->forget('google_calendar_state');

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            return $this->backToDashboard($user)->with('error', 'Google Calendar link failed: ' . $e->getMessage());
        }

        $this->storeTokens($user, $googleUser);

        // Push this user's involved projects now so the calendar fills in
        // immediately instead of waiting for the next project edit.
        $this->dispatchUserProjects($user);

        return $this->backToDashboard($user)->with('success', 'Google Calendar connected — project dates will sync automatically.');
    }

    public function status()
    {
        $user = Auth::user();

        return response()->json([
            'success' => true,
            'configured' => GoogleCalendarService::isConfigured(),
            'connected' => (bool) $user->hasGoogleCalendar(),
            'email' => $user->google_email,
        ]);
    }

    public function disconnect(Request $request)
    {
        $user = Auth::user();
        $user->forceFill([
            'google_refresh_token' => null,
            'google_calendar_enabled' => false,
            'google_calendar_connected_at' => null,
        ])->save();

        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Google Calendar disconnected.'])
            : $this->backToDashboard($user)->with('success', 'Google Calendar disconnected.');
    }

    // Manual re-sync for one project (admin, or staff assigned to it).
    public function sync(Request $request, Project $project)
    {
        $user = Auth::user();

        if ($user->role === 'staff' && ! $project->isAssignedTo($user)) {
            abort(403, 'You are not assigned to this project.');
        }
        if (! in_array($user->role, ['admin', 'staff'], true)) {
            abort(403, 'Only admin or assigned staff can trigger a calendar sync.');
        }

        SyncProjectCalendar::dispatch($project->id);

        // Inline result for immediate feedback (queued job also runs via
        // the model observer path for future edits).
        $summary = ['queued' => true];
        try {
            $summary = array_merge($summary, app(GoogleCalendarService::class)->syncProject($project));
        } catch (\Throwable $e) {
            $summary['warning'] = 'Sync queued, live push failed: ' . $e->getMessage();
        }

        return response()->json(array_merge(['success' => true], $summary));
    }

    // No-OAuth fallback: downloadable .ics (works without calendar consent).
    public function ics(Project $project)
    {
        $user = Auth::user();

        if ($user->role === 'contractor') {
            $contractor = $user->contractor;
            if (! $contractor || $project->contractor_id !== $contractor->id) {
                abort(403, 'Unauthorized.');
            }
        } elseif ($user->role === 'staff' && ! $project->isAssignedTo($user)) {
            abort(403, 'Unauthorized.');
        } elseif (! in_array($user->role, ['admin', 'staff', 'contractor'], true)) {
            abort(403, 'Unauthorized.');
        }

        $ics = app(GoogleCalendarService::class)->icsForProject($project);
        $filename = ($project->code ?: 'project-' . $project->id) . '-calendar.ics';

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Persist OAuth tokens without ever wiping a stored refresh token when
     * Google omits it (it only returns one on first consent).
     */
    public static function storeTokens(User $user, $googleUser): void
    {
        $updates = [
            'google_id' => $googleUser->getId(),
            'google_email' => $googleUser->getEmail(),
            'name' => $googleUser->getName() ?: $user->name,
            'profile_photo' => $googleUser->getAvatar() ?: $user->profile_photo,
        ];

        if (! empty($googleUser->token)) {
            $updates['google_token'] = $googleUser->token;
            $updates['google_token_expires_at'] = now()->addSeconds((int) ($googleUser->expiresIn ?? 3600));
        }

        if (! empty($googleUser->refreshToken)) {
            $updates['google_refresh_token'] = $googleUser->refreshToken;
            $updates['google_calendar_enabled'] = true;
            $updates['google_calendar_connected_at'] = now();
        } elseif ($user->google_refresh_token) {
            $updates['google_calendar_enabled'] = true;
        }

        $user->forceFill($updates)->save();
    }

    private function backToDashboard(User $user)
    {
        return match ($user->role) {
            'admin' => redirect()->route('admin.dashboard'),
            'staff' => redirect()->route('staff.dashboard'),
            'contractor' => redirect()->route('contractor.dashboard'),
            default => redirect()->route('login'),
        };
    }

    private function dispatchUserProjects(User $user): void
    {
        try {
            $ids = Project::where('is_archived', false)
                ->where(function ($q) use ($user) {
                    $q->where('assigned_project_manager', (string) $user->id);
                    if ($user->role === 'admin') {
                        $q->orWhereNotNull('id');
                    }
                    if ($user->role === 'contractor' && $user->contractor) {
                        $q->orWhere('contractor_id', $user->contractor->id);
                    }
                })
                ->limit(50)
                ->pluck('id');

            foreach ($ids as $id) {
                SyncProjectCalendar::dispatch((int) $id);
            }
        } catch (\Throwable $e) {
            \Log::info('Calendar connect backfill skipped', ['error' => $e->getMessage()]);
        }
    }
}
