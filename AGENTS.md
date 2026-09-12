# AGENTS.md

PMU System (PROJ-TRACK): a Laravel 12 (PHP 8.2) project-monitoring app for CSPC PMU. Tracks projects, baseline schedules, progress, extensions, issues, warranties, AI predictions, ratings, and email reports. Auth is Google-only via `laravel/socialite` (no local registration).

## System Modules (passed title defense)

1. **Project Proposal Encoding and Management** (PMU-Controlled) — CRUD for projects (create, edit, archive, restore, delete). Fields: title, code, category, contractor, budget, start/end dates, scope, documentation. Proposal upload with auto-approval. Project code auto-generated (PRJ-YYYY-NNNNN).

2. **Milestone Tracking** (monthly cumulative progress — spec title not renamed) — Baseline schedules with monthly cumulative % targets. Activities are checklist items within each monthly target. S-curve chart with planned vs actual vs adjusted plan. Extension-adjusted deadlines. NOT individual weighted milestones — the original milestone design was replaced per client request.

3. **Contractor Viewing Access** (view-only) — Contractors see only their assigned projects. Read-only access to dashboard, project details, S-curves, documents, issues, risks, email reports, extensions. Write access limited to: warranty submissions and profile edit.

4. **Automated Notification and Reminder** — 7 notification types: deadline reminders (30/14/7 days + overdue), overdue inspection alerts, planned inspection reminders, month-end inspection reminders, extension status changes, issue reported, risk reported, risk level changed. Scheduled via `notifications:scan` (daily 07:00) + event-driven from controllers. Deduplication via tracking keys. Bell widget + full inbox UI.

5. **Document Archiving and Retrieval** — Upload, categorize (configurable document types), inline view, Excel-to-HTML preview, download, delete with physical file cleanup. Contractors: view/download only.

6. **Dashboard and Reporting** — 3 role-specific dashboards (admin, staff, contractor) with real data, stat cards, health badges, activity feed, filters. 6 export types: progress Excel, baseline Excel, extensions Excel, issues Excel, summary PDF (with embedded S-curve), PDF preview.

7. **Regression-Based Predictive Analytics** — SLR + polynomial regression, auto model selection, reference-class blending (cross-project similarity), multi-method completion estimation, prediction bands, forecast reliability scoring (0-100), class standing, prediction history with backtesting, contractor performance integration, recommendation engine (rule-based with AI fallback), AI text explanations.

## Client Workflow Notes

- Client does **NOT** record prospective risks — only known issues and problems.
- The Risk Register (Issues & Risks tab) exists as an **optional feature** for users to leverage if they choose. It may be removed or reworked later based on client feedback. Do NOT build new features on top of the risk register until the client confirms they want to keep it.
- The PMU's workflow is: Project Created → Baseline Schedule → Contractor Work → Monthly Inspection → Record Progress → Compare Against Baseline → Management Decision → Completion or Extension.

## Commands

- Dev stack: `composer dev` (runs `php artisan serve` + `queue:listen` + vite + `schedule:work` concurrently). A queue worker **must** be running (`QUEUE_CONNECTION=database`) for AI report emails / notifications to process. The scheduler runs in a CLI context (no session), so it uses real time only — the browser Date Simulator drives deadline/overdue scans solely through the dev widget "Run Notification Scan" button.
- Tests: `composer test` (clears config, then `php artisan test`). Single class: `php artisan test --filter=NotificationTest`. Tests use SQLite `:memory:` (see `phpunit.xml`); the live app uses MySQL `pmu_system4`.
- Seed categories after a fresh DB: `php artisan db:seed --class=CategorySeeder` (seeds exactly 4: New Construction, Renovation, Repair, Maintenance).
- Scheduler lives in `routes/console.php`, NOT `app/Console/Kernel.php` (its `schedule()` is empty): `reports:send-ai-monthly` at 08:00, `notifications:scan` at 07:00.

## Frontend: no-vite convention

App UI CSS/JS lives in `public/css` and `public/js`, linked via `asset('...')` in Blade layouts (`stafflayouts`, `contractorlayouts`, `app`). Do NOT add new feature styles/scripts to `resources/` or `@vite` them — follow the existing `public/` convention (e.g. `public/css/admin/projects/`, `public/js/admin/projects/`). The only `@vite` usage is the legacy `app.blade.php` bootstrap.

## Document Parser Libraries (updated Sept 2026)

Document parsers (normal PDF autofill + S-curve) are vendored in `public/js` per no-vite:

- `pdfjs-dist@4.4.168` via `public/js/vendor/pdfjs-loader.js` (jsDelivr→unpkg fallback, exposes `window.pdfjsLib`/`window.loadPdfJs`) — gives `x/y` for layout. No `smalot/pdfparser` for S-curve (strips bbox).
- `tesseract.js@5` lazy CDN (`document-parser.js:loadTesseract`) — already for normal scanned PDFs; reused for S-curve Tier 2 OCR `eng+osd` (auto, 30MB WASM ~2s, orientation fix for phone-tilted `scurvepdfsample2.pdf`).
- `phpoffice/phpspreadsheet ^4.0` (`composer.json`) for Excel S-curve — no `maatwebsite/excel` wrapper.
- **Duration word→digit** — approach B `words-to-numbers` `v1.5.1` concept vendored as `wordsToNumber()` in `public/js/admin/projects/document-parser-core.js:39` (dynamic `1..3650` for `NINETY`/`ONE HUNDRED TWENTY` etc., handles hyphen/`and`, pure no CDN). Keeps core `pure`/`node --test` and avoids extra `public/js/vendor` bundle. If a full `npm words-to-numbers` is preferred later, vendor it the same way (`public/js/vendor/words-to-numbers.js`) and keep `document-parser-core.js`'s `wordsToNumber` as wrapper. Tell the user before adding any new `npm`/`CDN` per project rule.

## Architecture

- Three role route groups in `routes/web.php`, prefixed `/admin`, `/staff`, `/contractor` with middleware aliases `role` + `active` (defined in `bootstrap/app.php`).
- `ProjectController` is shared by all three roles; per-role authorization is enforced inside the controller, not via separate controllers. Other controllers are split per role under `app/Http/Controllers/{Admin,staff,contractor}`.
- Business logic lives in `app/Services/` (e.g. `AI/` for regression/prediction services, `NotificationService.php`, `AIReportSendingService.php`). Models in `app/Models/`.
- App-wide date simulation: `SimulateDate` middleware + `/dev/*` routes (non-production only) set `Carbon::setTestNow()` via session. Use this to test deadline-sensitive features.

## Known Issues

- ~~`RiskLevelChanged` notification: bell dropdown shows "Issue reported" instead of correct label~~ **FIXED** (Aug 28, 2026).
- ~~AI Prediction tab shows "ON TRACK" while dashboard/index shows "At Risk" for same project — escalation factors not applied in AI presenter~~ **FIXED** (Aug 28, 2026).
- ~~Risk level notification only triggers on manual progress recording, not on month boundaries~~ **FIXED** (Aug 28, 2026).
- `GET /admin/projects/{id}/alternative` route exists in `routes/web.php` but controller method is missing (would 500). Safe to remove during dead code cleanup.
- `MilestoneController` + `ProjectMilestone` model are legacy dead code — the original milestone system replaced by baseline schedules. Not rendered in UI. Can be removed.
- `EmailDraftTest` — 2 pre-existing test failures (mailable not sent in test context).

## Tests

- Feature tests use `RefreshDatabase` and factories; model factories must stay valid (see `tests/Feature/Infrastructure/FactoryVerificationTest.php`).
- `Tests\Helpers\CreatesProjectWithHistory` trait builds regression datasets for AI tests, including the known "Project 7" dataset (slope ≈ 5.115, R² ≈ 0.954) — don't change those expected values.
- One test file per feature area (e.g. `NotificationTest.php`, `ProgressAttachmentTest.php`).
- Full suite: 985 tests / 3474 assertions (2 pre-existing `EmailDraftTest` failures excluded).

## Session Handoff (rewritten 2026-09-11 — read this first in a new session)

**What this is (30 seconds):** Laravel 12 + MySQL project-monitoring app for CSPC PMU. The current workstream is the **document parser track**: reading contractor PDFs/Excels to auto-fill project creation + S-curve baselines, with a rule reader first, a learning loop second, and Gemini AI last. Working tree: `merge ver15\PMU_System`, branch **`phase-5-parser-accuracy`** (merge-10 frozen at 7b0056d as cold backup — do not build there).

### What is done and proven (all on phase-5-parser-accuracy unless noted)
- **Normal-doc parser + L1/L2/L3 learning loop** (miss → teach → approve → fills free), ABC pooling/words-rule, weak-find guard. Browser-proven. JS suite shows 2 intended blinding fails (see constraints).
- **S-curve Excel end-to-end:** 2a/R2a (BUHI + roof families)/2b reference+warn/fallback/GRID/UX-2b, all browser-proven. Plus **E1 header learning** (E1-1 capture, E1-2 desk, E1-3 reader consult, Phase-A point-and-teach, E1-B learn-from-AI) and **E2 Excel AI** (`parseExcelSlice`, homework/presence-gate validation, prefer-math, Lite never falls back on this path). **E3 key/model split** live (parser = own Flash key; predictions untouched).
- **PDF S-curve rules (P1/P2):** detection batch + merge hardening (continuation inheritance, TOTAL fallback, reference+warn, rounding audit). Months/percentages correct on both proof twins; activity *names* stay generic — AI is the remedy, not more geometry.
- **PDF AI (F-A..F-D + B, committed through `1efc186`):** image-slice service with printed-words name guard (Flash-or-nothing — Lite rescue reverted after it masked transport failures as summary copies), `parse-pdf-gemini` endpoint (staff+admin), beside-button offer (failures) + in-modal recommended link (weak reads), merge with provenance, month-word learning (`scurveMonth` field, same trust rule). 10-month twin merges the TRUE curve + 4 warnings + Checked; 6-stage twin exact; Roof PDF probe returns the TRUE 7-month curve + real names (B-wins verdict — no rule family needed).
- **Suites right now:** JS ~116 + 2 intended blinding fails; PHP ~1140 passed / same 7 pre-existing failures (2 ContractorDashboard + 1 OverdueInspection + 2 EmailDraft + 2 baseline — all reproduce on the clean tree, re-verified via stash).

### Current work — BLOCKED ON DELIVERY, NOT LEARNING (read before touching)
- The SEGMENT loop proof (`sampledocs/SCurve-Test-Segment-Novel-Specimen.xlsx`, TRUE 5/20/40/65/100) shows learning works end-to-end (miss → 5 AI-assisted rows → approved Live) but the **re-import still misses on BOTH flows**. Full record: `textfiles/pdf_ai_loop_proof_tests.md`.
- ~~**Bug (a)** the word loader (`loadLearnedKeywords`) lives in `document-parser.js`, loaded only in the create wizard — the detail Baseline tab never defines it~~ **FIXED 2026-09-12 (uncommitted):** moved to shared `public/js/admin/projects/learned-keywords-loader.js`, loaded by both role layouts; consumers go through `window.loadLearnedKeywords` behind a typeof guard.
- ~~**Bug (b)** stale 24h `sessionStorage` word cache~~ **FIXED 2026-09-12 (uncommitted):** approve/reject bust it, and the cross-page-load cache is gone entirely — the live word set is memoized in memory per page load, so a refresh always re-reads the server (a DB-side wipe no longer "sticks"). Don't reintroduce a persistent cache without a version endpoint.
- **B / F-D / F-E CLOSED 2026-09-12** (user step-6 re-run): approved SEGMENT row → rule reader parsed the TRUE 5/20/40/65/100 natively, zero AI. AI-assisted read on the same file verified EXACT against the Excel twin (curve + names + the Finishing M4+M5 span).
- **P3 BUILT 2026-09-12** (browser proof pending): `mergePages` now pairs column-overflow pages by ROW HEIGHT instead of row order, and blank pdf.js tokens no longer collapse the description column. Measured on every sample PDF: SEGMENT names fixed; 6-stage and 10-month now read REAL names with Excel-matching per-month splits; the long-standing **"19.99-style dust" is gone** (it was a phantom cumulative row inflating the contract denominator); CONSCHEDSAVINGS identical. Rule reader now matches the AI on all three specimens.
- **NEXT WORK = E1-PDF layout learning**, specced in **`textfiles/document_parser_pdf_layout_learning_plan.md`** (handoff-ready: status, order of work, hard rules, verify commands).
- PDF AI link now renders on strong reads too (worded as a cross-check), matching the always-available Excel cross-check.

### Future queue (needs explicit user order each)
1. ~~The two delivery fixes~~ BUILT 2026-09-12 — step-6 re-proof pending. **NEXT AFTER THAT: E1-PDF layout learning** (user-ordered 2026-09-12; own plan file on build — PDF twin of the Excel E1 loop: capture+consult, learn-from-AI from the `monthLabels` the PDF AI already returns, point-and-teach via pseudo-columns reusing the Phase-A desk UI; `scurve_header_proposals.source_type` already reserves it). 2. Months:11 count-sanity guard (one-off sighting, needs a confirming screenshot). 3. Clipped display names verdict (check edit/manage for full names first). 4. Time-budget hardening (PHP 60s overflow → honest 422; sender-only). 5. Desc-label learning (prompt-reported work-names title + same plumbing; F-D is month-words only). 6. Blinding lift (pre-merge-to-main prerequisite — flips blinded fixtures green; NOT routine). 7. Parked: fingerprint memory (F-PDF2), rule family A (Roof-style), Tier-2 OCR, E1-3 follow-ups, 2c, P1/P2 passive mining + lifetime counting.

### Standing constraints (do not violate)
- **Blinding stays ON** (`L3_TEST_BLIND_ABC = true` in `document-parser-core.js`); restore is a tracked pre-merge-to-main prerequisite, not a routine step.
- Plan files: `textfiles/document_parser_accuracy_improvement_plan.md` (history + R0–R4), `document_parser_learning_loop_plan.md` (L1-L3), `document_parser_excel_learning_loop_plan.md` (E1), `document_parser_ai_fallback_plan.md` (AI-1..AI-6 + E2), **`document_parser_pdf_ai_fallback_plan.md` (PDF AI F-A..F-D + B)**, **`document_parser_pdf_layout_learning_plan.md` (P3 merge-by-Y + E1-PDF layout learning — the active file, handoff-ready)**, **`textfiles/pdf_ai_loop_proof_tests.md` (all proof results — read before re-proving anything)**, `document_parser_future_improvements_plan.md` (parked P1/P2). Log every behavioral change + browser result before committing (Adjustment Logging).
- Sample files live in git-ignored `sampledocs/` (BUHI, roof ×2, test5, test_page_6, third-family, 2c + 6-stage + SEGMENT specimens) — reference by name, never commit. One-off scripts go in `C:\temp\opencode` (outside the repo).
- `bootstrap/cache/routes-v7.php` goes stale on route changes — `route:clear` whenever endpoints are added (recurring incident). Blade JS tags need `$jsv(...)` versioning or browsers serve ghosts (second recurring incident).
- Browser-proven beats suite-green for DOM/canvas paths (slicers, buttons, links); suite-green suffices for pure/service paths. Clean-tree check (stash) before claiming zero regressions.
- No merge to main without explicit user confirmation (Rule 12); user uses short orders — "commit" alone means no push unless "push" is said. Never link live framework dirs into temp worktrees (wiped-vendor incident — copy or checkout in place).

## Development Rules

### Adjustment Logging (mandatory)

All bug fixes, enhancements, and behavioral changes must be logged in plan files under `textfiles/`. Each plan file (e.g. `bugs_and_behavior_adjustments.md`, `extension_notification_fix_plan.txt`, etc.) serves as the **historical record** of every adjustment a feature goes through during development, testing, and iteration. These files collectively form the project's adjustment history — any future developer or AI model can trace how and why a particular implementation evolved.

**What to log:**
- Each bug/enhancement with its problem, root cause, fix, and status
- Session logs with: date, changes implemented, user testing results, test pass/fail counts
- Fix plans when initial implementation needs correction (e.g. E4 live-gap fix plan)
- Any behavioral decisions made during testing (e.g. "recordProgress keeps month-specific variance")

**When to log:**
- Before committing any behavioral change
- After each testing session when user provides feedback
- When a fix plan is created for a discovered issue

**Which file to log in:**
- `textfiles/bugs_and_behavior_adjustments.md` — notification system bugs, enhancements, and behavioral changes
- Feature-specific plan files (e.g. `extension_notification_fix_plan.txt`) — changes scoped to that feature
- When in doubt, create a new plan file in `textfiles/` with a descriptive name

### Existing Development Rules (from project_context_and_development_rules.txt)

The following rules are established in the project. Full reference: `textfiles/active_reference/project_context_and_development_rules.txt`.

1. Always request updated source code before suggesting modifications when uncertain about current implementation.
2. Provide complete functions or classes — avoid partial implementations unless explicitly requested.
3. Clearly explain: where code belongs, what it replaces, what depends on it, why the change is necessary.
4. Every code block should contain descriptive comments explaining its purpose.
5. Maintain clean separation of responsibilities (math → services → controllers → views).
6. Avoid unnecessary coupling between services — single primary responsibility per service.
7. Prefer expanding existing architecture over redesigning completed components.
8. Test after each implementation step before proceeding to the next.
9. Every function must include a descriptive comment label (simple → one-line; complex → purpose/caller/dependency).
10. Inform user before continuing after each phase/sub-phase completion.
11. Record/update logs on every phase completion.
12. Branching workflow: new features on separate branches, named `phase-<number>-<short-description>`, no merge to main without explicit confirmation.
