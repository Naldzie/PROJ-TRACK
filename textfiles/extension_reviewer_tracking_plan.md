# EXTENSION REVIEWER TRACKING PLAN — B1 (Current Reviewer) + B2 (History Table) Deferred

**Branch:** `phase-extension-reviewer-tracking` (off `feature/budget-savings` @ `61f86a0`)
**Status:** 🟡 PLAN ONLY — no implementation yet. Awaiting approval to build.
**Date:** 2026-09-02
**Author:** Muse Spark (plan mode)
**Related docs:** `textfiles/extension_notification_fix_plan.md:1`, `textfiles/active_reference/project_context_and_development_rules.txt:1`, `AGENTS.md:1`

---

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

System Name
  Construction Project Monitoring System (Capstone Project System) — PROJ-TRACK

Client
  CSPC Project Management Unit (PMU)

Stack
  Laravel 12 / PHP 8.2 / MySQL (pmu_system4) / SQLite :memory: for tests
  Service-oriented: Project → DatasetBuilder → RegressionService → PredictionService → PageService → Controller → View
  Auth: Google-only via laravel/socialite. Roles: admin / staff / contractor (contractor view-only, warranty+profile writes only)

Module
  #2 Milestone Tracking — monthly cumulative baseline + S-curve + extension-adjusted deadlines
  #4 Automated Notification — 7 types via notifications:scan @07:00 + event-driven, dedup keys
  #7 Regression-Based Predictive Analytics

Extension subsystem (current)
  Table: project_extensions (migrations 2026_03_28_100001, 2026_04_11_100000, 2026_06_24_075435, 2026_08_08_* etc.)
  Columns today: approved_by_user_id FK nullable, approved_at nullable, created_by_user_id FK nullable,
                 status enum('pending','approved','rejected','superseded'), rejection_reason text nullable,
                 extension_number int, original_end_date/new_end_date/duration_days, reason/reason_category,
                 approved_new_end_date, baseline_snapshot json, SoftDeletes
  Model: app/Models/ProjectExtension.php:15 — relations approver() (approved_by_user_id), requester()
  Controller: app/Http/Controllers/Admin/ProjectExtensionController.php:1 (1631 lines, shared by admin+staff)
  Notification: app/Notifications/ExtensionStatusChanged.php:1 — via ['database','mail'], dedup key extension:{pid}:{eid}:{status}
  Email view: resources/views/emails/notification.blade.php:1 — shared, renders lines[] table
  Table UI: resources/views/projects/project_detail/components/tabs/partials/extensions-section.blade.php:121 header Approved By
           public/js/admin/projects/baseline-schedule-extensions.js:202 cell status==='approved' ? approved_by_name : '—'
  Reports: app/Services/ReportExportService.php:142 Extensions sheet Approved By / Approved At
  PDF: resources/views/reports/project-summary.blade.php:220 no approver column
  Tests: 985 / 3474 (2 EmailDraftTest pre-existing fails excluded)

Development rules (from project_context_and_development_rules.txt:123)
  Rule 11 log after phase, Rule 12 branch phase-<n>-<desc>, no merge without confirmation.
  CSS/JS in public/css & public/js via asset(), not @vite (AGENTS.md:36).

===============================================================================
  2. STATUS SUMMARY
===============================================================================

Phase overview:

  ✅ COMPLETE  Extension lifecycle (store / update / resubmit / softDelete / archived / bulk ops / baseline snapshot)
  ✅ COMPLETE  Extension notification fix (935312b) — trigger exclusion, recipient fan-out
  ⬜ NOT STARTED  B1 — Reviewer attribution: reviewed_by_user_id + reviewed_at + actor in email (THIS PLAN)
  ⬜ NOT STARTED  B2 — Per-extension status history table (deferred, see §9 Flagged for Future)
  ⬜ NOT STARTED  UI: Reviewed By rename + rejected display + inbox actor + report columns
  ⬜ NOT STARTED  Tests: lifecycle + notification assertions for reviewer
  ⬜ NOT STARTED  Verification (manual + composer test) + docs

===============================================================================
  3. PROBLEM STATEMENT
===============================================================================

3.1 Email does not show who acted
-----------------
  ExtensionStatusChanged:24 constructor = projectId / projectTitle / extensionId / extensionNumber /
  status / rejectionReason / approvedEndDate / currentEndDate / daysExtended — NO actor param.
  ProjectExtensionController:1491 notifyExtension() builds notification without auth()->user()->name.
  toMail():42 lines = Project / Extension / Status / (Rejection reason OR New Deadline/Current End/Days Extended)
  + disclaimer — never "Approved by" / "Rejected by".
  toDatabase():87 same — no actor_name / actor_id.
  Result: approver/rejector email to requester+contractor+staff+admins says "Your extension was
  approved/rejected" with no attribution. User asked to add the user that approved/rejected it.

3.2 Rejection not recorded on row
-----------------
  DB has approved_by_user_id + approved_at only (2026_04_11_100000:34). No rejected_by / reviewed_by.
  grep rejected_by → 0 hits repo-wide.
  Controller nulls on reject:
    store():116 only sets approved_by when canAutoApprove && status==='approved'
    update() staff 374: approved_by_user_id=null, approved_at=null when rejected
    update() admin 486: same
    pending revert 492: same
  Model approver() → belongsTo User approved_by_user_id (ProjectExtension.php:59).
  JS baseline-schedule-extensions.js:202 forces "—" for any status!=='approved'.
  So rejected rows forever show "—" in Approved By, and a later flip approved→rejected→re-approved
  overwrites/loses prior approver. Audit survives only in ActivityLog (Approve/Reject/Revert Extension
  at ProjectExtensionController:386 / 538) — not queryable on project_extensions, not shown in table/email.

3.3 Flip / reversal loss (user's follow-up)
-----------------
  Scenario: Extension #3 approved by Alice 2 days ago, then PMU (admin or assigned staff) decides to
  revert/reject it. Current code overwrites/nulls approved_by. Prior approver lost on row. User asks:
  "should we show who approved it first then who rejected it after?" — implies need for lineage, not just
  current-state overwrite.

===============================================================================
  4. CHOSEN DIRECTION
===============================================================================

Decision: Option B with reviewed_by semantics, implemented in two stages.

  Stage B1 (this plan, immediate):
    Add reviewed_by_user_id (FK users.id SET NULL nullable) + reviewed_at (timestamp nullable) to
    project_extensions via migration. reviewed_* = CURRENT reviewer (who performed the latest
    decision that put the row into approved OR rejected). Keep approved_by_user_id / approved_at
    as SYNC COPY for one release for backward compat (both written on approve, both mirror reviewed_*
    on reject after this fix), then deprecate approved_* in cleanup. Pending / superseded keep reviewed_* = null.
    History beyond current state is preserved in existing ActivityLog (already writes Approve/Reject/Revert
    with user_id + description + visibility + ip). Email adds actor lines. Table header "Approved By"
    → "Reviewed By", JS shows reviewer for approved AND rejected.

  Stage B2 (deferred, see §9):
    Add dedicated append-only table project_extension_status_histories (extension_id, from_status,
    to_status, actor_user_id, rejection_reason, metadata json, created_at). One row per transition,
    never updated. Gives cheap per-extension timeline query vs regex-parsing ActivityLog.description.
    Can be added after B1 without breaking B1.

Rationale
  - B1 satisfies user's two asks with minimal migration (2 columns, no rename of live column) while
    keeping backward compat for reports/tests that still read approved_by.
  - Single reviewed_by column = current-state pattern used by GitHub/Jira/ERP (current attribution
    queryable without scan; lineage via append log). Separate approved_by+rejected_by columns fail on
    multiple flips and on superseded/pending.
  - B2 follows audit best practices (hyrelog.com 2025-03-10 Principle 4 immutable, Palmyra workflow_step
    pattern, Formaloo who/what/version/when/why) but is separable — B1 already makes the system
    attributable in email/table/reports; B2 just makes history cheaper to query/display.
  - reviewed_by naming covers both approved and rejected; matches user's preference "Reviewed By /
    Reviewed At might be good too".

Consequences
  -   Existing rejected rows will have reviewed_by = null until backfilled (optional one-off script).
  - approved_by becomes alias to reviewed_by; after B1 both columns contain same id on approved/rejected.
  - JS, reports, notifications must read reviewed_* (fall back to approved_* for old rows).
  - Reviewed At was never visible in table before (payload approved_at at formatExtension:1589 existed but not rendered via baseline-schedule-extensions.js); B1 makes it mandatory column + email line (user confirmed 2026-09-02: JS local short date+time + include "Reviewed on" in email for approved/rejected).

===============================================================================
  5. DECISION LOG
===============================================================================

Decision 1 — Columns for attribution
  Context: Need to record rejector as well as approver.
  Options: A reuse approved_by for both; B add reviewed_by; C add rejected_by.
  Decision: B — add reviewed_by_user_id + reviewed_at (generic). Keep approved_* as sync copy.
  Rationale: Correct naming, won't confuse raw DB, allows future B2. Matches user leaning.
  Consequences: One migration, dual writes for one release.

Decision 2 — Overwrite vs history on row
  Context: Approved then 2 days later rejected — should row keep first approver or show rejector?
  Options: (1) overwrite reviewed_by with latest actor; (2) keep separate approved/rejected cols;
           (3) per-transition history table.
  Decision: (1) overwrite reviewed_by = latest actor (current decision), lineage via ActivityLog now,
            via status_histories in B2 later. Never add approved+rejected dual cols.
  Rationale: SaaS approval pattern (current-state column + immutable log). Dual cols break on
             re-approve. History table is the right place for lineage, not the row.
  Consequences: Table/email show current reviewer only; modal timeline must query log/history for full lineage.

Decision 3 — Email content
  Context: Which actor line to add?
  Options: "Reviewed by X" generic vs "Approved by X" / "Rejected by X" specific.
  Decision: Specific line — "Approved by {actor}" when APPROVED, "Rejected by {actor}" when REJECTED.
            intro match also includes actor ("has been approved by {actor}") for readability.
  Rationale: Users scan email for decision; specific label is clearer than generic.
  Consequences: ExtensionStatusChanged needs actorName param + lines logic.

Decision 4 — Table header wording
  Context: Column currently "Approved By" (extensions-section.blade.php:121).
  Options: Keep "Approved By", change to "Reviewed By", "Action By", "Approved/Rejected By".
  Decision: "Reviewed By" + "Reviewed At" (user preference, §4). Specific via badge+status already conveys approve vs reject.
  Rationale: Short, covers both, consistent with DB column naming.
  Consequences: Update Blade + JS + ReportExportService headers.

Decision 5 — Defer B2 history table
  Context: Should we build status_histories table now?
  Decision: Defer to follow-up after B1 ships. Note design in §9 so next session can continue without re-planning.
  Rationale: B1 already fixes attribution gap; B2 is additive and can branch off B1.
  Consequences: B1 must not close the door — keep ActivityLog writes untouched and leave room for history table.

===============================================================================
  6. IMPLEMENTATION STEPS — B1 ONLY
===============================================================================

Legend:  ✅ COMPLETE  ⬜ NOT STARTED  🔄 IN PROGRESS  ⚠️ BLOCKED  ❌ CANCELLED

Step 0 — Branch + plan file                                           ⬜ NOT STARTED
  - Branch phase-extension-reviewer-tracking (this plan). No implementation.
  - This file is the plan artifact. Also note in bugs_and_behavior_adjustments.md after build.

Step 1 — Migration: add reviewed_by_user_id + reviewed_at            ⬜ NOT STARTED
  File: database/migrations/2026_09_02_000001_add_reviewed_by_to_project_extensions_table.php
  - Schema::table('project_extensions', fn): foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete()->after('approved_by_user_id'); index; timestamp('reviewed_at')->nullable()->after('approved_at');
  - Backfill in migration up(): DB::table('project_extensions')->where('status','approved')->whereNotNull('approved_by_user_id')->update([
        'reviewed_by_user_id' => DB::raw('approved_by_user_id'),
        'reviewed_at' => DB::raw('approved_at'),
    ]);
  - Down: dropForeign + dropColumn.
  - Note: existing rejected rows stay null (optional artisan one-off backfill script can parse ActivityLog later — out of B1 scope).

Step 2 — Model: ProjectExtension.php:15                              ⬜ NOT STARTED
  - Fillable add: reviewed_by_user_id, reviewed_at
  - Casts add: reviewed_at => datetime
  - Relations:
      public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id'); }
      // BC alias — keep approver() pointing to reviewer() OR to approved_by for one release:
      // Decision: keep approver() as alias to reviewer() so old calls get new actor. Add approvedByLegacy() if needed.
      public function approver(): BelongsTo { return $this->reviewer(); }
      // Alternative safe BC: approver() still reads approved_by_user_id, reviewer() reads reviewed_by. Then formatExtension reads reviewer first fallback approver. We'll do fallback in formatter rather than alias.
  - Helpers: isReviewed() optional.
  - Keep REASON_CATEGORIES, scopes, daysAwaitingReview etc. unchanged.
  - Doc comment: "reviewed_by/at = actor of last approved/rejected transition; approved_by/at kept as sync copy for one release."

Step 3 — Controller: store / update / resubmit writes                 ⬜ NOT STARTED
  File: app/Http/Controllers/Admin/ProjectExtensionController.php:1
  3a. store():106 — change canAutoApprove logic already correct; add reviewed_* writes:
        'reviewed_by_user_id' => $canAutoApprove ? auth()->id() : null,
        'reviewed_at' => $canAutoApprove ? now() : null,
      keep approved_* writes as before (sync copy).
      Pending/Rejected at creation: for 'rejected' via store with status=rejected, also set reviewed_* = auth()->id()/now() (today only approved sets it; fix that).
  3b. update() staff review L368-377:
        before: if approved => set approved_by/approved_at, else => null
        after:  if approved => set BOTH approved_by+reviewed_by = auth()->id(), BOTH approved_at+reviewed_at = now(), rejection_reason=null
                else if rejected => set BOTH approved_by/reviewed_by = auth()->id()? No — Decision: set reviewed_* = auth()->id()/now(), keep approved_* ALSO = auth()->id()/now() as sync (or keep approved_* = null for rejected if you want to eventually drop it — but B1 says keep sync copy: set reviewed_* and also set approved_* = auth()->id()/now() for rejected? Better: for rejected, set reviewed_* = actor, and ALSO set approved_* = actor as sync so reports reading approved_by still show rejector. Document as deprecated.)
                else pending => clear both.
  3c. update() admin L477-495 same pattern:
        approved → set reviewed_by+approved_by = user->id, reviewed_at+approved_at = now(), rejection_reason=null
        rejected → set reviewed_by = user->id, reviewed_at = now(), rejection_reason = validated.rejection_reason, AND also set approved_by = user->id, approved_at = now() as sync copy (or leave approved_by null if you prefer strict — but then reports fail; choose sync copy for B1 BC)
        pending  → clear both approved_* and reviewed_*
  3d. resubmit():666 when creating new extension with status approved/rejected, set reviewed_* accordingly (copy from approved_* logic).
  3e. formatExtension():1568 return array add:
        'reviewed_by' => $ext->reviewer?->email ?? $ext->approver?->email ?? null,
        'reviewed_by_name' => $ext->reviewer?->name ?? $ext->approver?->name ?? null,
        'reviewed_at' => $ext->reviewed_at?->format('Y-m-d H:i:s') ?? $ext->approved_at?->format('Y-m-d H:i:s'),
        // keep approved_by / approved_by_name / approved_at for BC
  3f. Ensure isReverting / activeApproved checks still use status, not reviewed_*.

Step 4 — Notification: actor plumbing                                 ⬜ NOT STARTED
  File: app/Notifications/ExtensionStatusChanged.php:24
  - Constructor add: public ?string $actorName = null, public ?int $actorId = null, public ?string $reviewedAt = null (after daysExtended)
    // reviewedAt = Y-m-d H:i:s from reviewed_at / approved_at at decision time, passed so email can render "Reviewed on"
  - toMail():42:
        if ($this->status === self::APPROVED) $lines['Approved by'] = $this->actorName ?? 'System';
        if ($this->status === self::REJECTED) $lines['Rejected by'] = $this->actorName ?? 'System';
        // Reviewed timestamp — shown for both APPROVED and REJECTED (user confirmed 2026-09-02: include on email)
        if (in_array($this->status, [self::APPROVED, self::REJECTED], true) && $this->reviewedAt) {
            $lines['Reviewed on'] = \Carbon\Carbon::parse($this->reviewedAt)->format('M d, Y h:i A');
        }
        // update intro via match to include actor:
        self::APPROVED => $this->actorName ? "Your project extension request has been approved by {$this->actorName}." : "Your project extension request has been approved.",
        self::REJECTED => $this->actorName ? "Your project extension request was rejected by {$this->actorName}. See the reason below." : ...
  - toDatabase():87 add: 'actor_name' => $this->actorName, 'actor_id' => $this->actorId, 'reviewed_by_name' => $this->actorName, 'reviewed_at' => $this->reviewedAt
  File: app/Http/Controllers/Admin/ProjectExtensionController.php:1488 notifyExtension()
  - Fetch actor: $actor = auth()->user(); $actorName = $actor->name ?? $actor->email; $actorId = $actor->id;
  - Reviewed timestamp: $reviewedAt = $extension->reviewed_at?->format('Y-m-d H:i:s') ?? $extension->approved_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s');
  - Pass to new ExtensionStatusChanged(..., $daysExtended, $actorName, $actorId, $reviewedAt)
  - Keep dedup key extension:{pid}:{eid}:{status} (document that second reject of same extension is same status so dedup suppresses — notifyUnique dedupe:true for APPROVED/REJECTED at L1553; resubmit uses dedupe:false)

Step 5 — Service tweaks (optional but nice)                            ⬜ NOT STARTED
  File: app/Services/NotificationService.php:147 describeNotification()
  - Append actor if present in data['actor_name']: "Extension approved #N for "Title" by {actor}"
  - Keep logForAdmins behavior (already excludes trigger user via notifyMany unique).

Step 6 — Table + email View (Reviewed At MANDATORY per user 2026-09-02)   ⬜ NOT STARTED
  File: resources/views/projects/project_detail/components/tabs/partials/extensions-section.blade.php:121
  - <th>Approved By</th> → <th>Reviewed By</th> (mandatory rename)
  - Add <th>Reviewed At</th> AFTER Reviewed By, BEFORE Action — now 11 cols: Extension # / Requested By / From Date / To Date / Days Added / Category / Reason / Status / Reviewed By / Reviewed At / Action. Width ~90px, same 12px uppercase style. Confirmed: timestamp was never rendered before despite being in payload (formatExtension:1589 approved_at), so this is a new visible column.
  File: public/js/admin/projects/baseline-schedule-extensions.js:202
  - Reviewed By cell: `${(ext.status === 'approved' || ext.status === 'rejected') ? (ext.reviewed_by_name || ext.approved_by_name || ext.reviewed_by || ext.approved_by || '<span style="color:#cbd5e1;">—</span>') : '<span style="color:#cbd5e1;">—</span>'}`
  - NEW Reviewed At cell (mandatory, JS local short date+time per user 2026-09-02):
      const _rawAt = ext.reviewed_at || ext.approved_at || null;
      const reviewedAtLabel = (ext.status === 'approved' || ext.status === 'rejected') && _rawAt
          ? new Date(_rawAt).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) + ' ' + new Date(_rawAt).toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'})
          : '<span style="color:#cbd5e1;">—</span>';
      // Render in <td> with font-size:11px; color:#475569; white-space:nowrap; title=_rawAt (hover shows raw Y-m-d H:i:s)
  - Keep rejection_reason tooltip on status badge (already there at L142).
  File: resources/views/notifications/_list.blade.php:113
  - Show actor_name if data.actor_name exists: `<div class="text-xs text-slate-500">By {{ actor_name }}</div>` for extension notifications.
  - Keep rejection_reason display at L157.
  File: resources/views/emails/notification.blade.php:50
  - No edit needed if lines drives it — "Approved by"/"Rejected by" row auto-renders. Verify disclaimer still only for approved.

Step 7 — Reports (Reviewed At mandatory)                             ⬜ NOT STARTED
  File: app/Services/ReportExportService.php:142
  - Header 'Approved By' → 'Reviewed By', 'Approved At' → 'Reviewed At' (both mandatory now, not optional)
  - Value By: $extension->reviewer?->name ?? $extension->approver?->name ?? '—'  (fallback approved_by for old rows)
  - Value At: $extension->reviewed_at?->format('M d, Y h:i A') ?? $extension->approved_at?->format('M d, Y h:i A') ?? '—' — shown for approved+rejected, '—' for pending/superseded
  - Keep duration/status/reason columns unchanged.
  File: resources/views/reports/project-summary.blade.php:220
  - Optionally add Reviewed By column to Extension History PDF (if width allows) — at minimum ensure Excel fix covers client need; PDF can remain status/reason/duration for now to avoid layout break. Note in plan.

Step 8 — Factories + Seeders                                           ⬜ NOT STARTED
  File: database/factories/ProjectExtensionFactory.php:23
  - Add reviewed_by_user_id => null, reviewed_at => null in definition()
  - approved() state: also set reviewed_by_user_id = factory admin id, reviewed_at = now()
  - Add rejected() state: status rejected, reviewed_by_user_id = factory user id, reviewed_at = now(), rejection_reason = fake text, approved_by_user_id also = same (sync copy)
  - Keep status random pool pending/approved/rejected (superseded not random — fine).

Step 9 — Tests                                                         ⬜ NOT STARTED
  Update (don't break existing expectations):
  - tests/Feature/ProjectExtensionLifecycleTest.php:1213 assert approved_by_user_id === admin->id → also assert reviewed_by_user_id === admin->id, reviewed_at not null
  - Add cases: staff rejects pending → assert reviewed_by_user_id === staff.id, approved_by_user_id === staff.id (sync), rejected still queryable; admin flips approved→rejected → assert reviewed_by changes to rejector, old approver only in ActivityLog
  - tests/Feature/NotificationServiceTest.php / NotificationTest.php:249 — assert ExtensionStatusChanged mail contains "Approved by"/"Rejected by" and toDatabase actor_name
  - tests/Feature/ExtensionActivityLogTest.php — ensure ActivityLog still writes Approve/Revert/Reject and that B1 doesn't break visibility tiers (admin_only/staff/all at Controller:544)
  - Run composer test --filter=ProjectExtensionLifecycleTest + --filter=NotificationTest; full suite should stay 985/3474 green (minus 2 EmailDraftTest).

Step 10 — Verification (manual + automated)                            ⬜ NOT STARTED
  Manual checklist after implementation (use SimulateDate if needed, queue worker must be running):
  [ ] Admin creates approved extension → DB reviewed_by=admin, reviewed_at=now, email to assigned staff+other admins+contractor contains "Approved by Admin Name", table shows Reviewed By = Admin, Excel shows Reviewed By.
  [ ] Staff submits pending → admin approves → DB reviewed_by=admin, email to requester+contractor+assigned staff+other admins contains Approved by, pending no longer shows actor.
  [ ] Staff submits pending → admin rejects with reason → DB reviewed_by=admin, reviewed_at=now, rejection_reason retained, email contains "Rejected by Admin Name" + reason, table shows Reviewed By = Admin (not "—"), badge tooltip shows reason.
  [ ] Approved extension → 2 days later admin reverts to rejected (admin update status=rejected) → DB reviewed_by overwrites to rejector, approved_by also overwrites (sync), old approver only in ActivityLog feed (ProjectOverviewService:331); email for rejection goes to requester+contractor etc. with new actor.
  [ ] Resubmit rejected as new pending → new row reviewed_by=null, old rejected row retains its reviewer.
  [ ] composer test full suite — no new failures; grep -r "approved_by" to ensure no stale null-expectation.
  [ ] Browser: bell dropdown correct label (RiskLevelChanged already fixed), extension inbox shows "By {actor}" + reason.

Step 11 — Docs + logs                                                 ⬜ NOT STARTED
  - Append session log to this plan file + to textfiles/bugs_and_behavior_adjustments.md per AGENTS.md:79
  - Update textfiles/summary_of_next_work.txt if needed.
  - PR description must state B1 scope + B2 deferred.

===============================================================================
  7. FILES TOUCHED — B1 (8-10 files + 1 plan file)
===============================================================================

  New:
    database/migrations/2026_09_02_000001_add_reviewed_by_to_project_extensions_table.php
    textfiles/extension_reviewer_tracking_plan.md (this file)

  Modified:
    app/Models/ProjectExtension.php:22 fillable/casts/relations (reviewer + BC alias)
    app/Http/Controllers/Admin/ProjectExtensionController.php:106 store, 368 update staff, 477 update admin, 666 resubmit, 1488 notifyExtension, 1568 formatExtension
    app/Notifications/ExtensionStatusChanged.php:24 constructor, 42 toMail, 87 toDatabase
    app/Services/NotificationService.php:147 describeNotification (optional)
    resources/views/projects/project_detail/components/tabs/partials/extensions-section.blade.php:121 header
    public/js/admin/projects/baseline-schedule-extensions.js:202 Reviewed By cell + Reviewed At
    resources/views/notifications/_list.blade.php:113 actor line
    app/Services/ReportExportService.php:142 headers + values
    database/factories/ProjectExtensionFactory.php:23 definition + states

  Not modified in B1 (deferred to B2):
    resources/views/reports/project-summary.blade.php:220 (PDF layout change optional)
    app/Models/ActivityLog.php:1 (no change — already logs actor per action)
    routes/web.php:228 etc. (routes unchanged — PUT still drives status)

===============================================================================
  8. MIGRATION SPEC (B1)
===============================================================================

  -- Up
  ALTER TABLE project_extensions
    ADD COLUMN reviewed_by_user_id BIGINT UNSIGNED NULL AFTER approved_by_user_id,
    ADD COLUMN reviewed_at TIMESTAMP NULL AFTER approved_at,
    ADD CONSTRAINT fk_reviewed_by FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD INDEX idx_reviewed_by (reviewed_by_user_id);

  -- Backfill (in migration PHP, not raw SQL for test DB compat)
  DB::table('project_extensions')
    ->where('status','approved')
    ->whereNotNull('approved_by_user_id')
    ->update([
      'reviewed_by_user_id' => DB::raw('approved_by_user_id'),
      'reviewed_at' => DB::raw('approved_at'),
    ]);

  -- Optional one-off artisan command (outside migration, not run automatically):
  --  php artisan extensions:backfill-reviewed-by
  --  Iterates ActivityLog where action='Reject Extension' and tries to map description
  --  extension_number → project_id → latest rejected row with null reviewed_by; best-effort.
  --  Document as manual step, not part of migration, to avoid slow prod boot.

  -- Down
  DROP FOREIGN KEY fk_reviewed_by; DROP INDEX idx_reviewed_by; DROP COLUMN reviewed_by_user_id, reviewed_at;

  Risk: adding nullable FK + timestamp is online-safe (no table rewrite lock on MySQL 8 Instant DDL for nullable add). Backfill is WHERE status=approved only — small set. No downtime.

===============================================================================
  9. FLAGGED FOR FUTURE — B2 (Per-Extension Status History Table)
===============================================================================

  Goal: cheap per-extension lineage query without regex on ActivityLog.description.
  When to build: after B1 ships and client confirms they want timeline visibility for reversals.

  Table: project_extension_status_histories
    id bigIncrements PK
    project_extension_id FK -> project_extensions.id CASCADE
    project_id FK -> projects.id CASCADE (denorm for fast project-scoped queries)
    from_status enum('pending','approved','rejected','superseded') nullable (null for creation)
    to_status enum('pending','approved','rejected','superseded') not null
    actor_user_id FK -> users.id SET NULL nullable (who transitioned)
    rejection_reason text nullable (snapshot of reason at that transition)
    metadata json nullable (optional: daysExtended, new_end_date snapshot)
    created_at timestamp (use Laravel timestamps only created_at, no updated_at — immutable)
    Indexes: [project_extension_id, created_at], [project_id, created_at], [actor_user_id]

  Writes: in same DB::transaction as extension save in store() (creation), update() (both staff+admin paths),
          resubmit() (creation of new row). Insert one history row after $extension->save().

  Reads:
    - ProjectExtension model: hasMany statusHistories()->orderBy('created_at')
    - formatExtension() include history timeline for modal
    - Extension detail modal: vertical timeline "Alice approved Sep 1 10:00 → Bob rejected Sep 3 14:30 (Weather, 21 days, reason) → Carol approved Sep 5..."
    - Notification still shows current actor only; history is UI concern.

  Migration: 2026_09_xx_000002_create_project_extension_status_histories_table.php
  Model: app/Models/ProjectExtensionStatusHistory.php
  Keep ActivityLog writes unchanged — history table is domain-specific, ActivityLog stays global feed.

  Effort: ~1 migration + 1 model + ~30 lines controller + ~20 lines JS/Blade for timeline. No changes to
          notification deduplication or reports needed beyond B1.

===============================================================================
  10. RISKS & MITIGATIONS
===============================================================================

  R1 — Semantic duality approved_by vs reviewed_by during transition period
      Mitigation: document as sync copy, formatExtension fallback (reviewed_by ?? approved_by), report reads
      reviewed first. Mark approved_by as @deprecated in docblock, drop in cleanup branch after client confirms.

  R2 — Dedupe suppression ExtensionStatusChanged:90 key extension:{pid}:{eid}:{status}
      If same extension is rejected, fixed, rejected again (rejected→pending→rejected) second REJECTED is same
      status so notifyUnique dedupe:true at Controller:1553 would suppress the second email.
      Mitigation: B1 keeps current dedupe behavior (consistent with today). Document; if client hits it,
      B2 history key can include reviewed_at or actor to make each transition unique. SUBMITTED path already uses dedupe:false.

  R3 — Existing rejected rows have null reviewed_by until backfill
      Mitigation: Table shows "—" for old rejected rows until manual backfill or until next transition overwrites.
      Excel same. Add note in report disclaimer if needed.

  R4 — JS fallback for old data
      Mitigation: JS must check reviewed_by_name || approved_by_name || '—' and reviewed_at || approved_at so mixed
      DB (old approved rows have both, old rejected rows have neither) renders gracefully.

  R5 — No breaking of superseded scope
      Mitigation: reviewed_by is only for approved/rejected; superseded/pending keep null — same as today.

  R6 — Branch includes budget-savings commits (61f86a0 on feature/budget-savings)
      Mitigation: This plan branch off feature/budget-savings. Before merging to develop/main, rebase or cherry-pick
      only plan file + migration etc. onto develop to avoid mixing. Current step is plan-only, so no risk yet.

===============================================================================
  11. VERIFICATION CHECKLIST (executed after build, before PR)
===============================================================================

  Automated:
    composer test
    php artisan test --filter=ProjectExtensionLifecycleTest
    php artisan test --filter=NotificationTest
    php artisan test --filter=NotificationServiceTest
    php artisan test --filter=ExtensionActivityLogTest

  Manual (with queue worker + scheduler, simulate dates if needed):
    See Step 10 checklist above (8 scenarios).

  Audit:
    SELECT id, extension_number, status, reviewed_by_user_id, reviewed_at, approved_by_user_id, approved_at, rejection_reason
    FROM project_extensions WHERE project_id = ? ORDER BY extension_number;

===============================================================================
  12. SESSION LOGS
===============================================================================

  2026-09-02 — Plan session (Muse Spark)
    - Read AGENTS.md:1, project_context_and_development_rules.txt:1, extension_notification_fix_plan.md:1
    - Audited project_extensions schema across 12 migrations, ProjectExtension model, ProjectExtensionController store/update/resubmit/notifyExtension/formatExtension, ExtensionStatusChanged notification, notification.blade.php, extensions-section.blade.php, baseline-schedule-extensions.js, ReportExportService, factories, tests.
    - User asks: add actor to approved/rejected email; make approve column also record rejector.
    - Presented Option A (reuse) vs B (add reviewed_by) — user leaning B with Reviewed By / Reviewed At.
    - User follow-up: what happens on reversal (approved→rejected) — should we show both?
    - Researched audit patterns (hyrelog, Palmyra, Formaloo) — recommended current-state column + append log, deferred B2 history table.
    - User: "for now please make a new md file for the implementation plan. lets go with b1 first. but do note ... b2 after b1. make a new branch and commit and push this. no implementation yet"
    - Created branch phase-extension-reviewer-tracking, wrote this plan file, committed 2211103, pushed to origin.
  2026-09-02 — Plan amendment (build mode, Muse Spark)
    - User: "on the extension section can we also show the reviewed at (since currently the approval or rejection timestamp is not yet being shown despite having it if im not wrong)"
    - Verified: formatExtension:1589 payload has approved_at but baseline-schedule-extensions.js:202 never renders it; header extensions-section.blade.php:121 only "Approved By".
    - User confirmed 2026-09-02: Reviewed At mandatory via JS local short date+time (toLocaleDateString+toLocaleTimeString), and include "Reviewed on" in email for approved/rejected.
    - Amended plan: Step 4 adds reviewedAt param + "Reviewed on" line to ExtensionStatusChanged toMail + toDatabase; Step 6 makes Reviewed At mandatory 11th column after Reviewed By with JS local conversion + fallback approved_at; Step 7 makes Reviewed At mandatory in ReportExportService; updated §4 Consequences + §13 Q1 → resolved.

===============================================================================
  13. OPEN QUESTIONS / STILL TO RESOLVE (before build)
===============================================================================

  Q1 — ✅ RESOLVED 2026-09-02: Reviewed By / Reviewed At confirmed. Reviewed At is MANDATORY column in extension section (not optional) — JS local short date+time, "—" for pending/superseded. Email must also include "Reviewed on" line for approved/rejected.
  Q2 — Sync copy: should rejected set approved_by also (so old reports work) or leave approved_by null for rejected? Plan assumes sync copy (both = actor) for BC. Confirm preference.
  Q3 — Backfill: OK to leave old rejected rows as "—" until manual backfill, or want artisan backfill from ActivityLog in B1?
  Q4 — Confirm B1 scope excludes PDF column change (ReportExportService Excel only) — okay to defer PDF to B2?

  (Build will proceed with assumptions above unless user corrects before implementation.)

===============================================================================
  14. CONVERSATION CONTINUATION GUIDE
===============================================================================

  Next session should:
  1. Assume this plan file is the source of truth for B1; do not re-audit schema without need.
  2. Before suggesting code changes, read latest versions of ProjectExtension.php, ProjectExtensionController.php, ExtensionStatusChanged.php, baseline-schedule-extensions.js — they may have changed since plan was written.
  3. Do NOT redesign extension lifecycle or notification fan-out — build upon current store/update/notifyExtension logic.
  4. Continue from Step 1 migration in order (§6). Test after each step (Rule 8) before next.
  5. Log every phase completion to this file (§12) and to bugs_and_behavior_adjustments.md (Rule 11).
  6. For B2, branch off phase-extension-reviewer-tracking (not main) since B2 depends on B1's reviewed_* columns.

===============================================================================
  END OF PLAN
===============================================================================
