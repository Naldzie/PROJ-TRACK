# S-CURVE IMPORT INTO "EDIT ORIGINAL BASELINE" + CHOOSING THE FILE (BUILT — BROWSER CHECK OWED)
# Parent refs: textfiles/document_parser_and_scurve_import_plan.md (the import feature)
#              AGENTS.md → Document Parser Libraries (S-curve Excel/PDF readers)
# Date: September 21, 2026
# Status: 🔄 BUILT (2026-09-21) on phase-41 — browser check owed by the user.
#         Decisions S-D1..S-D6 answered (§5). Build log: §6.
# Branch: phase-41-scurve-import-original-baseline (from develop 7470c9e, after the
#         phase-40 merge).

===============================================================================
  1. THE ASK (user, 2026-09-21)
===============================================================================

  Client fact (head of office): in procurement the winning contractor submits a
  PRELIMINARY S-curve before work starts; after the project actually starts the
  PMU may request a REVISED S-curve. So the frozen original baseline must be
  editable by the staff in charge, not only the admin (the user already made that
  change — BaselineScheduleController::canEditOriginalBaseline = admin OR assigned
  staff).
  Asks:
    1. Add "Import from S-curve" inside the setup modal in Edit Original Baseline mode.
    2. What does the import do when a project has several S-curve files (e.g. the
       preliminary AND the revised)? Let the user choose the file.
    3. Does the import depend on the document TYPE "Schedule"? Types are editable —
       what happens if it is renamed or removed?

===============================================================================
  2. HOW IT WORKS TODAY (traced in source 2026-09-21)
===============================================================================

  PROJECT PAGE (components/tabs/baseline-schedule.blade.php :54-78)
    · The "Import from S-curve" button renders ONLY while the project has NO
      baseline (@if(!$hasBaseline)). Once a baseline exists there is no import.
    · It reads exactly ONE file: $project->documents()->byTypeName('Schedule')
      ->latest()->first() — the NEWEST document whose type is literally named
      "Schedule", and only if it is .xls/.xlsx/.pdf.
    · scurve-import-ui.js initProjectDetailSCurveImport(): Excel → server parse by
      document_id; PDF → the browser downloads the file and parses it locally.
      Result → setup modal in 'save-immediately' mode.
  CREATE-PROJECT WIZARD (refreshSCurveImportButton)
    · Candidates = pending uploads that are .xls/.xlsx/.pdf AND (type named
      "schedule" OR file name matches s-curve|scurve|schedule). It then takes the
      FIRST Excel candidate, else the first PDF — silently, no choice.
  SERVER (SCurveImportController::resolveSource)
    · Takes an uploaded file OR any document_id; requires only an Excel extension.
      It does NOT check the document type — and does NOT check that the document
      belongs to a project the caller may see (finding F-3).
  EDIT ORIGINAL BASELINE (baseline-schedule-setup-modal.js openEditOriginalBaselineModal
  → POST …/baseline-schedules/original/save, saveEditedOriginalBaseline)
    · Loads only the original months (frozen values), each with its row id and
      activities (ids kept so completion flags survive).
    · Months with recorded progress are shown locked; the SERVER silently skips any
      change to them (no error) and refuses to delete them.
    · Removed months are sent as removed_ids; activities are upserted by id.

  LIVE DATA (pmu_system4, 2026-09-21)
    · Document types: Contract, Supporting Document, Schedule (all active).
    · Project 155 has TWO "Schedule" files — only the newest can ever be imported.
    · Project 142's S-curve .xlsx is typed "Supporting Document" — so its project
      page shows NO import button at all.

===============================================================================
  3. ANSWERS TO THE THREE QUESTIONS
===============================================================================

  Q1 Import in Edit Original Baseline — not possible today (the button is hidden
     once a baseline exists). Buildable; the hard part is §5 S-D1 (inspected months).

  Q2 Several S-curve files — the project page reads only the NEWEST "Schedule"-typed
     file; the wizard reads only the FIRST matching upload. The user never sees
     which file was read and cannot pick the other one. With a preliminary and a
     revised S-curve both uploaded, whichever is newer wins silently.

  Q3 Document type — YES, the project page depends on the literal name "Schedule":
     · RENAME "Schedule" (e.g. to "S-Curve"): byTypeName('Schedule') matches nothing
       → the import button disappears for EVERY project, silently. The wizard still
       works only for files whose NAME contains s-curve/scurve/schedule.
     · DELETE the type while documents use it: DocumentTypeController::destroy only
       marks it inactive (name kept) → existing files still found, but new uploads
       can no longer be given that type → new projects lose the button.
     · DELETE it when unused: hard delete → same as rename for new uploads.
     · A file saved under another type (project 142) is invisible to the button today.

===============================================================================
  4. PROPOSED DESIGN (for review)
===============================================================================

  P1 ONE FILE PICKER for every import (project page setup, Edit Original, wizard):
     list every .xls/.xlsx/.pdf of the project, newest first, showing name, type,
     upload date and a hint badge ("Schedule type" / "name looks like an S-curve");
     preselect the best guess (Schedule-typed newest, else a name match, else the
     newest spreadsheet). With a single candidate it can go straight through, but
     the modal still says which file was read.
  P2 NO DEPENDENCY ON THE TYPE NAME: the type only RANKS files, it never gates them.
     Renaming/removing "Schedule" then changes only the preselection. (Alternative:
     an "S-curve source" flag on document types — needs a migration; not proposed.)
  P3 IMPORT IN EDIT ORIGINAL BASELINE: an "Import from S-curve" button inside the
     setup modal when it is in edit-original mode. The imported months are MERGED
     into the modal's current rows for REVIEW — nothing is saved until "Save Original
     Baseline", which uses the existing endpoint and its guards:
       · same month_label → update that row (keeps its id, activity ids/completion);
       · new month inside the project dates → added;
       · original month absent from the file → marked for removal;
       · months with recorded progress → per S-D1;
       · a summary of what will change is shown before saving.
  P4 F-3 (security, small): the parse endpoint should check that document_id belongs
     to a project the user may access (same rule as the project page).

===============================================================================
  5. DECISIONS FOR THE USER
===============================================================================

  S-D1 INSPECTED MONTHS when a revised S-curve is imported. The revision usually
       arrives after work started, so it covers months already inspected.
       (a) KEEP them as recorded — the revision applies only to months not yet
           inspected; the modal lists the kept months and their file values
           (recommended: matches the current server guard; history stays true —
           each inspection already stored the planned % it was measured against).
       (b) Let the revision overwrite the plan of inspected months too (server guard
           must be relaxed; the S-curve's past "plan" would no longer match what the
           inspections were measured against).
  S-D2 MONTHS OUTSIDE THE ORIGINAL SPAN: if the revised curve runs past the project's
       planned end date, (a) refuse those months with a note (recommended — moving
       the end date is the extension flow's job), or (b) extend the original
       baseline.
  S-D3 ACTIVITIES of a revised month: (a) match by name — keep existing activities
       (and their done flags) whose names reappear, add new ones, remove missing
       ones on non-inspected months (recommended); (b) keep the existing activities
       untouched and import only the percentages.
  S-D4 FILE PICKER SCOPE: all three entry points (recommended) or only the project
       page + Edit Original?
  S-D5 Show the picker even with ONE candidate (recommended — says which file is
       read), or skip it?
  S-D6 Fix F-3 in the same branch? (recommended — small).

  ✅ ANSWERS (user, 2026-09-21) — every one as recommended:
  S-D1 (a) inspected months KEPT as recorded; the revision applies only to months
       not yet inspected; the modal lists the kept months with the file's values.
  S-D2 (a) months past the project's planned end date are REFUSED with a note.
  S-D3 (a) activities matched by name (existing ones + done flags kept, new added,
       missing removed on non-inspected months).
  S-D4 the file picker on ALL THREE entry points (project-page setup, Edit
       Original, create wizard).
  S-D5 the picker is shown even when there is only ONE candidate file.
  S-D6 F-3 fixed on this branch (document_id must belong to a project the user
       may access).

===============================================================================
  6. SESSION LOG
===============================================================================

  2026-09-21 — Restudy done (read-only). No code changed.
  2026-09-21 — User answered S-D1..S-D6 (all as recommended) and ordered phase-40
    merged into develop (done: 7470c9e; PHP 1301 / 0, JS 267 / 0). phase-41 recut
    from develop 7470c9e. Build not started — waits for "go".
  2026-09-21 — ✅ BUILT on phase-41 (user: "yes start").
    BACKEND
    · NEW app/Services/SCurveSourceFinder.php — candidatesFor(Project): every
      .xls/.xlsx/.pdf of the project, ranked +4 Schedule type, +2 S-curve-looking
      name, +1 spreadsheet, ties newest first; each with type, upload date and the
      role's download URL (PDFs are read in the browser). The type never gates.
    · F-3: Document::isViewableBy(User) (ProjectPolicy::view). Enforced in
      SCurveImportController::resolveSource (refused with the same "could not be
      found" message, so other projects' ids are not revealed), in both of its
      document_id → contractor lookups (an unviewable doc scopes nothing), and in
      Admin\SCurveHeaderProposalController (resolveSource + resolveContractor).
      Today admin/staff may view every project and contractors cannot reach these
      routes, so nothing changes in practice — the check is future-proofing.
    FRONT END
    · scurve-import-core.js (pure, node-tested): rankSCurveCandidates (same scores
      as the PHP finder), splitSCurveMonthsAtEnd (S-D2 — refuse months past the end
      BEFORE normalizeSCurveMonths, which clamps them onto the end month as
      duplicates), mergeImportIntoOriginal (S-D1 inspected months kept + reported;
      same month → file %, row id and planned date kept, description from the file
      when it has one; activities matched by name keep their id → done flag
      survives; months only in the file added; open months missing from it
      removed).
    · scurve-import-ui.js: scurvePickFile() — one picker (stacked modal, backdrop
      lifted over the setup modal, textContent only) used by all three entry points
      and shown even for one file (S-D4/S-D5); project-page setup flow and create
      wizard now read the CHOSEN file (the wizard button shows for any pending
      Excel/PDF); new scurveImportIntoOriginal() → picker → read (Excel via the
      server, PDF in the browser) → split at the ORIGINAL end date → normalize →
      applyImportToOriginalBaseline().
    · baseline-schedule-setup-modal.js: the rows as loaded are snapshotted when
      Edit Original opens; applyImportToOriginalBaseline merges into THAT snapshot
      (so a second import or an earlier edit never compounds; it confirms first if
      the user already changed rows), replaces the list, sets removed_ids, and shows
      a summary box — updated / added / removed / kept-as-recorded (with file vs
      kept %) / refused past the original completion / import notes / "nothing is
      saved yet". Saving uses the existing endpoint and its guards.
    · _setup_baseline_modal.blade.php: "Received a revised S-curve?" row with the
      Import from S-curve button — shown only in Edit Original mode when the page
      supplied candidates; hidden on every reset.
    · baseline-schedule.blade.php: window.__scurveSources (candidates, parse URL,
      start date = original start, end date, originalEndDate) for admin/staff only
      (the parse routes do not exist for contractors); the setup import button now
      shows for ANY candidate (was: newest "Schedule"-typed document only).
    · Design note: Edit Original bounds months by the ORIGINAL completion date, not
      planned_end_date — after an extension, months past the original end are
      separate extension rows that this mode does not show; importing into them
      would have created duplicate months.
    · Not in this flow (kept simple): the AI assist / cross-check offers of the
      setup import; a read failure shows a toast. The setup and wizard flows keep
      them.
    TESTS: new tests/Feature/SCurveSourcePickerTest.php (10) — ranking, newest-first
      ties, renamed type, isViewableBy per role, parse refusal for another project's
      contractor, page wiring (sources JSON, button for any candidate incl. a
      Supporting-Document S-curve, no button without candidates, Edit Original after a
      baseline, no sources for contractors). New tests/js/scurve-original-import.test.mjs
      (12). Mutations (restored): access check removed → 1 red; type gates again →
      3 red; merge overwrites inspected months → 2 red.
    SUITES: PHP 1311 passed / 0 failed; JS 279 / 279 / 0; views compile.
    LIVE: project 142 (S-curve filed as Supporting Document) now offers it
      preselected; project 155 lists both Schedule files (xlsx preselected, PDF next).
    ⬜ BROWSER CHECK OWED (user):
      1. A project with no baseline and 2+ Excel/PDF files: Import from S-curve →
         the picker lists them, suggested one preselected → reads the chosen file.
      2. Create-project wizard with two uploads: the picker appears; the chosen one is read.
      3. A project with a baseline and some inspections: Edit Original Baseline →
         "Import from S-curve" → pick the revised file → the list updates, inspected
         months stay, the summary box lists the changes → Save Original Baseline →
         the Baseline tab shows the new plan; activity done-ticks survive.
      4. A revised file running past the original completion: those months are
         listed as not imported.
  2026-09-21 — USER FOLLOW-UP (from a browser run of the create wizard):
    1. "Use the reusable warning confirm modal" instead of the browser's confirm()
       dialog ("This replaces the current in-memory baseline (12 months). Continue?").
       · scurve-import-ui.js: new scurveConfirm(title, html) — a Promise around
         showWarningConfirm (components/warning-confirm-modal, included on all three
         pages that import; z-index 1080, above the setup modal); resolves false when
         the modal is closed/cancelled; window.confirm only if the component is missing.
         The wizard's replace-baseline question now uses it ("Replace the baseline?").
       · baseline-schedule-setup-modal.js: the Edit Original "replace your changes?"
         confirm (added earlier in this phase) uses showWarningConfirm too.
       · No window.confirm is left in either file except that fallback.
    2. "One-click restore — back to the current saved baseline after importing an
       S-curve." Built for Edit Original Baseline (the only mode with a SAVED
       baseline; the wizard and first-time setup have nothing saved to go back to):
       · "Restore saved baseline" button beside "Import from S-curve", shown only after
         an import (hidden on every open/reset); restoreSavedOriginalBaseline() puts
         the rows loaded from the server back, clears removed_ids and the import
         summary. No confirm (it only discards unsaved changes; nothing is written).
    Tests: page test asserts the restore button; PHP 1311 / 0, JS 279 / 0.
    ⬜ Browser check owed: the wizard replace question shows the amber warning modal
      (Cancel keeps the old baseline); Edit Original → import → Restore saved baseline
      → the list returns to the saved months and the summary disappears.
