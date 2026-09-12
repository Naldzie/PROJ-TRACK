# AI FALLBACK FOR PDF S-CURVE (AI-FOR-PDF)
# Parent refs: textfiles/document_parser_accuracy_improvement_plan.md (P1/P2 PDF batch, E2/AI-6 notes)
#              textfiles/document_parser_ai_fallback_plan.md (AI-1..AI-6, E2 Excel AI — the template to copy)
#              textfiles/document_parser_excel_learning_loop_plan.md (E1/Phase-A — why PDF cannot reuse click-to-teach)
# Date: September 11, 2026 — Status: PLANNED (no code yet)
# Branch on build: phase-<n>-pdf-ai (FROM phase-5-parser-accuracy P2 state; Rule 12, no merge without OK).

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

System Name / Client / Framework
  Construction Project Monitoring System (PROJ-TRACK) for CSPC PMU.
  Laravel 12 / PHP 8.2 / MySQL (live), SQLite :memory: (tests).

Why this file exists
  Hand-tuning the PDF S-curve reader (P1/P2 geometry: desc bounds, center rules,
  continuation inheritance) gives diminishing returns: every fix is positional
  guesswork on scattered page pixels, while Excel has neat clickable columns.
  User decision 2026-09-11: skip further manual PDF geometry work and let the AI
  read weak PDFs directly — the same way E2 already reads weak Excel files.

  The Excel "save the layout" idea carries over in SPIRIT but not in MECHANICS:
  Excel saves column letters (desc=C, M1=I+J) plus a clickable teach grid.
  PDFs have no columns, so there is nothing to click. What gets saved instead is
  VOCABULARY (new month/desc words the AI discovers), which makes the free
  offline reader smarter every time. Full answer-fingerprint reuse is deferred
  (see §10 F-PDF2).

Frontend convention (IMPORTANT)
  App UI JS/CSS lives in `public/` linked via asset('...') — NO @vite for new features.

Position in the pipeline (ascending cost/risk, unchanged)
  Tier 1 local PDF rules (free/offline, P1/P2 as built) → opt-in AI fallback
  (THIS FILE, quota/latency/data-leaves) → vocabulary learning (§7 Phase C).
  Tier-2 OCR and manual geometry tuning stay parked behind this file.

Sensitivity basis (same as AI-1..AI-5, user-approved)
  S-curve sheets are public-bidding schedule data. Slice-only (1–2 confusing
  pages as small images, never the whole file) + unsure-only (local reader
  admits weakness first) gates stay. Predictions key/model untouched.

===============================================================================
  2. STATUS SUMMARY
===============================================================================

  PDF rule reader (P1 + P1-follow-up + P2)   ✅ BUILT, NOT committed (phase-5-parser-accuracy)
    Months/percentages correct on both proof files; activity names generic;
    10-month PDF silent where Excel warns (P2-c drops the summary evidence).
  Excel AI (E2) + normal-doc AI (AI-1..AI-5) ✅ BUILT + LIVE-PROVEN (Flash, own key)
  PDF AI (THIS FILE)                          ⬜ PLANNED — no code yet
  Blinding                                    🔒 ON (demo; PDF track untouched by it)

Proof files (git-ignored sampledocs/, never commit)
  - 10-month PDF (Excel twin: TRUE activity curve 5/10/20/30/50/70/80/90/95/100
    + 4 summary warnings M5–M8). PDF today: curve ~correct (19.99-style dust),
    0 warnings, generic Activity N names.
  - 6-stage PDF (Excel twin: TRUE curve 5/20/40/60/80/100). PDF today: percentages
    exact, but 2–3 names per month where Excel has 1–2.

===============================================================================
  3. PROBLEM STATEMENT — Plain Language
===============================================================================

  The PDF reader finds the NUMBERS but loses the WORDS:
  - Months and percentages come out right (or nearly — 19.99 vs 20.00 dust).
  - Activity names come out as "Activity 1, Activity 2..." instead of
    "Mobilization Works", and each month lists too many of them.
  - The 10-month PDF shows no warnings while its Excel twin shows 4, because
    the page-2 summary row is thrown away instead of kept for comparison.

  Fixing this by hand means nudging invisible pixel boundaries per file —
  slow, fragile, and every new contractor layout re-breaks it. The AI already
  proved (E3 model verdict) it can compute the true curve from a slice, so we
  point the same AI at PDF pages instead of tuning geometry further.

===============================================================================
  4. DECISIONS BANKED (user, plain words, 2026-09-11)
===============================================================================

  Decision F1 — Button only (opt-in, never automatic).
    The AI runs only when the user clicks "Try AI assist" on a weak PDF read.
    Clean files never call AI: no quota spent, no waiting, nothing leaves the server.

  Decision F2 — Numbers + names.
    The AI returns monthly numbers AND real activity names. Everything lands in
    the preview for user review — never auto-locked. Names are checked against
    words actually on the page so made-up names get caught.

  Decision F3 — Save the words (Option 1 now; full-answer reuse later).
    When the AI discovers new wording ("STAGE means month", "SCHEDULED TASKS
    means work names"), those words are saved through the existing keyword
    learning loop (proposed → approve → live). Next identical file reads with
    no AI call. Full answer-fingerprint reuse (Option 2) is deferred to §10
    F-PDF2 — words-first makes the free reader smarter; fingerprints can follow
    only if the same contractor keeps resending identical PDFs.

===============================================================================
  5. WHERE THE HOOKS GO (restudy before build — copy, don't reinvent)
===============================================================================

  Server template (E2 Excel AI, proven)
    - app/Services/AI/GeminiDocumentParserService.php: parserKey()/parserModel()
      seam (own Flash key), callGeminiWithImages(), presence-gate + homework +
      mirror/unverified-note idioms, fallback-to-Lite + provenance flag.
    - SCurveImportController::parseWithGemini (dual input, 201/422 shapes,
      Http::fake test idiom). PDF adds a sibling method (PDF slice in, months
      + names out) — never rewrites the Excel path.
    - Validation to reuse verbatim: monotonic clamp, force-last-100, summary
      reference+warn (same plain wording + 1.0 threshold as Excel 2b/P2-e),
      contract sanity, unknown-key dropping.

  Client template (AI-3 normal-doc slice + merge, proven)
    - Weakness trigger: months < expected OR generic-name ratio high OR
      unresolved summary gap — button renders (S1 remove-first pattern),
      contractors hidden, one POST per click (busy + stale-run guarded).
    - Slice: weakest-2-pages canvas render (~150 DPI via toDataURL); PDF pages
      render client-side via the vendored pdf.js (no server renderer by design,
      same as AI-3). Scanned/image PDFs reuse the OCR retry chain first —
      AI sees the best pixels we have.
    - Merge: AI months into preview with "AI-assisted" badges + provenance
      (main vs backup model suffix); Apply stays manual.

  Vocabulary save (L1-L3 loop, proven)
    - New month/desc words from verified AI answers harvest as proposals
      (source badge, e.g. 'AI-assisted'; ≥3 auto-live OR one-click approve;
      staff+admin per L0a). Miner + caps + trust rules unchanged.
    - NEVER learns from unapproved/applied-only AI text (ambiguity must not
      become "knowledge" — L0c carries over).

===============================================================================
  6. IMPLEMENTATION STEPS (in order; each proved before the next)
===============================================================================

  PHASE F-A — Server: PDF slice service + unit tests (no UI).
    - GeminiDocumentParserService::parseSCurvePdfSlice(array $images): ?array.
      Strict-JSON prompt (months[{month_index, planned_progress_percent,
      activities[]}], contractAmount, optional summaryBlock) + two-series
      homework (activity math vs author summary held apart — E2 parity lesson)
      + schema-enforced verdict (verified/compared/unverified, never silent).
    - PHP-side validation: month indices 1..N ascending, percents 0–100,
      monotonic clamp + force-last-100, contract > 0 sanity, names allowlist
      (checked against page words; unmatched names flagged, never trusted).
    - Tests (Http::fake): success-shape, fence-wrapped JSON, homework-mirror
      note fires, summary-vs-activity gap warns, malformed/500/empty-key
      decline, image cap asserted. No commit without OK.

  PHASE F-B — Endpoint + routes + contract tests (no UI).
    - SCurveImportController::parsePdfWithGemini (images[] client slices |
      image document_id; 201/222-style shapes mirroring parseWithGemini;
      contractor blocked; per-slice + total byte budgets; PDFs-as-source
      declined with the slice instruction — no server renderer).
    - Routes POST /{staff,admin}/s-curve/parse-pdf-gemini (role middleware,
      contractors: nothing). `route:clear` after adding (recurring incident).
    - Tests: admin+staff success, contractor redirect, AI-miss 422, stored
      image/PDF/ghost document_id, byte-ceiling decline. No commit without OK.

  PHASE F-C — Button + slice + merge (client only).
    - Amber opt-in button on the PDF import preview (weak states only: months
      short OR generic-name share high OR summary gap; clean files silent).
    - Weakest-2-pages slice (unread scanned → scanned → first pages), one POST
      per click, mapped transport messages surfaced (E2 message-parity lesson:
      quota/busy/dead-model speak instead of generics).
    - Merge with AI-assisted badges + backup-model suffix; mapped failure
      voices; Apply manual. JS trigger fixtures. No commit without OK.

  PHASE F-D — Vocabulary save (Phase C wiring).
    - Verified AI months/names (kept or corrected at Apply) harvest NEW
      month/desc words as proposals with AI source badge; existing caps/trust
      (≥3 auto-live, one-click approve/reject, staff+admin) unchanged.
    - Tests: new-word surfaces / known words skipped / hallucinated names
      (absent from page text) mine nothing / never-auto-live guard holds.
      Full + JS suites green with clean-tree check. No commit without OK.

  PHASE F-E — Browser proof on both files (user, closes the phase).
    - 10-month PDF: curve matches Excel (5/10/20/30/50/70/80/90/95/100 ±0.01
      dust) + same 4 warnings + real names with 1–2 per month.
    - 6-stage PDF: 5/20/40/60/80/100 + Excel-matching names per month.
    - New-word row appears in the desk (AI-assisted badge) → approve →
      re-import reads without AI. THEN the phase is closed.

===============================================================================
  7. OPEN QUESTIONS / STILL TO RESOLVE
===============================================================================

  - Weakness threshold values (generic-name share cutoff, months-short cutoff):
    finalize at F-C with live numbers from the two proof files — not guessed now.
  - Scanned-PDF order of operations (OCR retry first, AI second): confirmed in
    principle; exact handoff line decided at F-C build.
  - Slice DPI/size ceiling for 2-page PDF renders: copy AI-3 numbers first,
    tune only if quality complaints appear.

===============================================================================
  8. DECISION LOG
===============================================================================

  Decision 1 — AI-first for PDF, geometry tuning parked.
    Context: P2 fixed months but names groupings + silent-summary remain; each
      further geometry fix is positional guesswork per file.
    Options: (a) continue P2-f/desc hand-tuning; (b) AI reads weak PDFs.
    Decision: (b). Rationale: E3 proved Flash computes true curves from slices;
      PDF has no clickable columns so manual teaching cannot scale.
    Consequences: P2-f rounding audit + desc-bound tightening park behind F-E
      (revive only if AI-reviewed evidence demands it).

  Decision 2 — Words-saved, not answers-saved (for now).
    Context: "save the layout" means columns for Excel but PDFs have none.
    Decision: save discovered vocabulary via the keyword loop (F-D); full
      answer fingerprints deferred to F-PDF2.
    Consequences: every AI success makes the FREE reader smarter; quota spend
      ratchets down per contractor layout without storing full readings.

===============================================================================
  9. FILES TO TOUCH (on build — NOT NOW)
===============================================================================

  - app/Services/AI/GeminiDocumentParserService.php (parseSCurvePdfSlice + prompt)
  - app/Http/Controllers/SCurveImportController.php (parsePdfWithGemini)
  - routes/web.php (2 mirrored routes + route:clear)
  - public/js/admin/projects/scurve-pdf-parser.js (button + slice + merge)
  - Harvest/miner wiring for new month+desc words (reuse L1-L3, no new table)
  - tests/Feature/Services/AI/GeminiSCurvePdfServiceTest.php (NEW)
  - tests/Feature/SCurvePdfGeminiTest.php (NEW)
  - tests/js/scurve-pdf-parser.test.mjs (trigger fixtures)
  - This file (build record on completion)

===============================================================================
  10. FLAGGED FOR FUTURE
===============================================================================

  - F-PDF2 Full answer-fingerprint reuse: store verified AI readings keyed by
    file-shape hash for zero-AI repeat imports. Needs: hash recipe for PDFs,
    staleness story, approval UX. Build only if one contractor's identical PDFs
    make words-alone insufficient.
  - F-PDF3 Tier-2 OCR for S-curve PDFs (eng+osd auto): still queued as engine
    work; F-C reuses whatever OCR yields today, nothing waits on it.
  - F-PDF4 P2-f rounding audit + desc-bound tightening: parked (Decision 1);
    revive with AI-reviewed evidence if F-E proof shows residual dust.

===============================================================================
  11. SESSION LOGS
===============================================================================

  2026-09-11 — File created (plan only, no code).
    - User: skip further manual PDF geometry; go AI directly (button-only,
      numbers+names, save-words). Ordered dedicated md file + back-references.
    - No code, no commit — NEXT = Phase F-A on go-ahead.

  2026-09-11 — F-A BUILT (code, NOT committed per user; restudy decisions:
  button on failures AND weak reads, drop-hallucinated-names, 2 pages/slice).
    - Service: parseSCurvePdfSlice(images, pageWords) — images sender with Lite
      backup + provenance (same as normal-doc); buildSCurvePdfPrompt (two-series
      homework + activities/monthLabels copied-as-printed-only); validatePdfCurve
      (sanitizeCurvePoints/Summary/Amount/Contract + recompute-decline reuse;
      no sheet-presence audit — no CSV for image slices; no layout keys — PDFs
      have no columns); sanitizeActivityNames (printed-words check, full-word
      always, prefix only for 5+ char words so "act" can't ride "activity");
      sanitizeMonthLabels (F-D fuel, shape-only). Caught pre-green: none —
      first green held (tightened the prefix rule by review before running).
    - Tests (+10 in GeminiDocumentParserServiceTest): success+names+Checked,
      hallucinated-drop, summary-gap warn, fabricated-homework decline,
      empty/key decline (both keys nulled + assertNothingSent), malformed,
      thin-reply, 3-image cap, summary adoption flagged, fences. All green.
    - Suites: service 49/49; JS 109 + 2 intended blinding fails (blinding ON);
      full 1130 passed / 7 failed = same 7 pre-existing (clean-tree verified via
      stash: identical 7 — zero regressions). No commit per user.
    - NEXT = Phase F-B (endpoint + routes) on go-ahead.

  2026-09-11 — F-B BUILT (code, NOT committed per user).
    - Endpoint: SCurveImportController::parsePdfWithGemini (images[] only +
      optional pageWords[] — no file/document_id since the server holds no PDF
      renderer; compact slice resolver mirroring DocumentParserController
      budgets 10MB/30MB PNG-JPEG-WebP incl. data-URIs; monthLabels stripped for
      F-D; aiAssisted + fallbackModel provenance; 422 shapes mirroring
      parseWithGemini). No map consult (Excel column maps don't apply to PDFs).
    - Routes: POST /{staff,admin}/s-curve/parse-pdf-gemini (contractors: nothing;
      route:clear run — recurring incident class).
    - Caught pre-green (1, test-side): bare Http::sequence() never registers as
      the fake — requests escaped to the real network (status-0 decline). Fixed
      with the mapped Http::fake(['*generativelanguage*' => sequence]) idiom +
      Retry-After 0 + explicit model config (same as DocumentParserGeminiTest).
      Lesson: sequences always ride inside Http::fake.
    - Tests (+8 SCurvePdfGeminiTest): admin/staff success (months + kept names +
      aiAssisted + monthLabels stripped), discipline (empty/>3/bad-mime/empty),
      data-URI accept, AI-miss 422, hallucinated-drop end-to-end (pageWords
      wiring proof), contractor redirect both routes, Lite provenance.
    - Suites: endpoint 8/8; full 1138 passed / same 7 pre-existing (clean-tree
      proven at F-A — unchanged since). No PHP regressions. No commit per user.
    - NEXT = Phase F-C (button + slice + merge) on go-ahead.

  2026-09-11 — F-C BUILT (code, NOT committed per user).
    - Trigger: core isWeakPdfCurve(parsed) pure + exported (months-short /
      generic-names ≥50% / summary-gap >1.0; empty = failure not weak; nameless
      weekly curves never flag). +3 fixtures.
    - Slicer: window.buildSCurvePdfSlices(2) in the PDF wrapper — table pages
      (P2-0 header hits) first, then leading pages; ~150 DPI, 4000px budget,
      toDataURL PNG; returns slices + per-page words. Stale-file guard by
      reference. DOM/canvas paths = browser proof covers them.
    - Offer + merge: beside-button wrap (failures, same home as Excel) +
      in-modal link (weak successes — modal buries the button); one POST per
      click to parse-pdf-gemini (images + pageWords JSON); mapped failure
      voices surface; backup-model note appended in scurveStashWarnings;
      wizard + detail flows rejoin their normal modal paths.
    - Caught pre-green (2, both fixed before green): (1) normalizeSCurveMonths
      dropped string-form AI activities (a.activity_name on a string) — now
      accepts bare names (+1 import fixture); (2) detail tab loaded 3 of the 4
      touched scripts without $jsv versioning (stale-JS ghost class from UX-2b)
      — versioned, view:cache clean.
    - Suites: JS 113 + 2 intended blinding fails (suite +4, all green); full
      1138 passed / same 7 pre-existing (clean-tree proven at F-A). No commit.
    - NEXT = user browser proof (button on failure + weak 10-month/6-stage;
      merged names + notes) → then F-D (vocabulary save) on go-ahead.

  2026-09-11 — F-C POLISH from browser proof (code, NOT committed per user).
    User findings on real files: (a) Excel cross-check link also rendered on PDF
    imports (dead — Excel endpoint declines PDFs); (b) AI names recorded but not
    shown on the month list; (c) in-modal link had no loading/error states.
    - (a) Cross-check offer gated to non-PDF flows (wizard mode + detail
      docType); modal-open hook needs no change (reads the same ctx, cleared
      every import start). PDF flows show only the PDF AI link.
    - (b) normalizeSCurveMonths falls back to joined activity names when
      description is empty (rule months always carry description — untouched;
      heals Excel-AI imports too). Caught pre-green: my own edit left the old
      activities block duplicated below the new return (dead code) — removed.
    - (c) In-modal link now carries the approved recommended wording + its own
      spinner/disabled loading + persistent error box (cross-check pattern);
      _trySCurvePdfAiAssist drives the clicked button and paints errors
      in-modal (toast kept as backup).
    - Suites: JS 113 + 2 intended blinding fails; full 1138 passed / same 7
      pre-existing. No commit.
    - OPEN (needs user's 10-month AI notes text): the 4 summary warnings are
      still absent on the AI 10-month curve — likely the AI never reported the
      summary block. Slice/prompt fix queued after the evidence lands.

  2026-09-11 — LITE FALLBACK REVERTED on the PDF path (code, NOT committed).
    User evidence: log showed "fallback to Lite" firing on PDF slices + a
    copied-summary result — the exact failure Excel excluded Lite for (E3: Lite
    copies author summaries instead of computing). The rescue rode in with the
    shared images sender from normal-doc; F-A never excluded it.
    - Fix: callGeminiWithImages gains $allowFallback=true (normal-doc default
      unchanged — its rescue + provenance tests still green); parseSCurvePdfSlice
      passes false (Flash-or-nothing). Overload/quota/dead-model now throw
      their mapped voices straight to the endpoint 422 → in-modal error box.
      fallbackModel stays null on PDFs, so no backup note ever prints there.
    - Tests: +service no-fallback case (busy,busy → mapped throw, exactly 2
      requests, lastFallbackModel null); endpoint fallback test rewritten to
      assert the honest 422 + mapped voice + 2 requests. Consequence accepted:
      spent Flash daily quota reads "try again tomorrow" instead of a Lite copy.
    - Suites: AI suites 66/66; JS 113 + 2 intended blinding fails; full 1139
      passed / same 7 pre-existing. No commit.
    - CLOSED by user evidence the same day: the 4 warnings + Checked note DO
      appear on the Flash 10-month run (user pasted all four) — the earlier
      silence was the Lite copy, not a slice gap. No slice/prompt fix needed.
    - SIDE OBSERVATION (needs one browser check): a Flash reply carried
      months:11 on the 10-month file — the sanitizer clamps values but never
      caps the count. If the modal ever imports 11 months, a count-sanity guard
      gets built.

  2026-09-11 — BROWSER PROOF (user, live runs on both proof files).
    - 10-month PDF via AI: TRUE curve (5/10/20/30/50/70/80/90/95/100) with
      per-month counts matching Excel (1,1,1,1,2,2,1,1,1,1) + all 4 summary
      warnings + Checked note. F-C merge + description fallback proven (names
      recorded; list shows them).
    - 6-stage PDF via AI: 5/20/40/60/80/100 with Excel-matching counts
      (1,2,1,2,1,1); names accurate in edit/manage. Rule-only read still shows
      generic Activity N over-assignment — expected, AI is the remedy.
    - Quota voice proven live: Flash per-day 429 surfaces "Today's AI allowance
      is used up. Please try again tomorrow." (log: GenerateRequestsPerDay-
      PerProjectPerModel-FreeTier) — the Flash-or-nothing revert behaves as
      designed. All three AI buttons share the parser meter and rest together.
    - Status: F-A..F-C + polish + revert COMMITTED (see log below); F-E proof
      substantially done (both files merge correctly). Remaining: F-D
      vocabulary save + months:11 count check, on go-ahead.

  2026-09-11 — F-D BUILT, month words only (code, NOT committed per user).
    Option 1 as approved: verified AI answers teach month-header WORDS (not
    positions — PDFs have no stable columns). Desc-label learning queued (needs
    a prompt-reported descLabel + the same plumbing — small follow-up).
    - Backend: 'scurveMonth' joins LEARNABLE_FIELDS (single shared controller
      serves staff+admin; desk renders it generically; same trust rule ≥3 /
      one-click). F-B endpoint now RETURNS monthLabels (was stripped — the
      F-B test updated to assert their presence).
    - Core: matchMonthLabel(str, monthWords) — taught "SEGMENT 1" generalizes
      to "SEGMENT N" (word ≥2 chars + number 1..12); lowest priority, omitted =
      today exactly. Threaded detectHeaderColumns ← parseSCurvePdf(opts).
      Weekly path untouched (monthly only).
    - Wrapper: parseSCurvePdfFile loads live scurveMonth rows (reuses the L3
      loader; any failure = today's behavior) and forwards them.
    - Harvest: scurveHarvestPdfMonthLabels fires on every AI merge — skips
      rules-known labels (hardcoded check, no learned list so live words still
      corroborate), cap 12, AI-assisted source, fire-and-forget, values never
      leave the browser. Contractors guarded.
    - Caught pre-green (2, both mine): (1) e2e fixture split header/rows into
      two pages (positional merge needs one page) — fixed to one sheet;
      (2) harvest edit first anchored a twice-present block — re-anchored on
      the PDF-only toast text.
    - Tests: +1 PHP (scurveMonth collect, AI source stored); +2 core fixtures
      (taught-word hit/tie/empty + e2e miss→hit); endpoint monthLabels assert.
      Suites: related 70/70; JS 115 + 2 intended blinding fails; full 1140
      passed / same 7 pre-existing. No commit.
    - NEXT = user browser proof (novel month word → AI → proposed row →
      approve → re-import reads without AI) → then months:11 count check.

  2026-09-11 — LOOP PROOF RUN: learning proven, DELIVERY failed both flows.
    Full record (scripts, evidence, suspects): textfiles/pdf_ai_loop_proof_tests.md
    (new file, single home for all proof results — this entry is the pointer).
    SEGMENT specimen (TRUE 5/20/40/65/100): rule misses honestly → AI merges
    the true curve + Checked → desk banks 5 correct AI-assisted rows → all
    approved Live → re-import STILL misses on wizard AND detail.
    Acquitted: harvesting, approval, generalization design (fixtures green).
    Indicted: (a) detail tab never loads the word loader (document-parser.js
    is wizard-only — wrapper guard silently yields nothing there; PROVEN by
    code read); (b) stale 24h sessionStorage word cache after table wipe
    (server wipe ≠ browser cache; OPEN, wizard flow). Queued fixes: shared
    loader for the PDF wrapper + approval/cache-bust. Step-6 re-run closes
    B/F-D/F-E in one go. No code yet.

  2026-09-11 — SEGMENT SPECIMEN BUILT (git-ignored sampledocs/, never commit).
    User-approved novel word (SEGMENT) + 5 months for the B/F-D loop proof.
    sampledocs/SCurve-Test-Segment-Novel-Specimen.xlsx: single header row
    (ITEM NO. / DESCRIPTION / CONTRACT AMOUNT / % WEIGHT / SEGMENT 1..5
    single columns), 4 activities one-per-month + Finishing spanning M4+M5,
    monthly pesos 50000/150000/200000/250000/350000 = 1000000, TRUE curve
    5/20/40/65/100, TOTAL anchor row, no summary block (hasSummaryRow false).
    Verified: Excel rule service MISSSES honestly ("No S-curve table found" —
    SEGMENT unknown to strict+relaxed), so the PDF conversion will miss the
    same way → beside-button AI offer. Builder/verifier scripts live in
    C:\temp\opencode (outside the repo, not committed).
    USER STEP: print to PDF in Excel (keeps the text layer) → Ctrl+A-paste
    validity check → rule import must miss → AI assist → "S-Curve Month" row
    for SEGMENT 1 → approve → re-import parses natively, zero AI. No commit.

  2026-09-11 — ROOF PROBE settles A-vs-B: B WINS, guard-only (no code yet).
    User ran AI assist on the Roof PDF (CSPC-Rehabilitation-of-Roof…-pdf):
    TRUE 7-month curve (6.32/24.31/42.30/59.71/77.03/99.14/100, counts
    4,4,4,4,3,4,3) with real names + Checked note + honest mirror note; no
    #REF! contamination, no phantom months (this run: months:7 — the months:11
    sighting stays a one-off on the 10-month file, still watching). Forensic
    (read-only pixel dump + core run: 2 pages, 250+71 items, zero header hits)
    had shown duration labels + percent-shaped cells + TOTAL-row pesos — a
    full family build (A) if needed. Verdict: one AI call reads it perfectly,
    so A parks behind proven need (repeats + quota pressure revive it).
    - Outage postscript: user's 4-hour error storm investigated jointly —
      external web evidence (Sept 10 multi-day flash 503 thread, Sept 1 30-day
      37% 503 on 3.6-flash, Sept 3 outage news) confirms upstream sickness on
      free-tier flash; user's 4/5 scenario-1 retest closed the case (no A/B
      checkout needed; tree stayed clean throughout). Lesson banked: the Lite
      revert unmasked transport failures into honest "busy" voices — correct,
      do not re-mask.
    - B scope FINAL (guard + label, no family, no prompt/quota/trust changes):
      (1) harvest guard skipping duration-shaped labels ("30 days" carries no
      index — banking it would pollute the desk); genuine word-variants flow
      untouched. (2) 'S-Curve Month' human label in the Learned Keywords desk
      (pkpFieldLabel fallback shows raw "scurveMonth" today — verified
      read-only; cosmetic one-liner riding with B).
    - Desk placement (user Q, answered): F-D rows live in Learned Keywords
      (the vocabulary desk: wording → field, generic rows) under Field
      `scurveMonth` — NOT in S-curve Layouts (columns-only: header blocks +
      letter maps + teach grid; a word row has nothing to display/teach there).
      Split stands: words → Learned Keywords; columns → S-curve Layouts.
    - NEXT = build B on go-ahead.

  2026-09-11 — B BUILT, guard + label (code, NOT committed per user).
    - Guard: isDurationShapedLabel() pure in scurve-import-core.js (number +
      days, case-insensitive) + window export; harvest skips duration-shaped
      AI month labels (best-effort lookup, guard failure never blocks merge).
      Genuine word-variants flow untouched. +1 fixture (durations true incl.
      187; STAGE/P1/Month/empty/null false).
    - Label: 'S-Curve Month' joins pkpFieldLabel (single shared Blade serves
      both layouts — one edit covers admin + staff).
    - Caught pre-green (1, mine): helper insert first anchored a mismatched
      comment line (edit rejected) — re-anchored on the exact header text.
    - Suites: JS 116 + 2 intended blinding fails (+1 fixture, green);
      Blade view:cache clean; full 1140 passed / same 7 pre-existing.
      Harvest/desk paths are DOM-timing — browser proof covers them (Roof AI
      merge banks nothing; novel word still banks). No commit.

  2026-09-12 — DELIVERY FIXES BUILT (bugs (a) + (b); code, NOT committed per user).
    Queue item 1. Learning was already proven (loop proof); this is the plumbing
    that kept approved words from reaching the reader on re-import.
    - Bug (a) SHARED LOADER: loadLearnedKeywords moved verbatim out of
      document-parser.js (create-wizard only) into NEW
      public/js/admin/projects/learned-keywords-loader.js, loaded by BOTH role
      layouts (app.blade.php + stafflayouts.blade.php) beside the pdfjs loader,
      filemtime-versioned (ghost-JS incident class). Contractor layout excluded
      by design. document-parser.js now consults window.loadLearnedKeywords behind
      the same typeof guard the PDF wrapper uses, so a missing loader degrades to
      hardcoded labels exactly as an empty table does. The detail Baseline tab can
      now consult learned words for the first time (it never loaded the definer).
      Behavior preserved verbatim: role-base /admin vs /staff, /contractor/ skip,
      { labels: { field: [phrase] } } shape, never-cache-an-empty-desk, any
      failure → EMPTY.
    - Bug (b) CACHE BUST, three layers: (1) the desk calls
      window.bustLearnedKeywordsCache() after a successful approve AND reject
      (guarded, best-effort, never blocks the write) — the desk ships in both
      layouts so it shares the tab with the import, and sessionStorage survives
      navigation within a tab; (2) key versioned parserLiveKeywordsV1 → V2, so
      caches poisoned during the loop proof die on first load with no console
      workaround; (3) TTL 24h → 15 min, bounding what busting cannot see
      (approval in another tab, server-side table wipes during testing — the exact
      Script 3 setup). Cache identity lives in ONE place (the loader) so the desk
      and the reader can never drift.
    - Rejected: refetch-on-miss — it only fires when the cached set has NO
      scurveMonth rows, so a stale-but-non-empty set would still slip through.
    - Suites: JS 116 passed + the 2 intended blinding fails (unchanged); full PHP
      1140 passed / 7 failed = the same 7 pre-existing by NAME (2 Contractor-
      Dashboard + 1 DashboardOverdueInspection + 2 EmailDraft + 2 default-baseline
      in IssueLifecycle/RiskRegister). No PHP was touched — JS + Blade only.
      view:clear run. No commit per user.
    - PROVEN SAME DAY by the user's step-6 re-run: approved SEGMENT row → rule
      reader parses the TRUE 5/20/40/65/100 natively with zero AI. B / F-D / F-E
      CLOSED for the learning loop (full record + the residual name-assignment
      note in pdf_ai_loop_proof_tests.md).

  2026-09-12 — TWO FOLLOW-UPS from the step-6 session (code, NOT committed).
    (1) CACHE MEMO replaces the sessionStorage TTL cache. User wiped the keyword
      rows in the DATABASE and the reader still used them — a server-side change
      the browser cannot see, exactly the residual case the 15-min TTL only
      narrowed. The cache never bought more than one small GET per import, so the
      live word set is now memoized IN MEMORY for the page load (shared in-flight
      promise for concurrent callers, empty desks never memoized). Consequence:
      any page refresh re-reads the server — what everyone already expects — and
      approve/reject still bust immediately. bustLearnedKeywordsCache() also
      removes the legacy parserLiveKeywordsV1/V2 sessionStorage keys so a set
      cached by an older build can never be served after the upgrade.
      Lesson banked: cross-page-load caches over a table the user edits by hand
      cost two proof sessions; don't reintroduce one without a version endpoint.
    (2) PDF AI LINK NOW OFFERED ON STRONG READS TOO (user request): the in-modal
      link used to render only when isWeakPdfCurve() flagged the read, so a clean
      PDF import had no way to ask for a second opinion — while the Excel flow
      always offers its cross-check. Both stash sites (wizard + detail) now stash
      the ctx on every parseable PDF with a `weak` flag; scurveRenderPdfAiLink
      uses that flag for WORDING ONLY ("looks weak … recommended" + "Try AI
      assist?" vs "Read offline from the PDF, no AI used. Want a second opinion?"
      + "Cross-check with AI?"). Quota discipline unchanged: one call per click,
      busy-guarded, opt-in — clean files still spend nothing unless clicked.
    - Suites: JS 116 passed + the 2 intended blinding fails (unchanged; the
      isWeakPdfCurve core is untouched, the link is a DOM path = browser proof).
      No PHP touched. No commit per user.
    - NEXT = P3 merge-by-Y, then E1-PDF layout learning. Both now live in their
      own file: **textfiles/document_parser_pdf_layout_learning_plan.md**
      (handoff-ready: status, order of work, hard rules, verify commands).

  2026-09-12 — SPECIMEN VERIFIED + P3 ROOT-CAUSED (read-only probes, no code).
    - User's AI-assisted re-run checked against the source files: EXACT on curve
      (5/20/40/65/100), names (Mobilization / Earthworks / Concrete and Masonry /
      Finishing ×2) and the Finishing-spans-M4+M5 split. The PDF AI track is
      doing its job on this specimen.
    - The rule-only name misassignment is NOT an AI or learning gap: the print is
      a COLUMN split across 2 pages and mergePages() aligns disjoint-month pages
      by row INDEX, while the pages' row Y coordinates match exactly. Full
      forensic record in pdf_ai_loop_proof_tests.md; fix spec in the new layout
      plan §3. Decision banked: P3 lands BEFORE E1-PDF (cheaper, deterministic,
      and it cleans the captures E1-PDF would otherwise learn from).

  2026-09-12 — FIGURES-VS-PAGE CHECK (PDF; code, committed).
    Question that found it (user): "do both paths return the monthly AMOUNTS so
    the system can check the AI?" Yes — both prompts request monthlyAmounts +
    contractAmount and monthsFollowAmounts() recomputes from them. But the PDF
    path verified WORDS against the page (sanitizeActivityNames) and never
    FIGURES: the F-A reasoning "no CSV exists for image slices" went stale once
    $pageWords started arriving for the name guard.
    - extractPrintedNumbers() — page-text sibling of extractSheetNumbers (which is
      CSV-shaped): whitespace split, strips peso/comma/percent, pairs consecutive
      numerics. Page words arrive joined per page so "consecutive" can straddle
      rows — that only ADDS matches, erring toward silence, the right bias here.
    - unmatchedAmountMonths() — the matching rule extracted so the Excel audit
      (which declines) and the PDF check (which warns) can never drift apart;
      amountsPresentInSheet now delegates to it, behaviour unchanged.
    - validatePdfCurve warns, naming up to 3 months, and NEVER declines (user
      decision): a month summing several activity rows often has no printed
      total, and a false decline costs the whole AI assist. Silent when the page
      has no numbers (scanned slice).
    - Tests: +3 (warn names the month AND months survive; silent without page
      text; plus the salvage-seam pair below). Suites: PHP 1153 passed / same 7
      pre-existing; JS 120 tests, 118 + the 2 intended blinding fails.
    - NOT yet browser-proven: a clean-text PDF (10-month or 6-stage twin) must
      show NO new warning. A warning there means extractPrintedNumbers is too
      strict and should be loosened before anything else.

===============================================================================
  12. CONVERSATION CONTINUATION GUIDE
===============================================================================

  For the next session:
    1. This file is the single home for PDF AI; AI-6 in the AI fallback plan
       stays history + pointer (do not build AI-6 separately — F-A..F-E IS it).
    2. Build order F-A → F-E (each proved before the next). Blinding stays ON.
    3. Proof files are git-ignored sampledocs/ twins — reference by name, never commit.
    4. Run AI service tests + JS suite + full suite before/after each phase;
       clean-tree regression check per standing practice.
    5. No merge without explicit user confirmation (Rule 12).

===============================================================================
  END OF PLAN
===============================================================================
