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
