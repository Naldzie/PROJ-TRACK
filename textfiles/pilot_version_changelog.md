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
  │ 2        │ (not cut)    │ —          │ 7 staged: 6 code + 1 docs — see §3 │
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
  A-2  ✅  Progress pace — real points per month, not averaged cumulative %
  -----------------------------------------------------------------------------
    Date:    2026-09-22
    Branch:  phase-43-progress-pace-fix   Commit: c02de5f
    Merged:  YES — into develop 2026-09-24, inside the phase-44 merge (phase-44
             carries a --no-ff merge of phase-43, so one merge brought both)
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
    Browser check:      ✅ CONFIRMED by the user 2026-09-24 — the AI panel's pace
                        figures read as points per month.
    ⚠ DEPLOYMENT IMPACT: no migration, no new .env key, no composer or npm
                         dependency. PHP + one Blade view. A file copy is
                         enough — Blade recompiles on mtime. Run
                         `php artisan view:clear` ONLY if `view:cache` was run
                         on that machine.


  -----------------------------------------------------------------------------
  A-3  ✅  Document-parser preview — seven fixes from pilot-style use
  -----------------------------------------------------------------------------
    Date:    2026-09-23
    Branch:  phase-44-parser-preview-ui-fixes   (cut from develop d705b2d)
    Commit:  9300e61    Merged: YES — into develop 2026-09-24
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
    Browser check:      ✅ CONFIRMED by the user 2026-09-24 — all seven items in
                        restudy §54.5. For this entry that was the ONLY thing that
                        could close it, since the JS suite does not reach the DOM half.
    ⚠ DEPLOYMENT IMPACT: no migration, no new .env key, no composer or npm
                         dependency, and NO vite build — the file is under
                         public/js per the no-vite convention and is cache-busted
                         by filemtime ($jsv), so copying it is enough and
                         browsers pick it up on the next load.


  -----------------------------------------------------------------------------
  A-4  ✅  Keyword desk shows what a phrase would READ, not just whether it is new
  -----------------------------------------------------------------------------
    Date:    2026-09-23
    Branch:  phase-44-parser-preview-ui-fixes   Commit: 9caa4f1
    Merged:  YES — into develop 2026-09-24
    Plan:    textfiles/normal_doc_parser_restudy_plan.md §56

    THE DEFECT: the Review Learned Keywords desk had exactly one quality signal,
    builtInCoversPhrase, which answers "is this wording NEW?". On the 2026-09-23
    pdfdocstest5 batch that question endorsed four wrong rows and dismissed the
    one correct one:

      "Approved Budget for the Contract; PB"   desk: adds nothing   WRONG (.00)
      "| TOTAL ESTIMATED PROJECT COST P"       desk: NEW            CORRECT
      "Implementing Office"                    desk: NEW            WRONG
      "LOCATION"                               desk: NEW            WRONG
      "Name of Procuring Entity"               desk: NEW            WRONG

    ⚠ NOVELTY IS EXACTLY WHAT A WRONG LABEL HAS MOST OF. "Finds a value no
    built-in rule does" reads as praise. Coverage cannot answer "is this RIGHT?",
    and on this batch the two answers were uncorrelated — so a reviewer approving
    on the desk's own signal would have taught the parser four false labels.

    THE FIX: core previewLearnedExtraction(field, phrase, excerpt) runs the phrase
    against the excerpt it was mined from and reports the VALUE it would produce.
    The desk renders it as a second chip beside the worth chip. The three wrong
    budgetSource labels then visibly produce one identical organisation name —
    which is what a generic value anchoring unrelated labels looks like, readable
    at a glance with no new machinery.

    ⚠ IT USES THE REAL EXTRACTION PATH (same learnedLabelPattern, cleanValue,
    field normalizer and first-match-wins as collectLearnedCandidates). A preview
    that could disagree with what approval actually does would be worse than
    showing nothing. It returns null and stays silent when it cannot tell.

    Files:  public/js/admin/projects/document-parser-core.js
            resources/views/admin/_manage_parser_keywords_modal.blade.php
            public/css/admin/projects/parser-keywords-modal.css
            tests/js/document-parser-core.test.mjs (+7)

    Tests:  JS 286 / 286 / 0 (was 279). ✅ Unlike A-3, this one IS suite-covered —
            it lives in document-parser-core.js, which the JS suite reaches. Every
            fixture is a VERBATIM row from parser_keyword_proposals with its stored
            excerpt. Mutations M9/M10/M11 all red; M10 (skip the field normalizer)
            also breaks pre-existing learned-label tests, which is the property
            worth having: the preview shares the real extraction path. No PHP
            changed.
    Browser check:      ✅ CONFIRMED by the user 2026-09-24 — the consequence chip
                        renders on the desk. This was the last unproven build on
                        phase-44 (restudy §58.0(a)).
    ⚠ DEPLOYMENT IMPACT: no migration, no new .env key, no composer or npm
                         dependency, and NO vite build. One JS file, one Blade view
                         and one CSS file, all under public/ or resources/views per
                         the no-vite convention and cache-busted by filemtime
                         ($jsv), so copying them is enough. Run
                         `php artisan view:clear` ONLY if `view:cache` was run on
                         that machine (a Blade view changed).


  -----------------------------------------------------------------------------
  A-5  ✅  S-curve import reads activity cells whose unit is not pesos
  -----------------------------------------------------------------------------
    Date:    2026-09-24
    Branch:  phase-45-scurve-fraction-scale   Commit: 7e64799
    Merged:  YES — into develop 2026-09-24 (9a9c287, --no-ff, clean)
    Plan:    textfiles/document_parser_day_column_layout_plan.md §7 (measurement)
             and §7.8 (build log)

    THE DEFECT: the Excel S-curve reader assumed every activity/month cell holds
    PESOS and divided it by the contract total. The first real daily-format
    contractor workbook to reach the project (sampledocs/CONSTRUCTION SCHEDULE.xlsx,
    supplied 2026-09-24) writes each day cell as a SHARE OF THE WHOLE CONTRACT
    instead — literally TOTAL% / DURATION, e.g. 0.0536 / 30 = 0.0018 — so the cells
    add up to 1.0, not to the contract. Dividing that by the contract a second time
    produced 0.0000039% for month 1.

      curve read:  0 / 0 / 0 / 100       true curve:  3.04 / 10.10 / 33.75 / 100

    ⚠ AND THE WRONG ADVICE WAS WORSE THAN THE WRONG NUMBER. The rescue built for
    this shape stepped over it: the summary fallback required activity math to be
    ABSENT, and here it was present and wrong by a factor of ~783,000, with
    force-last-100 already putting 100 in the final month. So the PMU was handed a
    flat baseline with three warnings that PRINTED the correct curve and then said
    "Your baseline below uses the activity math — no action needed". A blank curve
    invites scrutiny; "no action needed" disarms it.

    THE FIX, two parts, both needed:
      (a) detectMonthCellScale() decides whether the cells are pesos, shares of 1,
          or percentages BEFORE any division, and always names a non-peso verdict in
          a warning. It defaults to pesos on anything unrecognised, so an unknown
          shape keeps the old behaviour rather than being guessed at.
      (b) The summary fallback now also fires when activity math is present but the
          author's own summary contradicts it by more than 20x, with wording that
          says so instead of claiming no activity values were found.

    ⚠ LAYOUT-AGNOSTIC BY CONSTRUCTION. parse() has exactly one buildMonths() call
    and parseWithAiLayout() routes through parse(), so the scale detector sits
    downstream of every layout path — numbered months, calendar names, the date-row
    (daily) rule, taught layouts and AI-derived layouts alike. 1 column, 2 columns
    (15-day pairing) and 28 columns per month are the same code at different span
    widths, so this is not three separate fixes.

    Files:  app/Services/SCurveImportService.php
            tests/Feature/SCurveImportTest.php (+7)
            .env.example  (AI_MODEL -> gemini-flash-lite-latest — a docs-only
                           correction aligning the sample with the model the live
                           .env already uses; unrelated to the parser)

    Tests:  PHP 1365 / 0 / 5276 assertions (was 1358) — develop's total plus exactly
            these 7. JS untouched and not implicated: no JS file changed.
            ⚠ CORPUS GATE, the one that matters for a scale rule: all 13 sampledocs
            workbooks re-read before and after — 12 BYTE-IDENTICAL, and the only one
            that changed is the specimen, which changed to the truth. Its
            summaryReference gaps went from 3.04 / 10.1 / 33.75 to ALL ZERO: the
            reader and the author's own cumulative row now agree on every month.
            Mutations M1-M4 all red — but M4 (the implausibility ratio) SURVIVED the
            first run because the test written to guard it compared 100 < 100, true
            at no ratio at all. A 7th test whose activity curve legitimately falls
            short of its summary is what kills it. Detail: plan §7.8.
    Browser check:      ✅ CONFIRMED by the user 2026-09-24 — Set Up Baseline
                        Schedule reads 3.04 / 10.10 / 33.75 / 100.00 with the right
                        activities per month and "Plan Progress 100%".
    ⚠ DEPLOYMENT IMPACT: no migration, no new .env key, no composer or npm
                         dependency, and NO vite build. ONE PHP service file is the
                         whole application change — nothing under public/ or
                         resources/, so no cache clear of any kind is required.
                         .env.example is a sample file the running app never reads;
                         do NOT copy it over a pilot machine's real .env.
                         ⓘ Unrelated but worth knowing while here: if a pilot
                         machine's own .env still names an older AI model, the AI
                         features there will fail on their own account. That is a
                         pre-existing condition, not something A-5 introduces.


  -----------------------------------------------------------------------------
  A-6  ✅  AI assist keeps its button after a successful answer
  -----------------------------------------------------------------------------
    Date:    2026-09-24
    Branch:  phase-46-ai-assist-retry   Commit: 5aebe82
    Merged:  YES — into develop 2026-09-24 (1614ab2, --no-ff, clean)
    Plan:    textfiles/normal_doc_parser_restudy_plan.md §59

    THE DEFECT (user report, 2026-09-24): after a SUCCESSFUL AI assist the "Try AI
    assist" button disappeared. The success path wrote its outcome sentence over the
    whole banner with aiEl.innerHTML, so the offer and the outcome were competing for
    one element and the outcome won.

    ⚠ IT BLOCKED THE WORKFLOW THE CONSENT SURFACE EXISTS FOR. Naming the pages (S-1),
    refusing a page or a file (S-2) and choosing the count (S-4) were all built so a
    reviewer could steer what gets sent. The natural order is: try it, see a thin
    result, go pick better pages, send again — and the button vanished at step 4.

    ⚠ IT ALSO EXPLAINS AN EARLIER HALF-FIX. The 2026-09-23 "the button seems to not be
    working" report was a stuck busy flag, correctly fixed — but that report was only
    possible because refreshAutofillAiBanner() silently restored a button the success
    path had destroyed whenever the page selection changed. Fixing the flag left the
    oddity: a control that vanishes on success and returns if you happen to touch the
    page list.

    THE FIX: the outcome is remembered in window._autofillAiOutcome and rendered as a
    LINE ABOVE an otherwise unchanged offer ("Send again?" / "Try AI assist again").
    The retry offer is built by the same banner function, so the named page list stays
    honest when the selection changes between attempts. Exactly one <button> remains in
    the banner — _tryAiAssist() finds its control with querySelector('button') and takes
    the first — and a stale [data-ai-error] node is cleared before the repaint so a
    failure followed by a success cannot leave the failure on screen.

    Files:  public/js/admin/projects/document-parser.js

    Tests:  JS 286 / 286 / 0 — UNCHANGED, and that is the honest statement rather than
            a pass: the suite reaches document-parser-core.js only and every line of
            this change is in document-parser.js, the DOM half. PHP untouched.
    Browser check:      ✅ CONFIRMED by the user 2026-09-24.
    ⚠ DEPLOYMENT IMPACT: no migration, no new .env key, no composer or npm dependency,
                         and NO vite build. ONE JS file under public/, cache-busted by
                         filemtime ($jsv), so copying it is enough. No cache clear
                         needed — no Blade view and no route changed.


  -----------------------------------------------------------------------------
  A-7  ⬜  Contract Price rename + Revised Contract Price field  ⚠ CARRIES A MIGRATION
  -----------------------------------------------------------------------------
    Date:    2026-09-24
    Branch:  phase-47-contract-price-revised   Commit: 0950064 (P1)
    Merged:  NO — P1 built, P2 and P3 still to come
    Plan:    textfiles/contract_price_and_revised_amount_plan.md (§8 build log)

    ⚠⚠ THIS IS THE ADJUSTMENT THAT ENDS THE CLEAN-ROLLBACK PROPERTY. §4 of this
    changelog has said since version 1 that restoring the v1 files is a COMPLETE
    rollback "because no adjustment since v1 touches the schema", and that the first
    adjustment carrying a migration must revoke that in writing. THIS IS IT. See the
    DEPLOYMENT IMPACT block below and the revised §4 rollback paragraph.

    WHAT IT DOES
      (a) Renames the column approved_budget -> contract_price. This is the SCHEMA
          CATCHING UP WITH THE UI, not a change of meaning: all seven user-facing
          labels already read "Contract Price". The old name also reads as ABC
          ("Approved Budget for the Contract"), which is a different figure.
      (b) Adds revised_contract_price, nullable. A variation / change order can revise
          a contract after award (GPPB allows up to 10% of the original price). The
          system had nowhere to put that, so a revision could only be recorded by
          overwriting the award and losing it.

    ⚠ THE REVISED COLUMN IS NEVER MIRRORED FROM THE ORIGINAL. If every project carried
    a revised value, "never revised" and "revised to the same number" would be
    indistinguishable — destroying the one fact the column exists to record. Read it
    through Project::effectiveContractPrice() (= revised ?? contract_price), which is
    what every total, comparison, export and report now uses.

    ⓘ INERT ON DAY ONE. Because the new column is NULL for every existing row, the
    effective amount equals the contract price everywhere until someone records an
    actual revision. No existing number moves — including the AI's cross-project
    similarity, which also reads this figure.

    Files:  1 migration · app/Models/Project.php · 4 controllers · 4 services ·
            factory · seeder · 10 Blade views · 3 JS files
            (P1 also carried the mechanical view/JS rename, so the app never shows a
            blank contract price between phases.)

    Tests:  PHP 1373 / 0 / 5292 assertions (was 1365) — the rename was proven first at
            1365 / 0, then the 8 new tests added, so the rename and the new behaviour
            are proven separately. tests/Feature/ContractPriceRevisedTest.php.
            ⚠ The tests that matter most are the two dashboard ones. Three selectRaw
            strings compute savings and portfolio totals, and RAW SQL IS INVISIBLE TO A
            COLUMN RENAME — without those tests, savings could have gone on measuring
            against the award forever and nobody would have seen it.
    Browser check:      ⬜ NOT APPLICABLE TO P1 — nothing user-visible changed by
                        design. It belongs to P2, which adds the input and the marker.

    ⚠⚠ DEPLOYMENT IMPACT — THE FIRST ENTRY THAT IS NOT "NONE"
        Database migration    YES — 2026_09_24_000001. Run `php artisan migrate`
                              AFTER copying the files. Already run on the development
                              database pmu_system4 (49.91ms, 21 projects intact).
        New .env keys         NONE
        composer / npm        NONE
        vite rebuild          NOT NEEDED
        Cache clears          `php artisan view:clear` if view:cache was ever run on
                              that machine (ten Blade views changed).
        ROLLBACK              ⚠ NO LONGER A FILE COPY. Version 1 code selects
                              approved_budget, which will not exist, so restoring the
                              v1 files alone BREAKS THE APP COMPLETELY. The correct
                              order is: run `php artisan migrate:rollback --step=1`
                              FIRST, then restore the v1 files.
                              ⚠ AND THAT ROLLBACK DISCARDS EVERY REVISED AMOUNT
                              recorded since the upgrade — there is nowhere in the v1
                              schema to keep them. Export them first if they matter.


===============================================================================
  §4 — UPGRADING A PILOT MACHINE FROM VERSION 1
===============================================================================

  ⚠ NO UPGRADE IS ORDERED YET — but the blockers are gone. As of 2026-09-24 all five
  adjustments are browser-confirmed and merged into develop, so an upgrade is now a
  decision rather than a wait. Version 1 remains what the PMU is running
  until someone says otherwise. This section describes what the upgrade costs.

  ⚠ ALL SIX ADJUSTMENTS ARE MERGED INTO develop AND BROWSER-CONFIRMED as of 2026-09-24.
  There is nothing left to wait for: cutting pilot version 2 is purely a decision.

  COMBINED IMPACT OF A-1 + A-2 + A-3 + A-4 + A-5 + A-6 + A-7
  ----------------------------------------------------------
    Database migrations      ⚠ ONE — A-7 (2026_09_24_000001). Run `php artisan
                             migrate` after copying. Read A-7's rollback note first.
    New .env keys            NONE
    composer dependencies    NONE
    npm dependencies         NONE
    vite rebuild             NOT NEEDED
    Files to replace         10 application files + docs:
                               A-2  3 PHP + 1 Blade
                               A-3  1 JS  (document-parser.js)
                               A-4  1 JS  (document-parser-core.js)
                                    + 1 Blade + 1 CSS
                               A-5  1 PHP (SCurveImportService.php)
                               A-6  1 JS  (document-parser.js)
                               A-7  1 migration + 1 PHP model + 4 controllers
                                    + 4 services + factory + seeder
                                    + 10 Blade + 3 JS  ⚠ SCHEMA

  That is the whole cost. No adjustment since version 1 touches the schema, the
  configuration or the dependency set, which is why an upgrade is a file copy.

  PROCEDURE (the work is merged and browser-confirmed as of 2026-09-24)
  ----------------------------------------------------------------------
    1. Stop the queue worker and the scheduler on the server machine.
    2. Copy the files listed in each §3 entry into the pilot tree.
       Do NOT copy .env — the pilot machine's APP_URL, GOOGLE_REDIRECT_URI,
       DB_* and mail settings are machine-specific and overwriting them breaks
       Google login. This is the single most likely way to break the upgrade.
    3. php artisan view:clear     (only if view:cache was ever run there — A-2
                                   and A-4 each change a Blade view)
       php artisan route:clear    (harmless; required whenever routes change —
                                   none of A-1..A-5 change routes)
    4. Restart the queue worker and the scheduler.
    5. Verify: open a project's AI Prediction tab and confirm the pace figures
       read as points per month (A-2); upload a scanned document and confirm
       the preview's seven behaviours in restudy §54.5 (A-3); open Review
       Learned Keywords and confirm each row shows the VALUE its phrase would
       read, not only whether the wording is new (A-4); import an S-curve whose
       day cells are fractions of the contract and confirm the baseline reads the
       real curve rather than 0 / 0 / 0 / 100 (A-5); run an AI assist to success and
       confirm the "Try AI assist again" button is still there afterwards (A-6).

  ROLLING BACK
  ------------
  Keep the version 1 zip.

  ⚠⚠ REVOKED 2026-09-24 BY A-7 — THIS SECTION'S GUARANTEE NO LONGER HOLDS.
  It read: "Since no adjustment since v1 touches the database, restoring the older
  files is a complete rollback — the schema stays valid either way." That was true
  through A-6. A-7 RENAMES A COLUMN, so version 1 code now selects a column that will
  not exist, and a plain file restore breaks the app outright.

  THE CORRECT ROLLBACK, once A-7 is deployed:
      1. php artisan migrate:rollback --step=1     (renames the column back and DROPS
                                                    revised_contract_price)
      2. restore the version 1 files
  ⚠ Step 1 DISCARDS every revised contract amount recorded since the upgrade. There is
  nowhere in the v1 schema to keep them — export them first if they matter.

  A rollback to any build BETWEEN v1 and A-7 is still a plain file copy. The property
  was worth preserving deliberately and it survived six adjustments; A-7 is the one
  that spent it, and its DEPLOYMENT IMPACT line says so.


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
