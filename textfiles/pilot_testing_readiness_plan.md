# PILOT TESTING READINESS (POLISH BEFORE REAL USERS TOUCH IT)
# Parent refs: AGENTS.md (the standing constraints, the suite baselines, the known issues)
#              textfiles/active_reference/project_context_and_development_rules.txt (Rules 1-12)
#              textfiles/document_parser_learning_loop_plan.md (L1-L3 + §6 the OCR batch —
#                the normal-doc parser, which is the FIRST polish target, §5)
#              textfiles/ocr_page_preview_and_ai_picker_plan.md (the OCR preview/picker)
#              textfiles/document_parser_day_column_layout_plan.md (PARKED 2026-09-18)
#              textfiles/local_llm_parser_plan.md (the AI tier's real availability)
# Date: September 18, 2026
# Status: 🔄 THE ACTIVE PLAN FILE. First item BUILT: §5 N1 (ABC blinding lifted + the
#         N1a cents-marker fix) on branch phase-23-abc-blinding-lift, suite-green and
#         falsified, UNCOMMITTED, browser check owed. Everything else unstarted.
#         §2 the client document facts · §3 THE PILOT AS SCOPED (answered by the user
#         2026-09-18) · §4 the environment findings that scoping produced, three of them
#         verified blockers · §5 THE FIRST POLISH TARGET: normal-doc parsing · §6 the rest
#         of the candidate list. Every item needs an explicit order (Rule 10).
# Branch on build: phase-<n>-<short-description> per item (Rule 12; no merge to main
#         without explicit confirmation — though the pilot itself does NOT need main, §3).

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

  Construction Project Monitoring System (PROJ-TRACK) for CSPC PMU.
  Laravel 12 / PHP 8.2 / MySQL (live: pmu_system4), SQLite :memory: for tests.
  Google-only auth (laravel/socialite, no local registration). Three role route groups:
  /admin, /staff, /contractor.
  Frontend convention: app JS/CSS lives in public/ via asset()/$jsv() — never @vite,
  never resources/ for new feature scripts.

  WHAT CHANGED ON 2026-09-18: the project moves from FEATURE WORK to PILOT READINESS.
  The document-parser track has been the workstream for weeks; it is now good enough for
  a pilot, and two client facts (§2) say the remaining parser backlog is aimed at
  problems the client does not actually have.

===============================================================================
  2. THE TWO CLIENT FACTS THAT CHANGED THE PRIORITY (user, 2026-09-18)
===============================================================================

  FACT 1 — S-CURVES ARRIVE AS THE ORIGINAL EXCEL.
    The PMU confirmed they mainly have the contractor's original .xlsx for S-curve
    baselines, not just a print of it.
    WHY IT MATTERS: phpspreadsheet reads a workbook exactly, offline, with no AI and no
    teaching. Every Excel file in the corpus already reads that way. The hard,
    speculative work — reading an exotic PRINTED layout — is aimed at a case that is now
    the exception rather than the rule.
    CONSEQUENCE: document_parser_day_column_layout_plan.md steps 4 and 5, and the PDF
    month-name counterpart, are PARKED (logged there 2026-09-18). The PDF S-curve reader
    keeps a KNOWN DEFECT — it keys calendar month names by calendar number and caps
    labels at 12, so a PDF S-curve starting in any month but January is misread.

  FACT 2 — NORMAL DOCUMENTS ARE MAINLY SCANNED.
    Contracts, proposals and supporting papers reach the PMU as PHYSICAL COPIES and are
    scanned in. There is no source file to fall back on, and the uploaded PDF often has
    no text layer at all — the parser is looking at an image.
    WHY IT MATTERS: this is the opposite of Fact 1. It moves the parser's real difficulty
    from the S-curve track (largely solved, and now usually fed a workbook) to the
    NORMAL-DOC track — OCR, orientation, page selection, and how honestly the UI tells a
    reviewer what could not be read.
    CONSEQUENCE: §5. The user has chosen normal-doc parsing as the first polish target,
    which matches this fact exactly.

  A THIRD FACT ALREADY ON RECORD, NOW A PILOT RISK RATHER THAN A CURIOSITY
    The Gemini AI tier is effectively dead: 134 answers in 549 calls over seven days, and
    ONE in 59 over the last two (local_llm_parser_plan.md §4). It is opt-in and nothing
    depends on it — but a reviewer who presses "Try AI assist" during a pilot waits up to
    50 seconds for nothing. A UX decision to make BEFORE the pilot (§6 item P5).

===============================================================================
  3. THE PILOT AS SCOPED (user's answers, 2026-09-18)
===============================================================================

  WHO      PMU staff, INCLUDING the admin role.
           CONTRACTORS: uncertain whether they get access for the pilot. What is decided
           is only that contractors' Gmail addresses populate the contractor LIST — which
           is not the same as those accounts logging in. Treat contractor-role access as
           OUT of the pilot until said otherwise, but see §4 finding F5: "their Gmail is
           in the list" has a login consequence that needs a decision either way.

  WHERE    PMU DESKTOPS, LOCALLY. Not a server, not a hosted URL — the app runs on the
           desktop it sits on.

  HOW IT   MANUAL COPY-PASTE + ZIP. Not a git deploy, not a build pipeline: the working
  GETS     tree is zipped and copied onto each desktop.
  THERE    ⚠ This is the single most consequential answer in this section. It means
           whatever is in the tree at zip time IS the deployment — see §4, which is
           almost entirely about that.

  BRANCH   develop. NOT main.
           ⇒ THE MERGE-TO-MAIN QUESTION IS OFF THE PILOT'S CRITICAL PATH. Item P7 in the
           earlier draft of this file is therefore dissolved: no merge, no branch split,
           no Rule 12 confirmation is needed to run this pilot. It remains open as a
           project-hygiene question for after the pilot.
           ⚠ BUT THE BLINDING IS NOT DISSOLVED WITH IT — see §5 item N1. It was filed as
           a merge prerequisite; it is actually a NORMAL-DOC PARSER prerequisite, and the
           pilot starts with exactly that.

  DATE     Not given. Ordering in §5/§6 assumes "soon enough that only blockers matter".

===============================================================================
  4. WHAT THE SCOPING ANSWERS REVEALED (verified 2026-09-18 unless marked)
===============================================================================

  Local desktops + a hand-made zip is a legitimate way to run a pilot, and it removes
  whole categories of work (no server, no domain, no TLS, no deploy pipeline, no merge).
  It creates a different set, and these are specific and checkable rather than
  theoretical. F1-F3 are VERIFIED against the tree; F4-F6 need your answer.

  F1 — THE ZIP SHIPS A DEVELOPMENT CONFIGURATION.  ⚠ VERIFIED — BLOCKER
       .env today carries APP_ENV=local and APP_DEBUG=true.
       Two consequences on a PMU desktop, both user-visible:
         (a) APP_DEBUG=true means any error renders Laravel's full stack-trace page —
             file paths, code, and environment values — to a PMU staff member. It is also
             how a stray exception turns into something that looks like the system broke
             permanently.
         (b) THE DATE SIMULATOR BECOMES LIVE FOR REAL USERS. The /dev/* routes are
             guarded by environment() !== 'production' (routes/web.php:1024) and
             otherwise only by 'auth' (:1025) — so on an APP_ENV=local desktop, ANY
             logged-in PMU staff member can reach the date-simulation widget and set
             Carbon::setTestNow via session. That silently changes what the whole app
             believes "today" is: deadlines, overdue flags, month boundaries. A pilot
             tester who finds that widget will report data bugs that are not bugs.
       Decide: does the pilot zip ship APP_ENV=production (which also disables the dev
       routes by their existing guard), or a pilot-specific env with APP_DEBUG=false?
       Note APP_ENV=production has other effects to check first, not assume.

  F2 — THE ZIP SHIPS WHATEVER ELSE IS IN THE TREE.  ⚠ VERIFIED — needs a decision
       A copy-paste of this working directory currently carries, among other things:
         .env with LIVE CREDENTIALS      Google OAuth client secret, the Gemini parser
                                         API key, mail credentials — copied to every
                                         desktop, readable by anyone who opens the folder
         storage/logs/laravel.log        5.4 MB of development history (§ the AI-usage
                                         measurement was taken from it)
         session-ses_*.md                ~4.5 MB of AI session transcripts, 14 files
         pmu_system4 (2).sql             a database dump
         capstone chp 1 and 3.txt        the capstone paper draft
         textfiles/                      every plan file, including this one
         .phpunit.result.cache, tests/, backup/, merging/, ProjectDetailService(prev).php
       None of that belongs on a client desktop. THE ZIP NEEDS A DEFINED CONTENT LIST —
       what goes in, what is stripped, and who checks. This is cheap to write once and
       impossible to undo after the fact (a leaked API key is leaked).

  F3 — GOOGLE-ONLY AUTH MEANS NO INTERNET, NO LOGIN.  ⚠ VERIFIED — needs your answer
       Auth is Google-only (routes/web.php:53-61; no local registration, no password
       route). Sign-in requires reaching Google, so a PMU desktop without working
       internet at that moment cannot log ANYONE in, including the admin. "Locally
       installed" and "works offline" are not the same thing, and the pilot's whole
       access model rests on this.
       Also: GOOGLE_REDIRECT_URI must match what each desktop actually serves
       (APP_URL is http://127.0.0.1:8000 today). Same-URL-on-every-desktop is fine for
       localhost, but it has to be confirmed against the Google console entry, not
       assumed.

  F4 — THE DATABASE SHAPE.  ✅ ANSWERED 2026-09-18 — shape (b), LAN-local
       THE ANSWER: one device serves as the server — the ADMIN's own desktop — and the
       other PMU desktops work against it. Run locally for now (no hosting, no domain).
       This is shape (b) below, and it is the right choice: it keeps ONE database, so
       staff see each other's work and the cross-role workflow (encode → inspect →
       record progress → notify) is actually exercised by the pilot. The concern raised
       here — private per-desktop databases making the pilot a UI test only — does not
       apply.
       WHAT IT NOW IMPLIES (noted, not ordered — the user's instruction was to note these):
         - The admin desktop is the single point of failure AND the single point of
           truth. If it is off, asleep or its firewall is up, the other desktops have no
           system. Back it up: the DB, storage/app (uploaded documents), and .env.
         - MySQL must ACCEPT LAN CONNECTIONS: bound beyond 127.0.0.1, a user granted from
           the LAN, and the Windows firewall opened for its port. Out of the box on a dev
           machine, none of those is true.
         - The other desktops need DB_HOST pointed at the admin machine, which means
           either a STATIC/reserved LAN address or a hostname that resolves. A DHCP lease
           change silently breaks every other desktop.
         - APP_URL / GOOGLE_REDIRECT_URI are per-desktop questions again: each desktop
           serves its own http://127.0.0.1:8000 while sharing one database. Confirm the
           Google console accepts the redirect each desktop actually uses (F3).
         - THE QUEUE WORKER AND SCHEDULER BELONG ON THE ADMIN DESKTOP, next to the
           database — one worker, not one per desktop, or jobs get processed more than
           once. QUEUE_CONNECTION=database means AI report emails and notifications sit
           in a table until a worker runs; routes/console.php's daily jobs
           (notifications:scan 07:00, reports:send-ai-monthly 08:00) need schedule:work
           or a Windows Task Scheduler entry, and neither fires on a machine that is shut
           down overnight.
       The three shapes, kept for the record:
         (a) ONE desktop, one DB, staff take turns — simplest; multi-user untested.
         (b) Several desktops, all pointed at ONE MySQL on the LAN — ✅ THIS ONE.
         (c) Several desktops, each self-contained — separate databases; rejected by the
             answer above.
  F5 — CONTRACTOR GMAILS IN THE LIST vs CONTRACTOR LOGIN.  ❓ NEEDS YOUR ANSWER
       You said contractor Gmail addresses populate the contractor list but contractor
       ACCESS is uncertain. Worth being deliberate: with Google-only auth, whether those
       people can log in depends on how the app treats a Google account that matches a
       contractor record. If a contractor tries during the pilot and gets in, they see a
       contractor dashboard that is not in the pilot's test plan; if they get a confusing
       rejection, that is a support call to the PMU.
       Decide which, and confirm it in the code rather than by intention. Not yet
       measured — deliberately, because it needs your answer first.

  F6 — WHAT DATA DOES THE PILOT START WITH?  ❓ NEEDS YOUR ANSWER
       Real PMU projects, or seeded demo data? If real, someone has to encode them (and
       the category seeder must have run: exactly 4 categories via CategorySeeder). If
       seeded, the seeders need a pass of their own before staff judge the system by
       them. Either way the S-curve baselines will come from real contractor Excel files
       (Fact 1), which is the path that already works.

===============================================================================
  5. FIRST POLISH TARGET: NORMAL-DOC PARSING (user's choice, 2026-09-18)
===============================================================================

  This is the right first target and it matches Fact 2: the PMU's normal documents are
  scanned, and the normal-doc parser is what the create-project wizard uses to pre-fill
  title, location, budget, ABC, budget source, category, start/end date, duration and
  contractor.

  WHAT THE PATH IS (so the polish is scoped, not open-ended)
    public/js/admin/projects/document-parser-core.js   ~108 KB, pure logic: field
      extractors, the L1-L3 learning loop, ABC pooling, the words-to-number reader.
      Tested by tests/js/document-parser-core.test.mjs.
    public/js/admin/projects/document-parser.js        ~149 KB, the browser/UI half:
      pdf.js loading, OCR via tesseract.js, the page preview/rotation, the AI page
      picker, the auto-fill notice.
    Its OCR behaviour is documented in ocr_page_preview_and_ai_picker_plan.md (CLOSED,
  ── N1. LIFT THE ABC BLINDING. ✅ BUILT + BROWSER-PROVEN 2026-09-18 ───────────  ✅

  ── N1. LIFT THE ABC BLINDING. ✅ BUILT 2026-09-18, browser check owed ────────  🔄
    This is the finding that most changes the plan, and it was hiding in a constraint
    filed under "merge prerequisite" rather than "parser defect".
    WHAT IT IS: document-parser-core.js:661 sets L3_TEST_BLIND_ABC = true. Its own
    comment reads "LOCAL ONLY — RESTORE BEFORE ANY COMMIT". While it is on:
      - extractABC()'s hardcoded patterns are replaced by an EMPTY list (:783), so a
        document that plainly says "Approved Budget for the Contract (ABC): ₱X" does NOT
        fill the ABC field;
      - the corroborated "Total/Approved Budget Amount" reassignment is skipped (:786);
      - the ABC words-shape fallback (readAbcWordsAmount) is skipped (:797);
      - collectAbcCandidates (ABC-pooling across multiple uploaded files) sleeps too.
    WHY IT EXISTS: it was switched on deliberately so a clean, selectable file would MISS
    and the teach→approve learning-loop round trip could be demonstrated end to end. That
    was the right call for a demo. It is the wrong state to hand to PMU staff.
    WHY IT IS A PILOT BLOCKER SPECIFICALLY: the pilot starts with normal-doc parsing.
    Staff will upload real contracts and see the ABC field come back empty on documents
    that state the ABC in plain words. They will report it as a bug; it is a switch.
    WHAT LIFTING IT COSTS: the two INTENDED JS failures are these ABC-pooling tests
    (tests/js/document-parser-core.test.mjs:661-697). Lifting should turn 228/230 into
    230/230 — the suite gets BETTER, not worse. The test file says the restore step
    "flips the two sleep asserts below to full", so the restore is already specified.
    DO NOT lift it silently: it changes auto-fill behaviour on every document, so it
    needs its own branch, the suite re-run, and a browser check on a real file.

  ── N2. GET REAL PMU SCANS BEFORE TUNING ANYTHING ELSE ───────────────────────  ⬜
    ⬆ RAISED IN PRIORITY 2026-09-18 by the N1 browser check and by the user's own note
    that there are not enough real files to prove steps 3-4 with. Everything now known
    about scanned behaviour comes from ONE team-made specimen (pdfdocstest5.pdf). It is
    already telling us something real (§8, and the restudy file) — but it is not a corpus,
    and the restudy's stages 2 and 3 are BLOCKED on this.
    ASK FOR: half a dozen of their actual scanned contracts/proposals, ideally including
    one bad one. Then run them through the existing pipeline and MEASURE, before changing
    a line — which fields fill, which miss, what the notice says, how long it takes.

  ── N2b. THE NORMAL-DOC RESTUDY (ordered 2026-09-18, UI first) ───────────────  🔄
    textfiles/normal_doc_parser_restudy_plan.md — the active study.
    STAGE 1 (UI) IS DONE, read-only: five verified findings (the page badge names one
    cause and offers one cure · a missing field never says why · fields carry NO page
    provenance, which is what makes the second one structural rather than cosmetic · the
    pick-one discards excerpts and counts it already has · the strip and the fields are
    two unconnected stories) plus ONE HYPOTHESIS: the OCR page budget (10) may have been
    spent before page 7, because the per-page fallback attempts each consume a unit.
    ONE BROWSER RUN SETTLES THE HYPOTHESIS and decides the design of the rest — see the
    restudy's §5 UI-1. Stages 2-4 (read path, extraction, learning loop) not started.

  ── N3. THE OCR PAGE RANKING (deferred item, now a real candidate) ───────────  ⬜
    The AI page picker ranks pages by "which pages OCR handled worst", with no notion of
    whether a page HOLDS the fields — so a blank or signature page ranks first precisely
    because it yielded nothing. The per-page char-count and soupiness ledger ALREADY
    EXISTS and the ranking ignores it. Cheap to fix; do it after N2 says whether it
    actually bites on their documents.

  ── N4. WHAT THE REVIEWER SEES WHEN A SCAN READS BADLY ───────────────────────  ⬜
    The auto-fill notice, the page strip and the "See & straighten" panel were rebuilt in
    September and are browser-proven — on developer samples. With scanned input as the
    NORMAL case rather than the exception, re-judge the wording for a PMU staff member
    who has never seen the system: does it say what to do next, or only what failed?
    Depends on N2 for real material to judge against.

  ── N5. TIME. A scanned multi-page PDF costs real seconds ────────────────────  ⬜
    tesseract.js lazy-loads a ~30 MB WASM bundle on first use and OCR runs per page. On
    a PMU desktop (not a developer machine, possibly on slower internet for that first
    fetch) the first upload of the day is the slow one. Worth MEASURING during N2 and, if
    it is bad, deciding whether the bundle ships inside the zip rather than from a CDN —
    which also matters for F3's offline question.

  ORDER: N1 (it is a switch, and it is wrong today) → N2 (get their files) → then N3/N4/N5
  re-ranked by what N2 measures. N1 is the only one that does not need their documents.

===============================================================================
  6. THE REST OF THE CANDIDATE LIST (each needs an explicit order, Rule 10)
===============================================================================

  Retained from the 2026-09-18 first draft, re-ranked by §3/§4. VERIFIED = checked
  against the code; CLAIMED = from AGENTS.md, still to confirm (Rule 1).

  P0. ESTABLISH THE REAL BASELINE.  ⬜  Re-run both suites and record the numbers here.
      AGENTS.md's figures (PHP 1226 / 5 pre-existing failures; JS 230 / 228 + 2 intended)
      predate commits 9545cc1 and 89beb2f. Run the JS suite to a log file and grep the
      file — piping node --test into grep|head has stalled a session. N1 changes the JS
      expectation, so take the baseline BEFORE it.

  P1. THE DEAD ROUTE THAT 500s.  ⬜  VERIFIED: routes/web.php:561 registers
      admin.projects.show-alternative → ProjectController::showAlternative, and no such
      method exists. Remove the route (confirm nothing builds the URL by name) or
      implement it. A pilot user CAN reach a 500 here.

  P2. THE LEGACY MILESTONE CODE — VERIFY BEFORE REMOVING.  ⬜  AGENTS.md CLAIMS it is
      dead and removable. Checked 2026-09-18: MilestoneController is at
      app/Http/Controllers/Admin/MilestoneController.php and ProjectMilestone is
      referenced by routes/web.php, ProjectController.php, ProjectSeeder.php and
      ProjectArchiveTest.php. Not urgent for a pilot; do not delete on the old claim.

  P3. THE FIVE PRE-EXISTING TEST FAILURES.  ⬜  CLAIMED: 1 DashboardOverdueInspection,
      2 EmailDraft, 2 default-baseline. None is a regression, but a red suite hides the
      next real one. Fix or document each with its reason.

  P5. WHAT "TRY AI ASSIST" SHOULD DO WHEN THE AI IS DEAD.  ⬜  A press can cost the full
      50-second budget and return nothing ~98% of the time (measured). Options: leave it,
      shorten the budget for the pilot, hide the offer while the tier fails, or say in
      the UI that the second opinion is unavailable. A decision, not a bug. Note this
      lands in the same screens as §5 — worth deciding while N1/N4 are open.

  P6. THE PILOT PACKAGE.  ⬜  Replaces the old "deployment dry run"; scoped by §3 to
      local desktops + a zip. Covers F1 (ship a non-dev env; dev routes off), F2 (a
      defined zip content list; strip credentials, logs, transcripts, dumps), F4 (DB
      shape; queue worker and scheduler wherever the DB lives), F3 (Google reachability
      and redirect URI), plus the two recurring incidents from AGENTS.md — stale
      bootstrap/cache/routes-v7.php (route:clear) and $jsv(...) versioning on Blade JS
      tags, which matters more than usual when the app arrives as a copied folder.
      A WRITTEN INSTALL CHECKLIST for whoever sets up a desktop belongs here.

  P7. ~~THE BRANCH SITUATION~~ DISSOLVED FOR THE PILOT (§3): the pilot runs off develop,
      so no merge, split or Rule 12 confirmation is on its critical path. Still open as
      post-pilot hygiene: develop carries the whole mixed wip history and main is
      hundreds of commits behind. The blinding, which was filed here, moved to §5 N1
      where it belongs.

  P8. KNOWN DEFECTS BEING CARRIED IN KNOWINGLY.  ⬜  To decide on, not necessarily fix:
      the PDF S-curve reader's calendar-number month keying + 12-month cap (§2 Fact 1);
      the fraction SCALE defect (a row printed 0.07 0.20 0.48 0.97 means 7/20/48/97, but
      asPercent = maxVal <= 100 takes it at face value — untriggered in the corpus); the
      AI prompt's example contractAmount being the one-page BUHI's REAL contract; the
      4000-char capture block store limit.

===============================================================================
  7. HOW TO WORK THROUGH THIS (unchanged rules, restated because the pace changes)
===============================================================================

  - Rule 10 — ask before starting each item, and report at the end of each one.
  - Rule 12 — a branch per item, named phase-<n>-<short-description>. The pilot does not
    need a merge to main (§3), but the branch discipline still applies.
  - Rule 8 / Testing philosophy — implement, validate, refine, continue. Browser-proven
    beats suite-green for any DOM/canvas path; suite-green suffices for pure/service paths.
  - Adjustment Logging — log the behavioural change and the browser result HERE (or in
    the feature's own plan file) BEFORE committing.
  - Clean-tree check before claiming zero regressions; do not git stash to get one while
    uncommitted work is in the tree — compare against git show HEAD:<path> instead.
  - AGENTS.md standing constraints still apply, EXCEPT that "blinding stays ON" is now
    under active reconsideration as §5 N1 — that line in AGENTS.md is superseded by
    whatever you decide there.
  - sampledocs/ is git-ignored and never committed; one-off probes go in C:\temp\opencode;
    commit messages via git commit -F (PowerShell 5.1 mangles quoted here-strings).

===============================================================================
  8. SESSION LOG
===============================================================================

  2026-09-18 — FILE CREATED, then EXPANDED with the pilot scoping. No app code, no
  behavioural change.
    Recorded: the two client facts (§2) and the user's scoping answers (§3).
    Parked, in their own file: day-column steps 4 and 5 + the PDF month-name counterpart.
    Verified today against the code, not copied from AGENTS.md:
      L3_TEST_BLIND_ABC = true at document-parser-core.js:661, emptying extractABC's
        pattern list at :783 and gating :786/:797 — THE FIRST POLISH TARGET IS THE ONE
        THING A DELIBERATE SWITCH IS CURRENTLY BREAKING (§5 N1). The two "intended"
        JS failures are these ABC-pooling tests (test file :661-697).
      APP_ENV=local, APP_DEBUG=true in .env; /dev/* guarded only by
        environment() !== 'production' (routes/web.php:1024) + 'auth' (:1025) → the Date
        Simulator would be live for pilot staff (F1)
      auth is Google-only, routes/web.php:53-61, no password route (F3)
      routes/web.php:561 show-alternative has no controller method (P1)
      MilestoneController is under Admin/ and IS referenced in four places (P2)
    NOT verified today: the suite baselines (P0 exists to fix that); F5's contractor-login
      behaviour (deliberately — it needs the user's decision first).
    NOTHING IS ORDERED YET.
    DATE NOTE: this entry and the 2026-09-18 headings in AGENTS.md and the parked
    day-column plan were written in one continuous session that crossed local midnight
    (Asia/Manila) — the scoping answers in §3 and the §5 findings landed on 2026-09-19 by
    the clock. Kept as 2026-09-18 so the whole priority change reads as one event.

  2026-09-18 — ITEM N1 ✅ BUILT: the ABC blinding is LIFTED, and the defect it was hiding
  is fixed (N1a). Branch phase-23-abc-blinding-lift (off phase-22-scurve-month-headers at
  89beb2f; Rule 12). User order: "yes branch it." NOT COMMITTED — awaiting the user's word.

    WHAT CHANGED
      public/js/admin/projects/document-parser-core.js
        L3_TEST_BLIND_ABC          true -> false (:661 area). The comment block is
                                   rewritten: what the switch did, why it is off now, and
                                   how to re-blind for a demo.
        isCentsFractionOnly()      NEW, above extractABC. True only when EVERY digit in the
                                   text belongs to an "NN/100" written-cents marker.
        extractABC()               its findLabeledValue validator is now
                                   (t) => isCentsFractionOnly(t) ? null : asMoney(t).
        collectAbcCandidates()     the same guard before asMoney(t), so both ABC tracks
                                   agree (the module's own comment warns that divergent
                                   tracks confuse everyone).
        the module export block    isCentsFractionOnly added, so the guard is testable.
      tests/js/document-parser-core.test.mjs
        three sleep-asserting fixtures replaced by four that assert the restored behaviour
        (net +1 test); the two stale "RESTORE step flips these" header comments rewritten.

    ⚠ N1a — THE DEFECT THE BLINDING WAS HIDING (found by lifting it, not predicted)
      Lifting the flag alone did NOT give a correct reader. It turned an EMPTY ABC box into
      a WRONG one on exactly the document class the pilot will see most.
      A funds certificate spells the amount out and ends "… PESOS & 59/100". The ABC label
      pattern captures that whole tail; asMoney read "59" out of the cents marker and
      returned P59.00 — and because extractABC returns on the first truthy `direct`, it
      SHORT-CIRCUITED the words-shape rule that reads the line correctly. Measured on the
      REAL funds-cert wording already banked in the suite, not only on a synthetic fixture:
        before N1a   extractABC "59.00"      collectAbc ["59.00", "4834297.59"]
        after  N1a   extractABC "4834297.59" collectAbc ["4834297.59"]
      Why it matters for the pilot: the PMU's documents are SCANNED (§2 Fact 2), and OCR
      garbling a peso figure while leaving the written words intact is precisely the case
      the words rule exists for. Wrong money in a monitoring system is worse than blank
      money, so N1 could not ship without this.
      SCOPE NOTE (Rule 10): the ordered item was "lift the blinding". N1a is a correctness
      fix added on top because shipping N1 without it would be strictly worse than leaving
      the blinding on. Flagged to the user; drop it and the lift should be dropped too.

    RESULT
      JS suite   BEFORE  230 tests, 228 pass, 2 fail — the 2 INTENDED blinding failures,
                         confirmed BY NAME: "F-B1 conditional: budget-Amount reroutes to
                         ABC only with Original/Revised present" and "L3: hardcoded ABC
                         beats learned label regardless of line order".
                 AFTER   231 tests, 231 pass, 0 fail. Both intended failures now pass, as
                         the test file's own restore note predicted.
                 (The intermediate run — flag lifted, fixtures not yet updated — was
                 227/3, the three sleep-asserting fixtures. Exactly as documented.)
      Probe      C:\temp\claude\probe\abc_probe.mjs, four wordings through the real module:
                 truncated fixture 4000000.59 · real funds cert 4834297.59 · clean numeric
                 4834297.59 · round cents 2000000.00. All correct; all three tracks agree.

    FALSIFIED BY MUTATION (three, each red; file restored byte-exact after each)
      M1  isCentsFractionOnly returns false      -> 229/2, both N1a tests red
      M2  L3_TEST_BLIND_ABC back to true          -> 225/6 (the 2 original intended + the
                                                   4 restored/new)
      M3  the collector's guard removed           -> 230/1, the cross-track test red
      M3 is the one worth noting: only the test that asserts BOTH tracks catches it, which
      is why the guard is asserted on extractABC and collectAbcCandidates together.

    NOT TOUCHED: no PHP, no Blade, no route, no S-curve code. The PHP suite is therefore
    unaffected by construction; it was run anyway and the result is recorded below.

    ⬜ BROWSER CHECK OWED (user) — this is a DOM-consuming path, so suite-green is not the
      bar (AGENTS.md: browser-proven beats suite-green for DOM paths):
      1. Upload a document that plainly states "Approved Budget for the Contract (ABC):
         PHP x" in the create-project wizard. The ABC box should now FILL. Before this
         branch it stayed empty.
      2. Upload a funds certificate whose amount is in WORDS ("… PESOS & 59/100"). ABC
         should read the full written amount, not P59.00 and not blank.
      3. Upload a document the L1-L3 loop has TAUGHT. The learned single-match path must
         still win as before — the lift must not have changed what teaching does.
      4. Multi-file upload (ABC pooling) still agrees with itself: no "sources disagree"
         warning on files that actually agree.

    BROWSER RESULT (user, 2026-09-18) — N1 ✅ PROVEN; N1a's bar met; steps 3-4 OWED
      STEP 1 ✅ pdfdocstest5_selectable_text.pdf → ABC = 4834297.59. This is the exact
        INVERSION of the old blinding check (learning-loop plan :1080 required this box to
        be EMPTY), so the lift is proven end to end in the browser.
      STEP 2 ⚠ pdfdocstest5.pdf (the scanned original) → ABC = "Not found — fill manually".
        N1a'S BAR IS MET: blank, not 59.00, not any cents-sized number — the written-cents
        guard held on a real scanned file. But the ABC is missing for a bigger reason: the
        page strip reports PAGE 7 OF 10 as "nothing at all — try turning it", and page 7 is
        the page carrying "TOTAL ESTIMATED PROJECT COST P 4,834,297.59" — plainly legible
        to a human in the system's own preview. The document says it, the reviewer can see
        it, and the parser got nothing.
        ⇒ That result is the trigger for the whole normal-doc restudy:
          textfiles/normal_doc_parser_restudy_plan.md (ordered the same day).
      STEPS 3 (teaching still wins) and 4 (pooling does not false-alarm) NOT RUN — the
        user has no further real documents and declined to judge them on synthetic ones.
        STILL OWED. Step 3 matters most: with blinding off, hardcoded patterns and learned
        labels are both live for the first time in months, and if a hardcoded pattern now
        overrides an approved learned label then teaching quietly stopped mattering. It is
        stage 4 of the restudy.
      COMMIT DECISION: still the user's. N1+N1a are suite-green, falsified and now
        browser-proven on the case they were written for; steps 3-4 remain unproven.

    ⚠ NOTED, NOT FIXED (needs its own order): extractBudget has its own words-shape
      fallback and its own asMoney call. Whether the same "& NN/100" cents marker can
      mislead the BUDGET field was NOT measured — the ordered scope was ABC. Worth one
      probe before the pilot, since a contract price in words is at least as common as an
      ABC in words.

===============================================================================
  9. OPEN QUESTIONS FOR THE USER
===============================================================================

  ANSWERED 2026-09-18: who (PMU staff incl. admin; contractors uncertain), where (PMU
  desktops, locally), how it ships (manual copy-paste + zip), which branch (develop),
  first polish target (normal-doc parsing). All folded into §3 and §5.

  ANSWERED 2026-09-18 (second round): F4 — the ADMIN's desktop is the server and the
  other PMU desktops work against it, run locally. Shape (b). Its follow-on items (LAN
  MySQL, a stable DB_HOST, one queue worker + scheduler on the admin desktop, backups)
  are NOTED in §4 F4 at the user's instruction, not ordered.

  STILL OPEN — each is in §4 with its detail. The user's instruction 2026-09-18 was to
  NOTE these for now, not to act on them; they are recorded so the pilot package (P6)
  starts from a list rather than from memory:
  1. F1 — does the pilot zip ship a non-development configuration? Today's tree would put
     a live Date Simulator and full stack traces in front of PMU staff.
  2. F2 — what goes in the zip? Today's tree would also ship live API keys, 5.4 MB of
     logs, AI session transcripts and a DB dump onto client desktops.
  3. F3 — will those desktops have internet at login time? Google-only auth means no
     internet, no login, for anyone.
  4. F5 — should contractors be able to log in during the pilot, or be refused cleanly?
  5. F6 — real PMU project data or seeded data on day one?
  6. A date, if there is one. It decides how much of §6 is worth doing first.

===============================================================================
  END OF PLAN
===============================================================================
