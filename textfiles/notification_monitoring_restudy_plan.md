# NOTIFICATIONS (IN-APP + EMAIL) AND PROJECT-MONITORING SIGNALS — RESTUDY
# Parent refs: textfiles/bugs_and_behavior_adjustments.md (notification history),
#              AGENTS.md → System Modules 4 (Automated Notification and Reminder)
# Date: September 21, 2026
# Status: ✅ BUILT + BROWSER-CONFIRMED 2026-09-21 (§7), merged into develop. Findings §3,
#         proposals §4, decisions §5.
# Branch: phase-42-notification-monitoring-restudy (from develop be09bf7).

===============================================================================
  0. THE ASK (user, 2026-09-21)
===============================================================================

  "Restudy the notification feature (both in-app and Gmail); if possible do all the
  possible tests of them; look for anything missed / not implemented or other
  possible improvements, especially for the in-app. Also include the non-notification
  elements that are essential for monitoring the projects."

===============================================================================
  1. HOW IT WORKS (traced in source 2026-09-21)
===============================================================================

  TYPES (app/Notifications) — all 8 send to BOTH channels ['database', 'mail'];
  NONE implements ShouldQueue, so every email goes out INLINE over Gmail SMTP.
    Scheduled (notifications:scan, daily 07:00, SendNotifications.php):
      DeadlineReminder (30/14/7 days + overdue; admins, assigned staff, contractor)
      PlannedInspectionReminder, MonthEndInspectionReminder, OverdueInspectionReminder
        (admins + assigned staff)
      RiskLevelChanged (admins, staff, contractor)
    Event-driven:
      ExtensionStatusChanged (submitted → admins + staff; approved/rejected →
        requester, contractor, staff, admins) — ProjectExtensionController
      IssueReported (admins, staff, contractor) — IssueController / RiskController
      RiskLevelChanged also on progress record/edit/delete — ProjectProgressController
      RiskReported — RiskController (register now hidden, phase-40)
  DELIVERY (NotificationService): skips inactive users; dedupe by type + data.key;
    a mail failure is logged and the database notice is kept; one admin ActivityLog
    line per event.
  IN-APP UI: bell (5 newest UNREAD, rendered at page load), inbox /notifications
    (paginated 20, row → project tab link, Mark read, Mark all read).
  AI MONTHLY REPORT EMAIL (separate from the 8): reports:generate-ai-monthly (25th
    08:00) stages → 5-day review window (Email "In review") → reports:send-ai-monthly
    (daily 08:05) sends due rows; AiAnalysisMail is also sent INLINE; open tracking
    by an image at /track/{token}.
  QUEUE (QUEUE_CONNECTION=database): used only by SyncProjectCalendar (calendar sync).
  SETTINGS read by the scan: notification_deadline_days (default "30,14,7"),
    notification_overdue_grace_days (default 7) — no UI, no stored rows.

===============================================================================
  2. WHAT WAS TESTED (2026-09-21)
===============================================================================

  · Automated: every notification / email / reminder / tracking / extension-notice
    test — 98 passed, 0 failed (NotificationTest, NotificationScanTest,
    NotificationServiceTest, NotificationMailTest, NotificationUiTest,
    NotificationActivityLogTest, DevNotificationScanTest, EmailDraftTest, …).
    Full suites on develop after the phase-41 merge: PHP 1311 / 0, JS 279 / 0.
  · DRY RUN of today's notifications:scan on the LIVE database — inside a DB
    transaction that was ROLLED BACK, with Notification::fake(): nothing saved, no
    mail, no in-app notice. It WOULD send today: 45 OverdueInspectionReminder (30 to
    admins, 15 to staff) + 8 RiskLevelChanged — i.e. 53 in-app notices AND 53 Gmail
    emails; 33 of them for ONE project (#165: 11 uninspected months × 3 people).
  · Live data read (no writes): 342 notifications, ALL unread; 15 AI report emails
    "sent", 0 opened; 30 jobs waiting in the queue; no "Notification delivery
    failed" lines in laravel.log.
  · NOT done on purpose: no real email was sent and no real scan was run (outward /
    writes to the shared DB). A live email test is a user call — see §5 N-D7.

===============================================================================
  3. FINDINGS (ranked)
===============================================================================

  PILOT-CRITICAL
  F-1 ALERT FLOOD — OverdueInspectionReminder is one notice PER UNINSPECTED MONTH
      PER PERSON. A project with a backlog floods the bell and the mailbox (33 for
      #165 in one run). Dedupe stops repeats of the SAME month only. Consumer Gmail
      also caps sending (~500 recipients/day), and the scan sends each mail inline.
  F-2 EVERY EMAIL LINK IS BROKEN — APP_URL=http://127.0.0.1:8000. "View project"
      buttons in all 8 notification emails and the AI report's tracking image point
      at the RECIPIENT's own computer. Explains "15 sent, 0 opened". (For the LAN
      pilot APP_URL must be the server PC's LAN address; contractors outside the LAN
      still cannot open links, so emails must make sense without them.)
  F-3 (DOWNGRADED 2026-09-21 — see §6) STALE QUEUE JOBS — 30 jobs wait in `jobs`: 26 × App\Jobs\SendNotificationEmailJob
      (class no longer exists → they fail the moment a worker starts) and 4 old queued
      reminders dated Sep–Nov 2026 (simulated dates) that WOULD be emailed. `composer
      dev` starts a worker, so the first pilot run triggers them.
  F-4 AGENTS.md is wrong on the queue: "a queue worker must be running for AI report
      emails / notifications" — they are sent inline; the worker is only needed for
      the calendar sync (and the stale jobs above).

  IN-APP GAPS
  F-5 The bell never refreshes — count and list are drawn at page load; the
      /notifications/recent endpoint exists but nothing polls it.
  F-6 Inbox has no filters (unread only / by type / by project), no delete / clear,
      no "mark read on open" from the bell list beyond clicking; with 342 unread the
      badge is noise.
  F-7 Every admin receives every notification of every project (2 admins today; grows
      with users). No per-user email opt-out per type; no settings UI for the
      reminder days / grace days (F-12).

  EVENTS THAT DO NOT NOTIFY ANYONE TODAY
  F-8  Warranty: nothing monitors warranties at all — no reminder, alert or dashboard
       item for the warranty end date or the mid-year / final warranty inspections
       (live: 2 Active warranties, no inspection dates set).
  F-9  A "Planned" project whose start date has passed — the scan skips Planned
       projects entirely (live: all 6 Planned projects are past their start date).
  F-10 A pending extension waiting for review — admins get ONE notice at submission,
       never a follow-up (live: oldest pending since 2026-08-08, ~6 weeks).
  F-11 Others with no notice: an issue open > 30 days (shown on the project page only),
       an issue resolved / status changed, an AI report entering the 5-day review
       window (staff must remember to open Email → In review), a project assigned to
       a staff member or contractor, the original baseline edited (revised S-curve).
  F-12 Settings: notification_deadline_days / notification_overdue_grace_days have
       defaults in code but no admin screen.

  NON-NOTIFICATION MONITORING (already present, for reference)
    Dashboard: stat cards, health badges (ProjectHealthService), overdue-inspection
    and pending-extension widgets, timeline + calendar; project page alerts
    (next inspection due, open/aging issues, pending extensions, no documents);
    Progress tab "Risk Flags & Alerts"; AI prediction tab; activity log; Google
    Calendar sync. Missing: warranties (F-8) and not-yet-started projects (F-9).

===============================================================================
  4. PROPOSALS
===============================================================================

  P-1 (F-1) ONE overdue-inspection notice per PROJECT listing its missing months
      (dedupe key = project + latest missing month, so a new missing month re-alerts
      once); same idea for the email. Removes the backlog flood.
  P-2 (F-2) Pilot config, not code: APP_URL = the server PC's LAN address; add a
      pilot checklist line. Email text already names the project/months, so it
      stays useful where the link cannot open.
  P-3 (F-3) Before the pilot: clear the stale queue (php artisan queue:clear, or
      delete the 30 rows) — a one-off, needs the user's go.
  P-4 (F-4) Fix the AGENTS.md queue note.
  P-5 (F-5) Bell auto-refresh: poll /notifications/recent every 60 s and when the
      tab regains focus; update the badge and list.
  P-6 (F-6) Inbox: tabs All / Unread, filter by type and by project, "clear read"
      (delete read notices), relative times.
  P-7 (F-8) Warranty monitoring: scan reminders (end date in 30/14/7 days; mid-year and
      final warranty inspection due) + a dashboard "Warranties due" widget.
  P-8 (F-9) "Not yet started" reminder + dashboard item for Planned projects past
      their start date.
  P-9 (F-10) Pending-extension reminder to admins after N days (e.g. 7).
  P-10 (F-11) Pick from: aging issue reminder, issue resolved notice, AI report
      review-window notice to staff, assignment notice, revised-baseline notice.
  P-11 (F-7/F-12) Admin settings screen for reminder days / grace days; per-user
      email opt-out per notification type (in-app always on).
  P-12 (optional) Queue the MAIL channel only (in-app stays instant) so pages and the
      scan never wait on Gmail — makes the queue worker mandatory on the server PC.

===============================================================================
  5. DECISIONS FOR THE USER
===============================================================================

  N-D1 Which proposals to build, and in what order? (recommended first: P-1, P-3,
       P-4, P-5, then P-7 / P-8 / P-9)
  N-D2 P-1 shape: one notice per project with all missing months (recommended), or a
       single daily digest per person covering all projects?
  N-D3 P-12: queue the email channel (worker becomes required) or keep inline?
  N-D4 P-10: which of the extra events should notify, and whom?
  N-D5 P-11: build the settings screen / per-user email opt-out now or later?
  N-D6 P-3: clear the 30 stale queue jobs now?
  N-D7 A live Gmail test (sends real emails from projectmanagementunit57@gmail.com to
       a test address you name) — yes / no?

===============================================================================
  6. SESSION LOG
===============================================================================

  2026-09-21 — phase-41 merged into develop (be09bf7; PHP 1311 / 0, JS 279 / 0).
    Restudy done: code traced, 98 notification/email tests run, today's scan dry-run
    on live data (rolled back, faked), live stats read. No code changed.
  2026-09-21 — USER ANSWERS + FOLLOW-UP CHECKS
    · N-D1 / N-D2: P-1 YES — one overdue-inspection notice PER PROJECT listing all its
      missing months (applies when a project has more than one uninspected month).
    · N-D4 (user):
        – Warranty: show the due / expiring state ON THE WARRANTY RECORD (preview)
          page, plus at least an IN-APP notice to admins + assigned staff.
        – Planned project past its start date: same — in-app notice to admins +
          assigned staff (+ an indicator where it is seen).
        – Pending extension: leave the submit notice as is (it may be in process at
          another office); add a reminder only for an extension pending ONE MONTH+.
        – The other "silent" events (F-11): IN-APP ONLY, recipients admins + assigned
          staff.
    · N-D3 / N-D5 / N-D6: user asked for explanation first (answered in the session).
    · N-D6 FACT CHECK: the 30 jobs are TESTING RESIDUE — queued on a queue named
      "emails" by an earlier, never-committed version of the notification code (no
      commit in this repo ever had App\Jobs\SendNotificationEmailJob or queued
      notifications); created at simulated dates (2026-09-15 / 09-25 00:00, project
      142). `composer dev` runs `queue:listen` on the DEFAULT queue only, so they would
      NOT fire on the pilot — F-3 is downgraded from pilot-critical to housekeeping.
      Also stale: the SendNotifications.php docblock (line ~32) still says emails go
      through the 'emails' queue worker.
    · N-D7 LIVE GMAIL TEST (user-approved, to neinocencio@my.cspc.edu.ph): one real
      email of each kind, MAIL CHANNEL ONLY (unsaved User / EmailTracking — nothing
      written to the DB): DeadlineReminder 3555 ms, OverdueInspectionReminder 1635,
      PlannedInspectionReminder 1729, MonthEndInspectionReminder 1464, RiskLevelChanged
      1491, ExtensionStatusChanged 5053, IssueReported 1830, RiskReported 1578,
      AiAnalysisMail ("[TEST]" subject) 1640 — all 9 SENT. ⇒ inline sending costs
      ~1.5-5 s PER RECIPIENT inside the request (e.g. reporting an issue to 4 people ≈
      6-10 s). The user should check the inbox: layout, and the links / tracking
      image pointing at 127.0.0.1 (F-2).
  2026-09-21 — USER: the 9 test emails arrived ("[TEST]" / "Dear Notification Test") —
    ✅ N-D7 CONFIRMED. Decisions: bell auto-refresh YES (P-5), inbox filters YES
    (P-6), APP_URL waits for the pilot set-up (server PC address unknown — pilot
    checklist item), fix the AGENTS note YES (P-4), clear the stale queue YES (P-3).
    N-D3 (queue the emails?) — user asked first whether queueing would bring back an
    old issue.
  2026-09-21 — THE OLD ISSUE (bugs_and_behavior_adjustments.md Bug 7 + Bug 8, Aug 28-29):
    RiskLevelChanged emails delayed / missing; fixed by adding the missing
    `use Queueable` import + trait. Traced in vendor/laravel/.../NotificationSender:
    send() queues ONLY when the class implements ShouldQueue, and queueNotification()
    then reads $notification->connection / queue / delay — properties the Queueable
    trait provides. Bug 7's attempts had made every class ShouldQueue (Phase 1) and
    then used an 'emails' queue + SendNotificationEmailJob (Phase 2). While queued,
    RiskLevelChanged (the only class without the trait) broke; after the queued code
    was reverted the trait no longer mattered (non-queued notifications are sent
    with sendNow()). The 30 stale jobs were that experiment's leftovers.
    ⇒ Queueing again would NOT bring the bug back if: (1) every class keeps
    `use Queueable` (all 8 do today; a test can enforce it for new classes),
    (2) ONLY the mail channel is queued — viaConnections(['database' => 'sync',
    'mail' => 'database']) — so the in-app notice stays instant and dedupe keeps
    working (Phase 1 had queued the database channel too), (3) jobs go to the
    DEFAULT queue, which composer dev's `queue:listen` processes (Phase 2 used
    'emails', which no worker listened to). Remaining trade-off: the worker must run
    on the server PC, or emails wait (in-app is unaffected); failures land in
    failed_jobs.
  2026-09-21 — P-3 DONE: `php artisan queue:clear database --queue=emails --force` →
    "Cleared 30 jobs from the [emails] queue"; jobs table empty afterwards (the
    default queue had none).
  2026-09-21 — P-4 DONE: AGENTS.md dev-stack note corrected (notifications + AI report
    emails are inline; the worker is for the calendar sync; how to queue safely);
    SendNotifications.php docblock no longer claims ShouldQueue / an 'emails' worker.
  2026-09-21 — USER: "yes for email queue, go ahead" (N-D3 = QUEUE THE EMAIL; build the
    queued list). The previous session ran out of tokens mid-build; this session
    picked it up from the uncommitted tree. State found: P-1, the QueuesMailOnly
    trait on all 8 classes, the 5 in-app classes, WarrantyMonitor and the 4 scan
    checks were written and green; nothing rendered them, nothing sent the event
    notices, there were no tests for them and no log. Build log: §7.

===============================================================================
  7. BUILD LOG — phase-42 (2026-09-21) — 🔄 BUILT, SUITES GREEN, BROWSER CHECK OWED
===============================================================================

  N-D3 EMAIL QUEUE ✅ (the three conditions of the Bug 7/8 entry in §6)
    · app/Notifications/Concerns/QueuesMailOnly.php — viaConnections():
      database → 'sync', mail → config('queue.default'); $tries = 3,
      $backoff = [60, 300]. All 8 classes `implements ShouldQueue` +
      `use Queueable, QueuesMailOnly`.
    · tests/Feature/NotificationQueueContractTest.php — every class in
      app/Notifications (auto-discovered) must implement ShouldQueue and use
      Queueable + QueuesMailOnly. With a REAL database queue: the in-app row
      exists before any worker runs, exactly ONE job on queue 'default' carrying
      channels ['mail'] with tries 3; dedupe still stops a 2nd notice AND a 2nd
      email; in-app-only notices queue nothing.
    · ⚠ CONSEQUENCE: the queue worker is now REQUIRED for notification emails
      (composer dev runs it; the pilot server PC must too). AiAnalysisMail (the AI
      monthly report) is not a notification and is still sent inline — unchanged.

  P-1 ONE OVERDUE-INSPECTION NOTICE PER PROJECT ✅
    · OverdueInspectionReminder carries `months` (all uninspected, oldest first) +
      `month_count`, and is identified by the LATEST month (key
      overdue_inspection:{project}:{latest}). Same months → no repeat; a newly
      overdue month → one new notice with the full list. Old single-month rows
      still render.
    · LIVE DRY RUN (rolled back + Notification::fake; 342 rows / 0 jobs before
      and after): overdue-inspection notices today 45 → 12; project #165 33 → 3.

  P-7..P-10 MONITORING NOTICES — IN-APP ONLY, admins + ASSIGNED staff ✅
    · Base app/Notifications/InAppNotice.php (via ['database']). Audience helper
      NotificationService::internalRecipientsFor($project, $except) — moved there
      from the scan so the controllers share it; $except = the person who acted.
    · Scan (SendNotifications, each check in its own try):
        WarrantyReminder — App\Services\WarrantyMonitor (ONE rule set for the
          page and the scan): end date ≤ 30 days → 'expiring', past → 'expired',
          mid-year / final inspection ≤ 14 days → 'due'. A passed inspection date
          sends nothing (it may be the date it was done). Key
          warranty:{id}:{kind}:{date}.
        ProjectNotStartedReminder — status Planned, not archived, planned start
          < today. Key not_started:{project}:{start} (moving the start re-alerts).
        PendingExtensionReminder — pending ≥ 30 days; the key carries the month
          count, so it reminds at 30, 60, 90… days. The submit-time notice is
          unchanged.
        IssueAgingReminder — Open / In Progress ≥ 30 days, project not archived and
          not Completed. Key issue_aging:{issue} (once).
      LIVE DRY RUN: Not started 14 (6 Planned projects, all past their start);
      Warranty 0 (both end in 2027, no inspection dates); Pending extension 0
      (#70 is on ARCHIVED project 7, #174 is 19 days old); Issue aging 0 (the only
      30-day issue, #12, is on archived project 7). All four zeros are explained.
    · Events (ProjectUpdateNotice, key update:{kind}:{ref}):
        issue_resolved   — IssueController::update, only on the transition INTO
                           Resolved/Closed; ref carries resolved_at, so reopen +
                           re-resolve notifies again.
        ai_report_review — AIReportSendingService::stageProjectForReview; says the
                           date it goes out; links to the Email page.
        project_assigned — ProjectController store()/update() →
                           notifyAssignmentChanges(): the staff and/or contractor
                           id changed; the NEW staff is among the recipients.
        baseline_revised — BaselineScheduleController::saveEditedOriginalBaseline,
                           after COMMIT; not on a "No changes detected" save.

  INDICATORS ✅
    · Warranty record page (warranty/preview.blade.php — admin, staff AND the
      public /warranty/preview): pill under the warranty period and each inspection
      date — expired (red), expiring / due (amber), "Date passed" (grey).
      Partial: warranty/partials/watch_pill.blade.php.
    · Project page "Notifications & Alerts" (ProjectOverviewService::getAlerts):
      not yet started, warranty expiring / due; the pending-extension line now
      names an oldest request waiting ≥ 30 days.
    · Projects list (projects/index.blade.php): "Not yet started" badge.

  P-5 BELL AUTO-REFRESH ✅
    · public/js/notifications/notification-bell.js (moved out of the partial,
      filemtime-versioned): polls /notifications/recent every 60 s while the tab
      is visible, and on focus (≥ 10 s apart). The badge and "Mark all read" stay
      in the DOM (hidden) so they can reappear. While the menu is OPEN only the
      badge updates (the list swaps on close). Item clicks are delegated.

  P-6 INBOX ✅
    · All / Unread tabs, type filter (?type=slug), project filter (?project=id).
      The dropdowns list only what this user has; plain GET links; pagination
      keeps the filters.
    · "Clear read (N)": POST {role}/notifications/clear-read → sets cleared_at.
      ⚠ DECISION: cleared notices are HIDDEN, NOT DELETED. Dedupe looks for the
      existing row, so deleting a read overdue / warranty / not-started notice
      would make the next daily scan send it again. Migration
      2026_09_21_100001_add_cleared_at_to_notifications_table — RUN on pmu_system4
      (it was the only pending migration; rollback: migrate:rollback --step=1).

  ONE PRESENTER ✅
    · app/Services/NotificationPresenter.php — label (bell), title + detail lines
      (inbox), icon, tone, link and filter slugs for all 13 types. The bell
      partial, the inbox list and NotificationController::recent() all use it
      (they carried three separate match() blocks). The wording of the original 8
      types is unchanged EXCEPT: RiskReported in the bell now says "High risk
      reported" (the bell's default arm had labelled it "Issue reported").
    · NotificationService::describeNotification() — Activity Log lines for the 5
      new types + the multi-month overdue notice.

  TESTS
    · New: NotificationQueueContractTest (4), NotificationMonitoringTest (16),
      NotificationUiTest +7, AdminOriginalBaselineEditTest +1; NotificationScanTest
      P-1 tests (2, rewritten).
    · Test traps found: NotificationUiTest::createNotification() returns
      latest('id'), but notification ids are UUIDs, so that is a RANDOM row — the
      new tests select by data instead. A User::create() without 'is_active' is
      skipped by notifyUnique() (the attribute is not loaded).
    · Suites: PHP 1340 passed / 0 failed; JS 279 / 279 / 0. The new classes ran
      green 6 times in a row.

  ⬜ BROWSER CHECKS OWED (DOM, not covered by the suite)
    1. Bell: leave a page open, create an issue as another user → badge and list
       update within 60 s (or on switching back to the tab); opening the menu
       mid-refresh does not jump.
    2. Inbox: tabs, type + project dropdowns, Reset filters, Clear read (confirm
       dialog; unread stay).
    3. Warranty record page pills (set a warranty end date ~2 weeks out).
    4. Project page alerts + the projects-list "Not yet started" badge on a
       Planned project past its start.
    5. Email queue: with composer dev running, report an issue → the page returns
       without the 6-10 s wait; the email arrives once the worker picks it up.
       Stop the worker → the in-app notice is still immediate, the email waits in
       `jobs`.
    6. Dev widget "Run Notification Scan" → the Monitoring line in its output.

  FILES
    new:     app/Notifications/Concerns/QueuesMailOnly.php, InAppNotice.php,
             WarrantyReminder.php, ProjectNotStartedReminder.php,
             PendingExtensionReminder.php, IssueAgingReminder.php,
             ProjectUpdateNotice.php; app/Services/WarrantyMonitor.php,
             NotificationPresenter.php; public/js/notifications/notification-bell.js;
             resources/views/warranty/partials/watch_pill.blade.php; the migration;
             tests NotificationQueueContractTest, NotificationMonitoringTest.
    changed: the 8 notification classes, SendNotifications, NotificationService,
             NotificationController, routes/web.php (3 clear-read routes),
             notifications/partials/bell + _list, warranty/preview, projects/index,
             ProjectOverviewService, IssueController, ProjectController,
             BaselineScheduleController, AIReportSendingService; tests
             NotificationScanTest, NotificationUiTest, AdminOriginalBaselineEditTest.

  2026-09-21 — USER BROWSER RESULTS: #2 inbox ✅, #3 warranty pills ✅, #5 email
    queue ✅ CONFIRMED. #1, #4, #6 explained (how to observe them), awaiting results.
    DEFECT FOUND (#1 screenshot): the dashboard banner (search box, New Project /
    All Projects buttons) was drawn OVER the open bell menu. Cause (pre-existing,
    all 3 layouts): `.topbar > * { position: relative; z-index: 2 }` and the
    `.topbar` itself has no z-index, so the bell's z-index 2 competes at page level
    with `.db-hero { z-index: 30 }` (dashboard.css) and loses. The taller list of
    long "Not yet started" titles made it visible. FIX (notification-bell.js):
    while the menu is open the whole `.topbar` gets inline z-index 1040 (above the
    banner / dashboard pop-ups, below Bootstrap modals 1050+); cleared on close.
    ⬜ browser re-check owed.
  2026-09-21 — USER: #1 bell auto-refresh ✅, #6 dev scan Monitoring line ✅, #4
    project-page alerts ✅ + warranty alert ✅ CONFIRMED. #4 projects-list badge NOT
    SEEN: the user was on the TABLE view; the badge had only been added to the CARD
    view. FIX: projects/index.blade.php table "Health" column — a Planned project
    past its start shows an amber "Not yet started" (table-health-badge at-risk,
    hover = planned start) INSTEAD of the grey "PLANNED" badge. +2 tests
    (NotificationMonitoringTest: badge shown past start / absent for a future
    start). ⬜ browser re-check owed.
  2026-09-21 — USER: bell-over-banner fix ✅ and table "Not yet started" badge ✅
    CONFIRMED — ALL SIX BROWSER CHECKS DONE. Ordered: commit, push, merge into
    develop.

  STILL OPEN (not asked for this round): P-11 settings screen / per-user email
    opt-out; APP_URL for the pilot (checklist); F-7 every admin gets every notice.
