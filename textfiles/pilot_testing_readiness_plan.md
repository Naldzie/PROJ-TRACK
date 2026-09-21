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
  ⚠ NAMING: these F-numbers are THIS FILE'S environment findings and nothing else. The
  normal-doc restudy names its OCR findings OCR-1 / OCR-2 (they were F1/F2 until
  2026-09-18, which collided with these). Do not cross-reference the two sets.

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
  F5 — CONTRACTOR GMAILS IN THE LIST vs CONTRACTOR LOGIN.  ✅ ANSWERED 2026-09-20
       THE ANSWER: contractors CAN log in if they want to. Each contractor record carries
       that contractor's Gmail address, and those accounts are permitted.
       ⇒ CONTRACTOR-ROLE ACCESS IS THEREFORE IN SCOPE FOR THE PILOT, which reverses the
         "treat it as OUT until said otherwise" placeholder below. What follows from it:
         the contractor dashboard, the view-only project pages, the warranty submission
         and profile-edit write paths are all pilot surface and need to be exercised, not
         only the staff/admin side.
       The original framing, kept:
       You said contractor Gmail addresses populate the contractor list but contractor
       ACCESS is uncertain. Worth being deliberate: with Google-only auth, whether those
       people can log in depends on how the app treats a Google account that matches a
       contractor record. If a contractor tries during the pilot and gets in, they see a
       contractor dashboard that is not in the pilot's test plan; if they get a confusing
       rejection, that is a support call to the PMU.
       Decide which, and confirm it in the code rather than by intention. Not yet
       measured — deliberately, because it needs your answer first.

  F6 — WHAT DATA DOES THE PILOT START WITH?  ✅ ANSWERED 2026-09-20 — REAL PMU PROJECTS
       THE ANSWER: the pilot starts with real PMU projects, not seeded demo data.
       WHAT FOLLOWS (noted, not ordered):
         - someone has to ENCODE them, which makes the create-project wizard and its
           document parser the first thing real users touch — the B-queue work is on
           the pilot's critical path, not beside it;
         - CategorySeeder must have run on the pilot database (exactly 4 categories);
         - the S-curve baselines come from real contractor Excel files, which is the
           path that already works (§2 Fact 1);
         - the seeders do NOT need the pass that option (b) would have required.
       The original framing, kept:
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
    browser-proven) and document_parser_learning_loop_plan.md §6.

  ── N1. LIFT THE ABC BLINDING. ✅ BUILT + BROWSER-PROVEN 2026-09-18 ───────────  ✅
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

  ── N2. ⚠ MAY NEVER BE POSSIBLE — SAID BY THE USER 2026-09-20 ───────────────  ❌
    "the request cant really be done, as it might be not possible even on the day the
    pilot test starts".
    ⇒ THIS IS NOT A BLOCKED TASK WAITING ITS TURN. Several items here and in the
      restudy are written as though real scans will eventually arrive and settle
      things. THEY MAY NOT. pdfdocstest5.pdf is the corpus, and probably all there
      will be.
    WHAT FOLLOWS, and it should shape decisions rather than be discovered later:
      · suite-green carries more weight than the restudy has been giving it, because
        the browser check that would have replaced it may never be available for the
        cases the one document does not contain;
      · THE PILOT ITSELF becomes the first real corpus. Under F6 the PMU encodes real
        projects, so the evidence arrives as production use — which makes the learning
        loop's honesty and coverage (restudy §39.2, §39.3) the mechanism that gathers
        what nobody can hand us in advance;
      · any fix that cannot be justified from ONE document plus the suites should not
        be built at all. Fuzzy anchoring was already ranked last for risk; it is now
        also the one with no prospect of a corpus to validate it.
    THE ORIGINAL ITEM, kept:
  ── N2 (as written). GET REAL PMU SCANS BEFORE TUNING ANYTHING ELSE ──────────  ⬜
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

  ── N3. THE OCR PAGE RANKING — ⚠ ESCALATED 2026-09-20: IT IS NOW BROKEN ─────  ⬜
    P-3 fixed OCR-1 (4 of 10 pages read → 10 of 10), and in doing so removed the only
    signal this ranking had. It orders "pages that yielded NOTHING first"; nothing now
    yields nothing, so it sends PAGES 1 AND 2 of every file, every time. The user
    noticed it before I did. This is no longer "cheap to fix, do it if it bites" — it
    bites on every AI-assist press. See normal_doc_parser_restudy_plan.md §27.3.
    THE ORIGINAL NOTE, kept:
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

  P0. ESTABLISH THE REAL BASELINE.  ✅ DONE 2026-09-20, on phase-25-ocr-contrast-stretch.
        PHP  1226 passed / 5 failed / 4587 assertions / 64.5s
        JS   250 passed / 250 / 0 failed  (was 231 before the B-queue's new tests)
      THE FIVE PHP FAILURES ARE NOW VERIFIED BY NAME, not claimed — see P3. No PHP file
      has been touched by any parser work, so these are the same five as ever.
      Method note for the next run: JS suite to a log file, then grep the FILE — piping
      node --test into grep|head has stalled a session.

  P1. THE DEAD ROUTE THAT 500s.  ✅ DONE 2026-09-20 on phase-26-dead-route-and-red-suite.
      REMOVED, two lines from routes/web.php, then route:clear.
      WHAT WAS CHECKED FIRST (a deletion deserves the sweep, not the memory): no
      showAlternative anywhere in app/; no reference to the route name in resources/,
      public/js/, tests/ or routes/ — the only other hits were bootstrap/cache/routes-v7.php
      (the stale cache, cleared), backup/routes/web.php (a dead copy) and AGENTS.md
      (updated). The single 'alternative' hit in public/js/admin was the word in a
      sentence about Excel files.
      AFTER: route:list builds, 330 routes, the neighbours (chart-data, detail, getData)
      intact. PHP suite 1226 passed / the same 5 failures by name. Assertions 4587 ->
      4584 — three fewer, consistent with a test that ITERATES the route table; nothing
      asserted this route's existence or it would have turned red.
      ORIGINAL NOTE, kept: RE-VERIFIED 2026-09-20, and the open question in
      this item is now ANSWERED. routes/web.php:561-562 registers
      admin.projects.show-alternative → ProjectController::showAlternative;
      grep over app/ finds NO showAlternative method anywhere, and grep over resources/
      and app/ finds NOTHING that builds the URL by name.
      ⇒ SO IT IS A SAFE TWO-LINE DELETION, not a choice between deleting and
        implementing. Needs route:clear afterwards (bootstrap/cache/routes-v7.php goes
        stale on route changes — the recurring incident).
      ⭐ AND IT NEEDS NO SAMPLE DOCUMENTS, which as of 2026-09-20 is what distinguishes
        the work that can proceed from the work that cannot (see the session log).

  P2. THE LEGACY MILESTONE CODE — VERIFY BEFORE REMOVING.  ⬜  AGENTS.md CLAIMS it is
      dead and removable. Checked 2026-09-18: MilestoneController is at
      app/Http/Controllers/Admin/MilestoneController.php and ProjectMilestone is
      referenced by routes/web.php, ProjectController.php, ProjectSeeder.php and
      ProjectArchiveTest.php. Not urgent for a pilot; do not delete on the old claim.

  P3. THE FIVE PRE-EXISTING TEST FAILURES.  ✅ ALL FIVE FIXED 2026-09-20 on
      phase-26-dead-route-and-red-suite. THE PHP SUITE IS GREEN: 1231 passed, 0 failed,
      4592 assertions. It has not been green for the life of this plan file.
      None of the five was flaky. Three distinct causes, and the first is an APP BUG:

      (1) ⚠ A REAL DEFECT — SimulateDate WIPED A CLOCK IT DID NOT OWN.
          DashboardOverdueInspectionTest › count badge matches ajax filter list.
          app/Http/Middleware/SimulateDate.php ended every request with a bare
          Carbon::setTestNow(), which CLEARS test-now whether or not the middleware had
          set one. Carbon's test-now is process-wide global state and a feature test's
          travelTo() IS that state, so the first HTTP call in a test silently reset the
          clock and every request after it ran at REAL time.
          That is exactly what the test caught: the dashboard request computed its badge
          at the travelled-to date (correctly, 2), the middleware then wiped the clock,
          and the follow-up filter request ran at today's date — where all three
          fixtures were overdue, so the list returned 3 rows against a badge of 2.
          THE TEST WAS RIGHT ALL ALONG. "count badge matches ajax filter list" is
          precisely the invariant that was broken.
          FIX: save the previous test-now and RESTORE it, undoing only what this
          middleware did. Measured, not guessed — a throwaway probe printed now() inside
          the test as 2026-09-20 (real) before the fix and 2026-08-06 (travelled) after.
          ⚠ PRODUCTION IMPACT: none. Nothing outside tests sets Carbon test-now, so the
          old clear was a no-op in the app. The defect was real but its blast radius was
          the test suite.

      (2) TWO DATE-BOMBS THAT DETONATED THIS MONTH.
          IssueLifecycleTest › default baseline returns earliest baseline when no progress
          and no current month; RiskRegisterTest › its twin.
          Both create a fixture baseline labelled 2026-09 and then assert that the
          EARLIEST baseline wins "when there is no current month". getDefaultIssueBaseline
          prefers a baseline matching TODAY'S month — so the moment the real calendar
          reached September 2026 the fixture WAS the current month, the premise in the
          test's own name stopped being true, and it began returning baseline 3 instead
          of 1.
          FIX: travelTo a month the fixtures do not contain (2026-07), so the tests mean
          what they say regardless of the calendar.
          ⚠ WORTH NOTING: these would have started PASSING again in October, for the
          wrong reason. A test that heals itself on the calendar is worse than one that
          stays red.

      (3) TWO ASSERTIONS ON A PROPERTY THAT DID NOT EXIST YET.
          EmailDraftTest › sending a draft mails the contractor then locks it; and ›
          editing a sent message marks it edited and auto resends.
          AiAnalysisMail sets its subject inside build(), and Mail::fake() captures the
          mailable BEFORE build() runs — so $mail->subject was null, the closure could
          never match, and PHPUnit reported the misleading "The expected mailable was not
          sent". The mail WAS sent: the sibling test that checks only hasTo() passed all
          along.
          FIX: invoke $mail->build() in the closure, which exercises the real subject
          rule (tracking->subject ?: a generated fallback) rather than asserting on the
          draft's own column, which would be circular.
          ALSO REMOVED: three leftover fwrite(STDERR, "DEBUG ...") calls sitting in the
          test file from an earlier investigation.

      ORIGINAL NOTE, kept for the record — VERIFIED BY
      NAME 2026-09-20:
        DashboardOverdueInspectionTest › count badge matches ajax filter list
        EmailDraftTest › sending a draft mails the contractor then locks it
        EmailDraftTest › editing a sent message marks it edited and auto resends
        IssueLifecycleTest › default baseline returns earliest baseline when no progress
                             and no current month
        RiskRegisterTest › default risk baseline returns earliest when no progress or
                           current month
      None is a regression. Still to do: fix or DOCUMENT each with its reason, because a
      permanently red suite hides the next real failure.
      ⭐ NEEDS NO SAMPLE DOCUMENTS.

  P5. ⚠ CORRECTED 2026-09-20 BY THE USER — THE AI TIER IS NOT DEAD, AND THIS ITEM WAS
      BUILT ON A MIS-SCOPED NUMBER.
      WHAT I HAD: "134 answers in 549 calls over seven days, ONE in 59 over the last
      two", carried from local_llm_parser_plan.md §4 and repeated in §2 of this file as
      a pilot risk, with the conclusion that a reviewer pressing "Try AI assist" waits
      50 seconds for nothing ~98% of the time.
      WHAT THE USER SAYS: that failure rate is the PDF S-CURVE path only. For NORMAL
      DOCUMENTS — which is the current priority and the path the pilot will actually
      exercise — AI assist has almost no problem.
      AND A SECOND CORRECTION TO THE CAUSE: the S-curve failures may not be Flash being
      busy at all. They may be the FILE BEING SENT — the one-page full-page BUHI image
      — and there may be a record of that in storage/logs/laravel.log.
      ⇒ THE ITEM IS NOT A UX DECISION ABOUT HIDING A DEAD BUTTON. It is now:
         (a) re-measure the ladder SPLIT BY PATH (normal-doc vs PDF S-curve) from the
             log rather than in aggregate — the aggregate is what created the false
             conclusion;
         (b) check whether the S-curve failures correlate with the BUHI slice
             specifically, which would make it a payload problem, not an availability one;
         (c) only then decide whether anything in the UI should change — and on the
             evidence so far, for normal documents, probably nothing should.
      ⚠ §2's "A THIRD FACT ALREADY ON RECORD" IS SUPERSEDED BY THIS ENTRY. It states the
      aggregate figure as a pilot risk without the path split, and should not be quoted
      again in that form.

      ⚠⚠ UPDATE 2026-09-20 — (a) IS BLOCKED, AND THE BLOCK IS IN THE CODE, NOT THE DATA.
      Step (a) above says to re-measure the ladder split by path FROM THE LOG. That
      cannot be done: the log does not carry the split and never has.
        · parseProjectSlice (normal doc, :474) and parseSCurvePdfSlice (S-curve, :616)
          share ONE sender, which tags every call 'images'
          (GeminiDocumentParserService:1933, and :1935 for the fallback ladder);
        · logGeminiFailure (:282) records only that tag, the model, the status and the
          quota id — nothing that distinguishes the two callers;
        · and the bias runs the wrong way: parseSCurvePdfSlice logs a SUCCESS line
          ('F-A pdf slice response keys', 2000 in the current log) while
          parseProjectSlice LOGS NOTHING AT ALL, so the path the pilot depends on is
          the only one leaving no positive trace.
      ⇒ (a) NOW HAS A PREREQUISITE: tag the two image paths distinctly, and give the
        normal-doc path a success line. Both are small. Detail, including the trap that
        requestTimeout() keys on str_starts_with($where, 'images') so a new tag must
        still start with that word: ai_slice_selection_plan.md §10.2-§10.4.
      ⇒ THIS ALSO MATTERS FOR THE PILOT ITSELF, not just for settling an old number:
        under F6 the PMU encodes real projects, so the pilot IS the first real corpus.
        Without the tagging, the pilot will generate exactly the same unsplittable
        aggregate and the question will still be open afterwards.
      ⚠ AND IT HAPPENED AGAIN IN BETWEEN: the retired figure was re-quoted on
        2026-09-20 (to the user, and four times inside ai_slice_selection_plan.md)
        despite this entry already existing. The cause is that the SOURCE
        (local_llm_parser_plan.md §4) carried no caveat — that has now been fixed at
        the source, which is the only place a correction reliably survives.

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

  2026-09-21 — FOLDER MOVE + CALENDAR MERGE; AI PER-FILE SELECTION PARKED.
    · THE WORKING FOLDER IS NOW `merge ver19.5\PMU_System` (the teammate's copy).
      develop = phases 23-29 + the teammate's Google Calendar sync
      (phase-30-google-calendar-sync), merged with no conflicts and PUSHED.
      Suites on develop: PHP 1238 passed / 0 failed · JS 267 / 267 / 0.
    · ⏸ PARKED by the user: ai_slice_selection_plan.md §11 (per-file AI counts,
      whole-file sending, the cap raise). Reason: it sidetracks pilot readiness.
      The built S-1..S-4 stay in. P5's log-tagging prerequisite (§10.4(i)+(ii)
      there) is NOT parked but NOT ordered either — offered as optional
      pre-pilot instrumentation.
    · ⚠ NEW PILOT CONSEQUENCES OF THE CALENDAR MERGE (noted, not ordered — they
      belong to P6):
        (a) Login now requests Google's calendar.events scope. Every pilot user —
            contractors too, since F5 put them in scope — sees that consent. If the
            Google Cloud app is still in Testing mode, each pilot Gmail must be a
            listed test user or sign-in is warned/blocked. This sharpens F3.
        (b) ProjectObserver / BaselineScheduleObserver queue a SyncProjectCalendar
            job on every project/baseline save, so F4's "ONE queue worker on the
            admin desktop" is no longer optional for calendar correctness.
    · ✅ P5's PREREQUISITE BUILT (user order, same day): the two AI image paths now
      log under 'images-normal' / 'images-scurve', and normal-doc answers leave an
      'N-A normal slice response' line — so the pilot's own log can answer P5(a).
      Branch phase-31-parser-ai-log-tagging; detail in ai_slice_selection_plan §10.4.
    · User's stated next order of work: UI improvements (front-end design) of the
      parser keyword management modal. P6 (the pilot package) is "not yet".
    · 🔄 KEYWORD MODAL REDESIGN BUILT (user order, same day) on
      `phase-32-keyword-modal-ui` — browser check OWED (user).
      THE ASK: fixed size (it grew/shrank with the row count), a better overall
      layout, and make the actual keyword clear or hide the excerpt.
      WHAT CHANGED (resources/views/admin/_manage_parser_keywords_modal.blade.php +
      NEW public/css/admin/projects/parser-keywords-modal.css, linked mtime-versioned
      in app.blade.php and stafflayouts.blade.php):
        - FIXED HEIGHT: the dialog is min(680px, viewport − 3.5rem), centred; only the
          list scrolls; empty/loading/error states sit centred in the same space.
        - Table → card list. The learned WORDING is each card's headline (large,
          quoted); the box is a "Fills <Box>" chip beside the status pill; seen-count
          and origin on one quiet meta line; the worth-approving verdict is a chip.
        - THE EXCERPT IS HIDDEN by default behind "Show where it was found"; opened,
          it shows the full excerpt with the wording highlighted (<mark>, escaped
          piecewise — the only markup that reaches the DOM).
        - Tabs → segmented control WITH COUNTS; a search box (wording or box name);
          footer says "N suggestions" / "x of N shown". The long explanation is one
          line + an expandable "How review works".
      BEHAVIOUR CHANGES (small, deliberate):
        - The list is fetched ONCE (GET …/parser-keyword-proposals/all, unfiltered)
          and the tabs filter it in the browser — instant tab switches, and counts
          for every tab. The endpoint and its ?status= support are unchanged.
        - Reject is a labelled button (was a trash icon — it has not deleted since
          2026-09-20) and is HIDDEN on rejected rows, where it was a no-op (destroy()
          only sets status='rejected'). Approve on a rejected row reads "Approve
          instead". The confirm text now says the row moves to Rejected.
        - Approve/reject/cache-busting logic is byte-for-byte the old code.
      VERIFIED: node --check on the script; view:cache compiles; ParserKeywordProposal
        + UiPolishLayout + FactoryVerification tests 41/41; a static harness (real
        markup/CSS/JS, stubbed fetch, Bootstrap 5.3.0 as the layouts load it)
        screenshotted in headless Edge — Proposed with an open excerpt, Live, an
        empty Rejected tab (same modal size in all three), and phone width.
      ⬜ BROWSER CHECK OWED (user): open it from admin AND staff; approve one, reject
        one (the counts move and the row changes tab); open an excerpt; search.
      ✅ BROWSER-CONFIRMED by the user 2026-09-21 ("confirmed").
    · 🔄 DELETING REJECTED WORDING BUILT (user order, same day) on
      `phase-33-keyword-soft-delete` (off phase-32) — browser check OWED (user).
      THE ASK: let users delete rejected keywords (soft delete), plus an admin tab
      for deleted keywords with restore / permanent delete.
      ⚠ THE TRAP, and how it is avoided: collect() looked rows up WITHOUT trashed
        ones, so a soft-deleted rejection would be invisible and the next correction
        would create a NEW proposed row — the 2026-09-20 "no memory of the verdict"
        defect, reintroduced by tidying. collect() now uses withTrashed() and treats
        a trashed row as refused. SEMANTICS:
          Delete (admin + staff) — REJECTED rows only (422 otherwise); leaves the
            desk, STAYS refused.
          Restore (admin) — back to the desk as Rejected; only trashed rows (422).
          Delete permanently (admin) — only trashed rows (422, so no one click
            erases a row still on the desk); the loop FORGETS the phrase, and a
            future correction may propose it again.
      SERVER: ParserKeywordProposalController trash / getDeleted / restore /
        forceDestroy + ensureAdmin(); routes — staff: DELETE …/{id}/trash; admin:
        …/{id}/trash, GET …/deleted, PATCH …/{id}/restore and DELETE …/{id}/force
        (both ->withTrashed()). Staff on the admin URLs are turned away by the role
        middleware; ensureAdmin() is the second gate. Each action writes an
        activity-log entry.
      UI: Rejected rows get "Delete"; an admin-only "Deleted" tab (rendered by Blade
        from the user's role, fetched from /admin/…/deleted) shows deleted rows with
        "Restore" and "Delete permanently", the deletion date, a dashed red card.
        "All" still means everything ON THE DESK — deleted rows only in their tab.
        Confirm dialogs say what each delete keeps and what it loses.
      VERIFIED: ParserKeywordProposalTest +6 (19/19); three mutations (collect
        without withTrashed, the rejected-only guard, the trashed-only force guard)
        each turn a test red; route:list shows the 5 routes; node --check; headless
        Edge screenshots of the Rejected tab, the Deleted tab and phone width.
        PHP 1244 passed / 0 failed.
      ⬜ BROWSER CHECK OWED (user): as STAFF — delete a rejected row (no Deleted tab
        visible). As ADMIN — the row is in Deleted; restore it (back in Rejected);
        delete it again, then delete permanently (gone from every tab).
      ✅ BROWSER-CONFIRMED by the user 2026-09-21 ("confirmed the browser check").
    · MERGED 2026-09-21 on the user's order: phase-31-parser-ai-log-tagging,
      phase-32-keyword-modal-ui and phase-33-keyword-soft-delete → develop.
      The keyword desk work is CLOSED. Next is the user's call — P6 (the pilot
      package) remains "not yet"; F1/F2/F3 and the calendar consent (above) are
      still the open pilot blockers.
    · 🔄 S-CURVE LAYOUTS MODAL — SAME OVERHAUL (user order, same day) on
      `phase-34-scurve-layout-modal-ui` (off develop) — browser check OWED (user).
      SCOPE: the Review S-Curve Layouts desk only (resources/views/admin/
      _manage_scurve_headers_modal.blade.php, top modal + its list renderer). The
      TEACH modal in the same file is untouched (its SCH_ROLE_BADGE is still used
      there, so it stays). NEW public/css/admin/projects/scurve-layouts-modal.css,
      its own file so the confirmed keyword modal is never touched by changes here;
      linked mtime-versioned in both layouts.
      WHAT CHANGED: fixed height (min(720px, viewport − 3.5rem)), only the list
        scrolls, centred empty/loading/error states · one card per layout, headline =
        the contractor ("Any contractor (global layout)" when blank), PDF/Excel +
        status pills, seen/origin meta line · a READINESS line replaces the Mapped /
        Needs teaching badges + the map summary: green "Column map ready · page 1:
        M1–M12" / "DESC=B · M1=C…", or amber "Needs teaching" / "Months not
        numbered" with the reason · the captured header block is COLLAPSED behind
        "Show captured header block" and built on first open; restyled with own
        classes (the app's global .badge styling had rendered R1/PDF/Mapped as big
        grey buttons) · segmented tabs with counts, search by contractor, footer
        count, one-line help + "How review works" · Reject is a labelled button.
      BEHAVIOUR: the list is fetched ONCE (…/scurve-header-proposals/all) and filtered
        in the browser; _schRowsCache now holds every row, which the Teach modal's
        lookup-by-id needs anyway. Approve stays disabled on unreadable rows (no map,
        or an unnumbered PDF map) exactly as before; the server still refuses them.
        Reject semantics unchanged (soft delete; a later failed import may capture
        the layout again). loadScurveHeadersForManagement keeps its name — the Teach
        save path calls it.
      FOUND AND FIXED WHILE CHECKING: at phone width an opened header block widened
        the whole card past the screen (column-stacked flex item sized by the
        table); the card body is now pinned to 100% there so the table scrolls.
      VERIFIED: view:cache compiles; node --check on the scripts; JS 267/267/0 (incl.
        the test that reads this Blade file); PHP 1246/0; headless Edge screenshots
        against a local server with the real scurve-teach-core.js — Proposed with a
        grid open (ready + not-numbered cards), Live (Excel map summary), phone width.
      ⬜ BROWSER CHECK OWED (user): open the desk (admin and staff); open a header
        block; Teach a row (the Teach modal opens as before and saving reloads the
        desk); approve a ready row; reject one; search a contractor.
    · 🔄 S-CURVE LAYOUTS: REJECTED TAB + DELETE / RESTORE / PERMANENT DELETE (user
      order, same day: "add the tab for reject and have the same soft delete and hard
      delete like in the keyword management") on `phase-35-scurve-layout-reject-delete`
      (off phase-34) — browser check OWED (user).
      ⚠ A BEHAVIOUR CHANGE, NOT ONLY A TAB. Reject used to SOFT-DELETE the row, and
        collect() could not see deleted rows — so the next failed import of the same
        file re-captured the layout as a fresh Proposed row (the confirm even said
        "Future failed imports can capture it again"). That is the defect the keyword
        desk fixed on 2026-09-20, and a Rejected tab is meaningless without the fix.
        NOW: Reject sets status 'rejected' and KEEPS the row (Rejected tab); a repeat
        capture only raises its count (response carries refused:true) — never
        re-proposed, never auto-lived. A rejected LIVE layout stops being read (the
        reader consults status 'live' only).
      SEMANTICS (mirror of the keyword desk):
        Delete (admin + staff) — REJECTED rows only (422 otherwise); leaves the desk,
          STAYS refused.
        Restore (admin) — only trashed rows (422); returns ALWAYS as Rejected. This
          matters for LEGACY rows: rejects made before today are soft-deleted rows
          whose status still reads proposed or live — they now appear in the admin's
          Deleted tab, and restoring one must not put a layout back before the reader.
        Delete permanently (admin) — only trashed rows (422); the loop forgets it and
          the next failed import captures it again as Proposed.
        Teach stays on Rejected cards (teaching is how a rejected capture becomes
          useful): a variant is a new Proposed sibling; "replace" drops the row to
          Proposed — an explicit reviewer action, so that un-rejecting is intended.
      TWO TRAPS THIS TABLE HAS THAT THE KEYWORD TABLE DID NOT:
        (1) a legacy soft-deleted reject may already have a second, ACTIVE twin with
            the same hash (captured by a later import). ScurveHeaderProposal::
            findForCapture() prefers the ACTIVE row and falls back to a deleted one
            only when none exists — a plain withTrashed()->first() would bump the dead
            row and orphan the live one. Used by collect() AND storeAiDerived().
        (2) storeAiDerived() backfills a map-less / unnumbered row and drops it to
            proposed — on a rejected row that would UN-REJECT it with nobody deciding.
            It now returns a refused row untouched (isRefused(): rejected or trashed).
      SERVER: SCurveHeaderProposalController destroy (now status), trash / getDeleted /
        restore / forceDestroy + ensureAdmin(); getAllForManagement accepts
        ?status=rejected; model findForCapture() + isRefused(). Routes — staff: DELETE
        …/{id}/trash; admin: …/{id}/trash, GET …/deleted, PATCH …/{id}/restore and
        DELETE …/{id}/force (both ->withTrashed()). Activity-log entry per action.
      UI: Proposed · Live · Rejected · All | Deleted (admin, Blade-rendered from the
        role). Rejected cards: "Approve instead" (still disabled when unreadable),
        Teach, Delete. Deleted cards: Restore, Delete permanently, the deletion date,
        dashed red card, no readiness line. Confirm texts say what each keeps/loses.
      VERIFIED: ScurveHeaderProposalTest — the old soft-delete reject test REWRITTEN to
        the new verdict, +7 new (95/95); SIX mutations each turn a test red (collect
        refusal, no trashed fallback, trashed not deprioritised, storeAiDerived guard,
        restore keeping the old status, the rejected-only trash guard); route:list
        shows the 5 routes; node --check; headless screenshots (Rejected, Deleted,
        phone). PHP 1254 passed / 0 failed · JS 267/267/0.
      ⬜ BROWSER CHECK OWED (user): reject a layout (it moves to Rejected); re-import
        the same file (it does NOT reappear in Proposed); Delete it as staff (no
        Deleted tab); as admin, restore it (back in Rejected), delete again, delete
        permanently (gone; a re-import then captures it fresh). Also: older rejected
        layouts should now be visible in the admin's Deleted tab.
      ✅ BROWSER-CONFIRMED by the user 2026-09-21 ("all browser checks confirmed") —
        covers BOTH phase-34 (the layouts desk overhaul) and phase-35 (this entry).
        Neither is merged to develop yet (no merge order given).

  2026-09-20 — THE PARSER SIDE ADVANCED A LONG WAY; THE ENVIRONMENT SIDE DID NOT MOVE.
    Everything this session happened in the normal-doc parser (restudy §§12-24). The
    short version for this file:
      ✅ OCR-1 IS CLOSED. The test scan went from 4 of 10 pages read to 10 OF 10, and the
        ABC now fills — §5 N2's premise ("developer samples are not representative") is
        untouched, but the parser is far better than it was this morning.
      ✅ P0 done, P1 and P3 upgraded from claimed to verified (above).
      ⚠ SIX PARSER FIXES ARE BUILT AND BROWSER-UNPROVEN (B-1..B-5 + B-1b, restudy §§21-24).
        They cannot be proven with what exists: there are no further sample documents,
        and on the one file we have, B-1 now empties the budget source, so the very
        control B-2 rewrote never renders.
      ⇒ §5 ITEM N2 ("get real PMU scans") IS NO LONGER JUST THE NEXT PARSER STEP. It now
        BLOCKS the proof of six shipped changes as well as N3/N4/N5. It is the single
        highest-value thing the user can do for the parser track, and it costs a request
        rather than a day's work.
    WHAT THIS MEANS FOR ORDERING: the pilot work that needs NO sample documents — P1, P3,
    P6, and the F1/F2 decisions — is now the queue that can actually move. Marked
    ⭐ above where that applies.

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
