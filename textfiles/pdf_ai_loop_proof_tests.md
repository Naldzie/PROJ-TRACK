# PDF AI LOOP PROOF TESTS (B / F-D / F-E) — SESSION RECORD
# Parent: textfiles/document_parser_pdf_ai_fallback_plan.md (Phases F-A..F-D, B)
# Date: September 11, 2026 — Status: SCRIPTS 1–2 PROVEN, SCRIPT 3 BLOCKED (delivery bugs, not learning bugs)
# Specimen: sampledocs/SCurve-Test-Segment-Novel-Specimen.xlsx → user-printed PDF
#   (5 single months SEGMENT 1..5, 4 activities, TRUE curve 5/20/40/65/100)

===============================================================================
  SCRIPT 1 — 10-MONTH PDF (rule-weak file, in-modal AI link)
===============================================================================

  File: 10-month S-curve PDF twin (Excel holds the TRUE curve
  5/10/20/30/50/70/80/90/95/100 + author summary 45/60/75/85 at M5–M8).

  Step 3 — rule import: MISSES honestly ("table not recognized") OR reads
  weak (generic Activity N, 19.99-style dust). Beside-button offer appears.
  Step 4 — AI assist: TRUE curve 5/10/20/30/50/70/80/90/95/100 with REAL names
  (Mobilization Works / Earthworks and Excavation / Concrete and Masonry
  Works / Finishing and Painting Works), 1–2 activities per month. ✅ PASS
  Notes: AI-assisted + Checked + all 4 summary warnings (45-vs-50 etc.).
  (Earlier silence was a Lite copy — closed, see Lite revert entry.)
  Known cosmetic: list may clip long names ("Mobilizatio", "Concrete a") —
  VERDICT PENDING: check edit/manage for full names (whole there = CSS
  ellipsis, no fix; clipped there = real bug, report it).

===============================================================================
  SCRIPT 2 — 6-STAGE PDF (rule-weak file, in-modal AI link)
===============================================================================

  File: 6-stage S-curve PDF twin (TRUE curve 5/20/40/60/80/100).

  Rule import: percentages exact, counts wrong (2–3 generic names per month
  where Excel has 1–2 real ones). AI assist: exact curve + Excel-matching
  counts (1,2,1,2,1,1) + accurate names (proven in edit/manage). ✅ PASS
  (Names show in edit/manage; list display fixed by the description fallback.)

===============================================================================
  SCRIPT 3 — SEGMENT SPECIMEN (novel-word loop proof: B + F-D + F-E)
===============================================================================

  Setup: Learned Keywords table WIPED CLEAN before the test (server empty).

  Step 1–2 — print to PDF + Ctrl+A-paste check: text layer valid. ✅ PASS
  Step 3 — rule import: MISSES ("table not recognized", zero header hits —
  SEGMENT unknown to every tier). Beside-button offer appears. ✅ PASS
  Step 4 — AI assist: TRUE curve 5/20/40/65/100, exact names, 1 act/month.
  Notes: AI-assisted + Checked. ✅ PASS
  Step 5 — desk check: FIVE proposed AI-assisted rows (SEGMENT 1..5, Seen 1×,
  field "S-Curve Month"). ✅ PASS with a note: harvest banks one row per
  distinct novel label (cap 12) — approving ANY one unlocks the whole family
  via generalization ("SEGMENT 1" taught ⇒ SEGMENT N matches). Five rows is
  correct behavior, not over-harvest (dedup polish optional, not queued).
  Step 6 — approve ALL FIVE (rows Live at re-import time) → re-import.
  ❌ FAIL on BOTH flows: rule reader still misses ("could not read s curve").

  Failure analysis (learning acquitted — delivery indicted):
  - Learning works: miss → teach → 5 rows → approve → Live all proven above.
  - Suspect 1 (approval timing): RULED OUT — rows were Live at re-import.
  - Suspect 2 (detail tab has no loader): PROVEN BY CODE READ — the word
    loader (loadLearnedKeywords) lives in document-parser.js, which loads ONLY
    in the create wizard modal. The detail Baseline tab never defines it, so
    the wrapper's `typeof === 'function'` guard silently yields no learned
    words there. Detail-tab re-imports can NEVER consult the table today.
  - Suspect 3 (stale 24h cache): STILL OPEN for the wizard flow — wiping the
    server table does not wipe the browser's sessionStorage word cache, so a
    pre-wipe live set (no SEGMENT) may have served the re-import. Workaround:
    console `sessionStorage.removeItem('parserLiveKeywordsV1')`, re-import
    with no refresh (loader runs fresh per import).
  - NEXT: user states which flow step 6 used (or both — reported BOTH failed,
    so both fixes are queued): (a) shared loader for the PDF wrapper (no
    borrowing from the wizard); (b) approval/cache-bust (version the key or
    refetch-on-miss). Then re-run step 6 to close B/F-D/F-E in one run.

  BOTH FIXES BUILT 2026-09-12 (not committed; full record in the parent plan's
  §11 entry "DELIVERY FIXES BUILT"):
  - (a) loadLearnedKeywords moved to the shared learned-keywords-loader.js,
    loaded by both role layouts → the detail Baseline tab consults learned words
    for the first time. Verify live: on the detail tab, console
    `typeof window.loadLearnedKeywords` must now answer 'function' (it answered
    'undefined' before the fix).
  - (b) approve/reject bust the cache, key bumped to parserLiveKeywordsV2 (old
    poisoned caches die by themselves), TTL 24h → 15 min.
  STEP 6 RE-RUN 2026-09-12 — ✅ PASSED ON THE CURVE (user, live, no workaround):
  - Desk showed the 5 SEGMENT rows (AI-assisted, Proposed, Seen 1×, field
    "S-Curve Month" — the B label renders). Approved → re-import.
  - Rule reader parsed NATIVELY, zero AI: TRUE curve 5 / 20 / 40 / 65 / 100 over
    5 months. The taught word generalized as designed (SEGMENT N).
    ⇒ B / F-D / F-E CLOSED for the learning loop: miss → teach → approve →
      free offline read. The delivery bugs were the only thing in the way.
  - RESIDUAL (not a learning bug): activity NAMES came back misassigned —
    Mobilization / Earthworks / Mobilization / Earthworks / Earthworks, where the
    specimen has 4 distinct names with Finishing spanning M4+M5. Also warned
    "pages carry different row counts (4 vs 2) — months were aligned by position".
    ROOT-CAUSED the same day, see the P3 entry below.

  AI-ASSIST RE-RUN 2026-09-12 (user, the paste that was lost the first time):
    M1 Mobilization Works 5% · M2 Earthworks and Excavation 20% · M3 Concrete and
    Masonry Works 40% · M4 Finishing and Painting Works 65% · M5 Finishing and
    Painting Works 100%, 1 activity each.
    VERIFIED AGAINST THE SOURCE FILES (read-only, 2026-09-12) — EXACT on all
    three axes: curve, names, and the Finishing-spans-M4+M5 split. Excel truth
    (PhpSpreadsheet dump of SCurve-Test-Segment-Novel-Specimen.xlsx, sheet
    S-CURVE): header row 10 = ITEM NO./DESCRIPTION/CONTRACT AMOUNT/% WEIGHT/
    SEGMENT 1..5; rows = Mobilization 50,000 (S1) · Earthworks 150,000 (S2) ·
    Concrete and Masonry 200,000 (S3) · Finishing and Painting 600,000 split
    S4 250,000 + S5 350,000; TOTAL 1,000,000 ⇒ 5/20/40/65/100.

  P3 ROOT CAUSE — MERGE BY POSITION (forensic probe 2026-09-12, read-only: the
  real PDF through the real core in node with node_modules/pdfjs-dist; script in
  C:\temp\opencode, outside the repo):
    The print splits the table across two pages BY COLUMNS — page 1 carries
    ITEM NO./DESCRIPTION/CONTRACT AMOUNT/% WEIGHT/SEGMENT 1/SEGMENT 2 with 4
    activity rows; page 2 carries SEGMENT 3/4/5 ONLY, with no description column
    and just 2 value lines. mergePages() aligns disjoint-month pages BY ROW INDEX
    (its own comment calls positional alignment "a guess"), so page-2 row 0
    inherits Mobilization and row 1 inherits Earthworks.
    BUT THE ROW Y COORDINATES MATCH ACROSS PAGES EXACTLY:
      header  p1 y=595.90  p2 y=595.90
      row 3   p1 y=537.79  p2 200000 @ y=537.79
      row 4   p1 y=523.27  p2 250000 + 350000 @ y=523.27
    So merge-by-Y is deterministic here, needs no AI and no learning. Rule-only
    percentages were already correct — only the name attribution drifts.
    Fix spec + fixtures: textfiles/document_parser_pdf_layout_learning_plan.md §3.

===============================================================================
  SCRIPT 4 — P3 BROWSER PROOF + EXCEL/PDF PARITY SWEEP (user, Sept 12)
===============================================================================

  All runs on the project-detail Baseline tab. AI was intermittently 503 during
  the session (upstream weather, see the reliability probes below).

  SEGMENT PDF
    AI assist    : 5/20/40/65/100 + real names + Checked note. ✅
    Rule re-read : IDENTICAL to the AI after approving ONE word (SEGMENT 1).
                   ⇒ P3 + F-D word learning proven together in the browser.

  SEGMENT EXCEL
    AI assist    : curve correct (5/20/40/65/100) but NO activity names — see
                   GAP 1 below (prompt omission, not a model failure).
    Auto-capture : row stored with column_map NULL.
    E1-B         : ran and DECLINED, reason "no-header-row" (log evidence).
                   Root cause: serializeForAi tagged every row [DATA] — the role
                   tagger uses the same detector as the rule reader, which does
                   not know SEGMENT, so the slice showed the AI no HEADER row and
                   the AI (correctly) omitted headerRow. See GAP 2.
    Manual teach : taught columns → re-import parses with the learned-layout note
                   and the CORRECT names. ✅ The designed human fallback works.

  6-STAGE PDF
    Rule read    : recognized on FIRST import, NO import note, exact curve
                   5/20/40/60/80/100 with REAL names and the Excel-matching split
                   (1,2,1,2,1,1). ✅ P3 in the browser.
    AI crosscheck: same curve; adds the honest "matches the file's own summary
                   exactly — could not be independently verified" note (the file
                   does carry CUMULATIVE % ACHIEVED with those very values).
    No keyword learning fired — CORRECT: STAGE is a built-in month word and the
    harvester deliberately skips words the rules already know.

  6-STAGE EXCEL
    Rules missed → AI assist: curve correct, again NO activity names (GAP 1).
    Retested on a second key/model (flash-latest vs 3.6-flash): identical, which
    confirms GAP 1 is a PROMPT omission, not model weather.
    Auto-capture : column_map NOT null; E1-B logged "learned map" for proposals
                   41 and 42. ⇒ layout learning works end-to-end here.
    Approved (no manual teach) → re-import reads with the learned layout AND real
    names (1,2,1,2,1,1). ✅ Note the inversion worth remembering: the LAYOUT path
    recovers activity names that the AI path does not report.

  10-MONTH PDF
    Rule read    : no import note, exact 5/10/20/30/50/70/80/90/95/100 with real
                   names. ⇒ the long-documented "19.99-style dust" is gone in the
                   real app, not just in probes. ✅

  GAPS FOUND — BOTH FIXED 2026-09-12 (user-ordered; see the fix notes under each)
    GAP 1 — Excel AI returns no activity names. buildSCurveExcelPrompt asks for
      {"months":[{month_index, planned_progress_percent}], "monthlyAmounts",
      "summary", "hasSummaryRow", "contractAmount"} and never requests names,
      while the PDF prompt asks for "activities" per month. Fix shape: add the
      key to the Excel prompt + thread names through validateSCurveMonths with
      the SAME discipline the PDF path uses (names must appear in the sheet text;
      unmatched are dropped). Model-independent — proven on two models.
      ⚠️ FIRST ATTEMPTS FAILED (prompt-only, 2026-09-12): asking for "activities"
      per month changed nothing — the live log read monthsWithActivities:0 on real
      runs, both before and after the prompt's worked EXAMPLE was corrected to
      include the key.
      ❗ ROOT CAUSE, FOUND 2026-09-12 WHILE ANSWERING A DIFFERENT QUESTION — and it
      corrects what this file said first: **the response SCHEMA forbade the key.**
      callGeminiText sends generationConfig.responseSchema (structured output), and
      scurveResponseSchema() built month items from
      $point('planned_progress_percent','NUMBER') — exactly two declared
      properties. Gemini drops every undeclared key, so "activities" was
      STRUCTURALLY IMPOSSIBLE on this path no matter what the prompt or the example
      said. callGeminiWithImages sends NO generationConfig at all, which is the
      whole reason the PDF path returns names and Excel never could.
      The earlier explanations recorded here — "the worked example overrode the
      instruction" and "the model simply will not emit that nested key" — were
      WRONG, and were written into commit ac9583b's message too. The model was
      obeying a contract nobody had read.
      ✅ SCHEMA FIXED: months items now declare an optional
      activities: ARRAY<STRING> (monthlyAmounts/summary deliberately do NOT — they
      share the point builder). +1 test that inspects the OUTGOING request, since
      every other test feeds decoded JSON to the validator and bypasses the schema
      entirely — which is precisely how this hid for a day.
      LESSON BANKED: on the Excel path the SCHEMA is the contract, not the prompt.
      Any new key the prompt asks for must be declared there too, or it is dropped
      in silence.
      ✅ ALSO FIXED 2026-09-12 (independent second source) — the SERVER reads the
      sheet:
      SCurveImportService::parseWithAiLayout() derives a map from the layout keys
      the model DOES report reliably (descCol/monthColumns/headerRow — present in
      every logged reply), parses the sheet with it, and the controller
      (applyDerivedLayout) attaches those names. Real cells: hallucination is
      impossible by construction, and it works even when the model omits names
      entirely. The AI's own per-month "activities" are still honoured when present
      (printed-words checked), so the PDF path and compliant replies are unchanged.
      +2 service tests, +1 endpoint test.

  DERIVED MATH — the same map also CORRECTS the curve (user decision 2026-09-12)
    Found while proving GAP 1: on the 10-month file the model returned 40/60/70
    for months 5-7 where the sheet's rows say 50/70/80, and it STILL printed
    "Checked: these months follow from the stated monthly amounts". Both homework
    guards passed because they check the answer against ITSELF and against any
    number in the sheet: the invented amounts (400,000 / 200,000) exist in the
    sheet as BUDGET COST figures. The code's own docblock predicted this —
    "Pollution (budget costs) can only false-pass, never false-decline".
    Meanwhile the derived map computed the TRUE curve (verified read-only). So the
    system held the right answer and displayed the wrong one.
    Fix: when the derived map's math differs from the AI's by >1.0, the SHEET wins
    and says so; the AI's verdicts are dropped with its arithmetic (they described
    numbers no longer on screen) and the map parse's own summary comparisons take
    their place. The misleading "learned layout for this contractor" note is
    filtered — no approved layout was involved. Learning stays approval-gated: a
    separate meta flag is used so learnMapFromAi still banks the proposal.
    GATE (caught by the tests, would have been a real downgrade): sheet math is
    preferred ONLY when the parse found a real denominator (contractAmount > 0)
    and covers every month the AI reported. Without a contract column or TOTAL row
    the parse falls back to weights and can yield a degenerate 0/0/100 — its NAMES
    still ride, its numbers stay out. +1 endpoint test.
    NOTE: this lets an UNAPPROVED derived map influence the displayed curve. It is
    a reviewed preview, gated as above, and Apply stays manual — but it is a real
    move of the trust boundary. The warn-only variant is the fallback if it ever
    feels too eager.
    GAP 2 — no HEADER tag ⇒ no AI layout learning. When the rule detector cannot
      find the header, serializeForAi tags every row [DATA], so the AI has no
      R-number to report and E1-B declines with "no-header-row". Exactly the
      files that most need layout learning are the ones that cannot get it
      automatically. Options: (a) prompt nudge to report headerRow even when no
      row is tagged HEADER; (b) infer headerRow server-side from the AI's own
      descCol/monthColumns letters; (c) leave it to manual teach (works today).
      ✅ FIXED 2026-09-12 with (a) AND (b): the prompt now says the labels often
      sit on a row tagged DATA because the tagger missed the table, and that row
      still counts. Independently, an ABSENT headerRow no longer refuses —
      inferHeaderRowKey() reads the REAL cells at the columns the AI DID name and
      keeps the first row that reads like LABELS there (text, never numbers, in
      the description column and in ≥2 month columns). A data row fails on sight
      because its month cells hold amounts. Nothing is guessed: with no such row
      the derivation still refuses, now naming the gate 'header-row-unfindable'.
      The explicit-headerRow path is untouched, and an inferred map comes out
      IDENTICAL to the one the model naming the row produces (asserted).
      +2 tests. NOTE: the old 'no-header-row' reason is now unreachable — a
      declined derivation says 'header-row-unfindable' instead.

===============================================================================
  SCRIPT 5 — FULL SWEEP AFTER THE SCHEMA + DERIVED-MATH WORK (user, Sept 12)
===============================================================================

  Both specimens, both file types, both flows (create wizard AND detail tab),
  ~8 AI calls on the 10-month file alone. Every curve correct.

  6-STAGE EXCEL
    AI assist (first upload): exact curve 5/20/40/60/80/100 AND — for the first
    time ever on the Excel path — REAL ACTIVITY NAMES from the model itself
    (log: monthsWithActivities 6 of 6). The responseSchema fix is proven live.
    Auto-capture stored a non-null map; no separate ai-derived row appeared,
    which is CORRECT (storeAiDerived backfills only map-LESS rows).
    After approval: learned-layout read, same curve, same names.
  6-STAGE PDF
    Recognized on FIRST upload with no import note (P3). AI cross-check agrees.
  10-MONTH EXCEL
    AI assist: true curve 5/10/20/30/50/70/80/90/95/100 with all four summary
    warnings. Auto-capture map non-null; after approval the learned layout gives
    the same curve, and AI cross-check answers "agrees with the imported months".
  10-MONTH PDF
    Rule read exact on first upload, no note. AI cross-check exact.

  ✅ THE NEGATIVE THAT MATTERED: the new figures-vs-page check stayed SILENT on
  both PDFs. No false "could not be found printed on the page" on clean-text
  files, so extractPrintedNumbers is not too strict. That was the one result
  that could have sent it back.

  ⚠️ FOUND IN THE SWEEP — DUPLICATE MONTH LABELS (create wizard, 10-month PDF):
    #9 and #10 both rendered "March 2026". Cause (user diagnosed, confirmed):
    normalizeSCurveMonths dates each month as start + (index-1) and CLAMPS it to
    the project's end date; the sample project ended March 2026 while the file
    carries 10 months, so month 10 was pinned back onto month 9's slot. Setting
    the end date to April fixed it.
    WHY IT MATTERS: the clamp is silent, and applying that baseline would store
    two rows labelled 2026-03. Month labels are keys downstream (activity
    reloads, manage-month modal, progress recording) and the S-curve would plot
    two points on one x. A project whose dates are shorter than its schedule file
    can therefore produce a colliding baseline with nothing on screen explaining
    why. Guard queued: detect duplicate labels after normalizing (pure helper in
    scurve-import-core.js) and warn through the existing stash.

===============================================================================
  MODEL PROVENANCE (asked + answered 2026-09-12 — read before blaming code)
===============================================================================

  User question: "the Excel AI fallback computed month 5 correctly BEFORE the PDF
  work started — why is it wrong now?" Investigated read-only; answer in three
  parts, none of which is the PDF work:

  1. The double-check EXISTS and is not new: validateSCurveMonths runs the
     recompute check (monthsFollowAmounts — months must follow from the AI's own
     stated amounts) plus the presence audit (amountsPresentInSheet). It is what
     replaced the Lite-era summary copying.
  2. It has a KNOWN blind spot, documented in its own docblock: both guards test
     the answer against itself and against any number in the sheet, never against
     the RIGHT cells. Budget-cost pollution can false-pass. The 10-month file is
     the pathological case (its BUDGET COST column holds 400,000 / 200,000).
     The new derived-map cross-check is the first guard that tests against real
     cells — it closes exactly this hole.
  3. The PDF work did NOT touch the Excel path. Verified per commit: 5fd1457,
     edbefc5 and 1efc186 changed ZERO lines in buildSCurvePrompt /
     validateSCurveMonths / monthsFollowAmounts / amountsPresentInSheet, and the
     two matching lines in d11f481 are both ADDITIONS inside the new PDF code.
  4. What DID change: the parser model. config/services.php still carries the E3
     comment "pinned gemini-2.5-flash per user", but the live config reads
     parser_model = gemini-3.7-flash (key switched after a rate limit).
     Long multi-series arithmetic is exactly what shifts between models.
     Also honest: the 2026-09-12 prompt edits lengthened that prompt, which can
     perturb output on its own (one reply dropped contractAmount entirely).
     Model change, prompt change and plain run-to-run variance cannot be cleanly
     separated from the evidence available.

  USER DECISION 2026-09-12: stay on gemini-3.7-flash, because the derived-map
  cross-check now backstops its arithmetic against real cells. Recorded here so a
  future session knows which model the proofs below ran on.

  "DID THE PROMPT EDITS CAUSE THE WRONG CURVE?" — asked by the user, answered from
  the log against commit times. NO, and the evidence is specific:
    12:47  10-month, contractAmount present → AI returned 40/60/70 (WRONG)
    13:05  10-month, SAME prompt, 18 minutes later → 50/70/80 (RIGHT)
    13:43  schema fix committed (021a2c0)
    13:45  first reply ever with monthsWithActivities > 0 (6 of 6)
  Same file, same prompt, opposite arithmetic: a deterministic prompt defect
  cannot do that. The errors landed on months 5-6 — the only months where two
  activities overlap in one column — which is a CELL-READING error, not a wording
  one. Model swap (3.7-flash vs the pinned 2.5-flash) plus run-to-run variance are
  the likely contributors and cannot be separated from this evidence.
  THE REAL MISTAKE was the reverse of what was suspected: prompt text was added
  asking for "activities" WITHOUT READING THE SCHEMA that forbade it, leaving the
  request incoherent for a day. Read the schema before writing prompt text.
  AND BE PRECISE ABOUT WHAT IMPROVED: a misread is NO LESS LIKELY now. What
  changed is that it can no longer reach a baseline silently — applyDerivedLayout
  recomputes from real cells and overrides past 1.0. That is protection of the
  OUTCOME, not improvement of the model. Do not let these logs imply otherwise.

  SENDERS, FALLBACK AND SCHEMA (asked 2026-09-12: "does the Excel parser fall back
  to Lite the way the normal-doc one does?" — answer: NO, it cannot):

    path                     sender                    Lite rescue   responseSchema
    ----------------------------------------------------------------------------
    normal-doc autofill      callGeminiWithImages()    YES (default) none
    PDF S-curve              callGeminiWithImages(,f)  NO (reverted) none
    Excel S-curve            callGeminiText()          NONE AT ALL   YES (enforced)

  VERIFICATION PER PATH (what the server checks about the AI's own figures):

    check                                  Excel              PDF
    ------------------------------------------------------------------------
    months recomputed from stated amounts  yes (declines)     yes (declines)
    claimed pesos found in the file        yes (DECLINES)     yes (WARNS only)
    activity names found in the file       yes (drops name)   yes (drops name)
    independent re-read of the source      yes (derived map)  none possible

  The PDF check warns rather than declines (user decision 2026-09-12): a month
  that sums several activity rows often has no printed total anywhere on the
  page, so Excel's strictness would throw away good reads. It stays silent when
  the page carries no numbers at all (scanned slice) — missing evidence is not
  evidence of fabrication.

  Two consequences worth remembering:
  - The Lite-copies-the-summary failure class (proven on PDFs, Sept 11) CANNOT
    reach the Excel path: callGeminiText has no fallback code whatsoever.
  - The Excel path is the only schema-constrained one, so it is the only path
    where a prompt can ask for something the reply is structurally forbidden to
    contain. That is what GAP 1 turned out to be.

===============================================================================
  RELIABILITY PROBES (Gemini weather, Sept 11)
===============================================================================

  - Outage window (~4h): sustained 503/throwable storm + PHP 60s fatals on
    hung retries. External corroboration (Sept 10 multi-day flash-503 thread,
    Sept 1 30-day 37% 503 on 3.6-flash, Sept 3 outage news): upstream sickness
    on free-tier flash, NOT a code regression (untouched Excel text path failed
    identically; same-model cross-check path succeeded minutes apart).
  - Scenario retest (Roof PDF failed-parse assist): 4/5 success — case closed.
  - Quota voice proven live: per-day 429 → "Today's AI allowance is used up.
    Please try again tomorrow." (Flash-or-nothing revert behaves as designed.)
  - Roof AI probe: TRUE 7-month curve (6.32/…/100, counts 4,4,4,4,3,4,3) +
    real names + Checked + honest mirror note. B-wins verdict stands.

===============================================================================
  OPEN WATCH ITEMS (no action until evidence)
===============================================================================

  - months:11: one Flash reply carried 11 points on the 10-month file; later
    runs show months:10 correct. Count-sanity guard builds ONLY on a confirmed
    11-month modal import (screenshot required).
  - Clipped display names: pending edit/manage verdict (see Script 1).
  - Time-budget hardening (PHP 60s overflow → honest 422): spec'd, unordered.
  - Desc-label learning (prompt-reported work-names title + same plumbing):
    queued, unordered. F-D covers month words only.

===============================================================================
  END OF RECORD
===============================================================================
