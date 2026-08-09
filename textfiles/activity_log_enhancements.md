# ACTIVITY LOG — POSSIBLE FUTURE ENHANCEMENTS (NOT PLANNED)

CSPC PMU System
Created: Aug 9 2026 (during Phase 8 planning of `extension_management_plan.md`)
Status: ⬜ NOT STARTED — reference notes only. The current activity log is
        deliberately left mostly untouched (activity logging is not the main
        responsibility; the extension work just hooks into it).

---

## CURRENT DESIGN (for reference)

- Single durable `activity_logs` store: `user_id`, `project_id` (nullable),
  `action`, `description` (free text), `ip_address`, `visibility`
  (`['all','admin_only']` → Phase 8 adds `'staff'`), `created_at`.
- One event store feeds MANY surfaces (matches mainstream pattern — see §1):
  admin full log page (`admin/activity-logs`, filters date/action/user),
  admin/staff dashboard recent-activity feed (`forDashboard` scope), contractor
  dashboard feed (`visibleTo`), project Overview feed
  (`ProjectOverviewService::getRecentActivity`), and "Recent activity"
  sidebars on staff/contractor/email pages.
- Notifications live in a SEPARATE table (personal + dismissable) vs logs
  (durable) — already matches Modulos' "notification vs audit log" split.

---

## 1. RESEARCH SUMMARY — how other systems do it

| System | Model |
|---|---|
| Outworx | Full audit log is forensic/owner-only; a lightweight "Recent activity" card is broadly visible. Same store, two surfaces. |
| Sankofa | One store; Viewer/Editor see project-scoped entries; admins see global aggregate + CSV export + webhook. |
| Aptli | Field users get inline record timelines; the global `/audit` page + export are permission-gated. "Activity transparency is the point." |
| Procore | Internal vs External permission templates; Read Only for externals; activity-stream viewing is itself a granular permission. |
| Aprolio | Admin total / Staff manages projects / Client sees own projects only. |
| Xenett | Project/task activity visible to anyone with access; **Delete log + Transaction log are admin/own-only**. |
| Uku | Separate Activity log (feed) vs Audit log (field-level `old → new` diffs); non-admins see own changes by default. |
| Modulos | One event → both a notification (dismissable) and a durable log entry; logs visible to anyone with record access, admin actions admin-scoped. |

Takeaways:
- **Single durable store + multiple presentation surfaces** is the norm (no
  separate "feed table" needed).
- **Three-tier visibility** (admin all / internal most / external least) is
  the common shape — implemented in Phase 8 via a new `'staff'` visibility.
- Deletions, housekeeping, and sensitive decision rationale are the categories
  most consistently restricted to internal/admin roles.

---

## 2. POSSIBLE ENHANCEMENTS (each independent; pick as needed)

### E1. Field-level diffs (before → after)  [Uku, Sankofa, Outworx]
- Store `old`/`new` JSON (or `changes` JSONB) per entry so the log shows
  exactly what changed per field, e.g. `new_end_date: 2026-12-01 → 2027-02-01`.
- Today the log is free-text only. Cost: schema columns + a small diff helper
  used by each controller log site. Value: real forensic depth.

### E2. Per-record scoped log views  [Uku, Aptli, Sankofa]
- Add a nullable polymorphic `entity_type`/`entity_id` (or `extension_id`)
  column and render "Activity" panels scoped to a single record (extension,
  issue, risk, baseline month).
- We explicitly chose NOT to add this for extensions (free-text only,
  extension is not the focus). If the client later wants "history for
  extension #3", this is the prerequisite.

### E3. Export (CSV / PDF)  [Outworx, Sankofa, Aptli, Aprolio]
- Export the admin activity-log page (and/or per-project feed) to CSV with
  the current filter window; later PDF for reports.
- Already noted in `summary_of_next_work.txt` (Report Generation).

### E4. Configurable alerts for sensitive actions  [Aprolio, Outworx]
- Watch for sensitive actions (bulk delete, force-delete, permission changes)
  and notify admins in-app/email. Small: reuse `NotificationService`.

### E5. Procore-style permission-tied visibility
- Replace the single `visibility` enum with a permission matrix per role
  (None/Read Only/Standard/Admin per tool). Overkill for 3 roles — Phase 8's
  `all`/`staff`/`admin_only` tiers are the pragmatic subset.

### E6. Own-only default for non-admins  [Uku, Xenett]
- Staff see only their OWN log entries by default, with an admin-only filter
  to see everyone. Conflicts with the current transparency feeds — only
  relevant if the client wants stricter privacy.

### E7. Retention / pruning policy  [Outworx, Xenett]
- Delete-log rows auto-purged after 45 days (Xenett); configurable retention
  window (Outworx). Our `activity_logs` grows forever today. Add a scheduled
  prune if volume becomes an issue.

### E8. Separation of "feed" vs "audit" curation
- Keep the single store but make the feed curation explicit: e.g., a
  `feed_category` (operational / housekeeping / decision) instead of relying
  on `action LIKE 'Delete%'` string matching in `forDashboard()`. The
  `visibility` column already does the role part; this would clean up the
  dashboard filter heuristics.

---

## 3. RELATED / ALREADY PLANNED

- **Activity Log UI for Staff** — listed in `summary_of_next_work.txt`
  (staff have no dedicated log page; today they see dashboard + overview
  feeds). Kept out of Phase 8 scope per discussion (feeds-only for now).
- **Export/report generation** — `textfiles/report_generation_plan.md`.
- **Visibility semantics** — Phase 8 of `extension_management_plan.md`
  implements the `'staff'` tier for extension entries; the same pattern is
  reusable for other modules later.

---

END OF NOTES
