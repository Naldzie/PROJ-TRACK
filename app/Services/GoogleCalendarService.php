<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectBaselineSchedule;
use App\Models\ProjectCalendarEvent;
use App\Models\User;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * === GOOGLE CALENDAR SYNC SERVICE ===
 *
 * Pushes a project's start date, current due date (planned_end_date, which
 * extensions keep current) and monthly baseline inspection dates to the
 * Google Calendars of everyone involved: every active admin, the assigned
 * project manager, and the assigned contractor's user.
 *
 * Idempotency: one ProjectCalendarEvent row per (project, user, event key)
 * holds Google's event id, so re-syncs UPDATE in place (deadline moved by
 * an extension) and stale inspection events are deleted when a baseline
 * month is removed. Users without calendar consent are skipped — the UI
 * falls back to one-click "Add to Google Calendar" template links + .ics.
 */
class GoogleCalendarService
{
    public const EVENT_START = 'start';

    public const EVENT_DUE = 'due';

    public const TIMEZONE = 'Asia/Manila';

    public static function scopes(): array
    {
        return config('services.google.calendar_scopes', [
            'openid',
            'email',
            'profile',
            'https://www.googleapis.com/auth/calendar.events',
        ]);
    }

    public static function isConfigured(): bool
    {
        return ! empty(config('services.google.client_id'))
            && ! empty(config('services.google.client_secret'));
    }

    public static function inspectionKey(int $scheduleId): string
    {
        return 'inspection:' . $scheduleId;
    }

    /**
     * Everyone involved in this project who should get the dates.
     * All active admins + the assigned PM user + the contractor's user.
     */
    public function resolveRecipients(Project $project): Collection
    {
        $ids = [];

        $adminIds = User::where('role', 'admin')
            ->where('is_active', true)
            ->pluck('id')
            ->all();
        foreach ($adminIds as $id) {
            $ids[$id] = true;
        }

        if (! empty($project->assigned_project_manager) && is_numeric($project->assigned_project_manager)) {
            $ids[(int) $project->assigned_project_manager] = true;
        }

        $project->loadMissing('contractor.user');
        $contractorUserId = $project->contractor?->user?->id;
        if ($contractorUserId) {
            $ids[(int) $contractorUserId] = true;
        }

        if (empty($ids)) {
            return collect();
        }

        return User::whereIn('id', array_keys($ids))
            ->where('is_active', true)
            ->get();
    }

    /**
     * The desired calendar entries for a project right now.
     * Each entry: ['key','kind','date' (Y-m-d),'schedule_id','label'].
     */
    public function desiredEvents(Project $project): array
    {
        $project->loadMissing(['baselineSchedules', 'contractor']);

        $events = [];

        if ($project->planned_start_date) {
            $events[] = [
                'key' => self::EVENT_START,
                'kind' => self::EVENT_START,
                'date' => Carbon::parse($project->planned_start_date)->toDateString(),
                'schedule_id' => null,
                'label' => 'Project kickoff',
            ];
        }

        if ($project->planned_end_date) {
            $events[] = [
                'key' => self::EVENT_DUE,
                'kind' => self::EVENT_DUE,
                'date' => Carbon::parse($project->planned_end_date)->toDateString(),
                'schedule_id' => null,
                'label' => 'Project deadline',
            ];
        }

        foreach ($project->baselineSchedules ?? collect() as $schedule) {
            if (empty($schedule->planned_date)) {
                continue;
            }
            $events[] = [
                'key' => self::inspectionKey((int) $schedule->id),
                'kind' => 'inspection',
                'date' => Carbon::parse($schedule->planned_date)->toDateString(),
                'schedule_id' => (int) $schedule->id,
                'label' => 'Inspection — ' . ($schedule->month_label ?: Carbon::parse($schedule->planned_date)->format('M Y')),
            ];
        }

        return $events;
    }

    /**
     * Build an authenticated Google client for a user, refreshing the
     * access token when it expired. Returns null when the user never
     * granted calendar consent or Google OAuth is not configured.
     */
    public function clientFor(User $user): ?GoogleClient
    {
        if (! self::isConfigured() || empty($user->google_refresh_token)) {
            return null;
        }

        $client = new GoogleClient();
        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));
        $client->setScopes(self::scopes());
        $client->setAccessType('offline');

        $expiresAt = $user->google_token_expires_at
            ? Carbon::parse($user->google_token_expires_at)
            : null;

        $accessToken = [
            'access_token' => (string) ($user->google_token ?: ''),
            'expires_in' => $expiresAt ? max(0, now()->diffInSeconds($expiresAt, false)) : 0,
            'created' => now()->subSeconds(10)->timestamp,
        ];

        if (empty($accessToken['access_token'])) {
            $accessToken['access_token'] = 'placeholder';
            $accessToken['expires_in'] = 0;
        }

        $client->setAccessToken($accessToken);

        if ($client->isAccessTokenExpired()) {
            try {
                $fresh = $client->fetchAccessTokenWithRefreshToken($user->google_refresh_token);
                if (isset($fresh['error'])) {
                    Log::warning('Google Calendar refresh failed', [
                        'user_id' => $user->id,
                        'error' => $fresh['error'],
                    ]);

                    return null;
                }
                $user->forceFill([
                    'google_token' => $fresh['access_token'] ?? $user->google_token,
                    'google_token_expires_at' => now()->addSeconds((int) ($fresh['expires_in'] ?? 3600)),
                ])->saveQuietly();
            } catch (\Throwable $e) {
                Log::warning('Google Calendar refresh exception', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        return $client;
    }

    /**
     * Push all desired events for a project to every connected recipient.
     * Never throws — failures are counted and logged so project saves are
     * never broken by a Google outage.
     */
    public function syncProject(Project $project): array
    {
        $summary = ['synced' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        if ($project->is_archived) {
            return $summary;
        }

        $desired = $this->desiredEvents($project);
        $desiredKeys = collect($desired)->pluck('key')->all();

        foreach ($this->resolveRecipients($project) as $recipient) {
            if (! $recipient->hasGoogleCalendar()) {
                $summary['skipped']++;

                continue;
            }

            $client = $this->clientFor($recipient);
            if (! $client) {
                $summary['skipped']++;

                continue;
            }

            $service = new GoogleCalendar($client);

            foreach ($desired as $entry) {
                try {
                    $record = ProjectCalendarEvent::firstOrNew([
                        'project_id' => $project->id,
                        'user_id' => $recipient->id,
                        'event_key' => $entry['key'],
                    ]);
                    $record->baseline_schedule_id = $entry['schedule_id'];
                    $record->target_date = $entry['date'];

                    $payload = $this->payloadFor($project, $entry);

                    if ($record->exists && $record->google_event_id) {
                        $updated = $service->events->update('primary', $record->google_event_id, new GoogleEvent($payload));
                        $record->google_event_id = $updated->getId() ?: $record->google_event_id;
                    } else {
                        $created = $service->events->insert('primary', new GoogleEvent($payload));
                        $record->google_event_id = $created->getId();
                    }

                    $record->last_synced_at = now();
                    $record->sync_status = 'synced';
                    $record->sync_error = null;
                    $record->save();
                    $summary['synced']++;
                } catch (\Throwable $e) {
                    $summary['failed']++;
                    $summary['errors'][] = "User {$recipient->id} / {$entry['key']}: {$e->getMessage()}";
                    Log::warning('Google Calendar sync failed', [
                        'project_id' => $project->id,
                        'user_id' => $recipient->id,
                        'event_key' => $entry['key'],
                        'error' => $e->getMessage(),
                    ]);

                    try {
                        $record = ProjectCalendarEvent::firstOrNew([
                            'project_id' => $project->id,
                            'user_id' => $recipient->id,
                            'event_key' => $entry['key'],
                        ]);
                        $record->baseline_schedule_id = $entry['schedule_id'];
                        $record->target_date = $entry['date'];
                        $record->sync_status = 'failed';
                        $record->sync_error = substr($e->getMessage(), 0, 1000);
                        $record->save();
                    } catch (\Throwable $ignored) {
                        // Never let bookkeeping break the sync loop.
                    }
                }
            }

            // Delete stale events (deadline key unchanged, but an inspection
            // month was removed or the date was cleared).
            try {
                $stale = ProjectCalendarEvent::where('project_id', $project->id)
                    ->where('user_id', $recipient->id)
                    ->whereNotIn('event_key', $desiredKeys)
                    ->get();
                foreach ($stale as $row) {
                    try {
                        if ($row->google_event_id) {
                            $service->events->delete('primary', $row->google_event_id);
                        }
                    } catch (\Throwable $e) {
                        Log::info('Google Calendar stale delete skipped', [
                            'project_id' => $project->id,
                            'user_id' => $recipient->id,
                            'event_key' => $row->event_key,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    $row->delete();
                }
            } catch (\Throwable $e) {
                Log::warning('Google Calendar stale cleanup failed', [
                    'project_id' => $project->id,
                    'user_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * Delete every pushed event for a project (used on permanent delete).
     */
    public function deleteProjectEvents(Project $project): void
    {
        $rows = ProjectCalendarEvent::where('project_id', $project->id)->with('user')->get();

        foreach ($rows->groupBy('user_id') as $userId => $group) {
            $user = $group->first()?->user;
            if (! $user || ! $user->hasGoogleCalendar()) {
                continue;
            }
            $client = $this->clientFor($user);
            if (! $client) {
                continue;
            }
            $service = new GoogleCalendar($client);
            foreach ($group as $row) {
                try {
                    if ($row->google_event_id) {
                        $service->events->delete('primary', $row->google_event_id);
                    }
                } catch (\Throwable $e) {
                    Log::info('Google Calendar delete skipped', [
                        'project_id' => $project->id,
                        'event_key' => $row->event_key,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        ProjectCalendarEvent::where('project_id', $project->id)->delete();
    }

    /**
     * Google Calendar API payload for one desired entry (all-day event).
     */
    public function payloadFor(Project $project, array $entry): array
    {
        $project->loadMissing('contractor');
        $date = Carbon::parse($entry['date'])->toDateString();
        $nextDay = Carbon::parse($date)->addDay()->toDateString();
        $contractor = $project->contractor->company_name ?? $project->contractor->contractor_name ?? '—';

        $prefix = match ($entry['kind']) {
            self::EVENT_START => '▶ Starts',
            self::EVENT_DUE => '⏰ Due',
            default => '🧪 Inspection',
        };

        $detailUrl = rtrim((string) config('app.url'), '/') . '/admin/projects/' . $project->id;

        $descriptionLines = [
            ($project->code ? "[{$project->code}] " : '') . ($project->title ?? 'Project'),
            $entry['label'] ?? '',
            'Contractor: ' . $contractor,
            $project->location ? ('Location: ' . $project->location) : null,
            'Status: ' . ($project->status ?? '—'),
            '',
            'Open in PMU: ' . $detailUrl,
            'Synced automatically by the PMU system.',
        ];

        $reminders = match ($entry['kind']) {
            self::EVENT_DUE => [
                ['method' => 'popup', 'minutes' => 7 * 24 * 60],
                ['method' => 'popup', 'minutes' => 24 * 60],
            ],
            'inspection' => [
                ['method' => 'popup', 'minutes' => 24 * 60],
            ],
            default => [],
        };

        return [
            'summary' => "{$prefix}: {$project->title} ({$project->code})",
            'description' => implode("\n", array_filter($descriptionLines, fn ($l) => $l !== null)),
            'location' => (string) ($project->location ?? ''),
            'start' => ['date' => $date, 'timeZone' => self::TIMEZONE],
            'end' => ['date' => $nextDay, 'timeZone' => self::TIMEZONE],
            'reminders' => ['useDefault' => false, 'overrides' => $reminders],
            'extendedProperties' => ['private' => [
                'pmu_project_id' => (string) $project->id,
                'pmu_event_key' => (string) $entry['key'],
            ]],
        ];
    }

    // ── NO-OAUTH FALLBACKS (work for every user, no consent needed) ──

    /**
     * One-click "Add to Google Calendar" template URL for an all-day date.
     */
    public static function googleTemplateUrl(string $title, string $dateYmd, string $details = '', string $location = ''): string
    {
        $start = Carbon::parse($dateYmd)->format('Ymd');
        $end = Carbon::parse($dateYmd)->addDay()->format('Ymd');

        return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
            . '&text=' . rawurlencode($title)
            . '&dates=' . $start . '/' . $end
            . ($details !== '' ? '&details=' . rawurlencode($details) : '')
            . ($location !== '' ? '&location=' . rawurlencode($location) : '');
    }

    /**
     * Downloadable .ics feed for a project (start + due + inspections).
     */
    public function icsForProject(Project $project): string
    {
        $project->loadMissing(['baselineSchedules', 'contractor']);
        $contractor = $project->contractor->company_name ?? '';
        $detailUrl = rtrim((string) config('app.url'), '/') . '/admin/projects/' . $project->id;

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//CSPC PMU//Project Monitoring//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($this->desiredEvents($project) as $entry) {
            $uid = "pmu-{$project->id}-" . str_replace(':', '-', $entry['key']) . '@cspc-pmu';
            $summary = match ($entry['kind']) {
                self::EVENT_START => "Starts: {$project->title} ({$project->code})",
                self::EVENT_DUE => "Due: {$project->title} ({$project->code})",
                default => "Inspection ({$entry['label']}): {$project->title} ({$project->code})",
            };
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART;VALUE=DATE:' . Carbon::parse($entry['date'])->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:' . Carbon::parse($entry['date'])->addDay()->format('Ymd');
            $lines[] = 'SUMMARY:' . self::icsEscape($summary);
            $lines[] = 'DESCRIPTION:' . self::icsEscape("Contractor: {$contractor}\nStatus: {$project->status}\nOpen: {$detailUrl}");
            if (! empty($project->location)) {
                $lines[] = 'LOCATION:' . self::icsEscape((string) $project->location);
            }
            $lines[] = 'URL:' . $detailUrl;
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    public static function icsEscape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $value);
    }

    /**
     * Template links for one project (used by the dashboard modal + detail).
     */
    public function templateLinksFor(Project $project): array
    {
        $project->loadMissing('contractor');
        $links = [];

        foreach ($this->desiredEvents($project) as $entry) {
            $title = match ($entry['kind']) {
                self::EVENT_START => "Starts: {$project->title} ({$project->code})",
                self::EVENT_DUE => "Due: {$project->title} ({$project->code})",
                default => "Inspection ({$entry['label']}): {$project->title} ({$project->code})",
            };
            $links[$entry['key']] = self::googleTemplateUrl(
                $title,
                $entry['date'],
                "[{$project->code}] {$project->title} — {$entry['label']}",
                (string) ($project->location ?? '')
            );
        }

        return $links;
    }
}
