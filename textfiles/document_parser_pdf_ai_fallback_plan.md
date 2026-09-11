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
