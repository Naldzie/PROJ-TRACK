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
- Full suite (2026-09-15): **1217 passed / 4523 assertions, 5 pre-existing failures** — 1 DashboardOverdueInspection, 2 EmailDraft, 2 default-baseline (IssueLifecycle/RiskRegister). The 2 ContractorDashboard failures were fixed by the teammate work merged in 06d89cf. All reproduce on the clean tree; re-verify by stash before claiming a regression.
- JS suite: `npm run test:js` — **213 tests, 211 passed + 2 INTENDED blinding failures** (2026-09-15, read guard + AI shrink-to-fit + teach adds-up check + page-edge marking). Run it to a log file and grep the file; piping `node --test` into `grep | head` has stalled a session. (F-B1 conditional, L3 hardcoded-ABC). Two failures is green while blinding is on.

## Session Handoff (rewritten 2026-09-14 — read this first in a new session)

**What this is (30 seconds):** Laravel 12 + MySQL project-monitoring app for CSPC PMU. The current workstream is the **document parser track**: reading contractor PDFs/Excels to auto-fill project creation + S-curve baselines, with a rule reader first, a learning loop second, and Gemini AI last.

⚠️ **WHERE THE WORK ACTUALLY IS.** Working tree `merge ver15\PMU_System`, branch **`wip/phase-5-parser-accuracy-premerge`** — NOT `phase-5-parser-accuracy`, which is clean at `959f0ec`. That branch carries three unrelated bodies of work stacked together and is **not a clean thing to PR**:
1. `23abd20` — the PDF teach-grid build (was uncommitted on 2026-09-13).
2. `06d89cf` — a teammate's dashboard/UI merge, folded in by hand (their copies of three shared files predated the S-curve work, so `app.blade.php`, `stafflayouts.blade.php` and `projects/index.blade.php` were merged, not overwritten).
3. Everything after — the seven teach-grid defect fixes, two S-curve restudies, and the whole normal-doc OCR preview track.
Splitting these into reviewable branches is unfinished business. `merge-10` is frozen at 7b0056d as a cold backup — do not build there.

### What is done and proven (on `wip/phase-5-parser-accuracy-premerge` unless noted)
- **Normal-doc parser + L1/L2/L3 learning loop** (miss → teach → approve → fills free), ABC pooling/words-rule, weak-find guard. Browser-proven. JS suite shows 2 intended blinding fails (see constraints).
- **Normal-doc OCR page preview + AI page picker (2026-09-14, browser-proven):** the reviewer sees the page they are straightening, turning is instant, the strip says what each page yielded and why, and the AI's two pages are named and changeable. See the LATEST section below.
- **AI fallback (E4/E4b, 2026-09-13):** all three AI paths share one ladder walk (`climbFallbackLadder`). Lite is unreachable from both S-curve paths by construction; normal-doc keeps it as a last rung. Empty unless configured, so the default behaviour is unchanged.
- **S-curve Excel end-to-end:** 2a/R2a (BUHI + roof families)/2b reference+warn/fallback/GRID/UX-2b, all browser-proven. Plus **E1 header learning** (E1-1 capture, E1-2 desk, E1-3 reader consult, Phase-A point-and-teach, E1-B learn-from-AI) and **E2 Excel AI** (`parseExcelSlice`, homework/presence-gate validation, prefer-math, derived-map cross-check, salvage). **E3 key/model split** live (parser = own Flash key; predictions untouched).
- **PDF S-curve rules (P1/P2/P3):** detection batch, merge hardening, and **P3 merge-by-Y** — column-split prints now pair rows by height, not order. The long-documented "19.99-style dust" is gone. 6-stage and 10-month PDFs read exactly on first upload with real names.
- **PDF AI (F-A..F-D + B):** image-slice service with printed-words name guard (**Flash-or-nothing** — Lite rescue reverted after it masked transport failures as summary copies), `parse-pdf-gemini` endpoint, beside-button offer + in-modal cross-check link, merge with provenance, month-word learning (`scurveMonth`).
- **E1-PDF layout learning (PDF-1/PDF-2/PDF-3):** capture on failure, reader consults approved multi-page maps, and learn-from-AI. Capture + consult browser-proven 2026-09-12 with the word channel provably out of play.
- **The ROOF family is solved offline (2026-09-13).** Its Excel reads the workbook's HIDDEN columns AJ:AS; printing to PDF drops them, so the export keeps only per-activity time fractions that cannot be summed. Three fixes landed in order, all browser-proven: **summary-row adoption** (the author's printed cumulative row becomes the curve when activity math is unusable, flagged, `curveSource:'summary'`), **P5 AI-vs-file cross-check** (the file overrules a contradicting AI answer — caught gemini 3.6 live), and **P6 chart-scale exclusion** (the S-curve picture's 100-to-0 axis is no longer read as month data). The roof now reads its TRUE curve AND its TRUE activity lists with **zero AI**, matching what the AI reports on all 7 months.
- **Suites right now:** JS **158 tests / 156 passed + the 2 intended blinding fails**; PHP **1197 passed / 5 pre-existing failures** (1 DashboardOverdueInspection + 2 EmailDraft + 2 default-baseline — all reproduce on the clean tree; the 2 ContractorDashboard ones were fixed by the teammate merge in `06d89cf`).

### LATEST (2026-09-15): three PDF findings diagnosed — NO CODE, plan logged (uncommitted docs only)

**Read `textfiles/document_parser_pdf_teach_grid_plan.md`, the LAST §13 entry ("THREE FINDINGS FROM A ONE-PAGE ROOF PRINT AND A PRINTED BUHI PDF").** Code is unchanged since `7b3402e`.
- **A — one-page roof print, capture shows Description = A.** Display only: a derived desc is a BAND (A..D) and the prefill names its first letter; the PDF reader only ever uses the band's RIGHT edge (`descLimit`, core :669/:683), so A and B read identically (true curve both ways).
- **B — Contract amount / Weight / Item code are not fields on PDF.** Never stored or read; `buildTaughtPdfMap` uses them only as stops when widening the taught description, which is roof-shaped — on a BUHI-shaped header (AMOUNT, 3% DISCOUNT before CONTRACT AMOUNT) it would pull peso columns into the names. Excel uses all four for real.
- **C — the printed BUHI PDF (`sampledocs/Updated-S-Curve-3-Storey-Bldg.CSPC-Buhi-pdf.pdf`, 12 pages) reads WRONG with no learned layout:** a down-then-across grid whose names/TOTAL/cumulative row sit on month-less pages p1-p3; the reader uses only p4/p7/p10 and returns M4 29.47 (truth 10.59), contract 11.5M (truth 32.6M), all "Activity N", one weak warning. AI assist cannot rescue it (2 page images, table pages first).
- **Ordered (user):** (1) a **split-down guard** so that shape stops reading as a confident baseline — measure candidate signals on all 10 PDFs first (8 originals + BUHI + roof pdf2), 16-read regression must stay 0 changed; then (3) **PDF description-band teaching** (Description as a letter range, the three hint buttons disabled on PDF, single-letter fallback measured before chosen). **Full split-down support is PARKED**; the client will be asked for the original Excel workbook for big schedules. The early-100 guard queues behind both.
- **Owed by the user:** a BUHI re-print with print titles + fit-all-columns (the step-3 specimen; export with Excel's own PDF export, not Microsoft Print to PDF). **Done:** the one-page roof print is `sampledocs/CSPC-Rehabilitation-of-Roof-at-Academic-Building-II-S-CURVE-pdf2.pdf` (1 page, text layer verified).
- **D — the one-page BUHI re-print (`…-Buhi-pdf-1page.pdf`) reads a half-month EARLY:** real names, 12 months, but month bands start AT each merged month label (`finalizeHeaderBands` :352) while BUHI's label sits over the middle of its two cells — so each band reads the 2nd cell of its month plus the 1st of the next; contract comes out 32,346,214 = truth minus the one TOTAL cell left of Month 1. Browser-confirmed by the user. **User decision: D must be LEARNABLE, not a new reader rule.** Measured blockers (teach-grid plan, "FINDING D, PART TWO"): (1) learned PDF layouts are only consulted when the rules find NO table, and here they do; (2) the teach grid's pseudo-columns merge neighbouring cells, so two-cell months overlap and are refused; (3) even ideal month ranges lose up to half a month's pesos vs the file's own TOTAL row — cause unknown yet. **Order confirmed by the user:** (1) guard for both shapes → (2) measurement-only restudy of D as a learning problem → (3) description-band teaching.
- **Guard measurement pass DONE (2026-09-15, read-only):** 11 PDFs × up to 5 reads (rules, taught word, live DB layouts #124/#128, capture map, AI map) = 45 reads. Signal 1 (a left-block label page with no months AND every activity nameless) fires only on BUHI 12-page; signal 2 (the merged TOTAL row's month cells do not add up to any figure it prints) MISMATCHes only on BUHI 1-page and MATCHes both roofs. **Zero false positives on correct reads.** A per-page TOTAL check was tried first and falsely flagged the two-page roof — reconcile the MERGED table. Proposed spec, flow/reason/caller/capture design points and regression gates: teach-grid plan, §13. Probes: `guard_measure2.mjs`, `dump_live_pdf_maps.php`.
- **READ GUARD BUILT (2026-09-15, UNCOMMITTED, not browser-proven)** — teach-grid plan, last §13 entry. Core `readRefusal` / `totalRowReconciliation` / `readPageResults` in `scurve-pdf-parser-core.js`; new optional `opts.onRefusal`; **a refused rules read now falls through to learned layouts** (the change item 2 needs). Wrapper stashes `window._scurvePdfRefusal`; `importPDFSCurve` throws the reason + Excel advice, `scurvePdfFailureNote` words the AI offer. `verify_guard.mjs`: both BUHI prints refused on all 8 reads, every other read identical (one intended improvement on a synthetic CONSCHEDSAVINGS capture-map read). `verify_p6` 16/0, `block_snapshot` 9/0, 4 mutations all caught (`falsify_guard.mjs`). JS **195 tests / 193 pass + 2 intended blinding fails**. Browser check owed (4 steps in the plan entry).
- **Guard browser check (user, 2026-09-15): steps 1-4 PASS.** The "two-page roof stopped working" report is the guard CORRECTLY refusing live manual-teach #136, whose page-1 months are one column right (G..K, truth F..J): without the guard it imports 24.31/42.30/…/99.14/100 (a month early); moved to F..J it reads the truth through the guard. Open proposals, not built: refusal wording that points at the layout when a learned map was refused; an "adds up" check at teach-save; slicer scale-down for pages over 4000 px (BUHI 1-page AI assist renders zero images — pre-existing). Teach-grid plan, last §13 entry.
- **AI assist SHRINK-TO-FIT BUILT (2026-09-15, UNCOMMITTED, not browser-proven)** — teach-grid plan, last §13 entry. A page over the 4000 px canvas budget is rendered smaller (core `pdfSliceRenderScale`) instead of skipped, so the one-page BUHI (2592x1728 pt) no longer fails "could not be rendered to images"; `pdfSliceShrinkNote` adds an accuracy warning pointing at the Excel (omitted when P5 swapped in the file's months; appended to errors). Not a bigger cap on purpose: Gemini 3 gives each image a fixed token budget, so more pixels add no detail. Only BUHI 1-page changes across 19 sample PDFs; 16 reads / 9 blocks 0 changed; 3 mutations caught. Tiling a big page into several images is PARKED. Same-day follow-up (built, suite-green): the note has a failure wording and adds the user's second choice (a PDF split left-to-right onto two pages, every row + header on each); a refusal of a LEARNED layout's reading carries `viaMap`/`mapId` and its message points at S-Curve Layouts. The teach-save "adds up" check is MEASURED ONLY (7 real scenarios, 0 wrong verdicts; necessary-not-sufficient). Committed as `dfb0974`. **Then BUILT (UNCOMMITTED, not browser-proven):** (A) teach-save adds-up check — `taughtTotalCheck`/`taughtTotalMessage` in `scurve-teach-core.js`, modal warns on first Save with "Save anyway", live "Adds up"/"Not checked" line, PDF only; (B) page-edge marking — capture gets page boxes, block gains `EDGE: R14:K` under the legend only when text is cut (reader + maps untouched; both block parsers skip the line), grid fades + badges the cell. JS 213 / 211 + 2 intended; PHP full 1218 passed / same 5 pre-existing (+1 test); 8 mutations caught; 16 reads / 9 blocks 0 changed. Expect ONE new capture row each for the two-page roof and BUHI 12-page. Browser steps: teach-grid plan, last §13 entry.
- **ITEM (2) DONE (2026-09-15, measurement only, no code): the one-page BUHI PDF IS LEARNABLE TODAY.** Taught M1=H+J M2=K+M M3=N+O M4=P+R M5=S+U M6=V+W M7=X+Z M8=AA+AC M9=AD+AE M10=AF+AH M11=AI+AK M12=AL+AM, Description=B, it reads the Excel truth exactly on all 12 months, "adds up" at save, and passes the guard. Both D blockers retracted: "ideal bands lose pesos" was a probe bug (divided by page width 2592, the reader uses pageMaxX 2500.4); "months share letters" came from an old renamed-label probe. Centred "Month N" labels get their own thin column, so the label column is not a cell column in 8 of 12 months (label-guided teaching goes wrong — the save check catches it). Still wrong on a correct read: the day-marker line is an activity (+1 count), names carry the money columns (item 3 fixes), 12 noisy fraction/cost summary notes. AI-derived layouts start bands at labels and are refused. Options + recommendation (browser proof first, then item 3 + day-marker line): teach-grid plan, last §13 entry. **BROWSER-PROVEN by the user (same day):** taught #132 with those letters -> "Adds up" -> approved -> re-import reads the exact Excel curve (the save check first caught the label-start prefill, naming column H). Activity membership verified exact in all 12 months; remaining: day-marker line as an activity, money columns in names, 2 wrapped descriptions lost ("Activity 64", "c."), 11 summary notes. Next build proposed, not ordered.
- Probes: `C:\temp\opencode\probe_buhi_pdf.mjs`, `probe_buhi_pages.mjs`, `probe_pdf_generic.mjs`, `probe_buhi1_bands.mjs`.

### PREVIOUS (2026-09-14, late evening): S-curve teach desk hardened and under test — NOTHING UNCOMMITTED

**Read `textfiles/document_parser_pdf_teach_grid_plan.md` §0 (current state in one block), then the LAST entry of §13 (the handoff point).** Everything below is committed and pushed to `wip/phase-5-parser-accuracy-premerge`.
- **Excel row-role teaching — CLOSED, browser-confirmed.** Summary rows survive the 15-row budget; a real `[CUMULATIVE]` tag; "Not an activity" / "Cumulative row" teaching stored as TEXT signatures (`excludeSigs` / `cumulativeSig`, never R-numbers) and honoured by the reader without touching the TOTAL-row denominator; header-row memory; picks restored on reopen (defect 12); rows picked by clicking R-numbers like columns (HDR/SKIP/CURVE tints, cumulative-row advisory). Row roles stay **Excel-only by decision** (plan §11 Decision 4) — PDF finds its cumulative row by shape.
- **Latent-defect batch** (`cd2804a`): controller class name matches its file (Linux autoload), `storeAiDerived` scoped by `source_type`, PDF CUMULATIVE tag cannot steal HEADER/META, dead code removed.
- **Teach desk logic under test:** `public/js/admin/projects/scurve-teach-core.js` (ESM → `window.scurveTeachCore`, loaded by both layouts beside the desk include) holds block parsing, the prefill (defects 6/7/12), row-click rules and the advisory; `tests/js/scurve-teach-core.test.mjs` (29). Row-signature parity with PHP is pinned by `tests/fixtures/scurve_row_signature_cases.json`, read by BOTH suites. The Blade `sch*` functions only delegate. The old `C:\temp\opencode` probes that cut functions out of the Blade are superseded.
- **Roof misfit-layout fix:** `matchMapPage` now requires every stored month band to hold one of the page's own header labels (`bandsHoldLabels`). An old one-page seven-band roof layout had matched on "30 days" wording and read month 4 twice. Misfits now leave a file honestly unread.
- **Open for the user:** item-2 browser steps not yet shown (Excel prefill on reopen, staff layout); re-capture wave from `e3c30d6` (a second Proposed row per pre-existing capture — approve new, reject stale; live rows keep working).
- **Next ordered (superseded 2026-09-15 — see LATEST):** (3) the **early-100 guard** — client-side after the P5 cross-check, reading pre-sanitizer values (`extractRawPoints` idiom). It now queues behind the split-down guard and PDF description-band teaching.
- **Date note:** plan entries headed "2026-09-15" were written on 2026-09-14 local (Asia/Manila) — see the DATE NOTE in the last §13 entry.

### EARLIER: the OCR page preview is CLOSED (2026-09-14) — normal-doc parser, not S-curve

**`textfiles/ocr_page_preview_and_ai_picker_plan.md` — built and BROWSER-PROVEN in one session** (`ea949d4` plan · `a34faa0` · `2df4ec3` · `4f95560` · `2f209f3` · `0c0e107`).
The complaint: when OCR found nothing, the notice offered a rotate button per page but never showed the document, so the reviewer was asked to fix an orientation they could not see — and each click cost a ~20s full re-read of every uploaded PDF to find out whether it helped.
Now: one **"See & straighten"** button opens a panel that renders the page itself at its current angle; turning is **instant**; the page strip says what the reader got from each page **and why** ("nothing at all — try turning it" vs "only garbled marks (40 characters)"); and the two pages bound for the AI are named, explained and **changeable**.
Three things worth carrying forward:
- **The document viewer modal could not be reused** — it renders PDFs in an iframe so its rotate button is Chrome's own, and more decisively the file has not been uploaded yet so there is no URL to give it.
- **The "re-read only what changed" property came from a CACHE, not a partial-rescan path.** `ocrPage` memoises by `file#size#page@rotation`; the full pipeline still runs and recomputes every ledger, so the ledger-drift risk the plan flagged never existed. Cache sits *after* the budget gate and a hit still increments `_ocrPagesDone`, so pages attempted and the truncation flag stay byte-identical.
- **The AI ranks by "which pages OCR handled worst", with no notion of whether a page holds the fields** — a blank or signature page ranks first precisely because it yielded nothing. That is why the picker exists; the ranking itself is unchanged.
Diagnostics (⤓ snapshots, "Copy what was read") are now behind `window._parserDebug`.

### PDF-4 STAGE 2 IS CLOSED (2026-09-14) — NEXT WORK is the early-100 guard

⚠️ **PDF-4 Stage 2 is COMMITTED as `23abd20` on `wip/phase-5-parser-accuracy-premerge`** — NOT on `phase-5-parser-accuracy`, which is still clean at `959f0ec`. That branch also carries `06d89cf`, an unrelated merge of a teammate's dashboard work. The §10 browser acceptance run WAS DONE 2026-09-14: the grid passed, and three defects surfaced — one of them a regression §2 introduced. Full detail: **`textfiles/document_parser_pdf_teach_grid_plan.md` §13, entry 2026-09-14** — read that before touching the teach path. It also carries the build log (§13), including the two column rules that were tried and rejected by measurement, and three self-inflicted bugs worth not repeating.

**What it does:** the teach desk used to draw a table the document does not contain — whole columns collapsed into one (pseudo-columns were read off the header LINE alone), the summary block was cut off before the cumulative row, only the first printed page was drawn, and the S-curve chart's own scale appeared as cells. Now columns come from the whole table, the printed pages merge into one grid with letters running on across the seam, summary rows always survive the row budget, the running-total row is badged `CUMULATIVE` so it cannot be confused with the per-month `%` beside it, and multi-page teaching works. None of this changed what the reader extracts — the 16-read sample regression is byte-identical.

**SEVEN defects, all fixed and all BROWSER-PROVEN 2026-09-14** across four acceptance rounds (22edd71, 63862dd, 4ffbcc0). The roof now reads 6.32 / 24.31 / 42.30 / 59.71 / 77.03 / 99.14 / 100 with counts 4,4,4,4,3,4,3 through BOTH an approved AI-derived layout and a hand-taught one. Six of the seven were not in the code §2-§7 wrote but in the code that spoke to it and was not updated with it — §2 changed what a LETTER means and §3 changed what a PAGE means. Detail + measurements: teach-grid plan §13, the four 2026-09-14 entries.
1. **Teaching cannot express a multi-column description band** (the blocker, and a §2 regression). `buildTaughtPdfMap` sets `descX/descRight` to ONE pseudo-column, but every derived map spans the whole left block (roof: A–D). Measured: the desc band alone decides the curve — swapping the derived band into the user's otherwise-correct taught map turns 2.48/10.8/… back into 6.32/24.31/…
2. **`derivePdfLayoutFromAi` never got §2/§3** — its block is page-one-only and its page-2 letters restart at A,B, so ai-derived rows are un-teachable.
3. **An unnumbered capture can be approved and nothing says it is a no-op** — the desk has zero `unnumbered` handling.

**The re-capture wave already happened** (rows 76–79 in `scurve_header_proposals` are the roof's). The block text feeds `header_hash`, so a row captured before this work cannot retro-fit itself; re-importing produces a second proposed row. Not a bug.

**Also in commit 23abd20, and NOT part of that plan:** `showDeleteConfirmation is not defined` broke Reject on the dashboard. The two desks live in the layouts and can be opened from any page, but the component defining that helper was included per-page. It is now `@once`-guarded and included by both layouts.

**After that, the queue returns to the early-100 guard.** A weak model that finishes the curve a month early still passes on the homework/presence warnings alone whenever the file carries no printed cumulative row. Two constraints are already established: a server-side refusal in `validatePdfCurve` would **preempt** the client-side P5 cross-check (on the roof, the exact answer P5 now corrects would become "could not read"), so it must live client-side or be a warning the cross-check can supersede; and `sanitizeCurvePoints` force-last-100 **manufactures** repeated values, so it must read pre-sanitizer numbers via the `extractRawPoints` idiom.

### Future queue (needs explicit user order each)
1. ~~**PDF-4**~~ CLOSED 2026-09-14 — teach-path bug fix (959f0ec), Stage 2 (23abd20), and the seven defects the §10 acceptance run found (22edd71, 63862dd, 4ffbcc0), all browser-proven. Nothing remaining.
2. Months:11 count-sanity guard (needs a confirming screenshot). 3. Clipped display names verdict. 4. Desc-label learning (F-D is month-words only). 5. **Blinding lift** — a tracked pre-merge-to-main prerequisite, NOT a routine step; see the milestone note below. 6. Parked: fingerprint memory (F-PDF2), Tier-2 OCR, E1-3 follow-ups, 2c, P1/P2 passive mining.
7. ~~**S-curve row-role teaching**~~ CLOSED 2026-09-14 (see LATEST above).
   **Latent-defect batch — COMMITTED + PUSHED `cd2804a` 2026-09-15** (teach-grid plan §13): controller class name now matches its file (was a Linux autoload bug), `storeAiDerived` scoped by `source_type` (a PDF AI layout could hijack an Excel row), PDF CUMULATIVE tag no longer steals HEADER/META, dead `isCumulativeLabel()` removed, both `collect()` use `ensureReviewer()`. PHP 1216 / same 5; JS 159 / 157 + 2 intended; 16 reads and 9 sample-PDF blocks unchanged. Still open: the 4000-char block store limit (raise it together with any row-cap raise) and never-invoked policies.
   **Item 2 — COMMITTED + PUSHED 2026-09-14 late** (teach-grid plan §13): the S-Curve Layouts desk / Teach modal's pure logic (block parsing, prefill for defects 6/7/12, row-click rules, advisory) moved to `public/js/admin/projects/scurve-teach-core.js` (ESM, `window.scurveTeachCore`, loaded by both layouts beside the desk include) and tested by `tests/js/scurve-teach-core.test.mjs` (29 tests). Row-signature parity with PHP is pinned by `tests/fixtures/scurve_row_signature_cases.json`, read by both suites — change `rowSignature` in both languages or a suite goes red. Modal `sch*` functions now delegate.
   **Roof misfit-layout fix — COMMITTED + PUSHED 2026-09-14 late** (teach-grid plan, last §13 entry): an old one-page, seven-band roof layout (DB #116, approved) matched the two-page roof print on wording alone and read month 4 twice / lost 99.14. `matchMapPage` now requires every stored month band to hold one of the page's own letter-bearing header labels (`bandsHoldLabels`). Misfits now leave the file honestly unread; the taught layout #123 still reads the true curve. +1 JS test (falsified); 16 reads + 9 blocks unchanged. Note: plan entries headed "2026-09-15" were actually written 2026-09-14 local time — see the DATE NOTE there.
   **Next ordered (2026-09-15):** (1) split-down guard → (3) PDF description-band teaching → the early-100 guard. Full split-down PDF support parked. See LATEST.
8. **OCR ranking by "least usable text" rather than "no text"** (from the OCR preview plan). The per-page char-count and soupiness ledger now exists but the AI ranking ignores it — a page with 40 garbled characters still ranks below one with none, though the first is likelier to be worth sending. The picker makes this optional rather than urgent.

~~Time-budget hardening (PHP 60s overflow to honest 422)~~ **DONE 2026-09-13** as E5 + E6 — it stopped being a theoretical item the day a live ladder walk hit PHP's uncatchable fatal.

**MILESTONE NOTE (2026-09-13):** `phase-5-parser-accuracy` is **313 commits ahead of `main`** and blinding is still ON, so none of this track is merged. The remaining queue is polish, not gaps — Excel end-to-end, PDF rules + layout learning + AI, the roof family offline, three verification layers and AI resilience are all done and proven. Lifting the blinding and merging is arguably higher value than any item above; it needs an explicit user decision (Rule 12).

### Standing constraints (do not violate)
- **Blinding stays ON** (`L3_TEST_BLIND_ABC = true` in `document-parser-core.js`; user re-confirmed 2026-09-13). The JS suite therefore shows 2 INTENDED failures — that is green. Restore is a tracked pre-merge-to-main prerequisite, not a routine step.
- Plan files: **`textfiles/ocr_page_preview_and_ai_picker_plan.md` (NORMAL-DOC OCR preview + AI picker — CLOSED, browser-proven 2026-09-14)**, **`textfiles/document_parser_pdf_teach_grid_plan.md` (PDF-4 Stage 2 — CLOSED; §13's three 2026-09-14 entries carry the acceptance-run findings AND the parked row-role restudies)**, `textfiles/document_parser_accuracy_improvement_plan.md` (history + R0-R4), `document_parser_learning_loop_plan.md` (L1-L3), `document_parser_excel_learning_loop_plan.md` (E1), `document_parser_ai_fallback_plan.md` (AI-1..AI-6 + E2), `document_parser_pdf_ai_fallback_plan.md` (PDF AI F-A..F-D + B), **`document_parser_pdf_layout_learning_plan.md` (P3 + E1-PDF + roof diagnosis + P5/P6 — the active file, handoff-ready)**, **`textfiles/pdf_ai_loop_proof_tests.md` (all proof results — read before re-proving anything)**, `document_parser_future_improvements_plan.md` (parked P1/P2). Log every behavioral change + browser result before committing (Adjustment Logging).
- Sample files live in git-ignored `sampledocs/` — reference by name, never commit. One-off scripts go in `C:\temp\opencode` (outside the repo); the P5/P6 probes live there (`probe_m7*.mjs`, `probe_axis.mjs`, `probe_strict.mjs`, `verify_p6.mjs`).
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
