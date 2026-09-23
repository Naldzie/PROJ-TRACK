===============================================================================
  PILOT VERSION CHANGELOG — CSPC PMU SYSTEM (PROJ-TRACK)
  Everything that changed AFTER the build handed to the PMU for pilot testing
===============================================================================

  Created:        2026-09-23
  Current pilot:  VERSION 1
  Maintained by:  whoever makes the change (see §0 — this is a standing rule,
                  not a courtesy)

  WHO THIS IS FOR
  ---------------
  Two readers, and the file has to serve both:

  1. Whoever updates the PMU pilot machine. The pilot ships as a manual
     copy-paste + zip of the working tree (no installer, no git on the PMU
     desktops), so an upgrade is "replace these files, run these commands".
     §4 is written to be followed without reading anything else.

  2. The next developer or AI session. The pilot build is a FROZEN reference
     point: when PMU staff report something, the first question is always
     "is that still true in the tree, or was it fixed after they got their
     copy?" This file answers that without a git archaeology session.

  RELATIONSHIP TO THE OTHER FILES
  -------------------------------
  This file is a LEDGER, not a plan. It records what differs from the pilot
  build and what an upgrade costs. The reasoning, root causes, measurements and
  mutation results stay in the feature plan files, which each entry links to.
  Do not duplicate that detail here — link to it.

  Related:
    textfiles/pilot_testing_readiness_plan.md
        — the pilot's scope, environment findings (F1-F3) and the P-queue.
    textfiles/pilot_setup_server_address_and_email_links.md
        — how the pilot machine is configured (APP_URL, Google redirect URI,
          queue worker, scheduler, firewall).


===============================================================================
  §0 — THE MAINTENANCE RULE (read before adding anything)
===============================================================================

  FROM 2026-09-23 ONWARD, EVERY BEHAVIOURAL CHANGE GETS AN ENTRY HERE, in
  addition to its feature plan file. The Adjustment Logging rule in AGENTS.md
  covers WHY a change was made; this file covers WHETHER THE PILOT MACHINE HAS
  IT. Those are different questions and the plan files cannot answer the second.

  Add the entry when the change is BUILT, not when it is merged — an adjustment
  that exists only on an unmerged branch is exactly the kind the pilot machine
  does not have, and that is the fact this file exists to record.

  Each entry must carry, at minimum:
    · date, branch, commit (or "uncommitted")
    · STATUS — see the badges below
    · what changed, in one paragraph a non-author can follow
    · files touched
    · test state, and whether a BROWSER check is owed
    · ⚠ DEPLOYMENT IMPACT — migrations, .env keys, composer/npm, cache clears.
      "None" is a valid answer and must be stated, not left blank. A missing
      migration on the pilot machine is the failure mode this line prevents.

  STATUS BADGES (project convention, from project_context_and_development_rules)
    ✅ COMPLETE      — built, tested, browser-confirmed, merged
    🔄 IN PROGRESS   — actively being worked on
    ⬜ NOT STARTED   — designed, not built
    ⚠️ BLOCKED       — waiting on a dependency or a decision
    ❌ CANCELLED

  When a NEW pilot build is cut:
    1. Add a new "PILOT VERSION n" block to §1 naming its exact commit.
    2. Move every adjustment now included into that version's block.
    3. Start a fresh "adjustments after version n" list in §3.
  Do NOT delete old entries. The point of the ledger is that an old pilot copy
  may still be in use on some desk.


===============================================================================
  §1 — VERSION INDEX
===============================================================================

  ┌──────────┬──────────────┬────────────┬────────────────────────────────────┐
  │ Version  │ Baseline     │ Cut        │ State                              │
  ├──────────┼──────────────┼────────────┼────────────────────────────────────┤
  │ 1        │ develop      │ 2026-09-21 │ IN USE — the copy the PMU has      │
  │          │ dd91e53      │            │                                    │
  ├──────────┼──────────────┼────────────┼────────────────────────────────────┤
  │ 2        │ (not cut)    │ —          │ 3 adjustments staged — see §3      │
  └──────────┴──────────────┴────────────┴────────────────────────────────────┘


===============================================================================
  §2 — PILOT VERSION 1 (the frozen baseline)
===============================================================================

  EXACT BASELINE
  --------------
    Branch:  develop
    Commit:  dd91e53  "merge: phase-42-notification-monitoring-restudy into
             develop"   (2026-09-21)

  ⚠ WHY THE COMMIT MATTERS MORE THAN THE DATE. develop's next commit, d705b2d,
  is dated 2026-09-22 but is DOCUMENTATION ONLY (the pilot setup guide). A pilot
  copy taken any time on 09-22 is functionally identical to dd91e53. When
  checking whether the PMU has a given fix, compare against the COMMIT, never
  against "the 09-21 build".

  WHAT VERSION 1 CONTAINS
  -----------------------
  develop through the phase 31-42 merges. The notable ones for pilot behaviour:

    phase-42  notification + monitoring restudy — emails are QUEUED (the queue
              worker must run or notification mail never leaves), one overdue
              notice per project, 5 in-app monitoring types, bell auto-refresh,
              inbox filters, "Clear read".
              ⚠ Carries migration add_cleared_at_to_notifications_table, which
              HAS been run on pmu_system4.
    phase-41  S-curve import inside Edit Original Baseline, file picker per
              import, document-access check on the parse endpoint.
    phase-40  Risk Register hidden; the tab is "Issues".
    phase-39  Project Monitoring Report (Word + PDF, month picker). The Excel
              exports still work but are hidden from the Reports menu.
    phase-38  UTF-8 BOM stripped from every response (the likely cause of the
              August "corrupt Excel" reports).
    phase-37  one Gemini call writes the AI analysis + recommendations; the AI
              tab no longer calls Gemini on render.
    phase-36  Create/Edit Project modal — fixed two-column layout.
    phase-35  S-curve layouts: Rejected tab, delete / restore / permanent delete.
    phase-34  Review S-Curve Layouts modal redesign.
    phase-33  learned-keyword soft delete; rejected wording stays refused.
    phase-32  Review Learned Keywords modal redesign.
    phase-31  parser AI calls log as images-normal / images-scurve.

  SUITE STATE AT THE CUT
  ----------------------
    PHP 1342 passed / 0 failed        JS 279 / 279 / 0

  KNOWN LIMITATIONS SHIPPED IN VERSION 1 (not defects introduced since)
  ---------------------------------------------------------------------
  These were understood and accepted at the cut. They are listed so a PMU report
  about one is recognised as known rather than triaged as new:

    · Google-only login. A desktop with no internet at login cannot sign anyone
      in, admin included (pilot finding F3).
    · APP_URL / GOOGLE_REDIRECT_URI must point at the server machine or Google
      login fails on every OTHER PC. See the pilot setup guide.
    · Notification EMAIL requires the queue worker to be running. The in-app
      notice is written immediately either way.
    · The scheduler runs at fixed times (07:00 scan, 08:00 AI monthly report)
      in a CLI context with no session, so the browser Date Simulator does not
      drive it — only the dev widget's "Run Notification Scan" button does.
    · Planned → Ongoing is a manual status change.
    · No failed-jobs screen.
    · .env ships APP_ENV=local + APP_DEBUG=true, so the Date Simulator is
      reachable by pilot staff and errors render a full stack trace (F1).
    · The AI parser tier depends on Google's free tier and returns 503 often.
      Nothing in the workflow REQUIRES it — the rule reader and learning loop
      read documents offline.


===============================================================================
  §3 — ADJUSTMENTS AFTER VERSION 1
===============================================================================
  Newest last. Every entry states its deployment impact explicitly.


  -----------------------------------------------------------------------------
  A-1  ✅  Pilot setup guide (DOCUMENTATION ONLY)
  -----------------------------------------------------------------------------
    Date:    2026-09-22
    Branch:  develop        Commit: d705b2d        Merged: yes (pushed)

    WHAT: textfiles/pilot_setup_server_address_and_email_links.md — the offline
    steps for pointing the system at the server machine: .env APP_URL and
    GOOGLE_REDIRECT_URI, the Google Console redirect entry, serve --host=0.0.0.0,
    npm run build + deleting public/hot, queue:work and schedule:work, the
    firewall rule, and the verification checks.

    WHY: Google rejects raw-IP and http redirect URIs, so this has to be right
    before any second PC can log in. It is a checklist, not a code change.

    Files:              textfiles/ (1 new file)
    Tests:              n/a — no code changed
    Browser check:      n/a
    ⚠ DEPLOYMENT IMPACT: NONE. No application file changed. A pilot machine on
                         dd91e53 is functionally identical to one on d705b2d.


  -----------------------------------------------------------------------------
  A-2  🔄  Progress pace — real points per month, not averaged cumulative %
  -----------------------------------------------------------------------------
    Date:    2026-09-22
    Branch:  phase-43-progress-pace-fix   Commit: c02de5f
    Merged:  NO — pushed to origin, not merged into develop
    Plan:    textfiles/progress_pace_fix_plan.md

    THE DEFECT: the AI panel's "Progress Speed Comparison" averaged the
    CUMULATIVE percentage over each half of the project and printed the result
    as a weekly rate. On project #142 that produced "4%/week" early and
    "26%/week" recent — figures that describe nothing. Because the numbers were
    meaningless, the recommendation rule that fires when recent progress is
    slower than early progress COULD NEVER FIRE.

    THE FIX: app/Services/AI/ProgressPaceService.php computes real pace —
    percentage points gained per month, over an early window and a recent
    window, plus the pace needed to finish by the planned end date. Both the
    AI presenter and RecommendationEngine now read it; the slowing rule works
    and a catch-up rule was added.

    Files:  app/Services/AI/ProgressPaceService.php (new)
            app/Services/AI/AIPredictionPresenter.php
            app/Services/AI/RecommendationEngine.php
            resources/views/projects/project_detail/components/tabs/
                ai-prediction.blade.php
            + 4 test files, the plan file, AGENTS.md

    Tests:              PHP 1358 / 0    JS 279 / 0
    Browser check:      ⬜ OWED
    ⚠ DEPLOYMENT IMPACT: no migration, no new .env key, no composer or npm
                         dependency. PHP + one Blade view. A file copy is
                         enough — Blade recompiles on mtime. Run
                         `php artisan view:clear` ONLY if `view:cache` was run
                         on that machine.


  -----------------------------------------------------------------------------
  A-3  🔄  Document-parser preview — seven fixes from pilot-style use
  -----------------------------------------------------------------------------
    Date:    2026-09-23
    Branch:  phase-44-parser-preview-ui-fixes   (cut from develop d705b2d)
    Commit:  UNCOMMITTED at the time of writing
    Plan:    textfiles/normal_doc_parser_restudy_plan.md §§50-54

    All seven are in the auto-fill preview that opens after the parser reads an
    uploaded document. All were found by using the feature on a real scanned
    PMU document (pdfdocstest5.pdf), which is why they are grouped.

    1. §50.1  The amber AI banner carried a duplicate "See or change these"
              button opening a panel already open above it. Removed — which
              also fixed the busy state, since the code located its own button
              as "the first button in the banner" and had been disabling the
              wrong one.
    2. §50.2  The scanned-page viewer could be opened and never closed, holding
              the modal's height for the rest of the session. A ✕ close button
              was added; rotations, page picks and refusals survive it.
    3. §50.3  ⚠ "AI-assisted" badges appeared over EMPTY Category and Budget
              Source boxes. A <select> silently drops a value it has no
              <option> for, so the AI's answer was discarded while the badge
              and the "filled N boxes" count both claimed success. Choice
              fields are now written through data-extracted (the mechanism the
              local reader already uses), and nothing is badged or counted
              unless the control actually took it.
    4. §51    The AI can now CORRECT a box the local reader filled, by offering
              rather than overwriting. The model's answer for already-filled
              fields was arriving in every response and being discarded; no
              prompt change, no second call and no extra pages sent.
    5. §52    ⚠ One successful AI assist jammed the button for the rest of the
              preview. Every failure path cleared the busy flag; the success
              path never did, so later presses died silently at the guard.
    6. §53    The AI send list: every chosen page now has an × that removes
              THAT page. Previously a fourth pick silently dropped the oldest
              choice, and the only removal control acted on the page currently
              on screen.
    7. §54    When the local reader and the AI disagree, ONE note lists every
              distinct reading, labelled by who read it. Driven by a real case:
              on ABC the local reader offered 4834297.00 (OCR misread) and
              834297.59 (leading digit cut off) while the AI read the correct
              4834297.59 — so discarding the AI where a local disagreement
              already exists would have removed the only correct value on
              screen. Values two readers agree on collapse into one entry
              marked "both agree".

    Files:  public/js/admin/projects/document-parser.js   (only application file)
            textfiles/normal_doc_parser_restudy_plan.md

    Tests:  JS 279 / 279 / 0 (unchanged — the suite covers document-parser-core.js,
            NOT the DOM half these fixes live in). Proven instead by two offline
            probes, 32 + 15 checks, with 8 mutations all confirmed red. No PHP
            changed, so the PHP suite was not re-run.
    Browser check:      ⬜ OWED — seven items listed in restudy §54.5. For this
                        entry that is the ONLY thing that can close it.
    ⚠ DEPLOYMENT IMPACT: no migration, no new .env key, no composer or npm
                         dependency, and NO vite build — the file is under
                         public/js per the no-vite convention and is cache-busted
                         by filemtime ($jsv), so copying it is enough and
                         browsers pick it up on the next load.


===============================================================================
  §4 — UPGRADING A PILOT MACHINE FROM VERSION 1
===============================================================================

  ⚠ NOTHING HERE IS ORDERED YET. Two of the three adjustments still owe a
  browser check and neither is merged into develop, so version 1 remains the
  correct thing for the PMU to be using. This section describes what an upgrade
  WOULD cost, so the decision can be made on facts.

  COMBINED IMPACT OF A-1 + A-2 + A-3
  -----------------------------------
    Database migrations      NONE
    New .env keys            NONE
    composer dependencies    NONE
    npm dependencies         NONE
    vite rebuild             NOT NEEDED
    Files to replace         4 PHP/Blade (A-2) + 1 JS (A-3) + docs

  That is the whole cost. No adjustment since version 1 touches the schema, the
  configuration or the dependency set, which is why an upgrade is a file copy.

  PROCEDURE (once the work is merged and browser-confirmed)
  ----------------------------------------------------------
    1. Stop the queue worker and the scheduler on the server machine.
    2. Copy the files listed in each §3 entry into the pilot tree.
       Do NOT copy .env — the pilot machine's APP_URL, GOOGLE_REDIRECT_URI,
       DB_* and mail settings are machine-specific and overwriting them breaks
       Google login. This is the single most likely way to break the upgrade.
    3. php artisan view:clear     (only if view:cache was ever run there)
       php artisan route:clear    (harmless; required whenever routes change —
                                   none of A-1..A-3 change routes)
    4. Restart the queue worker and the scheduler.
    5. Verify: open a project's AI Prediction tab and confirm the pace figures
       read as points per month (A-2); upload a scanned document and confirm
       the preview's seven behaviours in restudy §54.5 (A-3).

  ROLLING BACK
  ------------
  Keep the version 1 zip. Since no adjustment since v1 touches the database,
  restoring the older files is a complete rollback — the schema stays valid
  either way. That property is worth preserving deliberately: once an
  adjustment carries a migration, this stops being true, and the entry for it
  must say so in its DEPLOYMENT IMPACT line.


===============================================================================
  §5 — OPEN ITEMS CARRIED FROM VERSION 1
===============================================================================

  Not adjustments — these are known and unfixed as of this writing. Listed so a
  PMU report about one is recognised immediately.

  ⬜  The AI parser tier returns 503 from Google's free tier far more often than
      it answers (23 attempts / 1 success on 2026-09-23). Investigated
      2026-09-23: the per-attempt image timeout is 15s inside a 50s budget, so
      every run spends 47s and never reaches the third model; the one call that
      succeeded returned at 13s, leaving ~2s of headroom. Both values are
      .env-tunable (AI_PARSER_IMAGE_TIMEOUT, AI_PARSER_TIME_BUDGET) and must be
      raised TOGETHER — raising the timeout alone cuts the ladder from three
      attempts to one. Nothing in the workflow requires this tier.
      Related: textfiles/local_llm_parser_plan.md.

  ⬜  Deferred by the user at pilot setup (restudy plan §7): Date Simulator ×
      queue timing, fixed scheduler times, manual Planned → Ongoing, no
      failed-jobs screen, F-7, P-11.

  ⚠️  MAIL_ENCRYPTION=tls in .env is a Laravel 10 key that NOTHING in this
      codebase reads — config/mail.php reads MAIL_SCHEME. Mail works today
      because port 587 negotiates STARTTLS by inference. It will bite whoever
      sets MAIL_PORT=465 expecting MAIL_ENCRYPTION=ssl to switch to implicit
      TLS; that needs MAIL_SCHEME=smtps. Found 2026-09-23, not yet fixed.


===============================================================================
  END OF CHANGELOG
===============================================================================
