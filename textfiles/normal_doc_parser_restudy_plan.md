# NORMAL-DOC PARSER RESTUDY (SCANNED DOCUMENTS, UI FIRST)
# Parent refs: textfiles/pilot_testing_readiness_plan.md (§2 Fact 2, §5 N1-N5 — why this
#                is the pilot's first polish target; N1/N1a shipped on phase-23)
#              textfiles/document_parser_learning_loop_plan.md (L1-L3 + §6 the OCR batch)
#              textfiles/ocr_page_preview_and_ai_picker_plan.md (the preview/rotate/picker
#                UI this restudy re-judges against REAL client conditions)
#              textfiles/document_parser_accuracy_improvement_plan.md (R0-R4 history)
# Date: September 18, 2026
# Status: 🔄 RESTUDY IN PROGRESS — READ-ONLY, NO APP CODE WRITTEN.
#         Stage 1 (THE UI) — done, §4. FIVE VERIFIED FINDINGS (U1-U5).
#         Its one hypothesis (H1, "the OCR budget ran out") was MEASURED AND FALSIFIED the
#         same day — kept in §4 as a dead end so it is not re-proposed.
#         ⚠ WHAT THE MEASUREMENT FOUND INSTEAD IS WORSE, and is now the highest-value
#         question in the restudy: OCR-1 — SEVEN OF TEN PAGES of a real scanned document
#         yield nothing, through a three-rung OCR ladder, on pages a human reads at a
#         glance. 70% of the document is invisible to the parser. That is a READ-PATH
#         problem (stage 2), not a UI wording problem.
#         ⚠ OCR-2 NOTED 2026-09-18: the SAME file yielded 3 read pages one run and 4 the
#         next — a fixed file should read deterministically, and OCR-1 is not measurable
#         until it does. Stage 2 now opens with "establish determinism".
#         ⬛ STAGE 2 (THE READ PATH) RESTUDIED 2026-09-18 — §10, read-only. OCR-2 now has a
#         CONCRETE MECHANISM, not just "wobble": window._ocrLangMode pins itself to plain
#         'eng' after two empty OSD reads and the pin SURVIVES INTO THE NEXT RUN (it is
#         cleared only when the modal is cancelled), and the OCR cache key omits the
#         language mode, so one run can mix two engines. Both confirmed by measurement.
#         ⬛ MEASURED 2026-09-18 — §11, the browser run. OCR-2 mechanism 1 CONFIRMED in
#         the log, and its ROOT CAUSE found: the OSD path is STRUCTURALLY BROKEN on this
#         CDN build ("LSTM requested, but not present") — it can never work, it wastes
#         the first two pages of every session, and the pin it triggers IS the
#         non-determinism. ❌ My OCR-1 suspect (the washed-page gate) is REFUTED: every
#         page has full dynamic range, so the gate is correct. ⚠ And the user found a
#         FOURTH mechanism — devtools OPEN reads 3 pages, CLOSED reads 4.
#         ⚠ §9 CORRECTED: a dead page costs UP TO SEVEN recognitions, not ~3 (§10.4).
#         ⬛ M-1 BUILT 2026-09-20 (ordered by the user): the broken OSD path is now
#         UNREACHABLE — OCR_OSD_ENABLED = false in document-parser.js, the session starts
#         already pinned to plain 'eng', and useOsd tests the switch FIRST so a page read
#         before any modal reset cannot slip onto it. Suite-green + a 13-check
#         falsification including a flag-flip mutation, then BROWSER-CHECKED — see the next
#         entry and §12. Branch phase-24-ocr-osd-disable. M-2 / M-3 / M-4 unordered.
#         ⬛ M-1 BROWSER-CHECKED 2026-09-20 (§12). It does what it claimed: ZERO LSTM
#         errors, the pin never fires, pages 1-2 read on the same engine as the rest.
#         ❌ But my "the page count may go up" caution is REFUTED — pages 1-2 still
#         yield nothing, so the broken engine was never why they died. Reads are
#         UNCHANGED at 3/10. ⚠ The devtools mechanism SURVIVES M-1 and is now narrowed
#         to exactly ONE page (page 9), which answers half of M-3. ⚠ The determinism
#         step is NOT proven: run 2 was a CACHE REPLAY, not a re-read. Two tests owed.
#         ⬛ TEST A + TEST B RUN 2026-09-20 (§12.7-12.11). Reads are DETERMINISTIC:
#         two genuine back-to-back reads are byte-identical, so §11.1's last three rows
#         were a transcription slip, not drift. The "devtools" variable is CONFOUNDED
#         with window._parserDebug, which is logging-only at all 14 sites and cannot
#         change a read — the mechanism may not exist. ⚠ STRONGEST OCR-1 LEAD YET:
#         page brightness tracks deadness (every page with mean >= 161 yields ZERO, 6 of
#         6), and the washed-page gate that exists to rescue faint pages CANNOT FIRE
#         because it keys on the MINIMUM gray, which one dark speck pins to 0.
#         ⬛ T2 + T3 BUILT 2026-09-20 (§13), instrumentation only, no read-path change:
#         the debug flag now survives a reload (localStorage), which dissolves the
#         observation paradox; and the cleanup debug line now reports PERCENTILES and
#         INK FRACTION beside lo/hi/mean, which is the statistic §12.9 needs. Suites
#         green, 40 falsification checks incl. the gate-verdict preservation.
#         ⬜ T1 (one closed-devtools run) is now owed WITH a full ledger — §13.4.
#         ⬛ T1 RUN 2026-09-20 (§14) — THE DECISIVE MEASUREMENT. The fourth mechanism
#         IS REAL: devtools CLOSED reads 4 pages, OPEN reads 3, and §12.8's guess that
#         it might not exist is WRONG. It has a physical signature — the two renders
#         produce DIFFERENT PIXELS, and the closed one has darker stroke cores on 10 of
#         10 pages and reads MORE. Reads stay deterministic WITHIN a condition.
#         ⚠ OCR-1: ink<128 separates 9 of 10 pages at ~7.7%; page 3 is the lone
#         exception. §12.9 is SUPPORTED but is not the whole story.
#         ⬛ P-1 BUILT 2026-09-20 (§15): every canvas on the OCR READ PATH now takes
#         its 2D context from one helper with the backing DECLARED, instead of letting
#         the browser choose — the browser's choice being §14.2's prime suspect. The
#         three display / diagnostic / AI canvases are deliberately untouched. Suites
#         green, 25 further falsification checks, and T3's probe re-passes through the
#         new helper. ⬜ BROWSER CHECK OWED, and it is a REAL EXPERIMENT — §15.4 gives
#         the decision table, including the outcome where the lever must be flipped.
#         ❌ P-1 REFUTED 2026-09-20 (§16) — OUTCOME C, called in advance. Declaring
#         the canvas backing changed NOTHING: every p1, every ink figure and every
#         character count is identical to the pre-P-1 run, in BOTH conditions. The
#         backing is not the variable and §14.2's mechanism guess is wrong. §15.4
#         pre-committed to stopping here, and this stops here. ↩ P-1'S CODE IS
#         REVERTED (user's call, §16.4 option (a)) — it never reaches history; §15 and
#         §16 remain as the record of a refuted experiment.
#         ✅ THE USEFUL CONSEQUENCE: the devtools-CLOSED render is what real users get,
#         and it is the BETTER one (4 pages, not 3). The open-devtools numbers are a
#         developer artefact. All OCR-1 work goes against the CLOSED column (§14.4).
#         ⬛ P-3a 2026-09-20 (§17): the gate work opened with a CORRECTION TO ITS OWN
#         PREMISE — the stretch maps [lo,hi] onto [0,255], and with lo=0-2 and hi=255 it
#         is the IDENTITY. Fixing the gate alone would have fired it and changed nothing,
#         and read as "contrast does not help". The ENDPOINTS must go percentile too.
#         Shipped: p75/p95/p99 and gain= in the debug line, reporting only.
#         ⭐ OCR-1 SOLVED 2026-09-20 (§18): IT IS A CONTRAST PROBLEM. p5 (how dark the
#         darkest 5% is) separates ALL TEN pages with NO exception — readers 69-108,
#         dead 113-145 — including page 3 and page 9, which broke every earlier metric.
#         Page 3 measured offline: the whole document lives in 120-180 (text ~135 on
#         paper ~167, a 32-level separation), while lo=0 comes from the BINDER SHADOW
#         (1% of pixels) and hi=255 from the PAGE MARGIN (5.8%). Seven percent of
#         pixels, none of them text, disable the one mechanism built to rescue it.
#         §18.4 has the fix's shape. NOT BUILT — it changes every scanned read.
#         ⬛ P-3 BUILT 2026-09-20 (§19) on branch phase-25-ocr-contrast-stretch — the
#         document-band stretch. Gate and mapping both stop keying on lo/hi. On REAL
#         page-3 pixels: separation 49 -> 212, gain 1.00 -> 4.32, strokes landing at
#         p5=13. Suites green; a probe caught a gate keyed to the wrong percentile
#         population before it shipped (§19.3). ⬜ BROWSER CHECK OWED — §19.4 states the
#         per-page prediction and the three acceptance conditions IN ADVANCE.
#         ⭐ P-3 PROVED 2026-09-20 (§20): 4 of 10 -> 10 OF 10. All six stretched pages
#         went from ZERO to real text, the four untouched ones returned their predicted
#         counts TO THE CHARACTER, and §19.4's per-page prediction held exactly.
#         PAGE 7 NOW READS, so THE ABC IS FILLED — the field that started the restudy.
#         OCR-1 IS CLOSED. ⚠ Second-order: six newly-read pages also bring their
#         misreads. Budget source now accepts "Accor, Fost =" AND offers to create it as
#         a DB row — its validator is a LENGTH CHECK ONLY (core :869/:341). See §20.3.
#         ⬛ B-1 BUILT 2026-09-20 (§21): the budget source is validated by SHAPE
#         (asSourceName), not by length. "Accor, Fost =" is refused; thirteen real
#         sources still accepted. JS suite 234/234/0 with three new tests, mutation-
#         falsified. ⚠ §21.4: extractCategory has the IDENTICAL hole and identical
#         create-in-place risk — B-1b, not ordered, not done.
#         ⬛ B-1 CONFIRMED + B-4 BUILT 2026-09-20 (§22): the contractor was reading an
#         ABSTRACT OF BIDS row — four bidders in one field — because the rule asked only
#         for 5-150 chars with a construction word. asCompanyName refuses a value
#         carrying a '|' (a cell boundary) and tightens 3..150 to 5..100; a fallback
#         that returned the raw text whatever the validator said is closed too.
#         Suite 237/237/0, mutation-falsified. ⚠ §22.4 names the gap it does NOT close.
#         ⬛ B-2 BUILT 2026-09-20 (§23): create-in-place is no longer one click — not
#         offered at all for a SCANNED document, the reviewer TYPES the name into an
#         empty box, and a near-duplicate is named before anything is created (distance
#         0 disables Add; 1-2 warns but permits, because GAD really is one edit from GAA).
#         editDistance/nearestName are in the pure core; suite 241/241/0, mutation-
#         falsified. ⬜ Browser check owed — §23.4 lists the five DOM paths.
#         ⬛ B-1b + B-3 + B-5 BUILT 2026-09-20 (§24): the category validated by shape;
#         and one cure for three symptoms — values that ran into the next column. The
#         cut is scoped to location/contractor, NOT the title ("Phase 2: Completion" is
#         a real title). Suite 250/250/0 incl. a CLEAN-document-unchanged test.
#         ⚠ §24.5: NO browser check is possible — no further sample documents exist, and
#         B-1 empties the budget source on the one file we have, so no create control
#         renders at all. N2 (get real PMU scans) is now BLOCKING the whole B-queue.
#         ⬛ LEARNING LOOP 2026-09-20 (§25): B-4 had demoted the LEARNED vocabulary
#         below the heuristics — fixed, and the learned branch was unreachable before
#         that too. ⭐ AND THE ANSWER TO "does mining work on scans": it RUNS but usually
#         learns NOTHING, because the miner anchors on the corrected value appearing
#         VERBATIM in the text — and on a scan the correction exists precisely because
#         the text is wrong. Numeric fields and OCR-read-correctly fields still teach.
#         §25.3 predicts the retest; §25.4 has the fuzzy-anchor option, NOT built.
#         ⬛ STAGE 4 (THE LEARNING LOOP) RESTUDIED 2026-09-20 — §26, read-only. Seven
#         findings, all verified in source. The two that matter: an UNANCHORED
#         correction still writes a row, and that row AUTO-PROMOTES to 'live' with an
#         EMPTY phrase (L-2) — on scans that is the normal path, so the desk fills with
#         rules that teach nothing; and CAPTURE ONLY FIRES AT APPLY from the preview
#         modal (L-4), so a correction made later in the form is never seen. §26.8 ranks
#         the fixes and argues fuzzy anchoring should ship LAST, not first.
#         ⬛ ABC BLINDING RE-APPLIED 2026-09-20 at the user's request, and the suite is
#         now SWITCH-AWARE: L3_TEST_BLIND_ABC is exported and the six affected tests
#         assert whichever behaviour is configured. 250/250/0 with it ON *and* OFF — the
#         "2 intended failures is green" convention is retired for good.
#         ⭐ LOOP RETEST 2026-09-20 (§27): the scan DID teach — two ABC labels mined and
#         stored, because the ABC is NUMERIC and numeric anchoring survives OCR spacing
#         damage, exactly as §25.2 predicted. ⚠ TWO DEFECTS FOUND: approving one label
#         gives 4,834,297.00 and the other 4,834,297.59 (§27.2, needs the raw read text);
#         and P-3 SILENTLY BROKE THE AI PAGE PICKER — it ranks "pages that yielded
#         nothing" first, and now nothing yields nothing, so it always sends pages 1-2
#         (§27.3). AGENTS.md future-queue item 8 is no longer optional.
#         ⭐ §28 — THE READ TEXT SETTLES THE .00: OCR turned ".59" into " 5g" on page 1,
#         so the parser read 4,834,297 faithfully. NOT a parser bug. ⚠ §28.1 CORRECTS
#         §27.1 — I queried without withTrashed() and missed the two 'correction' rows;
#         the manual test DID record, and mined the SAME two phrases. NEW: two learned
#         labels for one field compete by LIST ORDER, not quality (L-8, §28.3); and the
#         "AI-assisted" badge CANNOT render because Stage 1b replaced the <td> grid it
#         hunts for (§28.5) — the second UI-rewrite casualty this session.
#         ⬛ AI-ASSIST BATCH 2026-09-20 (§29) on phase-27-ai-assist-targeting: the page
#         ranking now uses the pageChars/soupyPages ledger it was already collecting, so
#         on the real file the pick moves from pages 1,2 to PAGES 7,3 — and page 7 is the
#         ABC page. The AI-assisted badge can render again (it hunted for a <td> Stage 1b
#         had removed). And the request now NAMES THE EMPTY BOXES — field names only,
#         never our OCR text, because our own page-1 text says "4,834,297 5g".
#         Suites green; 9-check probe on the real ledger. ⬜ Browser + live-AI check owed.
#         Stage 3 (EXTRACTION) — not started.
#         ⬛ STAGE 1b BUILT 2026-09-18 (the user clarified that "UI improvement" meant
#         LAYOUT AND DESIGN): preview modal widened to modal-xl, table replaced by a
#         2-column grid, Category / Budget Source / Contractor given real option lists,
#         and the stuck "Scanning documents…" spinner fixed (both re-fixed after the first
#         attempt missed: the option lists are FETCHED so reading them once at render
#         found nothing, and the spinner is repainted per OCR page so clearing it at the
#         end was too late). Uncommitted, BROWSER CHECK
#         OWED — see §8, entry "STAGE 1b". The U1-U5 diagnostic items are a DIFFERENT
#         axis and remain unordered.
#         ⬛ 4th pass: the scan indicator is REPLACED, not patched — its own element,
#         removed from the DOM rather than hidden, because sharing one <div> with the
#         warning banner is what made three fixes leak. ❓ Q3 (§7) asks what to do when
#         an extracted Category / Budget Source / Contractor is not in the DB — answered
#         BUILT 2026-09-18: create-in-place for Category / Budget Source (policy-gated),
#         and a loud named miss for Contractor (never auto-created, never stored as text).
#         §9 answers the OCR-cost research question: sequential pages, a fresh tesseract
#         worker PER PAGE via the one-shot API, a ~3x penalty on pages that yield nothing,
#         and a per-FILE (not per-run) budget — with the accuracy-neutral levers ranked.
# NAMING (fixed 2026-09-18): this file's findings are OCR-1, OCR-2 … The pilot plan
#         (textfiles/pilot_testing_readiness_plan.md) numbers ITS environment findings
#         F1-F6, and they are UNRELATED — its F1 is "the zip ships a dev config", not
#         "seven pages yield nothing". They were both F1/F2 until today, which was
#         confusing in exactly the way you would expect. Do not cross-reference them.
# Branch on build: phase-<n>-<short-description> (Rule 12).

===============================================================================
  1. WHY THIS RESTUDY EXISTS
===============================================================================

  User order, 2026-09-18: "restudy the whole normal doc parsing (i want to start with the
  ui improvement first)."

  THE TRIGGER. The pilot's first polish target is normal-doc parsing (pilot plan §5),
  because the client's normal documents are SCANNED physical copies (§2 Fact 2). The N1
  browser check ran that path on a real scanned file and produced a result that is worth
  more than the check it was written for — see §3.

  THE POINT OF A RESTUDY (the discipline this project already uses): measure the CURRENT
  behaviour before designing anything. The item-(3) restudy in the teach-grid plan retired
  an agreed spec because measurement showed it did not do what it was agreed to do. The
  same rule here: no UI is designed in this file until the file says what today's UI
  actually does and why.

  SCOPE ORDER (user's): UI first. Then the read path, then extraction, then the loop.

===============================================================================
  2. WHAT THE NORMAL-DOC PATH IS (map, so findings have addresses)
===============================================================================

  ENTRY        Admin → Projects → Create Project modal
               resources/views/admin/_create_project_modal.blade.php
                 :98   <input type="file" id="documentFileInput" multiple
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png">
                 :194  the ABC field (#projectAbc) — wizard PAGE 4, "Project Setup"
                 :468  document-parser-core.js  (module, $jsv-versioned)
                 :469  document-parser.js
               $jsv = asset($p) . '?v=' . filemtime(public_path($p)) — mtime-based, so a
               code change busts its own cache. The LEARNED-SET cache is separate and
               lives in sessionStorage (survives a hard refresh; incognito clears it).

  READ         document-parser.js — pdf.js text layer first; textless pages go to
               tesseract.js (lazy ~30 MB WASM). Per page: rotation, scale-2 and crop
               fallbacks, a memoising cache keyed file#size#page@rotation.
               OCR_PAGE_LIMIT = 10 (:386), overridable via window._ocrPageLimit (1..50).
               Returns { text, ocr, truncated, scannedTotal, scannedRead, numPages,
               ocrPages, scannedPageNums, landscapePages, pagesVisited, pageChars,
               soupyPages } (:895).

  EXTRACT      document-parser-core.js — pure. parseProjectInfo / parseProjectInfoPooled
               over the CONCATENATED text of all pages of all files. Fields: title,
               location, budget, abc, budgetSource, category, startDate, endDate,
               duration, contractor.

  SHOW         document-parser.js — the OCR page strip + "See & straighten" preview, then
               the auto-fill PREVIEW MODAL (:1974 onward) with one row per field.
               components/autofill-preview-modal.blade.php.

  LEARN        L1-L3: a miss is taught, approved in the Learned Keywords desk, and fills
               free afterwards.

===============================================================================
  3. THE BROWSER EVIDENCE THAT STARTED IT (user, 2026-09-18, real files)
===============================================================================

  This was the N1 browser check (pilot plan §8). Both steps are recorded there as the N1
  result; they are repeated here because stage 1's findings come straight out of them.

  STEP 1 — pdfdocstest5_selectable_text.pdf (clean, selectable)  ✅ CONFIRMED
    ABC = 4834297.59. Before phase-23 this box was REQUIRED to be empty (that was the
    blinding check, learning-loop plan :1080). The inversion is the proof N1 landed.
    Also visible, and NOT part of the check: Contract Price offered a pick-one between
    4323196.77 and 4204128.84; Category and Budget Source read "Not found — fill manually".

  STEP 2 — pdfdocstest5.pdf (the SCANNED original, 10 pages)  ⚠ ABC NOT DETECTED
    ABC = "Not found — fill manually".
    THE N1a BAR WAS MET: the value is blank, not 59.00 or any cents-sized number, so the
    written-cents guard held on a real scanned file. Nothing about N1/N1a is contradicted.
    THE REAL RESULT IS ELSEWHERE. The page strip says, for PAGE 7 OF 10:
      "nothing at all — try turning it"   and   "Nothing turned since the last read."
    And page 7 is THE page that carries the figure. In the preview image a human reads,
    without effort: "TOTAL ESTIMATED PROJECT COST P 4,834,297.59", above it 3,714,320.00
    and 118,227.14, under a signature block.
    SO: the document says it, the reviewer can see it, the preview renders it — and the
    parser got NOTHING from that page.

  STEP 2b — THE SAME FILE RE-RUN WITH window._parserDebug = true (user, same day)
    THE NUMBERS THAT MATTER, from the run notice verbatim:
      "In pdfdocstest5.pdf: visited all 10 pages; 3 of 10 scanned pages yielded text
       (10 per file selected) — scan complete."
    Read that carefully, because it settles the stage-1 hypothesis outright:
      visited ALL 10       nothing was skipped
      10 of 10 SCANNED     every page needed OCR; there is no text layer anywhere
      3 yielded text       SEVEN PAGES RETURNED ESSENTIALLY NOTHING
      "scan complete"      the NOT-truncated branch (:2055) — the budget was never reached
    The strip's own badge agrees: "7 unread". The read-depth control already sits at
    "10 pages per file" with a Re-scan button beside it.
    THE AI NOTICE THAT FOLLOWED: "Parsing looks uncertain — the local reader could not
    fill much. Try AI assist? Sends at most 2 confusing pages (never the whole file)."
    Worth noting for pilot item P5: this offer appears exactly when the reader fails —
    and the AI tier currently answers ~2% of calls (local_llm_parser_plan.md §4). On this
    document a reviewer would be offered a second opinion that almost certainly never
    arrives, after already waiting through a ten-page OCR.
    WHAT THE VOTE LOG SHOWED (window._autofillVoteDebug):
      startDate  winner 2025-07-14, count 2, score 1.85, 2 excerpts
      endDate    winner 2026-03-13, count 1, score 0.85, 1 excerpt
      Both correct, both from the 3 pages that DID read. So extraction is working fine on
      whatever text it is given — which points the whole problem at the read path.

  STEPS 3 AND 4 — NOT RUN. The user has no further real files to prove the learned-path
    and multi-file-pooling regressions with, and did not want to judge them on synthetic
    ones. Recorded as OWED, not as passed. (Pilot plan N2 — "get real PMU scans" — is
    exactly this shortage.)

===============================================================================
  4. STAGE 1 — THE UI, AS IT ACTUALLY BEHAVES  ✅ RESTUDY DONE (read-only)
===============================================================================

  Five findings and one hypothesis. Each cites the code. VERIFIED = read in the source
  today. HYPOTHESIS = consistent with the evidence and NOT yet measured.

  ── U1. THE PAGE BADGE NAMES ONE CAUSE AND OFFERS ONE CURE ── VERIFIED
     document-parser.js:1139 ocrPageWhy():
       if (chars === null || chars === 0) return 'nothing at all — try turning it';
     The function's own comment is more honest than its output:
       "'Nothing at all' … is usually orientation OR A DARK SCAN".
     The message names only orientation, and the panel offers only "turn this page" /
     "turn whole file". On the user's page 7 the scan is UPRIGHT AND LEGIBLE, so the one
     affordance offered is the one least likely to help. The reviewer is left with a
     button that will not fix their problem and no other move.
     Worse, it is stated as advice ("try turning it"), so a reviewer who follows it and
     sees no change learns that the system is broken, not that the cause was different.

  ── U2. A MISSING FIELD NEVER SAYS WHY ── VERIFIED
     document-parser.js:1985:
       placeholder="${f.value ? '' : 'Not found — fill manually'}"
     One string for every cause. In the user's two runs the SAME words covered at least
     three different situations:
       ABC on the scan         the page that holds it yielded no text at all
       Category                no category label appears anywhere in the document
       Budget Source           not stated in this document type
     These need different actions from the reviewer — fix the scan, pick from a list,
     ignore — and the UI cannot tell them apart. This is the single biggest gap between
     what the system KNOWS and what it SAYS.

  ── U3. FIELDS CARRY NO PAGE PROVENANCE, SO U2 CANNOT BE FIXED CHEAPLY ── VERIFIED
     The read loop knows the page of every line (pageNum, scannedPageNums, ocrPages,
     pageChars — document-parser.js:817-895). Extraction then runs over the CONCATENATED
     text of all pages of all files, and nothing on an extracted field records where it
     came from. Grep for fromPage / sourcePage: no hits.
     CONSEQUENCE: the preview cannot say "ABC is missing because page 7 read nothing",
     because by the time a field exists, the page is gone. Any real fix for U2 needs a
     page (or at least a file) stamp carried from the read into the field — a data
     change, not a wording change. THIS IS THE STRUCTURAL FINDING OF STAGE 1.

  ── U4. THE PICK-ONE ASKS THE REVIEWER TO ARBITRATE WITH NO EVIDENCE ── VERIFIED
     The reviewer sees two bare numbers and must choose:
       document-parser.js:1956-1962 renders ONLY d.winner.value / d.runnerUp.value.
     The evidence to choose with EXISTS and is discarded twice on the way:
       core :1406  voteCandidatePoolDebug rows carry
                   { value, norm, count, score, labeledCount, files, patterns, excerpts }
                   — including up to TWO EXCERPTS of the surrounding text per value
                     (:1403 keeps them, capped at 2).
       core :1422  pooledDisagree narrows to { value, count, files } — excerpts and
                   patterns dropped.
       ui   :1961  renders value only — count and files dropped too.
     So the parser knows each candidate's surrounding text, which files it came from and
     how often it was seen, and shows none of it. On the user's scan the two offered
     values were 4,323,196.77 and 4,323,196.00 — the same figure read two ways, which an
     excerpt would have settled instantly. This is the cheapest high-value UI fix found
     in stage 1: the data is already computed and already capped for size.
     ⬆ STRENGTHENED BY THE 2026-09-18 VOTE LOG — the amber box means TWO OPPOSITE THINGS
     and looks identical in both. On the SELECTABLE file it offered 4,323,196.77 vs
     4,204,128.84; the excerpt behind the second reads "… Original Contract Time 185
     Calen… Amount Php4,204,128.84 | 4. Revised Contract Amo…". So those are two DIFFERENT
     FACTS — an original and a revised contract amount — both genuinely in the document,
     and the reviewer must know which the project needs. On the SCAN it offered
     4,323,196.77 vs 4,323,196.00: ONE fact, read two ways, where the right answer is
     "they are the same number and we misread it once".
     Those need opposite responses — choose deliberately vs merge and move on — and today
     they are the same amber box with the same two bare buttons. Showing the excerpt does
     not just help; it is the only thing that distinguishes the two situations. It also
     costs nothing: the excerpts are already mined, already capped at 2, already carried
     into the vote rows, and already inspectable by hand via window._autofillVoteDebug —
     which is to say the data is one function signature away from the screen.

  ── U5. THE STRIP AND THE FIELDS ARE TWO UNCONNECTED STORIES ── VERIFIED (by absence)
     The page strip knows page 7 failed. The preview knows ABC is empty. Nothing joins
     them, in either direction: no "this page's failure cost you these fields", no "this
     field is missing, here is the page it should have come from". The reviewer has to
     hold both screens in their head and guess the link. Same root cause as U3.

  ── H1. "THE BUDGET RAN OUT BEFORE PAGE 7" ── ❌ FALSIFIED 2026-09-18, TWICE OVER
     RETAINED IN FULL because a wrong hypothesis that has been killed is worth more than
     a deleted one: it stops the next session re-proposing it.
     WHAT IT CLAIMED: OCR_PAGE_LIMIT = 10 on a 10-page scan, with the per-page fallback
     rungs each consuming a budget unit, so the budget could be spent before page 7; a
     never-attempted page reports chars = 0/null, the SAME state as an attempted-and-empty
     page, so ocrPageWhy() would say "nothing at all — try turning it" for both.

     KILLED BY MEASUREMENT (user, browser, window._parserDebug = true). The notice reads:
       "In pdfdocstest5.pdf: visited all 10 pages; 3 of 10 scanned pages yielded text
        (10 per file selected) — scan complete."
     That is the NOT-truncated branch (:2055; the truncated branch at :2054 says "later
     pages were not scanned" instead). So r.truncated was FALSE: the budget was never
     reached, and every page was visited.

     KILLED BY THE CODE TOO — my reading of it was simply wrong. The fallback chain's own
     comment (:712) states: "Budget still counts pages, never attempts." The five
     _ocrPagesDone++ sites I counted are ALTERNATIVE EXITS of one page's path (osd hit,
     retry, plain recognize, cache hit), not cumulative increments. One page costs one
     unit. The mechanism H1 rested on does not exist.

     ALSO CORRECTED: stage 1 said truncation might not be surfaced. It IS, honestly and
     at run level — :2049 carries the intent verbatim, "three honest states — complete
     (visited everything; how many yielded text) vs cut (stopped at the budget). Never one
     sentence meaning both." The UI told the truth; the hypothesis was wrong.

  ── OCR-1. SEVEN OF TEN PAGES YIELD NOTHING THROUGH A THREE-RUNG LADDER ── ⚠ THE REAL FINDING
     This is what the measurement found instead, and it is worse than the hypothesis it
     replaced. Page 7 WAS attempted, and not once:
       :709-720  the dead-page fallback chain — plain recognize → scale-2 re-render →
                 cropped re-render. Each rung runs only while the page is still under 10
                 characters; the first yielding rung wins.
     So page 7 defeated all three rungs and produced under 10 characters, on a page whose
     figures ("TOTAL ESTIMATED PROJECT COST P 4,834,297.59", 3,714,320.00, 118,227.14) a
     human reads instantly in the system's own preview. And it is not alone: the notice
     says 3 of 10 scanned pages yielded text — SEVEN PAGES RETURNED ESSENTIALLY NOTHING,
     and the strip badge says "7 unread".
     THE PRECEDENT THAT MATTERS (:674-679, dated 2026-09-07): "giant canvases from 12MB
     phone-photo PDFs (test4 class) choke tesseract to SILENT EMPTY — even on perfectly
     upright, human-crisp pages (proven by pixels: test4 p1 @0° + p2 @90° yield 0)." The
     fix then was a 3000px longest-side cap (OCR_MAX_SIDE) on top of scale 3.
     ⇒ The same signature — upright, crisp, silent empty — is back on test5. Either the
     cap is not firing on these pages, or 3000px is still the wrong size for them, or the
     cause this time is not canvas size at all (contrast, a photographed grey background,
     a table layout tesseract segments badly). NOT YET MEASURED. This is stage 2's first
     question, and it is now the highest-value question in the whole restudy: 70% of a
     real document is unreadable to the parser.
     NEXT MEASUREMENT, both already possible with no code:
       (a) with _parserDebug on, look for "[Parser] OCR render capped: longest=… → scale=…"
           (:685) — it says whether these pages are being capped and to what scale;
       (b) press the strip's "Save page image ↓ p.7" button. That downloads THE ACTUAL
           PIXELS tesseract was handed. If that image looks poor, the fix is upstream of
           tesseract; if it looks fine, the fix is tesseract's configuration.

  ── OCR-2. THE SAME FILE GIVES A DIFFERENT NUMBER OF READ PAGES EACH RUN
         ── ✅ MECHANISM 1 CONFIRMED IN THE LOG §11.2; ROOT CAUSE FOUND §11.3;
            ⚠ A FOURTH MECHANISM FOUND (devtools open vs closed) §11.5
     User, 2026-09-18, on the second run of the identical file: "if you remember earlier
     the ocr gets 3 pages, now its 4 — there is an issue with the ocr right?" They asked
     for it to be NOTED and for the UI work to continue, so nothing was investigated.
     THE OBSERVATION, both from the run notice verbatim:
       run A   "visited all 10 pages; 3 of 10 scanned pages yielded text"   badge "7 unread"
       run B   "visited all 10 pages; 4 of 10 scanned pages yielded text"   badge "6 unread"
     Same pdfdocstest5.pdf, same 10-page read depth, both "scan complete".
     WHY IT MATTERS, and why it is worth more than it looks: a rules-based OCR read of a
     fixed file should be DETERMINISTIC. The same pixels through the same engine should
     give the same text. A run-to-run difference means something in the read path is not
     a pure function of the file — candidates, none checked:
       - the per-page fallback ladder is time- or state-sensitive (a rung that only fires
         when an earlier one is slow, or a cancelled/aborted page that is later retried);
       - the memoising cache (file#size#page@rotation) is serving or missing unevenly
         between runs;
       - rotations carried over from an earlier run change what is attempted;
       - tesseract itself is being fed a different canvas (the 3000px cap interacts with
         whatever scale survived), or its worker is warm vs cold.
     CONSEQUENCE IF LEFT: two identical uploads can fill different fields, and a reviewer
     who re-uploads "to check" gets a different answer with no explanation. That is worse
     for trust than a consistent miss, and it also undermines any measurement of OCR-1 —
     tuning against a moving baseline proves nothing.
     ⇒ STAGE 2 MUST ESTABLISH DETERMINISM BEFORE IT MEASURES ANYTHING ELSE. The first
     stage-2 action is now: run the same file three times and record scannedRead each
     time, BEFORE touching render scale or preprocessing. If the number moves, fix that
     first; OCR-1's "7 of 10 yield nothing" is not a stable measurement until it stops.

  ── U1 REVISITED. THE BADGE IS WRONG FOR A DIFFERENT REASON THAN H1 ASSUMED
     U1 stands, but its cause is now known: page 7 was read three ways and yielded
     nothing, so "try turning it" is not misleading because the page was skipped — it is
     misleading because ROTATION IS NOT THE FAILURE MODE. The page is upright; turning it
     re-runs the same ladder on the same pixels at a new angle and will fail the same way.
     The "7 unread" badge quietly says the same thing at file level and offers the same
     single cure.

===============================================================================
  5. WHAT STAGE 1 SUGGESTS (PROPOSED — nothing ordered, Rule 10)
===============================================================================

  Ordered by (evidence already in hand) ÷ (cost), not by ambition.

  ⚠ READ THIS BEFORE PICKING ONE. The 2026-09-18 measurement changed what "UI improvement"
  should mean here. Seven of ten pages of a real document are invisible to the parser
  (OCR-1). NO amount of better wording fixes that — the best possible UI on this run would
  say, very clearly, "we could not read 70% of your document". The UI items below are
  still worth doing and the user's order was UI first, so they are kept and sharpened;
  but STAGE 2 (the read path) is where this document actually gets parsed, and UI-1 has
  been rewritten to reflect that.

  UI-1  ~~SETTLE H1~~ ✅ DONE 2026-09-18 — H1 falsified (§4). SUPERSEDED BY: measure WHY
        seven pages yield nothing. Two no-code steps, both available in the UI the user
        already has open:
          (a) with _parserDebug on, look for "[Parser] OCR render capped: longest=… →
              scale=…" (:685) — does the 3000px cap fire on these pages, and to what scale?
          (b) press "Save page image ↓ p.7" in the strip — that is THE ACTUAL CANVAS
              tesseract was handed. If those pixels look poor, the fix is upstream of
              tesseract (render scale, contrast, the cap); if they look crisp, the fix is
              tesseract's own configuration (page-segmentation mode, language data).
        This is stage 2's doorway and the highest-value hour in the restudy.

  UI-2  SHOW THE EXCERPT IN THE PICK-ONE (U4). Widen pooledDisagree to carry the excerpts
        and counts it already receives, and render them under each button. No new
        computation, no new read, no data model change — the excerpts are already mined
        and already capped at 2. ⬆ PROMOTED after the vote log showed the same amber box
        covering two OPPOSITE situations (original-vs-revised amounts, and one figure
        misread twice) — the excerpt is what distinguishes them. Highest value per line of
        code in stage 1, and it does NOT depend on anything stage 2 finds.

  UI-3  SAY WHY A FIELD IS MISSING (U2), to whatever depth U3 allows. Two tiers:
        (a) CHEAP, no data change: distinguish "nothing in this document matched" from
            "this run had unread pages" using the run-level flags that already exist
            (truncated, scannedTotal vs scannedRead, soupyPages). On the user's run this
            alone would have turned a bare "Not found — fill manually" into something like
            "not found — 7 of this file's 10 pages could not be read", which is the
            difference between a dead end and a next step.
        (b) REAL, needs U3: stamp fields with the page/file they came from, so a missing
            field can point AT the page that failed. Bigger; decide after (a).

  UI-4  JOIN THE STRIP AND THE FIELDS (U5). Falls out of UI-3(b) almost for free; not
        worth doing on its own before it.

  UI-5  RE-WORD THE BADGE (U1). Now unblocked — H1 is dead, so "nothing at all" on this
        file genuinely means "read three ways, yielded nothing". The honest message is
        nearer "we read this page and got nothing — turning it is unlikely to help" with
        a different affordance than rotation. What that affordance SHOULD be depends on
        UI-1's answer, so do it after.

  NOT PROPOSED, DELIBERATELY: any redesign of the preview modal's layout, any new AI
  surface, any change to what the parser EXTRACTS. Stage 1 was a UI restudy; the
  extraction findings belong to stage 3 and do not exist yet.

===============================================================================
  6. STAGES NOT YET DONE
===============================================================================

  STAGE 2 — THE READ PATH (OCR).  ✅ RESTUDIED 2026-09-18 — SEE §10 (this entry is the
    pre-restudy brief, kept because it set the questions §10 answers).
    ⚠ ONE THING IT GOT WRONG, corrected in §10.3: it lists "what preprocessing happens
    and what does not" as an open question. preprocessOcrCanvas() DOES exist and runs on
    every page; the real question is its outlier-driven gate.
    The brief itself follows.
    ⬜ NOT STARTED — ⬆ NOW THE PRIORITY (OCR-1).
    The client's documents are scans, so this is where the system either works or does
    not, and on the one real specimen we have it does NOT: 7 of 10 pages yield nothing
    (§3 step 2b). Extraction is demonstrably fine on whatever text it is handed — the
    vote log read both dates correctly off the 3 pages that worked — so the loss is
    entirely upstream.
    ⚠ FIRST, BEFORE ANY OTHER MEASUREMENT: establish DETERMINISM (OCR-2). The same file gave
    3 read pages on one run and 4 on the next. Nothing measured here means anything until
    that stops moving. Run it three times, record scannedRead, then proceed.
    THEN (§5 UI-1): why does a legible, upright page return under 10 characters
    through plain recognize → scale-2 → crop? Note the 2026-09-07 precedent at :674 —
    oversized canvases from phone-photo PDFs "choke tesseract to silent empty … even on
    perfectly upright, human-crisp pages", fixed then with a 3000px longest-side cap. The
    same signature is back, so either the cap is not firing here, 3000px is wrong for
    these pages, or the cause is different this time.
    THEN: what preprocessing happens before tesseract and what does not (deskew? contrast
    normalisation? binarisation? — a legible page returning nothing smells like a
    preprocessing gap, not a model gap); the ~30 MB WASM first-load cost on a PMU desktop;
    whether a 10-page cap fits their real document lengths; and what the ~2s/page estimate
    in the read-depth control actually costs on their hardware.
    ALSO WORTH KNOWING: the "Try AI assist" offer fires precisely on runs like this one,
    and the AI tier answers ~2% of calls. A reviewer would wait through a 10-page OCR and
    then wait again for a second opinion that does not come. That is pilot item P5 and it
    is no longer hypothetical — it is what this document does today.
    NOT BLOCKED any more on real PMU scans for its FIRST question: pdfdocstest5.pdf
    already reproduces the failure. Real scans are still needed before tuning anything,
    because a fix calibrated on one team-made specimen is a fix for that specimen.

  STAGE 3 — EXTRACTION.  ⬜ Field by field, against real documents: which labels are
    recognised, which of the ten fields realistically fill, and whether "Not found" is
    usually a reading failure or a vocabulary failure. Note the open item already carried
    from N1a: extractBudget has its own words-shape fallback and its own asMoney call and
    was NOT audited for the same written-cents defect.

  STAGE 4 — THE LEARNING LOOP.  ⬜ With blinding off (phase-23), the hardcoded patterns
    and the learned labels are both live for the first time in months. The interaction
    was never browser-proven in that state — step 3 of the N1 check is precisely what is
    still owed. Priority question: does a hardcoded pattern now override an approved
    learned label? If it does, teaching quietly stopped mattering.

===============================================================================
  7. OPEN QUESTIONS
===============================================================================

  1. ~~H1 — did the OCR budget run out before page 7?~~ ❌ ANSWERED 2026-09-18: NO. The
     run reported "scan complete" and the budget counts pages, not attempts (§4).
  1b. THE REPLACEMENT, and the most important question in this file: WHY do 7 of 10
     legible pages yield nothing through a three-rung ladder? Two no-code measurements
     are waiting in §5 UI-1 — the "OCR render capped" debug line, and the downloaded
     page-7 canvas.
  2. Is "TOTAL ESTIMATED PROJECT COST" even in the ABC vocabulary? On the SELECTABLE twin
     the ABC filled — but that file's ABC may come from a different page or wording
     entirely. Worth checking before blaming OCR for everything: the scan and the
     selectable version may be failing at different layers. NOT MEASURED.
  3. Do real PMU scans look like pdfdocstest5.pdf at all? Everything in stage 1 is
     evidence from ONE specimen the team made, not from the client.
  4. What is an acceptable wait? Stage 2 cannot set a page budget without knowing what a
     PMU staff member will sit through on a PMU desktop.

  ── Q3. WHAT SHOULD HAPPEN WHEN AN EXTRACTED CATEGORY / BUDGET SOURCE / CONTRACTOR
         IS NOT IN THE DATABASE? ── ✅ BUILT 2026-09-18
     ✅ ORDERED AND BUILT 2026-09-18 — see the Q3 entry in §8. The text below is the
     proposal as written before the build; it is unchanged so the reasoning survives.
     Asked verbatim: "for the contractor, currently even when i apply the extracted, if
     the contractor extracted is not existing in the database, it will not be auto picked
     right? same case with the others. now what can we do? should we save those as text?"

     THE FACTS FIRST (checked in the schema and the fill code today)
       projects.contractor_id      nullable FK -> contractors, ON DELETE SET NULL
       projects.category_id        nullable FK -> categories
       projects.budget_source_id   nullable FK -> budget_sources
       There is NO name/text column on projects for any of the three. So "save it as
       text" is not a small change — it is a migration plus model, validation, display,
       filter and report changes.
       The fill is FUZZY already, not exact: tryMatchContractorDropdown() and the
       category matcher score word overlap and accept at >= 0.5 confidence. So a near
       miss ("SkyScraper Construction" vs "SkyScraper Construction and Engineering
       Services") already matches. What fails is a name that is genuinely absent.
       On a miss today: the box is left EMPTY and an indicator is shown — for category,
       'Category "X" not found — select existing or ask admin via (+) Category.'
       The preview modal now also keeps the extracted text visible as
       "… — from document (not in list)", so the reviewer at least SEES what was read.

     WHY "SAVE AS TEXT" IS THE WRONG DEFAULT, especially for CONTRACTOR
       A contractor is not a label in this system — it is a record other features hang
       off. Contractors log in (Google-only), see their own projects, receive
       notifications, carry ratings and warranty submissions. A project whose contractor
       exists only as free text would: not appear in contractor-filtered views, not link
       to ratings, not notify anybody, and not be visible to the contractor themselves.
       It would look filled in while being functionally empty — which is worse than an
       obvious blank, and exactly the kind of thing a pilot user would not discover until
       it matters. Two sources of truth for one field also means every consumer has to
       decide which to believe, forever.

     THE PROPOSAL (three tiers, each separable, NONE ordered)
       (a) CATEGORY and BUDGET SOURCE — offer CREATE FROM THE PREVIEW. These are simple
           lookup tables; the form already has (+) buttons and manage endpoints
           (openManageCategoriesFromCreateProject / the budget-source equivalent). A
           "not in list" value could carry an "Add as new category" action that creates
           the row and selects it. Keeps referential integrity, one click, no schema
           change. This is the cheap, high-value tier.
       (b) CONTRACTOR — NEVER auto-create, and do not store as text. Creating a
           contractor from OCR text would mint junk records with no company details and
           no user account, in the very table the pilot is supposed to seed with the
           PMU's real contractor Gmails. Instead make the miss LOUD and actionable:
           keep the extracted name on screen beside the field and link to the contractor
           list, so a human decides. (Most of this exists; it is the prominence that is
           lacking.)
       (c) IF the client genuinely needs to record a contractor who is not in the system
           yet, the honest fix is a real contractor record created deliberately — not a
           text column on projects. Worth asking the PMU whether that case actually
           happens before building anything for it.

     WHAT I WOULD NOT DO: add contractor_name / category_name text columns to projects.
     It is the fastest thing to build and the hardest thing to remove later.

     NEEDS THE USER'S DECISION (Rule 10) before any of (a)/(b) is built.

===============================================================================
  8. SESSION LOG
===============================================================================

  2026-09-18 — FILE CREATED. Stage 1 (UI) restudied read-only. NO APP CODE.
    Ordered by the user after the N1 browser check: "restudy the whole normal doc parsing
    (i want to start with the ui improvement first)."
    Recorded: the browser evidence (§3), five verified UI findings and one hypothesis
    (§4), a proposed order (§5), and the three stages still to do (§6).
    Verified in source today: ocrPageWhy():1139 · the placeholder :1985 · the pick-one
    render :1956-1962 · voteCandidatePoolDebug rows carrying excerpts (core :1403, :1406)
    · pooledDisagree narrowing them away (core :1422) · no fromPage/sourcePage anywhere ·
    OCR_PAGE_LIMIT = 10 (:386) with five _ocrPagesDone increment sites · ocrTruncated
    computed at :841/:895.
    NOT verified: H1 (needs one browser run); whether ocrTruncated is surfaced anywhere.
    NOTHING IS ORDERED.

  2026-09-18 (same day, second pass) — H1 MEASURED AND FALSIFIED; OCR-1 FOUND. NO APP CODE.
    The user re-ran pdfdocstest5.pdf with window._parserDebug = true and sent the notice
    and the vote log. Both are quoted verbatim in §3 step 2b.
    H1 IS DEAD, twice: the notice is the not-truncated branch ("visited all 10 pages …
    scan complete"), so the budget was never reached; and the fallback chain's own comment
    (:712) says "Budget still counts pages, never attempts", which means my reading of the
    five _ocrPagesDone++ sites as cumulative was simply wrong — they are alternative exits
    of one page's path. Recorded as a dead end rather than deleted.
    I ALSO WITHDRAW a stage-1 doubt: truncation IS surfaced honestly at run level (:2049
    states the intent, "three honest states … never one sentence meaning both"). The UI
    told the truth; the hypothesis did not.
    WHAT REPLACED IT (OCR-1): 7 of 10 scanned pages yield nothing, through all three rungs of
    the dead-page ladder, on pages a human reads at a glance. Extraction is fine on what
    it receives (both dates read correctly, with excerpts, off the 3 pages that worked).
    So ~70% of a real document is invisible to the parser, entirely in the read path.
    U4 GOT STRONGER from the same log: the pick-one's two values were ORIGINAL vs REVISED
    contract amounts on the selectable file (two real facts) and one figure misread twice
    on the scan — opposite situations, identical UI, and the excerpt is the only thing
    that tells them apart.
    STAGE 2 is now the priority and §5 UI-1 is rewritten into its doorway: read the
    "OCR render capped" debug line, and download the page-7 canvas to see what tesseract
    was actually handed. Both are no-code and available in the UI already on screen.
    STILL NOTHING ORDERED.

  2026-09-18 — STAGE 1b ✅ BUILT: the preview modal's LAYOUT, the choice fields, and the
  stuck scanning banner. Branch phase-23-abc-blinding-lift (same branch as N1/N1a).
  UNCOMMITTED. User order, clarifying what "UI improvement" meant: "i mean the layout and
  design first especially the preview modal … widen the modal … 2 column layout … compact
  more … category/budget source/contractor should also be an option … hide [the scanning
  label] if no extraction is happening."

    ⚠ A CORRECTION TO THIS FILE'S OWN FRAMING. Stage 1 read "UI" as the diagnostic story —
    what the system says when it fails (U1-U5). The user meant LAYOUT AND DESIGN. Both are
    real, and U1-U5 stand as findings, but the ordered work is this entry. §5's UI-1..UI-5
    remain PROPOSED and unordered; they are a different axis from what was just built.

    WHAT CHANGED
      resources/views/components/autofill-preview-modal.blade.php
        modal-lg -> modal-xl, plus modal-dialog-scrollable (long field lists scroll the
          dialog body, not the page).
        The two-column TABLE (Field | Extracted value) is gone. The container is now
          <div id="autofillPreviewRows" class="row g-2"> — same id, so the JS contract is
          unchanged and nothing else in the codebase referenced the table.
        Header/body/footer padding tightened; intro copy shortened; footer buttons btn-sm.
      public/js/admin/projects/document-parser.js
        the `fields` array carries `w` (12 = full row, 6 = pairs up) and `choices`
          (the id of the LIVE form control whose options this field should offer).
          Re-grouped: title / location / description full width, then
          Category|Contractor, Contract Price|ABC, Budget Source|Duration,
          Start Date|End Date. Order is presentational only — the L1 snapshot below it
          is keyed by field NAME, so nothing downstream depends on it.
        choiceOptionsFrom(selectId) — reads the real <select>'s options live and drops
          the "-- Select --" placeholders.
        the renderer emits one grid cell per field, label above input, and for `choices`
          fields an <input list=…> + <datalist>.
        showAutofillScanning / showAutofillWarning now stamp banner.dataset.state
          ('scanning' / 'warning'); new hideAutofillScanningBanner() clears the spinner
          ONLY when it is still the spinner; the main autofill flow gained a `finally`
          that calls it.
      public/css/admin/projects/_create_project_modal.css
        the dead `#autofillPreviewModal tbody tr:last-child td` rule removed with the
          table it styled; new .afp-cell (border, radius, height:100%) so half-width
          pairs stay visually separate and equal-height. THE BORDER IS ON AN INNER CELL,
          never on the Bootstrap column — the column carries the grid gutter, and a
          border there makes neighbouring cells touch (caught while building).
      resources/views/layouts/{app,stafflayouts}.blade.php
        _create_project_modal.css is now versioned by filemtime. Every CSS link in those
        layouts is a bare asset() with no cache-busting, which is the documented
        "browser serves a ghost" incident — and this change is markup + stylesheet
        together, so a stale stylesheet would render the new grid unstyled. Only the one
        file that changed was versioned; the rest are untouched and still unversioned.

    WHY input+datalist RATHER THAN <select> for the three choice fields. The user asked
    for "an option … if possible to do both is better". A datalist IS both: a dropdown of
    the real options AND a free-text box. It also keeps the existing contract intact —
    applyAutofillFromPreview() reads el.value.trim() and autofillFormFields() matches
    these fields BY NAME, so the control must keep yielding the name as its value. A
    <select> would have yielded an option value (an id) and broken that, or needed a
    parallel name->id map. The datalist needs neither, and an extracted value that matches
    no option is still preserved and still applicable instead of being silently dropped.

    THE STUCK BANNER — WHAT IT ACTUALLY WAS. The banner element is SHARED by two states:
    the in-progress "Scanning…" spinner and the finished "Auto-fill notice" warning.
    hideAutofillBanner() was called only from showAutofillPreview() (:2179) and when the
    modal closed. So any run that finished WITHOUT opening the preview left the spinner
    turning on a run that was already over — exactly the report. The fix is not "hide it
    at the end", which would wipe the legitimate warnings this very path raises; it is the
    data-state stamp, so the cleanup can tell a live spinner from a notice. A newer run
    re-stamps 'scanning' after the older run's finally, so overlapping runs are safe too.

    VERIFICATION
      node --check on both parser files — clean (one stray brace caught and removed).
      npm run test:js — 231 tests, 231 pass, 0 fail (unchanged; the core is untouched by
        this entry, and the suite does not cover the DOM half).
      php artisan view:clear — run, because a Blade component changed.
      NO PHP, no routes, no core extraction logic touched.

    ⬜ BROWSER CHECK OWED (user) — this is entirely a DOM path, so suite-green proves
      nothing here (AGENTS.md: browser-proven beats suite-green for DOM paths):
      1. Upload a document → preview modal is WIDER, fields are in a 2-column grid, the
         long three (title, location, description) still span the full width.
      2. Category, Budget Source and Contractor each show a dropdown arrow / list of the
         real options AND still accept typing. An extracted value that matches no option
         must still be visible in the box.
      3. Apply to Form still fills the real form correctly from all three — especially a
         TYPED value that matches an existing option by name.
      4. THE BANNER: trigger an extraction and let it finish. The "Scanning documents…"
         spinner must disappear on its own, WITHOUT closing the modal. Then trigger a run
         that ends in the amber "Auto-fill notice" (the scanned test5 does this) and
         confirm that notice still appears and STAYS.
      5. Narrow the window / phone width: the pairs should stack, not overflow.


  2026-09-18 — STAGE 1b FOLLOW-UP: BOTH REPORTED DEFECTS FIXED. Same branch, uncommitted.
  User: "the category/contractor/budget source is still not using the option (the one that
  lists like this [screenshot of the real create-form dropdown]) … also the label is still
  there even after the preview modal appears and I click apply to form."

    DEFECT 1 — THE CHOICE FIELDS SHOWED NO OPTIONS. Two independent causes, both mine.
      (a) TIMING, the real one. Category and Budget Source options are FETCHED
          (loadCategories / the budget-source loader in public/js/_create_project_modal.js
          — option.value = id, option.textContent = name). At the moment the preview
          renders, those selects usually still hold only "-- Select Category --", which my
          filter correctly drops — leaving ZERO options, so no list was emitted at all.
          I read the options once, at the wrong moment, and never looked again.
      (b) THE CONTROL WAS WRONG ANYWAY. A <datalist> renders as a plain text box with a
          faint arrow; it is not the dropdown the user pointed at. "Do both" was the wrong
          reading of the request — they wanted the create form's dropdown.
      FIX: the three fields now render a real <select class="form-select form-select-sm">
      carrying data-choices-from (the live control's id) and data-extracted (what the
      document said). New syncAutofillChoiceFields() fills them from the live control and
      runs TWICE — at render, and again on shown.bs.modal — so whichever pass happens
      after the fetch lands is the one that sticks. Running twice is harmless.
      WHAT IS PRESERVED: every option's VALUE IS THE NAME, not the id, because
      applyAutofillFromPreview() reads .value and autofillFormFields() matches these
      fields BY NAME — an id would have silently broken the fill. And an extracted value
      matching no option is kept as its own first option, labelled "— from document (not
      in list)", so a real dropdown still cannot lose what the document said. That is the
      "both" that actually mattered.

    DEFECT 2 — THE SPINNER STILL WOULD NOT GO. My first fix was too narrow.
      What I had: a data-state stamp plus a `finally` that cleared the spinner at the end
      of a run. What I MISSED: showAutofillScanning() is called again for EVERY OCR page,
      so any call arriving after a run had finished simply repainted the spinner, and by
      then nothing was left to clear it. The state stamp faithfully marked it 'scanning'
      again. hideAutofillBanner() at the top of showAutofillPreview() also runs before the
      modal opens, which is why it looked like the preview "should" have cleared it.
      FIX — make the spinner impossible rather than merely cleaned up:
        _autofillScansInFlight   new counter. runDocumentAutofill() increments it before
                                 the first showAutofillScanning() and decrements it in its
                                 finally; cancelDocumentAutofill() resets it to 0.
        showAutofillScanning()   RETURNS IMMEDIATELY when the counter is 0. A late call can
                                 no longer paint anything. This is the actual fix.
        hideAutofillScanningBanner()  also refuses to clear while a NEWER run is in flight,
                                 so overlapping runs do not fight.
        applyAutofillFromPreview()    zeroes the counter and clears, because once the
                                 reviewer is acting on the result nothing is "in progress"
                                 to them — this is the exact moment the user reported.
        shown.bs.modal           clears too, belt and braces.
      The warning banner is untouched by all of it: showAutofillWarning() stamps 'warning'
      and the cleanup only ever touches 'scanning'.

    LESSON WORTH CARRYING. Both defects are the same mistake in different clothes: I
    checked a piece of live state ONCE, at a moment that happened to be too early (the
    option list), and I cleaned up a shared element at a moment that happened to be too
    early (the spinner). Neither was a logic error; both were timing. In this UI the
    async boundaries — a fetch that fills a select, a per-page callback that repaints a
    banner — are where the bugs live, not in the branching.

    VERIFICATION
      node --check clean (two stray lines caught during editing and removed).
      npm run test:js — 231 / 231 / 0, unchanged. The suite covers the pure core, NOT this
        DOM code, so it proves only that nothing regressed in the core. The four browser
        steps below are the real bar.
      php artisan view:clear run again.

    ⬜ BROWSER CHECK OWED (user), sharpened by what was wrong:
      1. Category / Budget Source / Contractor now show a REAL dropdown listing the same
         options as the create form (Maintenance, New Construction, Renovation, Repair, …).
      2. Upload a document whose category matches nothing: the box should still show the
         extracted text as "… — from document (not in list)" rather than going blank.
      3. Apply to Form still fills the real form from all three — including a value chosen
         from the dropdown AND the "not in list" case.
      4. THE SPINNER: let an extraction finish. It must vanish by itself, without closing
         the modal and without pressing Apply. Then press Apply on another run — still
         gone. Then run the scanned test5 and confirm the amber "Auto-fill notice" STILL
         appears and STAYS (that is the banner the cleanup must never touch).

  2026-09-18 — STAGE 1b, THIRD PASS: the spinner, properly this time. Plus OCR-2 noted.
  User: "the preview modal already appeared and when i close it, the ocr label is still
  there." Also: "the ocr gets 3 pages, now its 4 — there is an issue with the ocr right?
  (for now just note this, and focus first on UI and layout improvements)."

    THE DROPDOWNS ARE CONFIRMED WORKING (user screenshot): Contractor opens a real list
    with the live options, and the extracted value shows as "… — from document (not in
    list)" when it matches nothing. Category and Budget Source render the same way.
    That half of the previous entry is proven; only the spinner remained.

    THE SPINNER — WHY TWO FIXES FAILED, AND WHAT CHANGED
      Attempt 1 cleared it at the end of a run. Missed: showAutofillScanning() is called
        again per OCR page, so a late call repainted it.
      Attempt 2 added an in-flight counter and gated the paint on it. Better, and the
        gate is kept — but it still assumed every run reaches its finally so the counter
        returns to zero. Worse, attempt 2 ALSO added a guard to the hide
        (`if (_autofillScansInFlight > 0) return;`). That guard can only ever PREVENT
        hiding. If the counter fails to reach zero for any reason — an await that never
        settles, a run superseded mid-flight — the guard becomes the bug it was sitting
        next to. It is removed. The paint gate already stops stale repaints; the hide
        does not need a second opinion.
      Attempt 3 (this one) stops reasoning about paths and implements the requirement as
        stated — "hide it if no extraction or OCR extraction process is happening":
          every paint records a heartbeat (_autofillSpinnerTick);
          a WATCHDOG (armAutofillSpinnerWatchdog) runs once a second WHILE the spinner is
            on screen and takes it down when either no run is in flight OR nothing has
            repainted for 6s — a live scan repaints per page, so silence that long means
            the work is over however it ended. Self-disarming, costs nothing when idle.
          hidden.bs.modal on the preview zeroes the counter and clears — that is the
            reviewer's exact reported moment ("when i close it, the label is still there").
      The warning banner remains untouched by all of it: it stamps state 'warning' and
      every clear path only ever touches 'scanning'.

    LESSON. Three attempts, and the first two were both me reasoning about which code path
    leaks instead of constraining the observable state. The spinner is a piece of UI whose
    correctness is stateable in one sentence; the watchdog encodes that sentence directly,
    so it holds whether or not I found the leaking path. Reach for that sooner when the
    requirement is simpler than the control flow.

    OCR-2 NOTED, NOT INVESTIGATED (§4): the same file yielded 3 read pages on one run and 4
    on the next. Recorded as a finding because a rules-based OCR read of a fixed file
    should be deterministic, and because it makes OCR-1 unmeasurable until it stops. Stage 2
    now opens with "establish determinism", ahead of the render-cap and pixel questions.
    Per the user's instruction, nothing about it was changed.

    VERIFICATION: node --check clean (one deleted function signature caught and restored);
    npm run test:js 231 / 231 / 0; php artisan view:clear. No PHP, no core extraction.

    ⬜ BROWSER CHECK OWED — the spinner, the same four ways it has now failed:
      1. Let a scan finish with the preview opening: spinner gone without any click.
      2. Close the preview: still gone. (The reported case.)
      3. Press Apply to Form on another run: still gone.
      4. Run the scanned test5 and confirm the amber "Auto-fill notice" appears and STAYS
         — the clear paths must never touch it.
      5. Start a scan and cancel the create-project modal mid-read, then reopen: no
         orphaned spinner.

  2026-09-18 — THE SCAN INDICATOR IS REPLACED, NOT PATCHED AGAIN (4th pass). Same branch.
  User: "its still there — can you replace it with a different label/sign of extraction."

    WHY REPLACING WAS THE RIGHT CALL, AND WHY THREE FIXES FAILED
      The root cause was never a missing clear. It was that the scanning spinner and the
      finished-run "Auto-fill notice" WERE THE SAME <div> (#autofillBanner). Sharing one
      element means clearing it can never be unconditional — "hide it, but only if it is
      not currently a warning, and only if no newer run is going" — and each of those
      conditions leaked differently:
        fix 1  cleared at end of run; showAutofillScanning() repaints per OCR page, so a
               later call put it straight back.
        fix 2  added an in-flight counter and gated the paint on it — and ALSO added a
               guard to the hide, which could only ever PREVENT hiding. Self-inflicted.
        fix 3  added a watchdog, but still watched a display style on the shared element.
      Every one of them was me defending a design that could not state its own rule.

    WHAT IT IS NOW
      A DEDICATED element, #autofillScanStatus, created when a scan starts and REMOVED
      from the DOM when it stops. The rule needs no conditions: IF IT IS ON SCREEN, A
      SCAN IS RUNNING. There is no hidden-but-present state left to get stuck in, and
      #autofillBanner (the warning) is a different element this code never touches — so
      the class of bug is gone rather than guarded against.
      It also READS differently, as asked: a slim indigo strip anchored beside the
      documents being read (after #documentsListContainer), with an indeterminate sweep
      instead of a spinner, and it NAMES THE PAGE — "Reading scanned page 7…". It moves
      while work is happening, so a stall is visible as a stall rather than looking
      identical to progress. Reduced-motion preference respected (strip stays, sweep
      stops).
      Removal points (all unconditional): the run's finally · applyAutofillFromPreview ·
      shown.bs.modal · hidden.bs.modal · cancelDocumentAutofill · the watchdog.
      The paint gate (_autofillScansInFlight <= 0 → return) is KEPT, because ocrPage()
      calls it per page and a late call must not be able to create the element at all.
      The watchdog is KEPT as a last line of defence, now checking for the ELEMENT
      rather than a style, removing it when no run is in flight or nothing has refreshed
      it for 6s.

    LESSON, and it is the same one twice over now. When a piece of UI keeps sticking,
    the question is not "which path forgot to clean up" but "why does cleaning up need
    a condition at all". Two elements with one job each need no conditions; one element
    with two jobs needs a correct condition on every exit, forever. The first is worth
    more than three careful fixes to the second.

    VERIFICATION: node --check clean (two edit slips caught and restored — a deleted
    function signature and a clobbered const line); npm run test:js 231/231/0;
    view:clear. No PHP, no core extraction logic.

  2026-09-18 — SCAN INDICATOR POLISH (user: "put it on top of the added documents text
  and also add the name of the file (not the full name if its long)"). Same branch.
    THE 4th-PASS REPLACEMENT IS CONFIRMED WORKING by the user's screenshot — the strip
    renders, names the page ("Reading scanned page 10…"), and no longer sticks.
    TWO REFINEMENTS BUILT
      PLACEMENT  the element is inserted 'beforebegin' of #documentsListContainer instead
                 of 'afterend', so it sits ABOVE the "Added Documents:" label and reads
                 as a heading for the list it belongs to rather than a footnote after it.
                 Its margin moved to 4px top / 10px bottom to hang the spacing downward.
      FILE NAME  _autofillCurrentFile is set per file in the read loop (module-scoped
                 because ocrPage() is several frames down and only receives the page),
                 reset at run start and on removal so a finished run cannot leave a stale
                 name behind. shortScanFileName() middle-truncates at 26 chars, KEEPING
                 THE EXTENSION — contractor file names are long and front-loaded with
                 boilerplate, and the page number must not be pushed off the strip. The
                 full name is on the element's title attribute, and the chip is capped at
                 45% width with ellipsis so CSS holds the line even if the JS budget is
                 ever wrong.
    VERIFICATION: node --check clean (one duplicated tail caught); npm run test:js
    231/231/0; view:clear. No PHP, no core extraction logic.

  2026-09-18 — Q3 BUILT (user: "Q3 lets go with your recommendation"). Same branch.
  The proposal in §7 Q3 is implemented as written: create-in-place for the two lookup
  tables, and a loud, honest miss for contractor. No schema change, no text columns.

    Q3(a) CATEGORY + BUDGET SOURCE — CREATE FROM THE PREVIEW
      When an extracted value matches no option, the preview now offers
      「 + Add "X" as a new category 」 under the field. Pressing it POSTs to the
      EXISTING store endpoint, reloads the live control from the server
      (loadProjectCategories / loadBudgetSources), and re-syncs the preview so the new
      row is present AND selected. If the reload does not bring it back (a different
      role's list route, a cache), the option is appended locally so the reviewer is not
      stuck; the next real load agrees.
      GATED BY THE SAME POLICY AS THE (+) BUTTONS. The store routes live in the admin
      group, so _projectModalConfig now carries canCreateCategory / canCreateBudgetSource
      computed from CategoryPolicy / BudgetSourcePolicy in the Blade (both policies
      exist). A staff user sees no button rather than a button that 403s.
      Duplicate names are treated as ordinary, not as failures worth shouting about — the
      server's message is shown and the list reload surfaces the existing row either way.

    Q3(b) CONTRACTOR — NEVER CREATED, NEVER STORED AS TEXT, NEVER SILENT
      The unmatched branch used to show a bare "not parsed" indicator, so the name the
      document gave us was simply lost. It now names it:
        "The document says <X>, which is not in the contractor list. Closest match: <Y>.
         Pick the right one, or add the contractor first — it is not created
         automatically."
      The "closest match" half only appears when the fuzzy matcher found something it was
      not confident enough to choose (score > 0 but < 0.5) — a near miss is exactly the
      case a human eye settles in a second.
      WHY NOT CREATE OR STORE AS TEXT, recorded here because it will be asked again:
      projects.contractor_id is an FK, and contractors carry logins, notifications,
      ratings and warranty submissions. A contractor minted from OCR text would be a
      hollow record in the very table the pilot seeds with the PMU's real contractors;
      a contractor stored as loose text would LOOK filled while dropping out of
      contractor-filtered views, ratings and notifications. Both are worse than an
      obvious blank with a clear note.

    FILES: public/js/admin/projects/document-parser.js (renderChoiceCreateAction,
    createChoiceFromPreview, setContractorMissNote, and the sync loop calling the first);
    resources/views/admin/_create_project_modal.blade.php (store routes + the two
    capability flags in _projectModalConfig).
    NO migration, NO model change, NO new route — every endpoint used already existed.

    VERIFICATION: node --check clean; npm run test:js 231/231/0; view:clear. The JS suite
    covers the pure core, not this DOM code, so the browser steps below are the bar.

    ⬜ BROWSER CHECK OWED (user)
      1. As ADMIN, upload a document whose category is not in the list: the preview shows
         「+ Add "…" as a new category」. Press it — the category is created, the dropdown
         reloads with it selected, and Apply then fills the real form.
      2. Same for Budget Source.
      3. As STAFF (who may not create): the same unmatched value shows NO add button, and
         the value is still visible as "… — from document (not in list)".
      4. Press the add button twice / add a name that already exists: the message is
         sensible and nothing breaks.
      5. Upload a document naming a contractor that does not exist: the field stays empty
         and the note names the extracted contractor, with a "Closest match" only when
         there genuinely is a near one.

  2026-09-18 — STAGE 2 RESTUDIED (the OCR read path). READ-ONLY, NO APP CODE.
  User order: "do a restudy of the document parser for normal doc, especially the one
  needing ocr." Written up as §10. Also a log-consolidation pass — see the end.

    WHAT CHANGED IN WHAT WE BELIEVE
      OCR-2 IS NO LONGER "WOBBLE". It has a concrete, deterministic mechanism, and in fact
      two of them, both state that survives from one run into the next:
        (1) window._ocrLangMode pins itself to plain 'eng' after TWO empty OSD reads
            (:776-781) or immediately on a thrown OSD error (:789). It is cleared only
            by cancelDocumentAutofill() — which fires when the MODAL is cancelled, not
            when a RUN ends. So a second run inside the same modal session starts in a
            different engine mode than the first did, and 'eng' and 'eng+osd' do not
            produce the same text. On this file SEVEN pages read empty, so the pin is
            guaranteed to trip partway through run 1.
        (2) the OCR cache key is file#size#page@rotation (:883) — NO language mode. A
            page read under OSD in run 1 is served from cache in run 2 even though run 2
            is pinned to 'eng', so one run's output can mix two engines.
      Mechanism (3), the 10-character cliff, is the one already on record and is still
      real — but it was being treated as the WHOLE answer.

      ⚠ PRIOR ART WORTH FACING SQUARELY. The learning-loop plan recorded on 2026-09-07:
      "the 2-vs-3 button flicker is marginal pages crossing the 10-char bar between runs
      (photo-reading is slightly non-deterministic; NOT a regression)" (:474), and left a
      later yield investigation unexplained with "prime suspects remain run-to-run OCR
      wobble ... or a stale-code run" (:525-526). That entry filed under "noise"
      something that has at least one deterministic, fixable cause. Not a criticism of
      it — it predates the current stabiliser — but it must not be quoted again as the
      complete explanation.

      OCR-1 HAS A NAMED SUSPECT, and §6 was wrong to list preprocessing as unknown.
      preprocessOcrCanvas() (:446-475) DOES run on every page. It is a contrast stretch
      gated on `washed = (lo >= 100) && ((hi - lo) <= 140)`, where lo/hi are the MIN and
      MAX grey of a sample. A single dark mark — a signature, a staple, a punch hole, a
      skewed scan's dark edge — drops lo below 100 and the stretch is SKIPPED on a page
      that may need it most. The user's page 7 carries three signatures and a stamp.
      Hypothesis, not finding — and it is cheap to settle because the function already
      logs its own verdict under _parserDebug: "OCR cleanup applied|skipped: lo= hi= mean=".

    ⚠ A CORRECTION TO MY OWN §9. I wrote that a dead page costs "about 3x" a good one.
    That undercounts: recognizeEnglish() is a FOUR-RUNG PSM LADDER (:574 — default, PSM
    6, PSM 11, PSM 4 empty-only), so one dead page costs up to SEVEN recognitions
    (eng+osd, up to 4 PSM rungs, scale-2, crop) against ONE for a page that reads first
    time. The code's own comment at :885 says "up to ~6", the same order. On the user's
    file that is roughly FIFTY recognitions for a ten-page document, each spinning up a
    fresh worker under the one-shot API. §9 is annotated in place rather than rewritten,
    so the correction stays visible. It also makes §9.3 lever A worth more than estimated.

    NOTHING WAS BUILT. Every item in §10.6 needs an order (Rule 10), and the first two
    are a single browser run with no code at all — the per-page yield log built for this
    in 2026-09-07 (:919, "OCR page N: yielded=M rot=D") has been sitting unused.

    DOCUMENTATION DEFECTS FOUND AND FIXED IN THE SAME PASS (both mine, both from
    incremental edits during the session rather than one coherent write):
      - AGENTS.md's Session Handoff had drifted into self-contradiction: it claimed N1
        and stage 1b were UNCOMMITTED (all four commits are pushed), gave the branch and
        tree state as phase-22 at 89beb2f, still described the choice fields as
        input+datalist (superseded by real <select>), predicted the JS suite would reach
        230/230 (it reached 231/231), carried a garbled tail where the superseded
        data-state spinner fix sat after the replacement description, and mentioned
        neither Q3 nor §9. Rewritten as one coherent block.
      - pilot_testing_readiness_plan.md carried a DUPLICATE N1 heading (the corrected
        "BUILT + BROWSER-PROVEN" one and the stale "browser check owed" one, both
        present). The stale one is removed.
    Lesson, and it is the same shape as the spinner's: a handoff assembled by repeated
    small edits drifts, because each edit is locally right and nothing re-reads the
    whole. Rewrite the block, do not patch the sentence.

  2026-09-18 — THE MEASUREMENT RUN (user, _parserDebug on). Written up as §11. NO CODE.
  This is the run §10.6 asked for, and it paid for itself three times over.

    ✅ CONFIRMED: OCR-2 mechanism 1. The log prints the pin firing verbatim —
    "orientation read empty twice running, using plain OCR for this session" (:780) —
    after pages 1 and 2 both came back empty from the OSD path. Predicted in §10.2 from
    reading the code; now observed.

    ⚠ ROOT CAUSE FOUND, and it was under the mechanism rather than beside it. The two
    red walls in the log are "Error: LSTM requested, but not present!!" from
    tesseract-core-simd-lstm.wasm (core v5.1.1), thrown by the 'eng+osd' call at :760.
    'eng+osd' asks an LSTM-ONLY core to load osd.traineddata, which is a LEGACY model.
    The core cannot run it. This is structural to the core/traineddata pairing, not
    flaky — SO ON THIS BUILD THE OSD PATH CAN NEVER WORK.
    The consequences chain: every fresh modal session burns its first two scanned pages
    on an engine that cannot work, the reviewer gets two stack traces, the pin trips, and
    THAT pin is OCR-2's mechanism 1. The non-determinism is a symptom of a broken
    dependency, not an independent defect.
    The code predicted this in a comment at :739-742 ("on this CDN build the OSD data can
    fail with 'LSTM requested, but not present'") and built the pin as a STABILISER for
    it. The stabiliser works. What nobody did was ask whether the thing being stabilised
    was worth keeping — it throws every time, so it straightens nothing, so turning it
    off costs a capability that is already absent.

    ❌ REFUTED: my own OCR-1 hypothesis. §10.3 guessed the washed-page gate in
    preprocessOcrCanvas was outlier-driven — one dark mark (a signature, a staple)
    dropping `lo` below 100 and skipping the contrast stretch on a page that needed it.
    THE DATA SAYS NO: every one of the ten pages reports lo = 0-2, hi = 255. Full dynamic
    range on all of them, so `washed` is correctly false and the stretch would be
    near-identity anyway. The gate is right. Preprocessing is not why seven pages yield
    nothing. Retired in place rather than deleted, so it is not re-proposed.
    A weak thread left behind, NOT an answer: the three pages that READ are the three
    darkest by mean (153, 158, 153) and the failures skew lighter (up to 218) — but p3
    (158) fails where p6 (158) reads, so brightness alone does not separate them. If
    anything it points at FAINTNESS, which would need local/adaptive thresholding rather
    than a global stretch. Unmeasured.

    ⚠ THE USER FOUND A FOURTH MECHANISM, and it is the strangest one: "if the console log
    is open, the ocr only get 3 out of 10, while if its close its 4 out of 10." Their two
    runs bear it out. Candidates (none measured): timing (devtools slows JS, and
    capturing those LSTM stack traces is itself expensive), memory pressure (a worker per
    recognition under the one-shot API), or a marginal page crossing the 10-character bar
    — which would be mechanism 3 surfacing under a new trigger.
    It matters out of proportion to its size: the parser's output depends on whether a
    developer tool is open, and the obvious way to investigate OCR-1 (compare runs) is
    itself perturbed by observing it.

    SMALLER THINGS THE LOG SETTLED
      - The crop rung NEVER runs a recognition on this file — every dead page logs
        "crop skipped (full-bleed)" or "(degenerate kept=0.00)". It costs nothing here
        and rescues nothing (0 for 7).
      - "degenerate kept=0.00" on three pages means the auto-crop decided ZERO percent of
        a mean-160 page should be kept. That looks wrong on its face.
      - The three pages that read need NO ladder at all — attempt 0, clean, non-soupy.
        The whole PSM/scale-2/crop apparatus exists for pages it never rescued here.
      - Dead pages DID walk all four PSM rungs: recognizeEnglish logs an attempt only
        after `if (!text.trim()) continue;` (:601 before :618), so empty rungs print
        nothing. Absence of attempt lines is evidence of empties, not of a skipped ladder.

    WHAT I GOT WRONG, worth keeping: I proposed the cleanup gate as OCR-1's suspect from
    reading the code, and the very first measurement killed it. The reading was not
    careless — the gate IS outlier-driven — but I reasoned about what the gate COULD do
    without checking what the pages actually look like. One log line per page settled in
    seconds what an hour of code reading had only made plausible. Measure the input
    before theorising about the filter.

    NOTHING BUILT. §11.7 proposes M-1..M-4; all need an order (Rule 10). M-1 (default the
    language mode to 'eng' so the broken OSD path is never taken) is the one that pays
    immediately: it removes two wasted page-reads per session, the error spam, and
    OCR-2's mechanism 1, and costs nothing that currently works.

  2026-09-20 — M-1 BUILT: THE BROKEN OSD PATH IS NOW UNREACHABLE. APP CODE, one file.
    ORDERED by the user ("lets go with m1") — the first item of §11.7. Branch
    phase-24-ocr-osd-disable, cut off phase-23-abc-blinding-lift (Rule 12: a dependent
    phase branches off its parent, not main).

    WHAT CHANGED — public/js/admin/projects/document-parser.js, three sites, +23/-2:
      :20-34    a build-level switch `const OCR_OSD_ENABLED = false`, with the reason
                written beside it (the LSTM-core / legacy-osd.traineddata mismatch of
                §11.3) so the next reader does not have to find this plan file to know
                why a whole path is switched off.
      :78-83    the per-session reset now sets window._ocrLangMode to 'eng' rather than
                null while the switch is off. "Untried" is precisely what invited the
                probe that cannot work.
      :762-764  useOsd tests the switch FIRST. This is the site that closes the hole the
                reset alone would leave: a page read before any modal reset has run sees
                _ocrLangMode === undefined, and the old expression treated undefined as
                "try OSD". §11.7 did not mention this; it would have been a silent gap.

    WHY A SWITCH AND NOT A DELETION: §11.7's own wording — leave the machinery intact so
    OSD can be re-enabled if the core/traineddata pairing is ever fixed. Flipping one
    const restores today's behaviour exactly. Nothing was removed.

    WHAT IT SHOULD DO — stated BEFORE the browser check, so the check can falsify it:
      - no "LSTM requested, but not present!!" errors, at all;
      - pages 1 and 2 of a fresh session read through the SAME engine as pages 3-10, so
        they stop being sacrificed — they may now yield text they never had a chance to;
      - "orientation read empty twice running" never fires, so OCR-2 mechanism 1 is gone
        and a second run in the same session should match the first;
      - two fewer recognitions per session.
    WHAT IT DOES NOT DO: it does not touch OCR-1 (seven dead pages) and is not expected
    to — that is M-4. It does not touch the reviewer's MANUAL "See & straighten" rotation
    either; that is a different path (window._ocrRotations) and is unaffected.
    ⚠ IT MAY MOVE THE PAGE COUNT. If pages 1-2 were dying because of the broken engine
    rather than their own content, the read count goes UP — and §11.1's ledger stops
    being the baseline. Re-measure; do not assume 3-vs-4 still means what it meant.

    VERIFICATION DONE (none of it is a substitute for the browser)
      node --check ....... clean.
      JS suite ........... 231 tests / 231 pass / 0 fail — unchanged. The suite covers
                           document-parser-core.js, NOT this file, so this says only
                           "nothing else broke". It is not evidence that M-1 works.
      Falsification ...... C:\temp\opencode\probe_m1_osd.mjs — 13 checks, all pass. It
                           reads the REAL expressions out of the file rather than a
                           retyped copy; proves OSD is unreachable from all four
                           _ocrLangMode states (undefined / null / 'osd' / 'eng'); and
                           MUTATES the switch back to true to prove the old behaviour
                           returns in full, which is what demonstrates the machinery is
                           intact rather than merely disabled.
      Source audit ....... exactly ONE `eng+osd` call site exists (:781) and it sits
                           inside `if (useOsd)`. Every other recognize() is plain 'eng'
                           (:613, :614, :661, :690). The path closes completely.

    ⬜ BROWSER CHECK OWED — three steps, and only these can prove it:
      1. Re-upload pdfdocstest5.pdf with window._parserDebug = true, devtools OPEN.
         Expect zero LSTM errors, no "orientation read empty twice" line, and a per-page
         ledger to set beside §11.1.
      2. Run it a SECOND time in the same modal session WITHOUT reloading. The counts
         must match run 1 — that is the determinism claim, and run 1 alone cannot show it.
      3. Repeat with devtools CLOSED (§11.5, M-3). If open and closed still disagree, the
         fourth mechanism is independent of OSD and survives M-1.
    Until 1-3 are done this is BUILT, not PROVEN.

===============================================================================
  9. OCR COST — WHY IT IS SLOW, HOW IT SCALES, AND WHAT CAN BE CUT
     Research, 2026-09-18, READ-ONLY. User: "the ocr parsing can take very long right?
     is it also gets longer as the number of pdf uploaded is more and more page? is
     there no way to reduce it without sacrificing the accuracy of the ocr parser?"
     NOTHING HERE IS BUILT OR ORDERED. Every figure is read from the code; the ones
     marked MEASURE must be confirmed in a browser run before anything is changed.
===============================================================================

  9.1 WHAT ACTUALLY COSTS TIME (line refs in public/js/admin/projects/document-parser.js)

    THE PAGE LOOP IS STRICTLY SEQUENTIAL. extractTextFromPDF walks pages in a plain
    `for (let pageNum = 1; ...)` and awaits ocrPage one at a time (:884). Nothing is
    parallel — one page is read while every core but one sits idle.

    THE BIGGEST ONE: EVERY RECOGNITION USES THE ONE-SHOT CONVENIENCE API.
    loadTesseract() resolves to window.Tesseract, the library NAMESPACE (:374-389), and
    every read calls tesseract.recognize(canvas, ...) (:594, :642, :671, :760). That
    entry point is documented as a convenience for a SINGLE image: it creates a worker,
    loads the WASM core and the language data, recognises, then terminates the worker —
    per call. The library's own guidance is to create a worker ONCE and reuse it when
    reading more than one image, which is exactly our case.
    So on a 10-page scan we pay worker spin-up and language initialisation TEN OR MORE
    TIMES instead of once, and with 'eng+osd' that is two traineddata sets each time.
    MEASURE BEFORE BUILDING: time one page through the current call versus one
    createWorker() reused across pages. The claim rests on the library's documented
    behaviour, not on a measurement of ours.

    ⚠ CORRECTED 2026-09-18 IN §10.4 — IT IS UP TO SEVEN RECOGNITIONS, NOT 3x. The figure
    below undercounts because it misses recognizeEnglish's four-rung PSM ladder. Kept as
    written so the correction is visible; read §10.4 for the real bill.
    A FAILING PAGE COSTS ABOUT 3x A SUCCEEDING ONE. ocrPage runs a dead-page ladder
    (:749-756): plain recognise, then if under 10 characters a scale-2 re-render, then
    if still under 10 a cropped re-render. A page that yields text stops at rung 1; a
    page that yields nothing pays all three, each with its own worker under the one-shot
    API.
    ON THE USER'S OWN FILE that is the difference between roughly 10 recognitions and
    roughly 24: 3 pages read (1 each) plus 7 dead pages (about 3 each). The pages that
    give us NOTHING are the ones we spend the most on — see OCR-1.

    OSD RUNS ON EVERY PAGE. useOsd defaults to TRUE whenever window._ocrLangMode is
    unset (:743), so the first rung is 'eng+osd' — orientation detection, on every page
    of every file. Orientation is a property of a SCAN, not usually of a page.

    RENDER COST. Pages render at scale 3 (tesseract's ~300 DPI sweet spot) capped to a
    3000px longest side (:674-690). Already tuned, and NOT a lever — see 9.4.

    WHAT IS ALREADY EFFICIENT and should not be re-solved: pages carrying a real text
    layer never reach OCR; a memoising cache keyed file#size#page@rotation makes
    re-reading a page at an angle already read free (:664); the reader script is warmed
    when the popup opens.

  9.2 HOW IT SCALES (the user's second question — yes, in both directions)

    PER FILE:   cost is about min(scanned pages, read depth) x per-page cost.
                Read depth defaults to OCR_PAGE_LIMIT = 10, user-settable 1..50.
    PER UPLOAD: THE BUDGET IS PER FILE, NOT PER RUN. extractTextFromPDF resets
                _ocrPagesDone = 0 for each file (:827, comment O1 — a deliberate
                fairness decision so a later file still gets read). There is NO global
                ceiling, so three scanned PDFs at the default depth is up to THIRTY page
                reads, and ten files is a hundred. Nothing warns the reviewer of that.
    SO: linear in scanned pages per file, and linear again in the number of scanned
    files. The UI's own estimate is about 2s per page ("Read depth (~2s per page)"),
    putting one 10-page scan near 20s and three of them near 60s — before the 3x penalty
    on pages that yield nothing.

  9.3 WHAT CAN BE CUT WITHOUT TOUCHING ACCURACY (ranked; none ordered)

    A. REUSE ONE WORKER INSTEAD OF THE ONE-SHOT API.  biggest win, ZERO accuracy cost
       createWorker(langs) once per run, reuse for every page, terminate in the finally.
       Accuracy-neutral BY CONSTRUCTION: identical engine, identical parameters,
       identical image — only the worker's lifetime changes. Removes N spin-ups and N
       language initialisations per file.
       Watch: terminate on cancel/abort or it leaks. _autofillRunId already marks
       superseded runs, so tie the worker's lifetime to that.

    B. READ PAGES IN PARALLEL WITH A SCHEDULER.  near-linear on a multi-core desktop,
       ZERO accuracy cost. Pages are independent, so a scheduler with 2-4 workers reads
       2-4 at once. Same engine, same images, same answers — only the ORDER of completion
       changes, so text must be reassembled by page number rather than by arrival.
       Watch: each worker holds its own WASM and traineddata, so memory is the limit. On
       a PMU desktop 2-3 is sensible, not 8. Pairs naturally with A.

    C. STOP PAYING FOR OSD ON EVERY PAGE.  likely win, SMALL accuracy risk
       Detect orientation once per FILE (first scanned page, or on a page that fails) and
       reuse it, instead of 'eng+osd' on all ten. Orientation is a property of how the
       document went through a scanner.
       Risk, and it is real: a file whose pages are NOT uniformly oriented. Measurable on
       the corpus before committing.

    D. GATE THE DEAD-PAGE LADDER ON EVIDENCE.  needs measurement FIRST
       The 3x penalty falls entirely on pages that yield nothing. The rungs exist because
       they sometimes rescue a page — but nobody has counted how often. MEASURE: across
       the sample corpus, how many pages did the scale-2 rung rescue, and how many did
       the crop rung rescue? A rung that has never rescued anything is pure cost and can
       go for free; one that rescues rarely can be gated (crop only when there is ink
       near the margins). Do NOT remove them on intuition — that is trading accuracy
       blind, and OCR-1 says accuracy is already short.

    E. PREFETCH THE LANGUAGE DATA WHEN THE POPUP OPENS.  small, zero accuracy cost
       The script is already warmed; the traineddata still downloads on the first
       recognise. With A in place the worker and its languages can be created while the
       reviewer is still choosing files, moving that cost off the critical path.
       PILOT ANGLE: PMU desktops fetch tesseract.js and its language data from a CDN. If
       those machines are offline or slow this is not a small constant — see the pilot
       plan F3. Vendoring the WASM and traineddata locally is the same argument the
       no-vite convention already applies to every other library here.

  9.4 WHAT I WOULD NOT DO

    LOWER THE RENDER RESOLUTION. Scale 3 aims at tesseract's documented ~300 DPI sweet
    spot, and the 3000px cap exists because oversized canvases made it fail silently.
    Dropping resolution trades accuracy directly, and OCR-1 says accuracy is already the
    problem — 7 of 10 pages yield nothing today. Spending less per page to read even
    less is the wrong direction.

    SKIP PAGES BY "IMPORTANCE". The picker's ranking is known to be poor (it ranks by
    which pages OCR handled WORST, with no notion of whether a page holds the fields),
    so using it to skip work would skip the wrong pages.

  9.5 THE ORDER THAT MAKES SENSE, AND ITS PREREQUISITE

    OCR-2 COMES FIRST. The same file yielded 3 read pages one run and 4 the next (see OCR-2).
    No speed change can be judged against a baseline that moves on its own — a "faster"
    run that happens to read one page fewer would look like a win.
    A CONNECTION WORTH TESTING: one of OCR-2's candidate causes was warm-versus-cold
    tesseract state. Under the one-shot API every page gets a FRESH worker, so if OCR-2 is
    state-related, lever A (one reused worker) may make runs MORE deterministic as well
    as faster — which would make A the first thing to try for BOTH problems.
    THEN: A (reuse) -> measure -> B (parallel) -> measure -> D's measurement -> C if the
    corpus says orientation is uniform -> E.

===============================================================================
  10. STAGE 2 — THE READ PATH (OCR), RESTUDIED
      Ordered 2026-09-18 ("do a restudy of the document parser for normal doc,
      especially the one needing ocr"). READ-ONLY, NO APP CODE WRITTEN.
      This is the stage §6 said was blocked; it is not, for its first questions —
      pdfdocstest5.pdf already reproduces the failures, and the code answers more
      than expected. Real PMU scans are still needed before TUNING anything (N2).
===============================================================================

  10.1 THE READ PATH, END TO END (so every finding below has an address)

    extractTextFromPDF(file, runId)                     :818
      arrayBuffer -> pdf.js getDocument
      maxPages = min(pdf.numPages, 50)                  :824
      _ocrPagesDone = 0  <- THE BUDGET IS RESET PER FILE :827
      for each page (SEQUENTIAL, one at a time):
        getTextContent -> core.pageItemsToText           :859-861
        IF that text trims to < 10 chars -> the page is "scanned"  :864
          count it, ledger it, note landscape
          rotKey = file#page      (manual rotations)     :878
          cacheKey = file#size#page@rotation             :883
          ocrText = await ocrPage(...)                   :884
          cache the result INCLUDING an empty one        :892-897
          if ocrText >= 10 chars -> the page counts as read  :898-902
        per-page debug line: "OCR page N: yielded=M rot=D"  :919

    ocrPage(page, runId, rotationDeg, cacheKey)          :683
      budget gate: _ocrPagesDone >= ocrPageLimit() -> "" :684
      run-supersede gate                                  :685
      cache hit -> return, and STILL count a budget unit :~697
      render at scale 3, capped to 3000px longest side   :~712-726
      preprocessOcrCanvas(canvas)                        :737
      useOsd = window._ocrLangMode is null or 'osd'      :743
      if useOsd:  recognize(canvas, 'eng+osd')           :760
                  >= 10 chars -> pin 'osd', reset misses, RETURN
                  else: misses++, and at 2 PIN 'eng' FOR THE SESSION :776-781
                        then recognizeEnglish(...) + dead-page ladder
      else / on throw: pin 'eng', recognizeEnglish(...) + ladder

    recognizeEnglish(tesseract, canvas)                  :566
      A FOUR-RUNG PSM LADDER, each rung a separate recognition:
        default -> PSM 6 (uniform block) -> PSM 11 (sparse) -> PSM 4 (empty-only)
      Later rungs run only while the best so far is short OR long-but-soupy
      (core.scoreOcrSoup). PSM 4 is skipped the moment there is any text at all.

    tryDeadPageFallbacks(current)                        :749
      only while under 10 chars: scale-2 re-render (1 recognition, :642)
      then cropped re-render (1 recognition, :671). First yielding rung wins.

  10.2 ⚠ OCR-2 EXPLAINED — AND IT IS NOT (ONLY) "WOBBLE"

    OCR-2 was: the same file yielded 3 read pages one run and 4 the next. The code gives
    a concrete, deterministic mechanism for it, and the mechanism is state that
    SURVIVES FROM ONE RUN TO THE NEXT INSIDE ONE MODAL SESSION.

    MECHANISM 1 — THE LANGUAGE MODE PINS ITSELF, AND THE PIN OUTLIVES THE RUN.
      window._ocrLangMode is a three-state session flag (:742-743):
        null = untried -> use 'eng+osd'
        'osd' = confirmed working
        'eng' = PINNED plain, no orientation detection
      An OSD read that comes back EMPTY increments window._ocrOsdMisses, and at TWO
      consecutive empties the flag is pinned to 'eng' for the whole session (:776-781).
      A thrown OSD error pins it immediately (:789).
      NEITHER FLAG IS RESET BETWEEN RUNS. cancelDocumentAutofill() does clear both
      (:64-65) — but that fires when the MODAL is cancelled/reopened, not when a run
      ends. So inside one modal session:
        run 1  starts at null -> early pages go through 'eng+osd'; if two of them come
               back empty (and on this file SEVEN pages come back empty), the flag pins
               to 'eng' partway through, and the REST of run 1 uses plain OCR.
        run 2  starts ALREADY PINNED to 'eng' -> every page, including the first ones,
               takes a different engine path than it did in run 1.
      'eng' and 'eng+osd' do not produce the same text. So run 2 legitimately reads a
      different number of pages, deterministically, for a reason that has nothing to do
      with the file. THAT IS THE 3-versus-4.

    MECHANISM 2 — THE CACHE KEY DOES NOT INCLUDE THE LANGUAGE MODE.
      cacheKey = file.name + '#' + size + '#' + pageNum + '@' + rotation  (:883)
      Nothing about the engine. A page read in run 1 under 'eng+osd' is served from
      cache in run 2 even though run 2 is pinned to 'eng'. So a single run's output can
      be a MIX of two engines' results, and which pages are mixed depends on the order
      things happened in an earlier run. Empty results are cached too (:892-897,
      deliberately and with the trade-off written down), so a page that failed once
      keeps failing for the rest of the session even if the conditions changed.

    MECHANISM 3 — THE 10-CHARACTER BAR IS A CLIFF, and this one IS the documented
      wobble. A page that yields 9 characters is "unread" and one that yields 11 is
      "read"; genuine OCR variance of a character or two flips it. This is the
      explanation already on record.

    ⚠ PRIOR ART, AND WHY THIS MATTERS. The learning-loop plan recorded this on
    2026-09-07: "the 2-vs-3 button flicker is marginal pages crossing the 10-char bar
    between runs (photo-reading is slightly non-deterministic; NOT a regression)"
    (:474), and a later yield investigation (3 pages -> 1) was left unexplained with
    "prime suspects remain run-to-run OCR wobble ... or a stale-code run" (:525-526).
    Mechanism 3 alone was the accepted answer. Mechanisms 1 and 2 are not wobble: they
    are state carry-over, they are deterministic, and they are fixable. The earlier
    conclusion may have been filing a fixable defect under "noise".
    NOT A CRITICISM OF THAT ENTRY — it was written before the mode-pinning stabiliser
    existed in its current form. But it should not be quoted again as the whole answer.

    THE DIAGNOSTIC ALREADY EXISTS. ":919" logs "OCR page N: yielded=M rot=D" per page
    under _parserDebug, built in 2026-09-07 for exactly this ("compare yielded counts
    across two runs to separate wobble from regression"). Nobody has had to build
    anything to settle OCR-2; the measurement below just has to be run.

    HOW TO SETTLE IT (no code, ~10 minutes)
      1. Open the create-project modal fresh. window._parserDebug = true.
      2. Upload pdfdocstest5.pdf. Record: every "OCR page N: yielded=M" line, and
         window._ocrLangMode afterwards.
      3. WITHOUT closing the modal, press "Preview Extracted Data" again. Record the
         same two things.
      4. Then CLOSE and REOPEN the modal (this resets the flags) and upload again.
      PREDICTION, if mechanisms 1+2 are the cause: run 2 differs from run 1, and run 3
      (after the reopen) matches run 1. If all three agree, mechanism 3 is the whole
      story after all and the fix is a different one.

  10.3 ⚠ OCR-1 — WHAT THE CODE SAYS ABOUT SEVEN LEGIBLE PAGES YIELDING NOTHING
       ❌ THE HYPOTHESIS BELOW (the washed-page gate) WAS MEASURED AND REFUTED — §11.4.
       Every page has full dynamic range, so the gate is correct to skip. Kept as a
       retired dead end. The live candidates are now in §11.7 M-4.

    THERE *IS* PREPROCESSING, and I was wrong to list it as an open question in §6 —
    preprocessOcrCanvas() (:446-475) runs on every page before recognition. It is a
    contrast stretch, and it is GATED:
        washed = (lo >= 100) && ((hi - lo) <= 140)      :462
        if (!washed) return;                            :466
    lo/hi are the min and max grey of a SAMPLE (every 4th pixel, :452).
    ⚠ THE GATE IS OUTLIER-DRIVEN. A single dark pixel anywhere in the sample — a staple
    mark, a binder hole, a punch shadow, the dark edge of a skewed scan, a signature in
    heavy ink — drops `lo` below 100 and the page is judged "not washed", so the stretch
    is SKIPPED on a page that may badly need it. The user's page 7 carries exactly that
    kind of ink: three signatures and a stamp.
    This is a hypothesis, NOT a finding — but it is cheap to confirm, because the
    function already logs its own decision under _parserDebug (:463-465):
        "[Parser] OCR cleanup applied|skipped: lo=… hi=… mean=…"
    ⇒ ASK FOR THAT LINE FOR PAGE 7. If it says "skipped" with a low lo and a high mean,
    the gate is the culprit and the fix is to make it robust (a percentile instead of a
    raw min — e.g. the 5th percentile of the sample rather than the minimum), which is
    accuracy-POSITIVE, not a trade.

    WHAT IS ALREADY RULED OUT, and should not be re-proposed:
      - the budget (H1, falsified twice — §4);
      - "we never tried" (the ladder runs up to seven recognitions on a dead page, 10.4);
      - orientation, on the evidence available — the page is upright in the preview and
        turning it changes nothing the user could see.
    STILL OPEN: the render cap. ":685" logs "OCR render capped: longest=… -> scale=…"
    when it fires. Worth reading the same run for it. Precedent at :674 says oversized
    canvases "choke tesseract to silent empty ... even on perfectly upright, human-crisp
    pages" — the 3000px cap was that fix, and the same signature is back.

  10.4 ⚠ CORRECTION TO §9: A DEAD PAGE COSTS FAR MORE THAN I SAID

    §9 estimated "about 3x" for a page that yields nothing. Reading recognizeEnglish
    properly, that is an UNDERCOUNT. The worst case for ONE dead page:
        eng+osd                                   1 recognition   :760
        recognizeEnglish PSM ladder               up to 4         :574
        scale-2 re-render                         1               :642
        cropped re-render                         1               :671
        -------------------------------------------------------------
        UP TO SEVEN RECOGNITIONS FOR ONE PAGE
    against ONE for a page that reads on the first attempt. The code's own comment at
    :885-887 says "up to ~6 recognitions", which is the same order and confirms the
    shape.
    ON THE USER'S FILE that is 3 good pages plus 7 dead ones at up to 7 each — on the
    order of FIFTY recognitions for a ten-page document, EACH of which, under the
    one-shot API (§9.1), spins up a worker and loads language data from scratch.
    THIS IS THE REAL ANSWER TO "why does it take so long": not the page count, but the
    number of recognitions per failed page multiplied by a per-recognition constant that
    should have been paid once for the whole run.
    It also sharpens §9.3 lever A: reusing one worker is worth roughly the per-worker
    constant times ~50 on this file, not times ~10.

  10.5 SMALLER THINGS WORTH KNOWING (verified, not urgent)

    - THE "SCANNED" TEST IS A 10-CHARACTER THRESHOLD on the pdf.js text layer (:864).
      A page with a thin text layer (a header, a page number, a watermark) that totals
      10+ characters is treated as fully read and NEVER goes to OCR — its body is then
      invisible to the parser with no ledger entry saying so. Not observed on this
      corpus; worth a probe when real PMU scans arrive, because "born-digital cover page
      + scanned body" is a common shape for exactly the documents the PMU handles.
    - maxPages = min(numPages, 50) (:824) caps the WALK, while the OCR budget caps the
      READS. A 60-page document silently stops being looked at after page 50.
    - EMPTY RESULTS ARE CACHED (:892-897). Deliberate, with the trade-off written down.
      Consequence worth stating plainly: within one modal session a page that failed
      once cannot succeed again, even after the language mode changes underneath it.
      That is mechanism 2's partner.
    - THE PSM LADDER'S LATER RUNGS ARE SOUP-GATED (:583) via core.scoreOcrSoup — so a
      long-but-garbled read keeps climbing, which is correct and worth preserving.

  10.6 WHAT STAGE 2 RECOMMENDS (PROPOSED — nothing ordered, Rule 10)

    S2-1  RUN THE OCR-2 MEASUREMENT IN 10.2. No code. It is the prerequisite for every
          other item here and in §9, and it distinguishes three mechanisms that need
          three different fixes.
    S2-2  READ THE TWO EXISTING DEBUG LINES FOR PAGE 7 — "OCR cleanup applied|skipped"
          and "OCR render capped". No code. Together with S2-1 this is one browser run
          and it decides whether OCR-1 is a preprocessing gate, a canvas size, or neither.
    S2-3  THEN, and only then: if mechanism 1 is confirmed, reset _ocrLangMode and
          _ocrOsdMisses at the START OF EACH RUN rather than only on modal cancel, and
          put the language mode in the cache key. Both are small, both are
          determinism-positive, neither changes what a single clean read produces.
    S2-4  If 10.3 is confirmed, make the washed-page gate percentile-based instead of
          min-based. Accuracy-positive.
    S2-5  Lever A from §9.3 (one reused worker) — now worth more than §9 estimated
          (10.4), and possibly determinism-positive too (§9.5).

    ORDER: S2-1 and S2-2 are the same browser run. Everything else waits on it.

===============================================================================
  11. THE MEASUREMENT (user, 2026-09-18, _parserDebug on, pdfdocstest5.pdf)
      This is the browser run §10.6 asked for. It CONFIRMS one thing, REFUTES one of
      my own hypotheses, and turns up a root cause nobody had named.
      NO APP CODE. Everything below is from the console log verbatim.
===============================================================================

  11.1 THE PER-PAGE LEDGER (devtools OPEN — see 11.5, it matters)

      page   cleanup            attempts        scale-2   crop            yielded
      1      skipped lo=0 hi=255 mean=200   (all empty)   chars=0   skipped full-bleed   0
      2      skipped lo=2 hi=255 mean=171   (all empty)   chars=0   skipped full-bleed   0
      3      skipped lo=1 hi=255 mean=158   (all empty)   chars=0   skipped degenerate   0
      4      skipped lo=1 hi=255 mean=153   attempt 0: 4312 clean                     4312
      5      skipped lo=1 hi=255 mean=218   (all empty)   chars=0   skipped full-bleed   0
      6      skipped lo=0 hi=255 mean=158   attempt 0: 1627 clean                     1627
      7      skipped lo=1 hi=255 mean=191   (all empty)   chars=0   skipped full-bleed   0
      8      skipped lo=1 hi=255 mean=161   (all empty)   chars=0   skipped degenerate   0
      9      skipped lo=1 hi=255 mean=159   (all empty)   chars=0   skipped degenerate   0
      10     skipped lo=1 hi=255 mean=153   attempt 0: 3864 clean                     3864

      Pages 4, 6 and 10 read, first attempt, clean, no ladder needed. Seven pages
      yielded ZERO — not garbage, zero.
      "(all empty)" is inferred, not missing: recognizeEnglish logs an attempt only
      AFTER `if (!text.trim()) continue;` (:601 before :618), so an empty rung prints
      nothing. The dead pages therefore DID walk all four PSM rungs and every rung
      returned an empty string.

  11.2 ✅ OCR-2 MECHANISM 1 — CONFIRMED, VERBATIM, IN THE LOG

      After pages 1 and 2 both came back empty from the OSD path, the log prints:
        "[Parser] orientation read empty twice running, using plain OCR for this
         session."   (:780)
      That is the pin firing exactly as §10.2 predicted. Pages 1-2 of a fresh session
      are read through one engine and pages 3-10 through another, and on a second run
      in the same modal session pages 1-2 would ALSO use plain 'eng'. The mechanism is
      no longer a hypothesis.

  11.3 ⚠ THE ROOT CAUSE UNDER IT — THE OSD PATH IS BROKEN ON THIS BUILD

      The log's red errors, twice, from ocrPage :760:
        "Error: LSTM requested, but not present!! Loading tesseract."
        ... tesseract-core-simd-lstm.wasm.js (tesseract.js-core v5.1.1)
      That is the failure the code comment at :739-742 predicted word for word: "on this
      CDN build the OSD data can fail with 'LSTM requested, but not present'".
      WHY IT HAPPENS: 'eng+osd' asks the LSTM-only core to load osd.traineddata, which is
      a legacy (non-LSTM) model. The core cannot run it. This is not a flaky failure — it
      is structural to the core/traineddata pairing, so ON THIS BUILD OSD CAN NEVER WORK.
      WHAT IT COSTS, every fresh modal session:
        - the first two scanned pages are read through an engine that cannot work, and
          contribute nothing but latency (and they are counted against the budget);
        - the reviewer gets two walls of red stack trace in the console;
        - the pin then trips, which is OCR-2's mechanism 1. THE NON-DETERMINISM IS A
          SYMPTOM OF THIS, not an independent bug.
      WHAT IS LOST IF OSD GOES: nothing that currently works. OSD exists to straighten
      tilted/sideways pages (added for a phone-tilted S-curve sample), but on this build
      it never straightens anything — it throws. Turning it off loses a capability that
      is already absent, and REMOVES two wasted page-reads plus the error spam plus the
      run-to-run drift.

  11.4 ❌ MY OCR-1 HYPOTHESIS IS REFUTED — the cleanup gate is behaving correctly

      §10.3 guessed that preprocessOcrCanvas's washed-page gate was outlier-driven, so a
      single dark mark (a signature, a staple) would skip the contrast stretch on a page
      that needed it. THE DATA SAYS NO.
      Every page reports lo = 0-2 and hi = 255 — FULL dynamic range, true blacks and true
      whites on all ten. `washed = (lo >= 100) && ((hi - lo) <= 140)` is correctly false
      for all of them, and on a page with full range the stretch would be near-identity
      anyway. The gate is right, the skip is right, and preprocessing is NOT why seven
      pages yield nothing. Hypothesis retired; recorded rather than deleted so it is not
      re-proposed.

      WHAT THE NUMBERS DO HINT AT, weakly and not enough to act on: the three pages that
      READ are the three darkest by mean (153, 158, 153), while the failures skew lighter
      (200, 218, 191, 171, 161, 159, 158). The overlap (p3 mean 158 fails, p6 mean 158
      reads) means brightness alone does not separate them, so this is a thread, not an
      answer. What it suggests is FAINTNESS — light grey strokes on white — which is a
      different problem from "washed out" and would need a different treatment
      (adaptive/local thresholding rather than a global stretch). NOT MEASURED.

  11.5 ⚠ NEW, AND THE USER FOUND IT: DEVTOOLS OPEN CHANGES THE RESULT

      User, verbatim: "i notice that if the console log is open, the ocr only get 3 out
      of 10, while if its close its 4 out of 10."
      Reproduced in their own two runs: this ledger (devtools open) yields 3 pages; the
      run notice they pasted from the closed-devtools run says 4 of 10.
      THIS IS A FOURTH NON-DETERMINISM MECHANISM and it is not any of the three in §10.2.
      Candidates, NONE measured:
        - TIMING. Devtools open slows JS substantially, and capturing/rendering those
          LSTM stack traces is itself expensive. Anything time-sensitive in the path
          (an abort, a worker lifetime, a race between runs) shifts.
        - MEMORY. The one-shot API creates a worker per recognition; devtools adds
          pressure. A recognition under pressure can fail where it otherwise succeeds.
        - MARGINAL PAGES. If the extra page is one hovering near the 10-character bar,
          this is mechanism 3 (§10.2) surfacing under a different trigger.
      WHY IT MATTERS OUT OF PROPORTION TO ITS SIZE: it means the parser's output depends
      on whether a developer tool is open. Whatever the cause, no measurement of OCR-1 is
      trustworthy until the read is stable — and the obvious next measurement (compare
      runs) is itself perturbed by the act of observing it. Which page is the fourth one
      is the first thing to establish: re-run with devtools CLOSED and read the notice,
      then open devtools and diff the ledger.

  11.6 SMALLER THINGS THE LOG SETTLED

      - THE CROP RUNG NEVER RUNS A RECOGNITION on this file. Every dead page logs either
        "crop skipped (full-bleed)" or "crop skipped (degenerate kept=0.00)". So it costs
        nothing here — and rescues nothing. Relevant to §9.3 lever D: on this specimen the
        crop rung is pure dead weight, though one file is not a corpus.
      - "degenerate kept=0.00" on pages 3, 8, 9 means the auto-crop computed that ZERO
        percent of the page should be kept — the whole page read as "dark surround" on a
        page whose mean is ~160. That looks wrong on its face and deserves its own look
        if the crop rung is ever revived.
      - THE READING PAGES NEED NO LADDER AT ALL: 4, 6 and 10 all succeed on attempt 0,
        clean, non-soupy. The entire PSM ladder, scale-2 and crop machinery exists for
        pages that, on this file, it never rescues — 0 for 7.
      - COST CONFIRMED (§10.4): a dead page here pays the PSM ladder (4) + scale-2 (1)
        = 5 recognitions, plus eng+osd on the first two pages. Seven dead pages is ~35
        recognitions that produce nothing, each spinning up its own worker.

  11.7 WHAT THIS CHANGES ABOUT THE ORDER (PROPOSED — nothing ordered, Rule 10)

      M-1  ✅ ORDERED AND BUILT 2026-09-20 — see §8. What shipped is exactly the
           "cheapest defensible form" below, with one addition the writing here missed:
           defaulting the reset is NOT sufficient on its own, because useOsd also treats
           an UNSET mode (undefined, before any reset has run) as "try OSD". The switch
           is therefore tested first in useOsd as well.
           TURN OFF THE OSD PATH ON THIS BUILD. It cannot work (11.3), it wastes the
           first two pages of every session, it spams errors, and it is the direct cause
           of OCR-2's mechanism 1. Cheapest defensible form: default window._ocrLangMode
           to 'eng' instead of null, leaving the existing machinery intact so OSD can be
           re-enabled if the core/traineddata pairing is ever fixed. This is
           determinism-POSITIVE and costs nothing that currently works.
           Better, if OSD is actually wanted back: load the legacy core for the OSD probe,
           or run orientation detection once per file rather than per page. Both are
           larger; M-1 is the one that pays immediately.
      M-2  RESET THE MODE AND THE MISS COUNTER AT THE START OF EACH RUN, and put the
           language mode in the OCR cache key (§10.2 mechanism 2). Still worth doing with
           M-1 in place — it removes the whole class rather than today's instance.
      M-3  ESTABLISH WHICH PAGE THE DEVTOOLS-CLOSED RUN READS (11.5) before trusting any
           further measurement.
           ⬛ HALF-ANSWERED 2026-09-20 (§12.4): the page is PAGE 9, and only page 9.
           What remains is WHAT it yields there — §12.6 Test B, which also names the
           way past the observation paradox (Chrome buffers a closed-devtools console).
      M-4  ONLY THEN look at why seven legible pages yield zero. The cleanup gate is
           exonerated (11.4); the next candidates are the actual pixels handed to
           tesseract ("Save page image ↓ p.7") and, if those look fine, faint-stroke
           handling — local/adaptive thresholding rather than the global stretch.
===============================================================================
  12. M-1'S BROWSER CHECK (user, 2026-09-20, _parserDebug on, pdfdocstest5.pdf)
      The run section 8 asked for. M-1 is confirmed on its own terms, one of my own
      cautions is refuted, and the devtools mechanism is narrowed to a single page.
      Console output verbatim from the user.
===============================================================================

  12.1 CONFIRMED - WHAT M-1 DID

      - ZERO "LSTM requested, but not present!!" errors in the entire log. Previously
        two walls of red per session. The broken path is not merely avoided, it is
        never entered.
      - NO "orientation read empty twice running, using plain OCR for this session"
        line. The pin never fires, so OCR-2 mechanism 1 is gone at the source.
      - Pages 1 and 2 are read on the SAME engine as pages 3-10. They are no longer
        spent on an engine that cannot work.
      - Two fewer recognitions per session, as scoped.

  12.2 REFUTED - THE CAUTION THAT DID NOT HAPPEN

      Section 8 warned the read count might go UP, because pages 1-2 might have been
      dying from the broken engine rather than from their own content. THEY STILL
      YIELD ZERO:
        page 1  lo=0 hi=255 mean=200 . scale-2 chars=0 . crop skipped full-bleed . 0
        page 2  lo=2 hi=255 mean=171 . scale-2 chars=0 . crop skipped full-bleed . 0
      So pages 1-2 are dead for whatever kills the other five (OCR-1), and the broken
      OSD engine was never the reason. Section 11.1's ledger REMAINS the baseline.
      NET EFFECT OF M-1 ON WHAT THE PARSER READS: none. 3 of 10, exactly as before.
      That is what it was scoped to be - cost and noise removed, behaviour unchanged -
      but it should be said plainly rather than dressed up as a win.

  12.3 NOT PROVEN - THE DETERMINISM STEP. RUN 2 WAS A CACHE REPLAY

      The user closed the PREVIEW modal, removed the PDF, re-uploaded it and previewed
      again. It was "almost instant" and printed ONLY the per-page "yielded=" lines -
      no cleanup line, no attempt line, no fallback line.
      THAT IS THE PHOTO-READ CACHE, not a second read. ocrPage() returns the cached
      text at :717-720, BEFORE any render or recognition, while the per-page log at
      :940 still prints - so a replay is indistinguishable from a real read in the log
      unless you notice the missing cleanup lines.
      WHY THE CACHE SURVIVED: the key is name#size#page@rot (:904), identical for the
      same file re-uploaded, and _ocrTextCache is cleared only by a CREATE-modal reset
      (:60) or by the "Re-scan" button (:1695). Closing the preview modal clears
      neither.
      => Run 2 proves the cache is correct and consistent. It says NOTHING about
         whether a genuine second read repeats. The determinism claim is still owed.

  12.4 SURVIVED - THE DEVTOOLS MECHANISM, NOW NARROWED TO ONE PAGE

      devtools OPEN   -> pages 4, 6, 10 read      (3 of 10)
      devtools CLOSED -> pages 4, 6, 9, 10 read   (4 of 10)
      This is M-3's question, and the user has ANSWERED HALF OF IT: the disputed page
      is PAGE 9, and only page 9. The other nine pages agree in both conditions.
      It was always expected to survive M-1 (it is independent of OSD), but it is now
      a far smaller target than "the run wobbles".
      WHAT PAGE 9 LOOKS LIKE IN THE OPEN RUN:
        page 9  lo=1 hi=255 mean=161 . scale-2 chars=0 . crop degenerate kept=0.00 . 0
      TWO CANDIDATE EXPLANATIONS, cheap to tell apart:
        (a) THRESHOLD WOBBLE. Page 9 sits near the >=10-char bar and tesseract wobbles
            run to run - the code's own comment at :909-911 already says photo-reading
            does this. Then "devtools" is a coincidence over very few trials, and the
            closed run's page 9 will show a SMALL yield.
        (b) DIFFERENT PIXELS. Devtools open changes canvas rasterization, so the page
            handed to tesseract genuinely differs. Then the closed run's page 9 will
            show a LARGE clean yield, like the pages that work.
      The discriminator is simply page 9's yielded= number in a closed run.

  12.5 UNEXPLAINED - A DISCREPANCY WITH 11.1. DO NOT THEORISE ON IT YET

      Pages 1-7's cleanup means match section 11.1 EXACTLY. Pages 8-10 do not:
        page     11.1     2026-09-20
        8        161      162
        9        159      161
        10       153      159
      Either the render genuinely drifts on later pages (which would be a live lead for
      BOTH the devtools mystery and OCR-1), or 11.1's last rows were mis-paired when the
      ledger was transcribed - the log interleaves cleanup / attempt / yield lines and
      the three columns are easy to slip by one. Note that today's p9/p10 means are
      11.1's p8/p9 means, which is exactly what an off-by-one transcription looks like.
      NOT RESOLVED, and deliberately not built on. Test A below settles it for free.

  12.6 WHAT IS OWED (proposed - nothing ordered, Rule 10)

      TEST A - DETERMINISM, AND THE 12.5 DISCREPANCY, IN ONE RUN.
        devtools OPEN. Read once. Then press "Re-scan" in the gray scan note - NOT
        re-upload. Re-scan is the deliberate fresh read: it drops the photo-read cache
        at :1695, which re-upload does not.
        Compare the two reads' per-page MEANS and YIELDS.
          means stable + yields stable -> reads are deterministic, and 11.1's last rows
                                          were a transcription slip.
          means drift                  -> the render itself varies run to run, which is
                                          a live lead for OCR-1 as well.
      TEST B - WHAT PAGE 9 ACTUALLY YIELDS WITH DEVTOOLS CLOSED.
        This is 11.5's observation paradox, and there is a way around it: Chrome BUFFERS
        console messages logged while devtools is shut and shows them when it is opened
        afterwards. So reload, leave devtools CLOSED, run the preview, and only THEN
        open devtools and scroll back. ~40 lines, well inside the buffer.
        Page 9's yielded= number decides between 12.4 (a) and (b).
      NEITHER TEST CHANGES CODE. M-2 and M-4 remain unordered.

  12.7 TEST A — DETERMINISM CONFIRMED, AND 12.5 RESOLVED

      Two reads in one session, the second via "Re-scan" so the photo-read cache was
      genuinely dropped (:1695). THE TWO LEDGERS ARE BYTE-IDENTICAL — every page's
      cleanup line, every fallback line, every yielded= count:
        p1 200/0 · p2 171/0 · p3 158/0 · p4 153/4312 · p5 218/0
        p6 158/1627 · p7 191/0 · p8 162/0 · p9 161/0 · p10 159/3864
      ⇒ A READ IS DETERMINISTIC. The render is deterministic too — the means repeat
        exactly, so the pixels handed to tesseract do not vary run to run.
      ⇒ 12.5 IS RESOLVED. Today's two runs agree with each other and both disagree with
        §11.1 on pages 8-10 only. Since nothing drifts within a session, §11.1's last
        three rows were a TRANSCRIPTION SLIP when that ledger was typed up — today's
        p9/p10 means are §11.1's p8/p9 means, the off-by-one signature 12.5 predicted.
        ⚠ §11.1's table should be trusted for pages 1-7 and treated as mis-paired for
        8-10. The yields in it were never in doubt.
      ⇒ OCR-2 is now fully accounted for: mechanism 1 was the OSD pin (M-1 removed it),
        mechanism 2 (the cache key omitting the language mode) is unreachable while only
        one engine exists, and there is no residual wobble inside a session.

  12.8 TEST B — WHAT IT ACTUALLY SHOWED, AND WHY MY INSTRUCTION WAS WRONG

      The closed-devtools run printed ONLY three lines: preview run 2, the vote-debug
      tip, and the render line.
      MY REASON WAS WRONG. I blamed Chrome's console buffer. The real reason is simpler:
      window._parserDebug DOES NOT SURVIVE A RELOAD. Test B requires a reload, the flag
      is set by hand in the console, and every per-page line is gated on it. The three
      lines that did print are the run-level ones at :338/:341/:2262, which are UNGATED.
      Nothing was buffered away; the lines were never emitted.

      ⚠ WHAT THAT REFRAMES — THE "DEVTOOLS" VARIABLE IS CONFOUNDED.
      In every open run the flag was ON; in every closed run it was OFF. So the
      condition that has been called "devtools open/closed" is really "devtools open AND
      debug logging on" versus "devtools closed AND debug logging off".
      AND window._parserDebug IS LOGGING-ONLY. All 14 of its sites (:268, :482, :510,
      :637, :663, :692, :739, :789, :939, :1225 and the comment sites) sit inside
      console.log guards or a debug-report helper. It gates no branch, no threshold, no
      rung. It CANNOT change what a page yields.
      ⇒ Combined with 12.7 (reads are deterministic), the honest position is that THE
        FOURTH MECHANISM MAY NOT EXIST. The "4 of 10" observation rests on runs whose
        page ledger was never captured.
      ⚠ THE MISSING DATUM: this Test B run's READ COUNT was not reported — only its
        console. If it read 3, the mechanism dissolves. That single number is now the
        cheapest thing in the whole restudy.
      HOW TO GET THE CLOSED-CONDITION LEDGER WITHOUT THE CONSOLE: the PAGE STRIP already
      shows per-page status in the UI ("page 7 of 10 — nothing at all — try turning it").
      Paging through it records which pages read, with devtools shut and no code at all.

  12.9 ⚠ THE STRONGEST LEAD YET FOR OCR-1: BRIGHTNESS TRACKS DEADNESS, AND THE GATE
       THAT EXISTS TO FIX IT CANNOT FIRE

      Sort the ten pages by the cleanup line's own mean:
        mean  153  158  159 | 158  161  162  171  191  200  218
        yield 4312 1627 3864|  0    0    0    0    0    0    0
      EVERY page with mean >= 161 yields ZERO — six of six. Of the four pages at or
      below 159, three read. The one exception is page 3 (mean 158, dead).
      Brightness is not proof of faintness, but on this specimen it separates the two
      groups almost perfectly, and it points straight at the ink.

      NOW THE GATE (preprocessOcrCanvas, :465-492). It stretches contrast only when:
        washed = (lo >= 100) && ((hi - lo) <= 140)
      lo is the MINIMUM gray over the sampled pixels. Every page reports lo = 0-2, so
      `lo >= 100` is false on all ten and THE STRETCH HAS NEVER RUN ON THIS FILE.
      WHY lo IS ALWAYS ~0: one dark region anywhere pins it. Page 7's photo has a dark
      binder edge down its left side, plainly visible in the preview. A page can be
      almost entirely white with pale gray text and still report lo=0 hi=255.

      ⚠ THIS CORRECTS §11.4, AND IT IS NOT THE SUSPECT I HAD BEFORE.
      §11.4 refuted the claim "the stretch is APPLIED and damages pages" — that refutation
      stands; the stretch is never applied. The new hypothesis is the opposite one: the
      stretch NEVER RUNS WHEN IT SHOULD, because min/max is the wrong statistic for
      "is this page's text faint". §11.4's conclusion that "the gate is correct to skip"
      followed from full dynamic range, and full RANGE says nothing about STROKE contrast.
      This is exactly what §10.6 S2-4 and §11.7 M-4 already proposed, now with evidence
      behind it instead of code reading.

      THE MEASUREMENT THAT WOULD SETTLE IT (proposed, pure instrumentation, no behaviour
      change): extend the existing cleanup debug line with percentile statistics — the
      5th-percentile gray and the INK FRACTION (share of sampled pixels below, say, 128).
      One run then shows directly whether the dead pages are ink-poor and the reading
      pages ink-rich. ONLY THEN is a gate change worth designing.
      ⚠ THE DISCIPLINE THIS FILE ALREADY LEARNED (§8, 2026-09-18): measure the input
      before theorising about the filter. The correlation above is a lead, not a cause.

  12.10 THE ABC QUESTION (user, 2026-09-20) — AND U1 CONFIRMED ON A REAL REVIEWER

      ASKED: the ABC blinding is lifted, so why is the ABC still not extracted from this
      PDF? Is it because the photo is tilted?
      ANSWER, PART 1 — THE BLINDING IS NOT INVOLVED. L3_TEST_BLIND_ABC is false
      (document-parser-core.js:670) and the patterns, the corroborated reassignment and
      the words-shape fallback are all live. The ABC sits on PAGE 7, page 7 yields ZERO
      characters, and extraction is only ever handed text. Nothing to match against is
      not the same as failing to match. This is OCR-1, start to finish.
      ANSWER, PART 2 — IT IS ALMOST CERTAINLY NOT THE TILT. The page is off-square by a
      couple of degrees; tesseract tolerates that. And the cure the UI offers cannot
      address it anyway: "turn this page" rotates in 90° steps (:1498, :1356, :1377), so
      it can fix a sideways page and can do nothing whatever about a 2° skew.
      Page 7's own numbers point elsewhere: mean=191, one of the brightest in the file,
      which is the 12.9 group.

      ⚠ THIS IS FINDING U1 HAPPENING TO A REAL PERSON, AND IT SHOULD BE RECORDED AS SUCH.
      U1 (stage 1) said the page badge names ONE cause and offers ONE cure regardless of
      what actually went wrong. Here the badge said "nothing at all — try turning it" on
      a page that is not meaningfully turned, and it led the system's own developer — who
      knows the parser better than any PMU staff member will — to the wrong hypothesis.
      If the UI can do that to him it will certainly do it to a reviewer during the pilot.
      U1 is no longer a design opinion; it has a witness.

  12.11 WHAT IS OWED NOW (proposed — nothing ordered, Rule 10)

      T1  THE ONE MISSING NUMBER, zero code: re-run with devtools CLOSED and read the
          RESULT — the count, and which pages the strip marks as read. If it is 3 of 10,
          the fourth mechanism dissolves and §11.5/M-3 close.
      T2  MAKE THE DEBUG FLAG SURVIVE A RELOAD (instrumentation only): read it from
          localStorage at load as well as from window. Today the only way to turn logging
          on is to open the console, which is the very condition under suspicion — the
          observation paradox is an artefact of how the flag is set, not of devtools.
          This dissolves it permanently and costs nothing.
      T3  THE PERCENTILE / INK-FRACTION LINE (§12.9), instrumentation only. The highest-
          value measurement available, because it aims at OCR-1 rather than at OCR-2's
          leftovers.
      T4  ONLY THEN consider the gate itself (S2-4 / M-4).
      M-2 is now LOW value (12.7: no residual wobble, and one engine makes the cache-key
      omission unreachable) — worth doing as hygiene, not as a fix.
===============================================================================
  13. T2 + T3 BUILT (2026-09-20) — INSTRUMENTATION ONLY
      Ordered by the user ("then start") off section 12.11. Both are measurement
      apparatus: neither may change what a page yields, and both were falsified
      against that requirement rather than merely tested.
      Branch: phase-24-ocr-osd-disable (kept with M-1 — same investigation, same read
      path; they should land as TWO commits, M-1 first, not one mixed commit).
===============================================================================

  13.1 T2 — THE DEBUG FLAG SURVIVES A RELOAD

      THE PROBLEM IT REMOVES (12.8): window._parserDebug could only be set by typing in
      the console, so logging was ON exactly when devtools was OPEN. The condition under
      investigation and the instrument for investigating it were the same switch.
      WHAT SHIPPED — document-parser.js :36-71:
        - PARSER_DEBUG_KEY = 'pmuParserDebug' and a load-time hydrator that sets
          window._parserDebug from localStorage.
        - window._parserDebugPersist(true|false) — writes the stored preference AND the
          session flag in one console call, and returns a confirmation string.
      THE DESIGN CHOICE WORTH RECORDING: the flag is hydrated ONTO window rather than
      the ~14 guard sites being rewritten to call a helper. That is not laziness — the
      PURE CORE also reads window._parserDebug (document-parser-core.js:138), so
      hydrating the window flag covers BOTH halves of the parser, leaves every existing
      guard and ocrDebugAffordances() untouched, and adds no new call surface.
      ORDER OF PRECEDENCE: an explicit in-session `window._parserDebug = true` always
      wins; the stored preference only fills an unset flag. Blocked/private-mode storage
      is caught and ignored — debug logging is never load-bearing.

      HOW TO USE IT (this is the recipe T1 needs):
        1. console:  window._parserDebugPersist(true)
        2. CLOSE devtools entirely. Reload the page.
        3. Upload and preview as normal, devtools still shut.
        4. Open devtools and scroll back — the full per-page ledger is there.
        5. When finished:  window._parserDebugPersist(false)

  13.2 T3 — THE CLEANUP LINE NOW DESCRIBES THE PAGE, NOT ITS EXTREMES

      WHAT SHIPPED — preprocessOcrCanvas(), document-parser.js :503-564
      (the histogram at :515, the diagnostics inside the debug branch):
        - a 256-bin gray histogram filled in the EXISTING sampling pass (one array
          increment per sample; the loop stays branch-free and unconditional);
        - the debug line extended with p1 / p5 / p50 and ink<128 / ink<160, after the
          existing "lo= hi= mean=" prefix, which is kept VERBATIM so every earlier
          ledger in this file stays comparable.
      WHY THESE NUMBERS: the washed gate keys on lo/hi, and one dark pixel — a binder
      edge, a staple, a curl shadow — pins those to 0/255 on any page at all. A pale
      scan therefore reports "full dynamic range" and is judged healthy. Percentiles and
      ink fraction cannot be moved by a single speck.
      THE VERDICT ITSELF IS UNTOUCHED. `washed = (lo >= 100) && ((hi - lo) <= 140)` is
      byte-identical, the histogram is never read before it, and the percentile work
      happens only inside the debug branch.

  13.3 VERIFICATION

      node --check .......... clean.
      JS suite .............. 231 / 231 / 0, unchanged. As always it does not cover this
                              file, so it only says nothing else broke.
      C:\temp\opencode\probe_t3_stats.mjs — 26 checks, all pass. It EXTRACTS THE REAL
        preprocessOcrCanvas() out of the file and runs it under Node against three
        synthetic pages with hand-computed statistics:
          "inky"   7 black rows on white  -> ink<128 = 10.94%, p5 = 0, gate skips.
          "pale"   2 rows of gray(150) + an 8px black speck -> THE CASE 12.9 IS ABOUT:
                   lo=0 hi=255 (reads as "full dynamic range") while ink<128 = 0.20%.
                   This is the blind spot, demonstrated rather than argued.
          "washed" text 120 on background 200 -> the gate STILL FIRES and the stretch
                   STILL RUNS. That is the behaviour-preservation check.
        Plus: the gate expression asserted verbatim, the histogram proven unread before
        the verdict, and the log prefix proven unchanged.
      C:\temp\opencode\probe_t2_debugflag.mjs — 14 checks, all pass. It runs the real
        hydrator and setter against a fake window and localStorage, simulating a reload
        by re-running the hydrator with a fresh window: stored preference survives a
        reload, an unset store stays quiet, a hand-set session flag is never downgraded,
        blocked storage neither throws nor enables anything, and the setter round-trips
        on and off across reloads.

  13.4 WHAT IS OWED — T1, NOW WITH A LEDGER

      T1 was "get the one missing number". With T2 in place it can deliver far more than
      a number: run the 13.1 recipe with devtools SHUT and the full per-page ledger will
      be waiting, including the new ink statistics. That single run answers BOTH open
      questions at once:
        (a) DOES THE FOURTH MECHANISM EXIST? Compare the closed-devtools page list with
            the open one (4, 6, 10). If it is the same three pages, 11.5 / M-3 close and
            the mechanism was never real — which 12.7 and 12.8 both now predict.
        (b) IS OCR-1 AN INK PROBLEM? Compare ink<128 on the three pages that read against
            the seven that do not. A clear separation makes the gate (S2-4 / M-4) the
            next target and tells us what a corrected gate should key on. No separation
            kills the 12.9 hypothesis outright, which is just as valuable.
      ⚠ STILL NOT ORDERED, and still no fix: 12.9 is a lead with a correlation behind it,
      not a cause. This file has already recorded once (section 8, 2026-09-18) what comes
      of theorising about the filter before measuring the input.
===============================================================================
  14. T1 — THE DECISIVE RUN (user, 2026-09-20, T2's persisted flag, pdfdocstest5.pdf)
      The first time the closed-devtools condition has ever been measured with a
      ledger. It settles the fourth mechanism, supports 12.9, and refutes 12.8.
===============================================================================

  14.1 THE FOURTH MECHANISM IS REAL — AND 12.8 WAS WRONG

      devtools CLOSED -> pages 4, 6, 9, 10   (4 of 10)   4446 / 1621 / 3075 / 4108 chars
      devtools OPEN   -> pages 4, 6, 10      (3 of 10)   4312 / 1627 /      / 3864 chars
      12.8 argued the mechanism might not exist, because window._parserDebug is
      logging-only and therefore cannot change a read. THE PREMISE WAS TRUE AND THE
      CONCLUSION WAS WRONG. The flag is not the variable; the devtools WINDOW is. T2 is
      what separated them — with the flag persisted, logging was ON in both conditions,
      so the only remaining difference was devtools itself.
      ⚠ AND IT IS NOT ONLY PAGE 9. The three pages that read in BOTH conditions yield
      DIFFERENT CHARACTER COUNTS (4446 vs 4312, 1621 vs 1627, 4108 vs 3864). Every page
      is being read differently. Page 9 is simply the one near enough the edge to flip
      from "something" to "nothing".

  14.2 THE PHYSICAL SIGNATURE — THE TWO RENDERS ARE NOT THE SAME PIXELS

      lo / hi / mean agree almost exactly between the conditions (mean is identical on
      9 of 10 pages). The new T3 statistics do not:
        page    p1 closed / open     ink<128 closed / open
        1         16 / 19              4.74% / 5.06%
        2         61 / 68              7.67% / 7.83%
        3         25 / 37             11.69% / 12.04%
        4          5 / 24             12.93% / 13.77%
        5         53 / 67              2.91% / 3.21%
        6          9 / 20              9.14% / 9.60%
        7         39 / 52              3.39% / 3.59%
        8         33 / 47              5.80% / 5.93%
        9         20 / 36              7.71% / 7.82%
        10        23 / 47              9.56% / 10.31%
      TWO PERFECT RUNS OF TEN. With devtools OPEN, p1 is HIGHER on every single page
      (the darkest 1% is LIGHTER) while ink<128 is also HIGHER on every single page
      (more pixels are dark-ish). Lighter extremes plus more dark-ish pixels is the
      signature of a SOFTER RENDER: the same ink spread over more pixels, with no
      pixel as dark at the core.
      ⇒ The devtools-CLOSED render has CRISPER STROKES, and it reads better: one extra
        page, and more characters on 2 of the 3 shared pages.
      ⇒ THIS IS NOT ABOUT INK QUANTITY. Page 9 has MORE ink<128 in the open run (7.82%
        vs 7.71%) and yields NOTHING there. What matters is stroke CONTRAST, for which
        p1 is the better proxy.

      WHERE IT CANNOT BE COMING FROM (checked, :801-818): the OCR render path is
      window-independent. scale is the constant 3, the viewport comes only from the
      PDF page's intrinsic size, the canvas is created off-document with
      createElement, and nothing reads devicePixelRatio, innerWidth or any layout
      measurement. The "OCR render capped" line does not appear in either log, so the
      3000px cap never fired and the viewport was identical in both runs.
      ⇒ The canvas DIMENSIONS are the same. What differs is how pdf.js rasterises INTO
        it — i.e. the 2D context's backing (GPU-accelerated vs software), which Chrome
        is known to switch when devtools is open. That is the one remaining candidate
        and it fits the softness signature exactly.

  14.3 12.7 AND 12.4 ARE NOW RECONCILED

      Reads are DETERMINISTIC WITHIN A CONDITION and DIFFERENT BETWEEN CONDITIONS.
      Today's open run reproduces the earlier open runs exactly (4312 / 1627 / 3864).
      Nothing wobbles; two stable regimes were being compared without knowing it.
      12.7's determinism finding stands. 12.4's mechanism stands. Only 12.8's
      speculation falls.

  14.4 OCR-1 — 12.9 IS SUPPORTED, WITH ONE CLEAR EXCEPTION

      The closed run (the better one), sorted by ink<128:
        page   mean   ink<128   ink<160   yield
         5     218     2.91%    11.40%      0
         7     191     3.39%     9.18%      0     <- the ABC page
         1     200     4.74%    24.29%      0
         8     162     5.80%    33.45%      0
         2     171     7.67%    29.35%      0
        ---------------------------------------- ~7.7% line
         9     162     7.71%    31.55%   3075
         6     158     9.14%    34.37%   1621
        10     159     9.56%    35.01%   4108
         3     158    11.69%    41.33%      0     <- THE EXCEPTION
         4     153    12.93%    31.44%   4446
      Every page below ~7.7% ink is dead, five for five. Above it, four of five read.
      INK FRACTION SEPARATES 9 OF 10 PAGES — far better than mean brightness did, and
      it is the statistic T3 was added to get.
      ⚠ PAGE 3 BREAKS IT. It carries MORE ink than three of the four reading pages
      (11.69%, and the highest ink<160 in the file at 41.33%) and still yields nothing.
      So low ink explains most dead pages and cannot explain them all. Page 3 also logs
      "crop degenerate kept=0.00", as pages 8 and 9 do — the auto-crop believes the
      whole page is dark surround. A page that is ink-rich AND read as all-surround
      sounds like a dark photograph or heavy shading rather than faint text.
      ⚠ AND THE GATE NEVER FIRES ON ANY OF THEM. Every page still reports lo=0-2, so
      `washed` is false ten times out of ten. The stretch has still never run.
      THE ABC PAGE, for the record: page 7 is the SECOND most ink-poor page in the file
      (3.39% ink<128, 9.18% ink<160). That is consistent with faint strokes and
      inconsistent with the tilt hypothesis (12.10), which this now closes.

  14.5 WHAT I WOULD DO NEXT (proposed — nothing ordered, Rule 10)

      P-1  PIN THE CANVAS BACKING, AND SEE WHICH RENDER WE GET.
           The context is created inline at :818 as canvas.getContext('2d') with no
           attributes, and getImageData is then called on it repeatedly (the cleanup
           pass, and the crop pass). Creating it ONCE as
           getContext('2d', { willReadFrequently: true }) makes the backing explicit
           instead of letting Chrome choose — and Chrome's choice is the prime suspect
           in 14.2. Note the attribute must go on the FIRST getContext call, because a
           second call on the same canvas returns the existing context and ignores its
           attributes.
           WHY IT IS WORTH DOING FIRST: if it pins the CRISP render, page 9 is gained
           permanently, every page reads a little better, and the condition-dependence
           disappears — three results from a two-line change.
           ⚠ IT MAY PIN THE WRONG ONE. I do not know which backing devtools forces. If
           both conditions then read 3 pages, we have determinism but have lost page 9,
           and the lever should be flipped rather than kept. THE TEST IS THE SAME EITHER
           WAY: run both conditions again and compare the p1 column against 14.2.
           This is a hypothesis with a falsifiable outcome, not a fix.
      P-2  LOOK AT PAGE 3 (no code). It is the one page that breaks 14.4, and the
           preview's "Save page image" already exists. Ink-rich, all-surround and dead
           is a different failure from faint-and-dead, and guessing at it is exactly
           what section 8 warned against.
      P-3  ONLY THEN THE GATE (S2-4 / M-4). It now has a measured threshold to key on
           instead of lo/hi — but P-1 may move every number in 14.4, so designing a gate
           before P-1 settles would be designing against the wrong render.
      NOT WORTH DOING: M-2 (12.7 already showed there is no residual wobble).
===============================================================================
  15. P-1 BUILT (2026-09-20) — ONE DECLARED CANVAS BACKING ON THE READ PATH
      ↩↩ REVERTED THE SAME DAY — READ §16 FIRST. Nothing below is in the code any
      more. The experiment was run, refuted, and the machinery removed. The section is
      kept because its design reasoning (above all: why the RENDER sites had to be
      hinted, not only the pixel-reading ones) is what anyone re-attempting this needs,
      and because a dead end that gets deleted gets re-proposed.
      Ordered by the user ("then start") off section 14.5. This is an EXPERIMENT with
      a falsifiable outcome, not a known fix, and section 15.4 says in advance what
      each outcome means so the result cannot be read generously after the fact.
===============================================================================

  15.1 WHAT SHIPPED — document-parser.js

      :74-95    the reasoning, and the switch:
                const OCR_CANVAS_WILL_READ_FREQUENTLY = true;
                Three-state on purpose — true declares frequent read-back (asking for a
                CPU-friendly backing), false declares the opposite hint, and null omits
                the attribute entirely, which is exactly today's browser-chooses
                behaviour. 14.5 warned the lever might need flipping; this is the lever.
      :108-117  ocrCanvasContext(canvas) — the one place a read-path context is made.
                Falls back to an unhinted context if an engine rejects the attribute
                object, because a working context matters more than the hint.
      SEVEN CALL SITES ROUTED THROUGH IT, all on the read path:
        :550  preprocessOcrCanvas   :631  autocropOcrCanvas
        :677  the autocrop tmp canvas          :679  the autocrop write-back
        :774  the scale-2 fallback render      :800  the crop fallback render
        :863  the main ocrPage render
      WHY THE RENDER SITES AND NOT ONLY THE PIXEL-READING ONES: context attributes apply
      only on the FIRST getContext call for a canvas. A later call returns the existing
      context and silently ignores its attributes — so hinting only in preprocessOcrCanvas
      would have done nothing at all, since the render gets there first.
      WHY ALL THE FALLBACK RUNGS TOO: a rung that rasterised differently from the main
      pass would reintroduce exactly the variance this removes, one rung down.

  15.2 WHAT WAS DELIBERATELY NOT TOUCHED

      Three canvases still take a plain, unhinted context, and should:
        _saveOcrPageImage()      the "Save page image" diagnostic — shown, never OCR'd
        renderOcrPagePreview()   the See & straighten preview — shown, never OCR'd
        buildAiSlices()          the image sent to the AI tier — sent, never OCR'd
      Changing them would widen the blast radius past the question being asked, and
      buildAiSlices in particular feeds a different consumer entirely.
      ⚠ ONE CONSEQUENCE WORTH KNOWING FOR P-2: "Save page image" renders at scale 1.5
      while the OCR path renders at scale 3, and now also with a different context
      backing. The saved image was never pixel-identical to what tesseract is handed,
      and is slightly less so now. It is a fair REPRESENTATION of the page, not evidence
      about exact pixels.

  15.3 VERIFICATION

      node --check .......... clean.
      JS suite .............. 231 / 231 / 0, unchanged.
      C:\temp\opencode\probe_p1_backing.mjs — 25 checks, all pass. Runs the REAL
        ocrCanvasContext against a canvas that records how its context was requested:
        true declares willReadFrequently, false declares the opposite, null passes no
        attributes at all, and an engine that throws on the attribute form gets a second,
        unhinted call rather than an exception. Then it AUDITS THE CALL SITES — all seven
        read-path sites routed, and all three display sites proven still unhinted, by
        anchoring on each function's DEFINITION.
      C:\temp\opencode\probe_t3_stats.mjs — re-run and still 26/26 after P-1, now pulling
        the real helper into scope. That is the integration check: preprocessOcrCanvas
        gets its context through the new helper and its statistics are unchanged.
      ⚠ TWO PROBE BUGS WERE FOUND AND FIXED, both mine, neither in the app code: the
      site audit first anchored on the first MENTION of each function (which matched the
      helper's own comment naming them), and then used a 3000-character window too short
      to reach renderOcrPagePreview's render call ~146 lines in. Recorded because a probe
      that fails for its own reasons is exactly how a real failure gets waved through.

  15.4 THE BROWSER CHECK — AND WHAT EACH OUTCOME MEANS (decide before running)

      RUN: the 13.1 recipe, BOTH conditions — devtools open, and devtools closed.
      READ: the p1 column, against section 14.2. p1 is the sharpness proxy; lower is
      darker stroke cores.
        OUTCOME A — p1 matches the CLOSED column in both conditions.
          The crisp render is pinned. Expect 4 pages (4, 6, 9, 10) with devtools open
          too. This is the win: page 9 gained permanently, every page a little better,
          and the condition-dependence gone. KEEP true.
        OUTCOME B — p1 matches the OPEN column in both conditions.
          The soft render is pinned. Expect 3 pages in both. Determinism gained, page 9
          lost. FLIP the switch to false and run again — that is what the third state is
          for. If false is no better, set null and accept that the backing is not
          controllable from here.
        OUTCOME C — p1 still differs between conditions.
          The backing is not the variable, and 14.2's conclusion is wrong. Then the
          difference lies inside pdf.js's rasteriser or the compositor, neither of which
          this codebase can address — and the honest move is to stop chasing it and
          record that OCR results are browser-condition-dependent by nature.
      ⚠ WHICHEVER OUTCOME: every number in 14.4's ink table was measured on the
      unpinned render and may move. Re-read that table from the new run before any gate
      work (P-3) is designed against it.
===============================================================================
  16. P-1'S RESULT — REFUTED, AND THE CHASE ENDS HERE (user, 2026-09-20)
      Section 15.4 named three outcomes before the run. This is outcome C, and the
      pre-commitment is honoured rather than reinterpreted.
===============================================================================

  16.1 P-1 IS A PERFECT NO-OP

      With OCR_CANVAS_WILL_READ_FREQUENTLY = true, both conditions reproduce the
      pre-P-1 ledgers EXACTLY — not approximately, identically, digit for digit:
        devtools OPEN    p1 = 19 68 37 24 67 20 52 47 36 47   pages 4, 6, 10
                         chars 4312 / 1627 / 3864
        devtools CLOSED  p1 = 16 61 25  5 53  9 39 33 20 23   pages 4, 6, 9, 10
                         chars 4446 / 1621 / 3075 / 4108
      Every ink<128, every ink<160, every p5, every p50 and every mean also match
      section 14.2 and 14.4 exactly.
      ⇒ OUTCOME C. Declaring the context backing does not touch how pdf.js rasterises
        into the canvas. 14.2's conclusion — "the variable is the 2D context's backing"
        — IS WRONG.
      TWO READINGS, ONE CONSEQUENCE: either Chrome ignored the hint for this canvas, or
      it honoured it and the hint does not govern rasterisation. Nothing observable from
      here separates them, and both mean the same thing — THIS IS NOT CONTROLLABLE FROM
      THIS CODEBASE.

  16.2 WHAT SURVIVES THE REFUTATION

      The pixels really do differ. That part is not in doubt and does not depend on the
      mechanism: p1 and ink<128 are computed by OUR code from the canvas BEFORE tesseract
      is ever called, so a difference in those numbers is a difference in the pixels
      handed to it. What is refuted is only my guess at WHY.
      DETERMINISM IS NOW OVERWHELMING: three open runs and two closed runs, across a
      code change, all byte-identical within their condition. Section 14.3 stands.

  16.3 ✅ THE CONSEQUENCE THAT ACTUALLY MATTERS — AND IT IS GOOD NEWS

      PMU STAFF WILL NEVER HAVE DEVTOOLS OPEN. The closed-devtools render is therefore
      the PRODUCTION render, and it is the BETTER of the two: 4 pages instead of 3, and
      more characters on the pages both conditions read.
      ⇒ The parser in real use reads 4 of 10 on this file, not 3. Every earlier "3 of 10"
        figure in this plan — including OCR-1's original framing, "SEVEN OF TEN PAGES
        yield nothing" — was measured through a developer's open devtools and is
        PESSIMISTIC BY ONE PAGE. The real figure is six of ten.
      ⇒ The open-devtools column is a DEVELOPER ARTEFACT. It should not be used to judge
        anything again. Section 14.4's ink table was already built from the closed run
        and remains the right baseline.
      ⇒ MEASURE WITH DEVTOOLS CLOSED FROM NOW ON. T2 exists exactly so that costs
        nothing: persist the flag, shut devtools, run, then open it and read back.

  16.4 THE DISPOSITION OF P-1's CODE — DECIDED: REVERTED (user, 2026-09-20)

      THE USER CHOSE (a). P-1 is out of the tree and never enters history: the switch,
      the ocrCanvasContext() helper and all seven re-routed call sites are back exactly
      as they were. Verified after the revert by re-running EVERY probe (M-1 13/13,
      T2 14/14, T3 26/26) and the JS suite (231/231/0).
      C:\temp\opencode\probe_p1_backing.mjs is now OBSOLETE — it asserts against code
      that no longer exists. probe_t3_stats.mjs was put back to extracting
      preprocessOcrCanvas alone, since no helper needs to be in scope for it now.
      The options as they were put, kept for the record:

      The code is harmless and does nothing measurable. Three honest options:
        (a) ✅ CHOSEN — REVERT IT. The hypothesis is dead and it bought nothing measurable. The
            tree stays clean and the branch carries only work that earned its place.
            RECOMMENDED — there is no evidence of benefit, and this file's own norm is
            not to carry machinery on a "just in case".
        (b) KEEP THE HELPER, DROP THE THREE-STATE SWITCH. willReadFrequently is the
            documented-correct declaration for a canvas read back with getImageData
            repeatedly, independent of the refuted hypothesis, and the helper does
            centralise seven call sites. ⚠ But TIMING WAS NEVER MEASURED, so the
            performance argument is from the documentation, not from this project.
        (c) Leave it as built. NOT RECOMMENDED: the comment at :74-95 currently frames
            it as a live experiment, which is now false.
      ⚠ WHICHEVER IS CHOSEN, (c)'s comment must not survive unchanged — leaving a
      refuted hypothesis described as an open question is a defect in its own right.

  16.5 WHERE THE INVESTIGATION STANDS

      CLOSED: OCR-2 (both mechanisms) · M-3 / the fourth mechanism — real, reproducible,
        and not addressable from here; it is a developer-condition artefact that does not
        affect users, who get the better render.
      OPEN, and now the only thing worth pursuing: OCR-1. Against the CLOSED column,
      six of ten pages yield nothing, ink<128 separates nine of those ten at ~7.7%, and
      page 3 is the one page that breaks the correlation.
      NEXT, unchanged by this result: P-2 (look at page 3's image — no code) and then
      P-3 (the gate, which has still never fired on any page of this file).
===============================================================================
  17. P-3a — THE GATE WORK STARTS WITH A CORRECTION TO ITS OWN PREMISE
      (2026-09-20, ordered: "proceed with the next open work")
===============================================================================

  17.1 ⚠ FIXING THE GATE ALONE WOULD HAVE DONE NOTHING

      Found by reading the code before writing any, which is what sections 8 and 16
      both say to do. The stretch is:
        const range = (hi - lo) || 1;
        v = ((g - lo) / range) * 255
      IT MAPS [lo, hi] ONTO [0, 255]. On every page of the test scan lo is 0-2 and hi
      is 255 — so the mapping is g -> g. THE STRETCH IS THE IDENTITY FUNCTION HERE.
      ⇒ S2-4 and M-4 both proposed "make the washed gate percentile-based". That is
        necessary and NOT SUFFICIENT: had the gate been corrected on its own, it would
        have fired on these pages and then handed every one of them back UNCHANGED, and
        the measurement would have read as "contrast stretching does not help" when in
        fact nothing had been stretched.
      ⇒ THE ENDPOINTS MUST STOP BEING lo/hi TOO. A percentile gate needs percentile
        endpoints, for the same reason: one dark speck decides both today.
      This is the same defect wearing two hats, and it nearly cost a wasted experiment.

  17.2 WHAT SHIPPED — reporting only, no behaviour change

      preprocessOcrCanvas()'s debug line gains:
        p75 / p95 / p99 — the upper percentiles a percentile ENDPOINT would be chosen
          from. p1/p5 already describe the ink; nothing described the background, and
          the background is the other end of any mapping.
        gain = 255 / (hi - lo) — what the CURRENT mapping multiplies contrast by.
          1.00 means "this page would be handed back unchanged", so 17.1's no-op is
          now visible in the ledger itself rather than only in this plan file.
      The gate verdict, the stretch and the existing fields are all untouched, and the
      lo/hi/mean prefix is still verbatim.
      Verified: node --check clean; JS suite 231/231/0; probe_t3_stats.mjs still 26/26,
      and its three synthetic pages now read gain=1.00, 1.00 and 3.19 — the two
      full-range pages showing the identity and the genuinely washed one showing a real
      stretch, which is 17.1 demonstrated rather than asserted.

  17.3 WHAT IS OWED BEFORE THE STRETCH IS DESIGNED

      ONE RUN, devtools SHUT (13.1's recipe), and the page-3 image from P-2 — they can
      be done in one sitting.
      WHAT THE NUMBERS WILL DECIDE: with p1/p5 (the ink) and p75/p95/p99 (the
      background) for all ten pages, the endpoints can be chosen from data instead of
      guessed. The question the run answers is how far apart the ink and the background
      actually sit on the dead pages — if they are well separated, a percentile stretch
      should rescue them; if they overlap, the page is not faint but BLURRED, and
      contrast is the wrong lever entirely (local thresholding would be the next idea,
      per M-4).
      ⚠ NOT ORDERED, AND NOT YET DESIGNED. P-1 was built on a mechanism I had not
      measured, and it was refuted. This time the endpoints get measured first.
===============================================================================
  18. OCR-1 IS SOLVED — IT IS A CONTRAST PROBLEM, AND p5 SEPARATES EVERY PAGE
      (2026-09-20. P-3a's ledger + P-2's page-3 image, measured offline with GD.)
===============================================================================

  18.1 ⭐ p5 SEPARATES ALL TEN PAGES WITH NO EXCEPTION

      p5 is "how dark is the darkest 5% of the page" — in a document photo, that is
      the text strokes. From the devtools-SHUT run:
        READS   page 4  p5= 69   4446 chars
                page 6  p5= 75   1621
                page 10 p5= 92   4108
                page 9  p5=108   3075
        ------------------------------------ the line falls between 108 and 113
        DEAD    page 3  p5=113
                page 2  p5=114
                page 8  p5=121
                page 1  p5=131
                page 5  p5=143
                page 7  p5=145
      TEN OUT OF TEN. Not nine — every page, including PAGE 3 and PAGE 9, the two that
      broke every earlier metric. Page 3 was "ink-rich and dead" under ink<128 (11.69%,
      section 14.4's lone exception); under p5 it is simply the darkest of the dead pages
      and sits on the correct side of the line. Page 9, the one that flips with devtools,
      is the weakest reader and sits nearest the line — exactly where a flipper should be.
      CONTRAST (p50 - p5) tells the same story: 95, 89, 74, 57 for the readers; 48, 52,
      42, 40, 21 for the dead ones (page 5 excepted — see 18.4).
      ⚠ ONE FILE, TEN PAGES. A perfect split on n=10 is a strong lead, not a law. It
      needs their real scans (N2) before it is treated as a threshold.

  18.2 WHAT PAGE 3 ACTUALLY LOOKS LIKE (the P-2 question, answered with numbers)

      Measured from sampledocs/snapshot-pdfdocstest5.pdf-p3-rot0.png with GD
      (C:\temp\opencode\measure_p3.php and tail_p3.php):
        canvas 892 x 1263 · the photo fills 94.2% of it — margin is NOT page 3's problem
        cumulative dark tail:  <40 = 1.05%   <100 = 3.76%   <128 = 11.97%   <160 = 41.58%
        the bulk:  120-139 = 10.4%   140-159 = 23.7%   160-179 = 52.6%   >=240 = 5.78%
        modal gray = 167
      ⇒ THE ENTIRE DOCUMENT LIVES BETWEEN ~120 AND ~180. The paper is ~167 and the text
        is ~120-155, so TEXT-TO-PAPER SEPARATION IS ABOUT 32 GRAY LEVELS out of 255.
        A clean scan has black text (~30) on white paper (~245): separation ~215.
        Page 3 is using 24% of the available dynamic range.
      ⇒ AND THE TWO EXTREMES THAT DISABLE THE GATE ARE NOT THE DOCUMENT AT ALL:
          lo ~= 0    is set by the BINDER SHADOW — 1.05% of pixels below 40
          hi  = 255  is set by the PAGE MARGIN  — 5.78% of pixels at 240+
        Between them they make `washed` false, and they make the stretch's own mapping
        [lo,hi] -> [0,255] the identity (section 17.1). Seven percent of the pixels,
        none of them part of the text, switch off the one mechanism built to rescue it.

  18.3 ✅ A CAUTION I RAISED IS WITHDRAWN

      Section 15.2 warned that the saved snapshot is rendered at scale 1.5 against the
      read path's scale 3 and so is "a fair representation, not evidence about exact
      pixels". On the numbers it is better than that: the snapshot gives p50=161,
      p75=165, p95=255 and 5.78% pure white, and the browser's own page-3 line from the
      OCR canvas gives p50=161, p75=165, p95=255. The distributions match. THE SNAPSHOT
      IS USABLE EVIDENCE, and measuring it offline with GD is a route to page-level
      numbers that costs the user nothing.

  18.4 THE SHAPE OF THE FIX (proposed — NOT built, and it changes what the parser reads)

      The gate and the endpoints must BOTH stop keying on lo/hi (17.1), and 18.2 says
      what they should key on instead: the document's own band, not the page's extremes.
        GATE      fire when the document band is narrow — e.g. (p95' - p5') small, or
                  simply when contrast (p50 - p5) is below the 18.1 line.
        ENDPOINTS map the document band onto [0,255] instead of [lo,hi]. For page 3 that
                  is [~120, ~180] -> [0,255]: a gain of about 4.2x. Text at 135 lands
                  near 64 and paper at 167 near 200, so separation goes from 32 to ~136.
      ⚠ THE ENDPOINTS MUST IGNORE NEAR-WHITE PIXELS. Pages 1, 5 and 7 carry 25-50% page
      margin (p75 or p50 = 255), so percentiles taken over the whole canvas are polluted
      by margin exactly as lo/hi are polluted by it today. Computing them over the
      non-white pixels only is what makes one rule work for both page 3 (94% photo) and
      page 5 (over half margin).
      ⚠ AND IT MUST NOT TOUCH THE PAGES THAT ALREADY WORK. Pages 4, 6, 9 and 10 have real
      dark strokes (p5 = 69-108) and read cleanly on attempt 0. Any gate must leave them
      alone; the 18.1 line is what a gate would be built around.
      ⚠ THIS IS A BEHAVIOUR CHANGE ON EVERY SCANNED DOCUMENT. Own branch, own browser
      proof, and the page-by-page ledger re-run as the acceptance test — the four
      readers must still read, and the count must go up, or it is not a fix.
===============================================================================
  19. P-3 BUILT — THE DOCUMENT-BAND CONTRAST STRETCH (2026-09-20, ordered)
      Branch phase-25-ocr-contrast-stretch, off phase-24 (Rule 12). This CHANGES WHAT
      THE PARSER READS on every scanned document, so section 19.4 states the expected
      result page by page BEFORE the run.
===============================================================================

  19.1 WHAT SHIPPED — preprocessOcrCanvas(), rewritten whole (Rule 2)

      Both the DECISION and the MAPPING stop keying on lo/hi.
        THE MAPPING now uses the DOCUMENT BAND: percentiles taken over pixels below
          OCR_BAND_WHITE_FLOOR (240), so the white page margin cannot drag the paper end
          to 255 the way it drags `hi` to 255 today. Band low = the strokes, band high =
          the paper. Values outside the band clamp, which is the point and not a side
          effect: the binder shadow goes to black, the margin to white, and the document
          gets the whole range to itself.
        THE GATE now uses the whole-canvas p5 against OCR_BAND_STROKES_DARK_ENOUGH (110)
          — see 19.3, this was nearly wrong.
        THREE REFUSALS, each naming itself in the ledger: almost no document (<5% of the
          canvas), a band too narrow to stretch without amplifying sensor noise (<12
          levels), and strokes already dark enough. "skipped" with no reason is what made
          the old gate unreadable.
      OCR_CLEANUP_MODE = 'band' ships. 'legacy' restores the old lo/hi gate EXACTLY, and
      'off' disables cleanup entirely. Every threshold is a named constant, because they
      come from ONE FILE and real PMU scans (pilot §5 N2) are what should set them.

  19.2 THE RESULT ON REAL PAGE-3 PIXELS

      Not a synthetic approximation — the probe rebuilds a canvas carrying page 3's
      ACTUAL gray distribution, dumped from its saved snapshot with GD, and runs the real
      function on it:
        before   p5=112  p50=161   text/paper separation 49    gain 1.00 (the identity)
        after    p5= 13  p50=225   text/paper separation 212   gain 4.32
      The strokes land at p5=13 — far inside the reading range (69-108) rather than just
      over the line. Separation improves 4.3x, from "barely distinguishable" to the sort
      of contrast a clean scan has.

  19.3 ⚠ THE DEFECT THE PROBE CAUGHT BEFORE IT SHIPPED

      The first build gated on the BAND's p5. Section 18.1 measured its ten-page
      separation on the WHOLE-CANVAS p5, and those are different numbers: excluding the
      margin moves the same percentile further down the dark tail. On page 3 the band p5
      is 109 against a whole-canvas 112 — so with a threshold of 110 the rule SKIPPED THE
      VERY PAGE IT WAS BUILT FOR, and would have shipped as a silent no-op on the
      motivating case.
      Fixed by gating on the quantity that was actually validated across ten pages, and
      using the band only for the mapping. The reasoning is written into the code beside
      the gate so it cannot be "simplified" back.
      THE LESSON, and it is the same one section 8 recorded: a statistic is not
      transferable just because it has the same name. p5 over one population is not p5
      over another.

  19.4 VERIFICATION, AND THE PREDICTION THE BROWSER RUN MUST MATCH

      node --check clean · JS suite 231/231/0 · every earlier probe still green
      (M-1 13/13, T2 14/14, T3 rewritten for the new function and passing).
      C:\temp\opencode\probe_p3_band.mjs — real page-3 pixels, the two guards, all three
      modes, and the proof that a page which already reads is left BYTE-IDENTICAL.

      PREDICTED PER-PAGE OUTCOME, from each page's measured p5 against the 110 line:
        STRETCHED  pages 1 (131) · 2 (114) · 3 (113) · 5 (143) · 7 (145) · 8 (121)
        UNTOUCHED  pages 4 (69) · 6 (75) · 9 (108) · 10 (92)
      ACCEPTANCE — all three must hold or this is not a fix:
        (a) THE FOUR UNTOUCHED PAGES MUST RETURN THE SAME CHARACTER COUNTS, EXACTLY:
            4446 / 1621 / 3075 / 4108. They are handed byte-identical pixels, so anything
            else means the stretch is reaching pages it must not reach.
        (b) The ledger must show why=band on the six, and a named refusal on the four.
        (c) THE READ COUNT MUST GO UP from 4 of 10. If every stretched page still yields
            nothing, contrast was the wrong lever and the honest next move is M-4's other
            candidate — local/adaptive thresholding rather than a global stretch.
      ⚠ A PARTIAL RESULT IS STILL A RESULT. Page 7 carries the ABC and is the faintest
      page in the file (p5=145, separation 21); if only it reads, the pilot gains the
      field that started this whole restudy.
      RUN IT WITH DEVTOOLS SHUT (13.1's recipe). Section 16.3: the open-devtools render
      is a developer artefact and reads one page fewer.
===============================================================================
  20. P-3'S RESULT — 10 OF 10, AND THE PREDICTION HELD PAGE FOR PAGE
      (user, 2026-09-20, devtools shut)
===============================================================================

  20.1 ⭐ ALL THREE ACCEPTANCE CONDITIONS MET

      READ COUNT: 4 of 10  ->  10 OF 10. Every page of the scan now yields text.
      Six pages were stretched and all six went from ZERO to real text:
        page 1  band= 55..171  gain 2.20  doc=53.0%   0 -> 1338 chars
        page 2  band=107..174  gain 3.81  doc=85.0%   0 -> 2397
        page 3  band=110..168  gain 4.40  doc=94.2%   0 ->  989
        page 5  band= 94..171  gain 3.31  doc=37.2%   0 -> 1030
        page 7  band=127..168  gain 6.22  doc=67.3%   0 ->  683
        page 8  band=118..169  gain 5.00  doc=93.6%   0 -> 1673
      (a) THE FOUR UNTOUCHED PAGES RETURNED THEIR EXACT PREDICTED COUNTS —
          4446 / 1621 / 3075 / 4108, to the character. The stretch reached nothing it
          was not supposed to reach.
      (b) why=band on the six; a named refusal on the four, each quoting the p5 that
          earned it: 69, 75, 108, 92.
      (c) The per-page PREDICTION of 19.4 was exactly right: stretched 1,2,3,5,7,8 and
          untouched 4,6,9,10. Ten for ten on the decision as well as the outcome.

  20.2 ⭐ THE FIELD THAT STARTED THE RESTUDY IS NOW FILLED

      PAGE 7 CARRIES THE ABC, and page 7 was the faintest page in the file (p5=145,
      text-to-paper separation 21, the second most ink-poor). It now reads, and the
      ABC field comes back populated — 4,834,297.00 / 4,834,297.59 offered as the two
      candidates the pick-one control exists for.
      Section 12.10 asked why the ABC was missing when the blinding had been lifted. The
      answer was never the blinding and never the tilt: it was that 70% of the document
      was invisible to the reader. It is visible now.

  20.3 ⚠ THE SECOND-ORDER CONSEQUENCE — MORE TEXT MEANS MORE NOISE

      This is the honest other half of the result, and it is not a regression in P-3:
      six pages that used to contribute NOTHING now contribute everything they carry,
      including their misreads. Visible in the preview from this run:
        BUDGET SOURCE  "Accor, Fost =" — offered as a value, AND offered for creation
                       as a new budget source (the Q3 create-in-place control).
        TITLE          a leading "]" artifact.
        LOCATION       "... Camarines Sur Polytechnic Colleges Date: 05142005" — a
                       stray "Date: ..." glued onto the end of the real location.
        CONTRACTOR     a pipe-joined run of several names. Not yet diagnosed — it may be
                       a DB option label rather than a parse, so it is NOT claimed as a
                       defect here (Rule 1: measure before asserting).

      THE BUDGET SOURCE ONE IS THE WORST, AND THE CAUSE IS EXACT.
      extractBudgetSource (document-parser-core.js:869) matches a funding-source label
      and then validates the captured tail with lenRange(2, 100) — which is a LENGTH
      CHECK AND NOTHING ELSE (:341). Any 2-to-100-character string following a matching
      label is accepted as a budget source. It never fired before because the pages
      carrying those labels were unreadable.
      ⚠ WHY IT MATTERS MORE THAN THE OTHER TWO: Q3 (phase-23) added CREATE-IN-PLACE for
      budget sources. A reviewer under time pressure clicks "Add" and a junk row enters
      the budget_sources table permanently. Garbage in a text box is a nuisance; garbage
      offered as a database row is a pilot problem.

  20.4 A BENIGN WARNING, RECORDED SO IT IS NOT RE-DIAGNOSED

      Page 8 logged "Image too small to scale!! (2x36 vs min width of 3)" and "Line
      cannot be recognized!!" from the tesseract core. The page still yielded 1673
      characters. It is tesseract declining one sliver of a line, not a failure.

  20.5 WHAT IS OPEN NOW

      OCR-1 IS CLOSED. Six of ten pages yielded nothing because the document's own
      contrast band was being ignored in favour of two outlier pixels. It is fixed, the
      fix was predicted before it was run, and the prediction held exactly.
      ⚠ STILL ONE FILE. The thresholds are named constants precisely so real PMU scans
      (pilot §5 N2) can move them. A rule validated on ten pages of one document is a
      good rule, not a finished one.
      NEXT, in the order the damage justifies:
        B-1  The budget-source validator — a shape test, not a length test. Pure core,
             so the JS suite can cover it, and the learning loop already has the
             machinery for a reviewer to correct a miss.
        B-2  Whether create-in-place should be offered at all for a low-confidence value.
        B-3  The title bracket and the location tail — cosmetic beside B-1.
        B-4  Diagnose the contractor field before calling it anything.
===============================================================================
  21. B-1 — THE BUDGET SOURCE IS VALIDATED BY SHAPE, NOT BY LENGTH
      (2026-09-20, ordered. Branch phase-25-ocr-contrast-stretch, with P-3.)
===============================================================================

  21.1 THE DEFECT, AND WHY IT SURFACED NOW

      extractBudgetSource (document-parser-core.js) validated the captured tail with
      lenRange(2, 100) — a LENGTH CHECK AND NOTHING ELSE. Any 2-to-100-character string
      following a funding-source label was accepted as a budget source.
      It never bit while most scanned pages went unread. P-3 made six more pages of a
      real scan readable, and one of them carried a misread tail: "Accor, Fost =", which
      is thirteen characters and therefore passed.
      ⚠ THE HARM IS NOT THE TEXT BOX. Q3 (phase-23) added CREATE-IN-PLACE for budget
      sources, so the preview offered "Add \"Accor, Fost =\" as a new budget source". A
      reviewer under time pressure clicks it and a junk row enters budget_sources
      permanently. What this validator lets through can become a database record.

  21.2 WHAT SHIPPED — asSourceName()

      A source is a NAME, and a name has a shape. The checks, each for a reason:
        length 2-60                 a source name is short; 100 was a sentence's worth
        name punctuation only       '=', '|', '_', '*', '<', '>', '#', '@', '"', ':' are
                                    OCR debris or a label bleeding in, never part of a
                                    name. This alone is what kills "Accor, Fost ="
        opens on letter/digit/'('   the leading DIGIT is deliberate: "20% Development
                                    Fund" is a real LGU source
        closes on letter/digit/')'/'.'/'%'   catches a dangling "GAA," or "Fund -"
        two consecutive letters     "05142005" and "3 1" carry no word at all
        at most six words           "General Appropriations Act (GAA) FY 2025" is
                                    already generous; nine words is a clause
        first word is not a         a fragment caught mid-sentence opens on "the", "of",
        function word               "in", "with"; a name does not
        punctuation under 25%       debris even when every character is permitted
      ⚠ DELIBERATELY BIASED TOWARDS REJECTING, and the asymmetry is the argument: a
      rejected value leaves the field empty for the reviewer to type, and the L1-L3
      learning loop lets them TEACH it so the next document fills free. An accepted
      misread invites a row nobody unmakes. Failing closed costs a keystroke; failing
      open costs a database.
      The value is refused outright rather than slid past — findLabeledValue stops
      rather than sliding (Step 1i-1), so the field comes back empty and honest.

  21.3 VERIFICATION

      JS suite 234 / 234 / 0 — three NEW tests in tests/js/document-parser-core.test.mjs,
      which is where a pure-core rule belongs rather than in a one-off probe:
        · thirteen real sources accepted, including the acronyms, the hyphenated and
          slashed forms, the parenthesised long form and the leading-digit LGU fund
        · eleven pieces of debris rejected, "Accor, Fost =" named explicitly as the one
          that prompted the work
        · extractBudgetSource end-to-end: takes "GAA", refuses the misread
      MUTATION-FALSIFIED: putting lenRange(2, 100) back fails exactly one test — the
      end-to-end one — and restoring returns the suite to green. The tests are not
      vacuous, they catch this specific regression.
      A CORRECTION TO MY OWN EXPECTATION, worth recording: I first listed "Fund -" as
      something to reject and "20% Development Fund" as something to accept, and got both
      wrong. cleanValue already strips trailing punctuation, so "Fund -" arrives as
      "Fund" and is a fair source; and my opening rule required a letter, which rejected
      a real fund name. The second was a genuine defect in the first build.

  21.4 ⚠ THE SAME HOLE IS STILL OPEN NEXT DOOR — B-1b, NOT DONE

      extractCategory validates with lenRange(3, 50), which is the identical weakness,
      and Q3 gave CATEGORY create-in-place too. So a misread category can still become a
      permanent categories row by exactly the same route.
      IT IS A ONE-LINE CHANGE now that asSourceName exists, but it was NOT ordered and is
      NOT done — the order was B-1, the budget source. Flagged here so it is not lost.
      (Category names are a slightly different shape from funding sources — "Renovation",
      "New Construction" — so whether asSourceName is reused as-is or given a sibling is
      a real question, not a copy-paste.)
      STILL OPEN from 20.5: B-2 (should create-in-place be offered at all for a
      low-confidence value), B-3 (the title's leading "]" and the location's trailing
      "Date: ..."), B-4 (diagnose the contractor field before naming it).
===============================================================================
  22. B-1 CONFIRMED, AND B-4 — THE CONTRACTOR WAS READING A BID-ABSTRACT ROW
      (user browser check + build, 2026-09-20)
===============================================================================

  22.1 ✅ B-1 CONFIRMED IN THE BROWSER

      The budget source now comes back "— none —" instead of offering
      "Add \"Accor, Fost =\" as a new budget source". The junk value is gone AND the
      invitation to make it a database row with it.

  22.2 THE CONTRACTOR DEFECT — DIAGNOSED BY THE USER, CONFIRMED IN THE SOURCE

      SYMPTOM: the contractor field came back
        "DONBER CONSTRUCTION | 301 ENGINEERS | SKYSCRAPER CONS.& | CH..."
      when the real contractor is SKYSCRAPER CONSTRUCTION & ENGINEERING SERVICES, named
      on the contract page as "hereinafter called the CONTRACTOR".
      CAUSE: page 3 of the bundle is an ABSTRACT OF BIDS, which prints every bidder
      across ONE table row. isContractorValueCandidate accepted any 5-to-150-character
      line carrying a construction word — and a row of four construction companies
      answers that loudly. extractContractor's labeled path was no better: lenRange(3,150),
      length and nothing else.
      ⚠ IT IS THE SAME SHAPE OF DEFECT AS B-1, AND THE SAME TRIGGER. The rule was always
      this loose; the bid-abstract page was simply unreadable until P-3, so the row never
      reached the vote. The user's read of it was right from the screenshot alone.
      A SECOND HOLE FOUND WHILE FIXING IT: extractContractor ended with
        return normalized || cleanValue(cut);
      so when the validator REJECTED a value the raw text was returned anyway. A stricter
      validator alone would have changed nothing. Now it returns null.

  22.3 WHAT SHIPPED — asCompanyName()

      A contractor field holds ONE company.
        a '|' is a CELL BOUNDARY — the line reconstructor joining columns, or tesseract
          reading a table's printed vertical rule. Never part of a company's name.
        length 5..100 (was 3..150) — 150 was a table row's worth.
      Used by BOTH the labeled path and the value-side heuristic, and the fallback hole
      above is closed.

  22.4 WHICH RULE ACTUALLY CATCHES WHAT — measured, because the mutation surprised me

      Removing the pipe check failed only ONE of the two new tests, not both. The reason
      is worth writing down rather than explaining away: THIS document's bid row is 131
      characters, so the length ceiling catches it even with the pipe rule gone. The two
      rules cover different shapes:
        131ch piped row (this document)   -> refused by EITHER rule
         25ch piped row                   -> refused by the PIPE rule alone
        111ch borderless row              -> refused by the LENGTH rule alone
         23ch borderless row              -> ⚠ REFUSED BY NEITHER. Accepted today.
      ⚠ SO THE GAP IS NAMED: a SHORT bidder list in a table drawn WITHOUT vertical rules
      still gets through. No such page exists in this bundle, and inventing a rule for a
      case nobody has seen is how the last three dead ends started — but it is a known
      hole, not an oversight, and real PMU scans (pilot §5 N2) are what should decide
      whether it needs closing.

  22.5 VERIFICATION

      JS suite 237 / 237 / 0 — three new tests: the row refused by asCompanyName and by
      both extraction paths, the real contractor still read with its "(hereinafter called
      the CONTRACTOR)" tail stripped, and "Awarded to: ACME BUILDERS" still working.
      MUTATION-FALSIFIED: removing the pipe check fails the asCompanyName test and
      restoring returns the suite to green (see 22.4 for why only one test moved).
      ⬜ BROWSER CHECK OWED: the contractor should return to SKYSCRAPER CONSTRUCTION &
      ENGINEERING SERVICES, which is what it read before the bid-abstract page became
      readable. If it comes back EMPTY instead, the contract page's own line is not being
      accepted either and that is the next thing to look at, not a reason to loosen this.

  22.5b ✅ B-4 CONFIRMED IN THE BROWSER, WITH ONE LEFTOVER (new item B-5)

      The contractor is back to SKYSCRAPER CONSTRUCTION & ENGINEERING — the bid-abstract
      row no longer wins, and the contract page's own line does. B-4 is proven.
      ⚠ THE VALUE STILL CARRIES OCR DEBRIS AT BOTH ENDS:
        "i SKYSCRAPER CONSTRUCTION & ENGINEERING NES : ELA fom"
      against a true name of SKYSCRAPER CONSTRUCTION & ENGINEERING SERVICES. A stray
      "i " leads it and "NES : ELA fom" trails it — "SERVICES" misread, most likely with
      the red RECEIVED stamp bleeding into the same line.
      WHY IT IS A REAL COST AND NOT ONLY UGLY: contractor is an FK and is deliberately
      never auto-created (Q3), so this CANNOT become a junk row — that risk is absent
      here. What it costs is the AUTO-MATCH: a clean name would select an existing
      contractor from the list, and a dirty one falls through to "not in list" and makes
      the reviewer pick by hand.
      B-5 (NOT BUILT, NOT ORDERED). The principled cure is the one B-3 needs too: a value
      that has run into neighbouring text should be CUT WHERE THE NEXT THING BEGINS — at
      an embedded label, or at leading/trailing single-character debris. A name is one
      phrase; anything after a colon inside it belongs to something else.

  22.6 STILL OPEN
      B-1b  extractCategory has B-1's identical hole and identical create-in-place risk.
      B-2   whether create-in-place should be offered at all for a low-confidence value.
      B-3   the title's leading "]" and the location's trailing "Date: 05142005" — the
            location one is the same class again: two table columns OCR'd into one line,
            but joined by a SPACE rather than a pipe, so 22.3's rule does not see it.
===============================================================================
  23. B-2 — CREATE-IN-PLACE IS NO LONGER ONE CLICK (2026-09-20, ordered)
      The user chose ALL THREE options put in §20.5 / the B-2 explanation: gate on
      confidence, make the reviewer type it, and warn on a near match.
===============================================================================

  23.1 WHY THIS CONTROL DESERVED THREE CHANGES AND NOT ONE

      renderChoiceCreateAction writes a PERMANENT ROW to a lookup table on the strength
      of text a machine read off a photograph, in one click.
      B-1 stopped the values that LOOK like debris. The residue is the dangerous half: a
      value can be perfectly well-shaped and still wrong. OCR confuses C and G, so GAA
      arrives as CAA, passes every check asSourceName makes, and becomes a near-duplicate
      row — after which every report that groups by budget source is quietly split in two
      and nobody can tell from the numbers that it happened.

  23.2 WHAT SHIPPED

      (1) NOT OFFERED AT ALL FOR A SCANNED DOCUMENT.
          ⚠ AN HONEST DEPARTURE FROM THE WORDING. The option was put as "offer only for
          a labeled match, not a value-side heuristic guess". For THESE TWO FIELDS that
          distinction does not vary: extractBudgetSource and extractCategory are
          label-only — the value-side keyword dictionaries exist for title and contractor
          and nothing else. So the literal rule would have changed nothing.
          What DOES vary, and is where the misreads actually live, is whether the text
          came from a PDF TEXT LAYER or from OCR. That is the axis gated: ocrUsed is
          stashed by showAutofillPreview and the create control is replaced by a note
          saying why. The scanned value still shows and still fills the form; it just
          cannot mint a row.
      (2) THE REVIEWER TYPES THE NAME. The input starts EMPTY, with the document's text
          shown beside it for reference. Prefilling would be a one-click button wearing a
          disguise — and typing is exactly what turns a misread CAA into a correct GAA.
          The Add button stays disabled under two characters.
      (3) A NEAR MATCH IS NAMED BEFORE ANYTHING IS CREATED, and re-checked on every
          keystroke so it tracks what will actually be created rather than what the
          document happened to say.
            distance 0  the row already exists → Add is DISABLED and the existing row is
                        offered for selection. Creating it would duplicate outright.
            distance 1-2  "this is very close to X — use that if you meant it", with a
                        one-click way to take the existing row.
          ⚠ IT WARNS, IT NEVER BLOCKS (except at distance 0). GAD is one edit from GAA
          and is a genuinely different fund; a rule that refused it would be wrong. The
          helper returns a DISTANCE, not a verdict, precisely so the caller can ask
          rather than decide.

  23.3 WHERE THE PIECES LIVE, AND WHY

      editDistance() and nearestName() are in the PURE CORE, not in the DOM half, so the
      JS suite tests the part that can be wrong in a quiet way. Normalisation folds case
      and collapses whitespace, because "Trust  fund" and "Trust Fund" are the same row
      to a human and must not become two.
      The gate, the form and the warning are DOM, and carry a browser check.

  23.4 VERIFICATION

      node --check clean on both files · JS suite 241 / 241 / 0 with four new tests:
      single edits counted in each direction, the misread found, case and spacing folded,
      silence when nothing is close, and the explicit assertion that a real neighbouring
      source comes back as a distance rather than a refusal.
      MUTATION-FALSIFIED: removing the case-folding from nearestName fails the
      "finds the row a misread was meant to be" test; restoring returns the suite to
      green.
      ⬜ BROWSER CHECK OWED — all three are DOM:
        · a SCANNED document shows the note and NO create control
        · a TEXT-LAYER document shows the type-it form, Add disabled until two characters
        · typing an existing name disables Add and offers to select that row
        · typing something one edit away warns and still permits creation
        · "use that"/"select it" puts the existing row in the field and clears the form

  23.5 STILL OPEN
      B-1b  extractCategory still validates by length. NOTE it is now partly shielded:
            a scanned document can no longer create a category at all (23.2 item 1), so
            the DB-pollution route is closed for scans. A text-layer misread can still
            reach the typing form — but a human now types the name, which is most of the
            defence. Its best fix remains matching against the FOUR known categories.
      B-3   the title's leading "]" and the location's trailing "Date: 05142005".
      B-5   the contractor's "i " prefix and "NES : ELA fom" tail — same cure as B-3.
===============================================================================
  24. B-1b, B-3 AND B-5 — THE REST OF THE NOISE P-3 EXPOSED
      (2026-09-20, ordered: "lets continue". No browser check is possible on any of
      this yet — see 24.5.)
===============================================================================

  24.1 B-1b — THE CATEGORY IS VALIDATED BY SHAPE

      extractCategory validated with lenRange(3, 50): length and nothing else, exactly
      what the budget source had before B-1. A category is not an ordinary label —
      CategorySeeder defines exactly four and the dashboards GROUP BY this column, so a
      junk fifth category does not sit quietly in a table, it splits every figure that
      counts projects by type.
      asCategoryName differs from asSourceName deliberately: a funding source is often an
      acronym and may open on a digit ("20% Development Fund"), while a category is an
      ordinary English noun phrase. So this one opens on a LETTER, takes no percent sign,
      and allows five words rather than six. It also refuses a '|', which is B-4's cell
      boundary arriving in a third field.
      ⚠ THE STRONGER FIX IS STILL NOT THIS ONE. With only four valid values, matching the
      extracted text against the live list (nearestName already exists) beats any shape
      test — but that list is in the DB and the core is pure, so it would have to be
      plumbed through parseProjectInfo's opts. Noted, not done.

  24.2 B-3 + B-5 — ONE CAUSE, THREE SYMPTOMS, ONE CURE

      A scanned page has no columns, only a PICTURE of them, so OCR reads a printed row
      as one line and whatever sat to the right arrives glued on. B-4 caught the version
      that carries a '|'; these carry only a space, so something else has to mark the
      edge.
        title       "] Rehabilitation/Refurbishment of Canteen..."   a leading artifact
        location    "...Polytechnic Colleges Date: 05142005"          the next column
        contractor  "i SKYSCRAPER CONSTRUCTION & ENGINEERING NES : ELA fom"   both ends
      stripEdgeDebris — a one-character token at either end that is not a word is a
        speck, a bracket or a margin mark, never part of a name.
      cutAtEmbeddedLabel — a "Word:" inside a value means the NEXT FIELD started there.
      ⚠ SCOPED ON PURPOSE, AND THE SCOPING IS THE INTERESTING PART. The cut is applied to
      LOCATION and CONTRACTOR, where a colon is never part of the value, and NOT to the
      TITLE, where "Phase 2: Completion" is a real title and cutting it would destroy
      content. The title gets edge-debris stripping only. A rule that is right for one
      field is not automatically right for its neighbour.
      RESULT, end to end through parseProjectInfo:
        title       "Rehabilitation/Refurbishment of Canteen at Food Laboratory Building"
        location    "Nabua Campus, Camarines Sur Polytechnic Colleges"
        contractor  "SKYSCRAPER CONSTRUCTION & ENGINEERING"
      The contractor is RECOVERED rather than refused: the tidying runs BEFORE
      asCompanyName, so a name that only needed cleaning is cleaned instead of dropped.
      (It still loses "SERVICES", which OCR destroyed outright. Nothing in the text can
      bring that back — B-2's typing form and the L1-L3 loop are where that is fixed.)

  24.3 ⚠ A DEFECT IN MY FIRST VERSION, CAUGHT BY TRYING IT

      cutAtEmbeddedLabel first matched a label of ONE OR TWO words before the colon.
      Against the real strings that was wrong in both directions at once:
        "…& ENGINEERING NES :"   read "ENGINEERING NES" as the label and cut at
                                 ENGINEERING  -> lost "& ENGINEERING"
        "…Colleges Date:"        read "Colleges Date" as the label and cut at Colleges
                                 -> lost "Colleges"
      Each swallowed a word of the answer. Matching the MINIMUM — the single word
      immediately before the colon — is correct, and two tests now pin that down
      explicitly so the "improvement" of allowing two words cannot be reintroduced.
      ACCEPTED RESIDUE: a genuinely two-word label ("Project Location:") leaves its first
      word on the value. A stray word is not a lost one, and the trade is the right way
      round.

  24.4 VERIFICATION

      JS suite 250 / 250 / 0 (from 244). Nine new tests across the three items,
      including two end-to-end through parseProjectInfo: the dirty document cleaned in
      all three fields, and A CLEAN DOCUMENT PROVEN UNCHANGED — which is the assertion
      that matters most, because every one of these rules could quietly damage a good
      parse.
      MUTATION-FALSIFIED: putting lenRange(3, 50) back in extractCategory fails exactly
      the B-1b end-to-end test; restoring returns the suite to green.

  24.5 ⬜ NO BROWSER CHECK IS POSSIBLE YET, AND WHY

      The user has no further sample documents. Worse, the obvious file cannot exercise
      any of this: B-1 already makes the budget source come back EMPTY on
      pdfdocstest5.pdf, so there is no unmatched value, so no create control renders at
      all — the scanned path included. B-2's five DOM paths and these three fields all
      need a document that has not been seen yet.
      ⇒ THIS RAISES N2 (pilot §5), "get real PMU scans", from a good idea to the
        BLOCKING ITEM for the whole B-queue. Four changes now sit suite-green and
        browser-unproven, and the suite does not cover the DOM half at all.
      ⇒ Until then, treat B-1/B-1b/B-2/B-3/B-4/B-5 as BUILT AND UNPROVEN, not as done.
===============================================================================
  25. THE LEARNING LOOP — A REGRESSION I CAUSED, AND WHY IT FINDS NOTHING ON A SCAN
      (2026-09-20, before the user's planned loop retest)
===============================================================================

  25.1 ⚠ B-4 HAD DEMOTED THE LEARNED VOCABULARY BELOW THE HEURISTICS — FIXED

      The core states the loop's rule plainly: "learned hits skip the construction-word
      gate ... a reviewed vocabulary outranks heuristics". B-4 put
      `if (!asCompanyName(raw)) return null;` at the TOP of
      isContractorValueCandidate, so a phrase the reviewer had taught and an approver had
      approved could still be refused by a heuristic ceiling it was meant to overrule.
      ⚠ AND THE OBVIOUS FIX WOULD NOT HAVE WORKED. Simply relaxing the length bound for
      the learned branch changes nothing, because THE LEARNED BRANCH WAS UNREACHABLE for
      most real lines: the hardcoded-keyword branch ran first and RETURNED — null
      included — before `extra` was ever consulted, and almost every contractor line
      contains one of those keywords. The original ordering did not implement its own
      stated precedence; B-4 only made that visible.
      WHAT SHIPPED:
        1. the learned check runs FIRST, and keeps the length bounds only (5..150);
        2. the hardcoded guess keeps the tighter ceiling — it has no label and no human
           behind it, so it is the path that should be strict;
        3. the '|' rejection applies to EVERY path INCLUDING the learned one. That is
           deliberate and is the one place teaching does NOT win: learned phrases match
           by SUBSTRING, so a taught "skyscraper construction" also matches the
           bid-abstract ROW that contains it, and B-4's defect would walk back in through
           the learned door. No amount of teaching makes a table row into one company.
      Verified in all four directions: a taught 104-character name is accepted; the same
      line untaught is refused; a taught phrase inside a piped row is still refused; an
      ordinary name still reads. JS suite 250/250/0.

  25.2 ⭐ DOES THE LOOP MINE FROM SCANNED FILES? YES IT RUNS, AND NO IT USUALLY LEARNS
       NOTHING — AND THE REASON IS EXACT

      The user asked this after observing it "might not work" on a scan. It is not
      imagination, and it is not a bug in the plumbing.
      THE PLUMBING IS FINE. window._autofillDocTexts is stashed unconditionally from
      whatever text a file produced (document-parser.js:252), OCR text included, and
      harvestCorrections() does not gate on OCR anywhere.
      THE ANCHOR IS THE PROBLEM. mineCorrectionLabels() finds the label by locating the
      CORRECTED VALUE inside a document line, and for any non-numeric field that is an
      EXACT case-insensitive substring match:
          const idx = line.toLowerCase().indexOf(lowerVal);
      On a scanned document the corrected value is, by definition, the thing OCR got
      wrong. The reviewer types SKYSCRAPER CONSTRUCTION & ENGINEERING SERVICES; the OCR
      text says "...ENGINEERING NES : ELA fom". No substring match, no anchor, no label,
      no proposal. THE CORRECTION THAT IS MOST WORTH LEARNING FROM IS THE ONE THE MINER
      CAN LEAST ANCHOR.
      WHAT DOES STILL WORK ON A SCAN, and it is not nothing:
        · NUMERIC fields, which use a far more tolerant rule — digit runs compared with
          separators stripped, so a typed "4,834,297.59" anchors an OCR'd "4.834,297.59".
          Tolerant of punctuation damage, NOT of digit damage.
        · TEXT fields where OCR read the VALUE correctly but the parser missed it — an
          unrecognised label, a layout it could not follow. That is the loop's core use
          case and it works on scans exactly as on selectable files.
      WHAT CANNOT WORK: any field where OCR mangled the value itself.

  25.3 PREDICTION FOR THE PLANNED RETEST (stated before the run, per §19.4's habit)

      SCANNED pdfdocstest5.pdf — a MIXED result is the correct outcome, not a failure:
        · corrections to fields whose value OCR read correctly should teach normally;
        · corrections to fields whose value OCR mangled should teach NOTHING, silently.
          The contractor is the clearest example in this file.
      SELECTABLE pdfdocstest5.pdf — should behave as designed throughout, since every
      corrected value exists verbatim in the text.
      ⚠ IF THE SCANNED RUN TEACHES NOTHING AT ALL, that is worth a second look: by 25.2
      the fields OCR read cleanly should still anchor.
      ⚠ AND NOTE WHAT CHANGED UNDER THE DEMO: the ABC blinding is LIFTED, so a clean
      selectable file now FILLS the ABC rather than missing it. The old
      miss-then-teach demonstration no longer reproduces without flipping
      L3_TEST_BLIND_ABC back on. The B-queue's stricter validators are the natural source
      of misses now.

  25.4 IF IT IS WORTH FIXING (proposed, NOT built)

      Anchoring a correction FUZZILY rather than exactly is the obvious move, and the
      machinery now exists: B-2 added editDistance/nearestName to this same core. A
      corrected value could anchor to the best-matching line within a distance budget
      instead of requiring a verbatim hit.
      ⚠ IT IS NOT A SMALL CHANGE AND IT CUTS BOTH WAYS. A loose anchor mines a label
      from the WRONG line, and a wrong label approved into live vocabulary is worse than
      no label at all — it will then mis-fill confidently on every later document. If
      this is attempted it wants a tight distance budget, the gated/ungated scoring kept,
      and the approval desk treated as the real safety net.
      NOT ORDERED. Measure the retest first: 25.3 says how to read it.
===============================================================================
  26. STAGE 4 — THE LEARNING LOOP, RESTUDIED (2026-09-20)
      READ-ONLY. No app code written for this section. Ordered by the user before
      the loop retest: "restudy and do research first for any improvements".
      Everything below was verified in source today, not recalled.
===============================================================================

  26.0 THE PIPELINE AS BUILT, IN ONE PASS

      L1 CAPTURE   applyAutofillFromPreview() calls harvestCorrections(parsed)
                   (document-parser.js:2728). For each of the 11 learnable boxes it
                   diffs what the parser produced against what the reviewer left, and
                   for each changed box asks mineCorrectionLabels(docText, value, 3)
                   for up to 3 candidate LABEL phrases. Posts to
                   /parser-keyword-proposals/collect.
      L1 MINER     mineCorrectionLabels() locates the CORRECTED VALUE inside a document
                   line and takes the text to its left as the label; falls back to the
                   previous line when nothing sits to the left. Scores each hit with the
                   F-GATE rule and ranks gated hits first.
      L2 STORE     one row per distinct (field, label_phrase). Repeats increment
                   `occurrences`. VALUES ARE NEVER STORED — labels only (Decision L0b),
                   which is the right call and constrains every fix below.
      L2 PROMOTE   status 'proposed' -> 'live' on human approval at the desk, OR
                   AUTOMATICALLY at occurrences >= 3 (controller :76-78).
      L3 CONSULT   learned-keywords-loader.js fetches ?status=live and hands
                   parseProjectInfo opts.learned.labels[field]. Each phrase becomes an
                   extra findLabeledValue pattern using the field's own normalizer, and
                   — for title and contractor — also widens the VALUE-side candidate
                   test. Consulted as lowest priority; an empty table reproduces
                   today's behaviour exactly.

  26.1 ⚠ L-1. THE ANCHOR IS EXACT, AND THAT IS THE WHOLE SCANNED-DOCUMENT PROBLEM

      Recorded in full at §25.2 and not repeated here. Summary: a non-numeric
      correction anchors by line.toLowerCase().indexOf(value.toLowerCase()), so on a
      scan — where the correction exists precisely because OCR mangled the value —
      there is nothing to anchor to. THIS IS THE DOMINANT FINDING, and F6 ("the pilot
      starts with REAL PMU projects") makes it the common case rather than the exception.

  26.2 ⚠ L-2. AN UNANCHORED CORRECTION STILL WRITES A ROW — AND THAT ROW GOES LIVE

      When the miner finds nothing, harvestCorrections STILL posts a proposal with
      label: null (the loop is written to record that a correction happened at all).
      The store's dedup key is (field, label_phrase) WITH AN EXPLICIT whereNull BRANCH
      (controller :67-71), so EVERY unanchored correction for a field collapses into ONE
      row — and that row's `occurrences` climbs with each one. At three it
      AUTO-PROMOTES to 'live' (:76-78), and the listing renders label_phrase ?? ''
      (:124).
      ⇒ THE RESULT IS A LIVE ROW WITH AN EMPTY PHRASE, which teaches nothing (the reader
        skips empty strings) but occupies the desk and reports itself as learned.
      ⇒ ON SCANS THIS IS THE NORMAL PATH, not the edge case. Three scanned corrections
        to the same field and the desk shows a live rule that is pure noise.
      This is not cosmetic: it is the loop telling a reviewer it learned something when
      it learned nothing, which is exactly the signal a reviewer uses to decide whether
      the feature is working.

  26.3 ⚠ L-3. PROMOTION AT THREE IS AUTOMATIC AND UNREVIEWED

      Nothing human stands between a mined phrase and live vocabulary once it has been
      seen three times.
      TODAY THAT IS DEFENSIBLE, because the exact anchor makes a wrong label unlikely —
      the phrase really did sit next to the value in the text.
      ⚠ IT STOPS BEING DEFENSIBLE THE MOMENT THE ANCHOR IS LOOSENED (§25.4). A fuzzy
      anchor that mines from a NEARBY line produces plausible-looking wrong labels, and
      three of them go live without anyone looking. ANY FUZZY-ANCHOR WORK MUST CHANGE
      THIS THRESHOLD IN THE SAME BREATH — they are one decision, not two.

  26.4 ⚠ L-4. CAPTURE FIRES ONLY AT APPLY, AND ONLY FROM THE PREVIEW MODAL

      harvestCorrections reads the PREVIEW's boxes at the moment Apply is pressed
      (document-parser.js:2706-2728). A reviewer who presses Apply and THEN fixes a
      field in the create-project form itself teaches the loop nothing.
      ⇒ That is very likely the common human path: people correct things where they can
        see the whole form, not inside a modal. Under F6, PMU staff encoding real
        projects will do exactly this.
      ⇒ The richest corrections — the ones made after seeing everything in context —
        are the ones the loop never sees.

  26.5 ⚠ L-5. ONE PHRASE LIST SERVES BOTH THE LABEL SIDE AND THE VALUE SIDE

      learnedFor('title') feeds BOTH tryLearnedLabel (as a findLabeledValue pattern) AND
      extractTitle's extraTitleKeywords / isContractorValueCandidate's extraKeywords.
      So a phrase mined as a LABEL — "Project Title", "Name of Contractor" — also
      becomes a VALUE-side keyword, and a learned value hit deliberately SKIPS the
      heuristic shape gates ("a reviewed vocabulary outranks heuristics").
      ⇒ A line that merely CONTAINS the label phrase can therefore be accepted as the
        VALUE. The desk shows an innocuous-looking label row; the behaviour it unlocks is
        broader than what the approver thought they were approving.
      ⇒ This is the same substring-matching hazard that §25.1 had to guard against for
        the bid-abstract row, showing up on a second path.

  26.6 L-6. CLEARING A WRONG VALUE TEACHES NOTHING

      harvestCorrections skips a box when the final value is empty or under 3 characters
      (:2771). So "the parser filled this, it is wrong, and there is no right answer on
      this page" — a real and confident human judgement — is discarded.
      The loop can only learn what a field IS, never what a rule got WRONG. A bad
      hardcoded rule cannot be unlearned by use.

  26.7 L-7. NO DOCUMENT-TYPE SCOPING

      Every learned phrase is global. A phrase that means ABC on a funds certificate may
      mean something else on a contract or an abstract of bids, and the loop has no way
      to express that. With the pilot's documents arriving as mixed bundles this is a
      real cross-contamination route, though no instance has been observed yet.

  26.8 WHAT I WOULD PROPOSE, RANKED BY VALUE PER UNIT OF RISK (nothing ordered)

      A. FIX L-2 FIRST. It is the cheapest and it is pure cleanup: either stop posting a
         proposal when the miner found no label, or keep recording it but EXCLUDE
         null-label rows from auto-promotion and from the live list. My preference is the
         second — the count is genuinely useful evidence ("47 corrections to contractor
         that we could not anchor" is exactly the number that justifies fixing L-1) — but
         it must never present itself as a learned rule.
         ⇒ No accuracy risk whatsoever. It only stops the loop claiming false credit.
      B. THEN L-4, capture on form submit as well as on Apply. Also low risk: the same
         diff against the same snapshot, fired from a second place. It roughly doubles
         the corrections the loop ever sees, and it catches the considered ones.
      C. THEN L-3, and ONLY WITH a decision about L-1. Raise the auto-promotion bar, or
         remove auto-promotion for anything not exactly anchored. This is the safety
         valve that makes any anchor loosening survivable.
      D. L-1 (fuzzy anchoring) LAST, not first, despite being the headline problem.
         It is the only one that can make the parser CONFIDENTLY WRONG on every future
         document, and it should not ship before C is in place. §25.4 has the shape.
      E. L-5 and L-7 are design questions to answer, not defects to rush. L-5 wants the
         label list and the value list separated; L-7 wants a document-type column. Both
         are larger than they look and neither has a witnessed failure yet.

  26.9 ⚠ WHAT TO DO BEFORE ANY OF THIS — AND IT IS NOT CODE

      The retest the user has planned (§25.3) is the measurement that should decide A-E,
      and the blinding is now ON specifically so the round trip can be demonstrated.
      RUN IT FIRST. In particular it will show whether L-2's null-label rows appear as
      predicted, which is a claim made here from reading the code and has not yet been
      seen happen.
      ⚠ AND A CAUTION ABOUT THE RESTUDY ITSELF: every finding above is from SOURCE, not
      from a run. §11 and §15 of this file are both records of a confident reading being
      refuted by the first measurement. Treat 26.1-26.7 as well-founded predictions.
===============================================================================
  27. THE LOOP RETEST ON THE SCANNED FILE, AND WHAT IT TURNED UP
      (user, 2026-09-20, blinding ON, pdfdocstest5.pdf)
===============================================================================

  27.1 WHAT THE SCAN ACTUALLY TAUGHT — §25.2's PREDICTION HELD

      The loop mined TWO label candidates for the ABC and stored both. Verified in the
      live DB (parser_keyword_proposals), not from the screen:
        #82  abc  occ=1  proposed  AI-assisted  'Approved Budget for the Contract; PB'
        #83  abc  occ=1  proposed  AI-assisted  '| TOTAL ESTIMATED PROJECT COST P'
      ⇒ WHY IT ANCHORED ON A SCAN, WHICH §25.2 SAID WOULD USUALLY FAIL: the ABC is a
        NUMERIC field, and numeric anchoring compares DIGIT RUNS with separators
        stripped. The OCR line reads "P 4,834, 297.59" — mangled spacing, correct digits
        — so the typed value still matched. That is exactly the exception §25.2 named:
        "tolerant of punctuation damage, NOT of digit damage."
      ⇒ ZERO NULL-LABEL ROWS. L-2 (§26.2) did NOT materialise here. It is UNOBSERVED
        RATHER THAN REFUTED: only the ABC box was corrected in this run, and it anchored,
        so nothing could have produced a null row. The prediction still stands for a text
        field on a scan; it has simply not been exercised yet.
      ⚠ BOTH ROWS ARE source = 'AI-assisted', NOT 'correction'. What the loop harvested
        was the AI's answer being KEPT, not a human typing a value. That is the designed
        AI-4 behaviour ("learn everything, tag the origin") and the desk shows the badge
        — but it means that on this file THE LOOP IS CURRENTLY LEARNING FROM THE MODEL,
        not from the reviewer. Worth knowing before the desk's contents are read as
        human judgement.

  27.2 ⚠ THE REAL DEFECT THE RETEST FOUND — TWO LABELS, TWO DIFFERENT ANSWERS

      Approving '| TOTAL ESTIMATED PROJECT COST P'  -> ABC reads 4,834,297.59  (correct)
      Approving 'Approved Budget for the Contract; PB' -> ABC reads 4,834,297.00 (WRONG)
      The user's read is that this label is what produces the competing .00 value that
      the pick-one control has been offering all along. That is a good hypothesis and it
      fits: the preview showed BOTH .00 and .59 as candidates before any learning.
      WHAT I COULD REPRODUCE, and it is not yet the answer:
        'Approved Budget for the Contract; PB 4,834,297.59'  -> 4834297.59  correct
        'Approved Budget for the Contract; PB 4,834, 297.59' -> 4834297.59  correct
          (glueMoneyFragments handles the split figure, so the OCR spacing is NOT it)
        'Approved Budget for the Contract; PB P 4,834, 297.59' -> null
          (a peso marker BETWEEN the label and the figure defeats it)
      None of those produces .00, so the line that does is a line I have not seen.
      ⬜ OWED, AND IT IS ONE BUTTON: the preview carries "Copy read text". With the raw
      OCR text in hand, every line containing 4,834,297 can be checked and the one that
      yields .00 identified. Guessing at the shape has already cost three wrong synthetic
      lines above; this file's own §8 records what that habit costs.

  27.3 ⭐ P-3 SILENTLY BROKE THE AI PAGE PICKER — A SIDE EFFECT NOBODY PREDICTED

      The user noticed AI assist "seems to default to page 1 and 2". It does, and the
      reason is P-3's success.
      ocrAiCandidates() (document-parser.js:1543) orders candidates as:
        pages that yielded NOTHING first, then pages that were read
      Before P-3, seven of ten pages yielded nothing, so that ordering carried real
      signal — it sent the pages the reader had failed on. AFTER P-3 EVERY PAGE YIELDS
      TEXT, so the "yielded nothing" list is EMPTY, `ordered` collapses to plain document
      order, and with AI_SLICE_PAGES = 2 (:475) the picker sends PAGES 1 AND 2, every
      time, on every file.
      ⇒ THE RANKING NOW HAS ZERO SIGNAL. It is not choosing the weakest pages; it is
        choosing the first two and calling it a ranking.
      ⇒ THIS IS AGENTS.md's FUTURE-QUEUE ITEM 8 — "rank by LEAST USABLE text rather than
        NO text ... the per-page char-count and soupiness ledger already exists and the
        ranking ignores it" — which was filed as optional polish. IT IS NO LONGER
        OPTIONAL. The condition that made it a nicety (some pages yield nothing) is the
        condition P-3 removed.
      ⇒ THE FIX IS CHEAP BECAUSE THE DATA IS ALREADY THERE: every scan report carries
        pageChars and soupyPages. Ordering by ascending usable characters, soupy pages
        first, restores the signal without new plumbing.

  27.4 ⚠ AND A CAUTION ABOUT THE GOOD RESULT

      The AI filled the (blinded) ABC correctly from pages 1 and 2, which the user says
      do not contain the ABC — it is on pages 5, 7 and 8.
      ONLY IMAGES ARE SENT. postAiSlices posts body { images: slices } and nothing else
      (:1986) — no OCR text, no extracted values. So if the ABC truly is not on the two
      pages sent, THE MODEL DID NOT READ IT.
      A value that is right by luck is indistinguishable, from the outside, from one that
      is wrong by luck, and the banner itself said "Answered by backup model — verify
      carefully". Before this is taken as evidence that AI assist works well on
      normal-doc scans, it is worth confirming whether the ABC appears on page 1 or 2 at
      all. If it does not, the outcome is a warning wearing the costume of a success.

  27.5 THE USER'S PROPOSAL — LET THE REVIEWER CHOOSE HOW MANY PAGES GO

      Raised by the user in the same message, and it is a good idea that COMPOSES with
      27.3 rather than replacing it: a better ranking decides WHICH pages go, a depth
      control decides HOW MANY.
      THERE IS ALREADY PRECEDENT IN THE SAME PANEL: "Read depth (≈2s per page)" with a
      10/25/40/50 selector drives the OCR budget. An "AI pages" selector beside it would
      be the same control shape in the same place, which is the cheapest kind of new UI.
      WHAT IT COSTS, and it must be said before it is built: pages go as IMAGES, so
      each page is tokens, latency and quota against a tier the pilot cannot rely on. The
      50-second ladder budget does not grow because the reviewer asked for ten pages.
      A sensible shape: keep 2 as the default, offer 2 / 5 / all, and warn on the larger
      settings rather than forbidding them.
      NOT ORDERED. 27.3 should land first — sending MORE of the wrong pages is not an
      improvement, and fixing the ranking may well make 2 pages enough.
===============================================================================
  28. THE READ TEXT SETTLES IT — AND FOUR MORE FINDINGS
      (user, 2026-09-20: the raw OCR text, the approve-both test, two questions)
===============================================================================

  28.1 ⚠ A CORRECTION TO §27.1 — I READ THE DATABASE WRONG

      §27.1 said both stored rows were source='AI-assisted' and concluded "on this file
      the loop is learning from the model, not from the reviewer". THAT WAS WRONG, and
      the user said so.
      My query used the Eloquent model, whose SoftDeletes global scope HIDES deleted
      rows. With withTrashed() there are FOUR:
        #80  abc  live  correction   DELETED  'Approved Budget for the Contract; PB'
        #81  abc  live  correction   DELETED  '| TOTAL ESTIMATED PROJECT COST P'
        #82  abc  live  AI-assisted           'Approved Budget for the Contract; PB'
        #83  abc  live  AI-assisted           '| TOTAL ESTIMATED PROJECT COST P'
      ⇒ THE MANUAL TYPING TEST DID RECORD, with source='correction', and it mined THE
        SAME TWO LABEL PHRASES the AI-assisted run later mined. That agreement between
        two independent routes is a genuinely good sign for the miner.
      ⇒ THE LESSON IS ABOUT THE QUERY, NOT THE LOOP: any future check of this table must
        use withTrashed(), or a deleted row will look like a row that never existed.

  28.2 ⭐ THE .00 IS SOLVED, AND IT IS NOT A PARSER BUG

      From the raw read text, page 1 (the Abstract of Bids) reads:
          Approved Budget for the Contract; PB 4,834,297 5g
      OCR TURNED ".59" INTO " 5g". The label 'Approved Budget for the Contract; PB'
      matches that line, the tail is "4,834,297 5g", and "5g" is not a number — so the
      value reads 4,834,297 and the ABC comes back as 4,834,297.00.
      THE PARSER READ EXACTLY WHAT IT WAS GIVEN. There is nothing to fix in extraction.
      Meanwhile the other label matches page 7:
          | TOTAL ESTIMATED PROJECT COST P 4,834, 297.59
      where only the SPACING is damaged, glueMoneyFragments repairs it, and the value
      reads 4,834,297.59 correctly.
      ⚠ §25.2 SAID THIS IN ADVANCE, in a different context: the numeric path is
      "tolerant of punctuation damage, NOT of digit damage". Here is the same boundary
      deciding which of two labels tells the truth.
      ⚠ AND NOTE WHAT THE DOCUMENT ITSELF SAYS: the funds certificate (page 5) carries
      "(Php 4,834,297.59)" cleanly, and the contract (page 2) carries 4,204,128.84 which
      is the CONTRACT PRICE, not the ABC. The true ABC is 4,834,297.59, so the label that
      won is the one reading the damaged copy.

  28.3 ⚠ NEW FINDING L-8 — TWO LEARNED LABELS FOR ONE FIELD COMPETE BY LIST ORDER

      With BOTH labels approved to live, the ABC resolved to 4,834,297.00 — the damaged
      one. Not because it is better, but because tryLearnedLabel walks the phrase list
      and the FIRST match wins, and the list arrives in row order (#82 before #83).
      ⇒ THE LOOP HAS NO NOTION OF WHICH LEARNED LABEL YIELDS A BETTER VALUE. Teach it two
        phrases for one field and the winner is decided by insertion order.
      ⇒ THAT IS A REAL DESIGN GAP, and it gets worse as the loop succeeds: the more a
        field is taught, the more likely two phrases disagree, and the tie-break is an
        id. §26.8's ranking should gain this as a new item.
      POSSIBLE SHAPES, none ordered: prefer the label whose captured value passes the
      field's normalizer most cleanly; prefer the one with more occurrences; or let the
      POOL adjudicate rather than first-match (see 28.4, which is why it could not here).

  28.4 WHY THE TWO COMPETING VALUES DID NOT APPEAR — EXPECTED, NOT A DEFECT

      The user noticed the pick-one control ("I found two different values") did not
      activate this time, though it did before the blinding.
      That is the blinding doing exactly what it is for. collectAbcCandidates SLEEPS
      while L3_TEST_BLIND_ABC is true, and that collector is what feeds the ABC POOL.
      No pool, no two candidates, no pick-one. Turn the blinding off and the control
      returns.
      ⚠ WORTH SEEING CLEARLY: while blinded, the ABC is decided by a SINGLE first-match
      learned label with no second opinion. That is a fine demo configuration and a poor
      production one, which is the same thing the blinding has always been.

  28.5 ⚠ NEW FINDING — THE "AI-ASSISTED" BADGE CANNOT RENDER ANY MORE

      The user noticed the AI-assisted label did not appear on the AI-assist run. It
      cannot. mergeAiIntoEmptyBoxes stamps the field and then tries to hang the badge:
          const td = el.closest ? el.closest('td') : (el.parentNode || null);
          if (td && !td.querySelector('[data-ai-marker]')) { ...append... }
      STAGE 1b (phase-23) REPLACED THE PREVIEW'S FIELD TABLE WITH A RESPONSIVE GRID.
      There are now ZERO <td> elements in the file — the cells are <div class="afp-cell">
      — so el.closest('td') returns null, `td` is null, and the badge is never appended.
      The ternary tests whether el.closest EXISTS (it always does), so the parentNode
      fallback beside it is dead code and never runs.
      ⇒ The RENDER-time path still works (aiMarkerFor is in the grid template), but the
        AI merge happens AFTER the grid is drawn and relies solely on the dynamic path.
      ⇒ THE PROVENANCE IS STILL RECORDED — the DB rows carry source='AI-assisted'
        correctly (28.1), and the desk badge shows. Only the in-preview per-field marker
        is missing, which is the one a reviewer sees at the moment of deciding.
      FIX IS ONE LINE: el.closest('.afp-cell') || el.closest('td') || el.parentNode.
      ⚠ THIS IS A SECOND-ORDER CASUALTY OF A UI REWRITE, and it is the second one this
      session (P-3 broke the AI page picker, §27.3). Both were invisible because nothing
      tests the DOM half.

  28.6 THE USER'S TWO PROPOSALS

      (a) A REJECT BUTTON ON THE KEYWORD DESK — status 'rejected' rather than deletion.
          GOOD, AND CHEAP. Today the only way to refuse a proposal is to delete it, which
          soft-deletes the row — and 28.1 just showed what that costs: a deleted row is
          invisible to the ordinary query, so the same bad phrase can be re-proposed,
          re-reviewed and re-deleted forever with no memory that it was already judged.
          A 'rejected' status keeps the judgement, and the collector could skip
          re-proposing a phrase already rejected — which is the real prize, not the
          button. Needs: the enum widened, the desk filter, and collect() to respect it.
      (b) LET THE REVIEWER CHOOSE HOW MANY PAGES GO TO THE AI — see §27.5, unchanged.

  28.7 ⚠ SHOULD THE AI BE SHOWN WHAT OCR READ? — A SPLIT ANSWER

      The user asks whether sending the OCR text along with the images would give the
      model context about where the system needs help.
      SENDING THE MISSING FIELD NAMES: YES, and it is nearly free. The request body today
      is { images: slices } and nothing else — the model is not even told which boxes are
      empty. Telling it "we need abc and budgetSource" focuses the read without
      suggesting an answer, and would likely improve the hit rate on exactly the case
      that matters.
      SENDING OUR OCR TEXT: NO, or at least not without care, for a reason this very
      session demonstrates. The whole value of the AI tier is that it is an INDEPENDENT
      read of the pixels. Feed it our transcription and two things happen: it may anchor
      on our error instead of looking (had we sent page 1's text here, it would have seen
      "4,834,297 5g" — the wrong value), and we lose the ability to tell whether it READ
      the image or paraphrased our text, which is what makes a second opinion worth
      having at all.
      ⇒ IF IT IS TRIED ANYWAY, send the text as something to CHECK rather than to trust
        — "our reader produced X for this field; confirm or correct from the image" — and
        keep the field-level provenance so a disagreement is visible rather than merged.
===============================================================================
  29. THE AI-ASSIST BATCH (2026-09-20, ordered: "for the ai lets go with your
      recommendation"). Branch phase-27-ai-assist-targeting.
      Three changes, in the order §27.5 argued for: fix WHICH pages go before
      offering to send MORE of them.
===============================================================================

  29.1 THE PAGE RANKING NOW USES THE LEDGER IT WAS ALREADY COLLECTING

      ocrAiCandidates ordered "pages that yielded NOTHING first, then pages that were
      read". P-3 emptied the first group, so the ordering collapsed to document order and
      the picker sent pages 1 and 2 of every file, every time (§27.3).
      The sort now falls through three real signals, and the first one preserves the old
      behaviour exactly wherever it still applies:
        1. a page the reader got NOTHING from — still the best thing to send;
        2. then pages whose text came back SOUPY. Forty garbled characters are worth
           less than four hundred clean ones, and the old rule saw both as "read";
        3. then least usable text first, by pageChars;
        4. document order as a tie-break, so the choice is stable across runs.
      THE PANEL NOW SAYS WHICH KIND OF WEAK a page is — "the text here came back
      garbled", or "read, but the least text on this file (683 chars)". That string is
      the only place a reviewer can see why a page was chosen.
      ⭐ ON THE REAL LEDGER FROM §20.1 THE PICK CHANGES FROM PAGES 1,2 TO PAGES 7,3 —
      and PAGE 7 IS THE ABC PAGE, the thinnest read in the file at 683 characters. The
      ranking now sends the page the user had to go looking for by hand.

  29.2 THE "AI-ASSISTED" BADGE CAN RENDER AGAIN

      One line. el.closest('td') became
      (el.closest && (el.closest('.afp-cell') || el.closest('td'))) || el.parentNode,
      so it finds Stage 1b's grid cell, still finds a <td> if one ever returns, and the
      parentNode fallback is reachable now rather than dead (§28.5).

  29.3 THE REQUEST NAMES THE EMPTY BOXES — AND NOTHING ELSE

      The body was { images: slices } and the model was not told which fields were
      wanted. It now carries need: [...], the core names of the preview boxes still
      empty at the moment the button is pressed.
      ⚠ FIELD NAMES ONLY, NEVER OUR OCR TEXT, and §28.2 is the argument: our page-1 text
      says "4,834,297 5g" — the wrong value — so handing over the transcription would
      have offered the model our own error to agree with. This tier earns its place by
      reading the pixels INDEPENDENTLY; the moment it sees our text we can no longer
      tell whether it read the page or paraphrased us.
      SERVER SIDE: the controller reads `need`, the service takes it as a second
      argument, and buildPrompt appends one sentence naming the fields.
      ⚠ UNKNOWN NAMES ARE DROPPED, not trusted — array_intersect against self::FIELDS.
      A stale or hostile client cannot use this to write into the prompt. Verified: a
      payload of ['abc', '; IGNORE ALL PREVIOUS INSTRUCTIONS', 'notAField'] produces a
      prompt containing "needs these: abc." and neither of the other two.
      AND WITH AN EMPTY LIST THE PROMPT IS BYTE-IDENTICAL TO BEFORE, so a client that
      never sends the hint gets exactly today's behaviour.

  29.4 VERIFICATION

      JS 250/250/0 · PHP 1231 passed / 0 failed · both files lint clean.
      C:\temp\opencode\probe_ai_ranking.mjs — 9 checks, all pass. It extracts the REAL
      ocrAiCandidates and runs it against the ACTUAL post-P-3 ledger for
      pdfdocstest5.pdf: the thinnest two pages are chosen, the full ordering is ascending
      by characters, the pre-P-3 shape still puts unread pages first, a soupy page
      outranks a longer clean one, and the no-scanned-pages fallback is untouched.
      Prompt behaviour checked directly through reflection (29.3).
      ⚠ A PROBE BUG WORTH RECORDING: my first fixture gave UNREAD pages a character
      count, and the ranking duly sorted them by it. Real unread pages record 0. The
      failure was mine, not the code's — but it is exactly the shape of mistake that
      would have made me "fix" working code, and it was only visible because the
      expectation was written down before the run.

  29.5 ⬜ WHAT IS OWED

      All three are DOM or live-AI paths and the suites cannot speak for any of them:
        · press AI assist on the scanned file and confirm the panel now offers pages 7
          and 3 (not 1 and 2), with the new reason strings;
        · confirm the AI-assisted badge appears on the boxes the AI filled;
        · confirm the answer is no worse than before now that the prompt names the
          missing fields — this one is a LIVE MODEL call, so it cannot be asserted, only
          observed. §28.7 predicted it should help; if the ABC comes back wrong on pages
          the AI can now actually see, that is the more interesting result.
===============================================================================
  30. §29 CONFIRMED IN THE BROWSER, AND A GAP IN MY OWN B-5 (2026-09-20)
===============================================================================

  30.1 ⭐ ALL THREE AI CHANGES CONFIRMED

      The panel now reads "AI assist would read: pdfdocstest5.pdf p.7 | pdfdocstest5.pdf
      p.3" — pages 7 and 3, exactly as §29.1 predicted, and page 7 is the ABC page.
      The AI-assisted badges render on the boxes the AI filled (Category and ABC).
      The ABC comes back 4,834,297.59, correct.
      ⚠ THE USER'S OWN READING, AND IT IS THE RIGHT ONE: AI assist was correct BEFORE
      this batch and is correct after it. These changes removed a broken ranking, a
      dead badge and a blind prompt — they did not fix the extraction, because the AI
      path was never what broke it. The .00 came from the LEARNED-LABEL path (L-8).
      Recorded plainly so nobody later reads §29 as having fixed the ABC.

  30.2 ⚠ B-5 ONLY CLEANED HALF THE CONTRACTOR PATH — THE HALF THIS DOCUMENT DOES NOT USE

      The same screenshot showed the contractor still reading
          "i SKYSCRAPER CONSTRUCTION & ENGINEERING NES : ELA fom"
      B-5 (§24.2) wired stripEdgeDebris and cutAtEmbeddedLabel into extractContractor —
      the LABELED path — and tested exactly that. But the contract page has no
      "Contractor:" label on that line; the name sits mid-sentence and is found by
      isContractorValueCandidate, the VALUE-SIDE heuristic, which I left untouched.
      ⇒ THE UNIT TEST PASSED ON A PATH THE DOCUMENT NEVER TAKES. That is the same
        lesson as §28.5 and §27.3 arriving a third time: the suite covers the pure core,
        nothing covers which branch real input actually walks, and a green suite kept
        saying so.
      FIXED: the value-side path now tidies before its shape checks, exactly as the
      labeled one does, so a recoverable name is recovered rather than refused.
        value-side  "i SKYSCRAPER … NES : ELA fom" -> "SKYSCRAPER CONSTRUCTION & ENGINEERING"
        bid row                                      -> null (still refused)
        clean name                                   -> unchanged
        hereinafter tail                             -> still stripped
      Two new tests, one of them through parseProjectInfo with NO label anywhere, so
      the value-side branch is the only one that can answer. JS 252/252/0.

  30.3 WHAT IS STILL OPEN, AND IT IS THE ONE THAT BROKE EXTRACTION

      L-8 (§28.3): two learned labels for one field compete by LIST ORDER. Approving
      both ABC phrases makes the damaged one win on primary key, and the ABC reads
      4,834,297.00 from page 1's "4,834,297 5g".
      MY RECOMMENDATION, not ordered: stop taking the FIRST learned match and collect
      them all; when two disagree, hand the choice to the reviewer through the pick-one
      control that already exists for exactly this. An id is not a tie-break, and a
      human looking at two candidates is the mechanism this app already trusts
      everywhere else.
      ⚠ NOTE THE INTERACTION: while the ABC blinding is ON the pooled track sleeps, so
      the pick-one cannot render (§28.4). Any fix has to work with the pool awake, and
      be checked with the blinding OFF.

===============================================================================
  31. L-8 FIXED, AND REJECTION BECOMES A VERDICT (2026-09-20, ordered)
      Branch phase-27-ai-assist-targeting.
===============================================================================

  31.1 L-8 — A PRIMARY KEY IS NOT A TIE-BREAK

      tryLearnedLabel handed findLabeledValue the whole phrase list at once, and that
      returns the FIRST match. The list arrives in row order, so a field with two
      approved phrases had its winner chosen by insertion id. On the real document the
      phrase reading page 1's damaged line ("4,834,297 5g", the ".59" read as " 5g")
      beat the one reading page 7 cleanly, and the ABC came back .00.
      THE USER PUT IT EXACTLY RIGHT: this is how the HARDCODED track already works — it
      pools every find and hands a real disagreement to the reviewer — so the learned
      track should work that way too.
      NOW: each phrase is asked SEPARATELY, distinct values are kept, and when two
      disagree the pair is published in the SAME shape the pooled track uses, so the
      existing pick-one note renders it without knowing where it came from. The first
      value is still returned, so a field with one phrase is byte-identical to before.
      MEASURED on the two real labels against the two real lines:
        both phrases -> abc 4834297.00, disagreement { winner .00, runnerUp .59 }
        one phrase   -> abc 4834297.59, no disagreement
      ⚠ THE WINNER IS STILL THE DAMAGED ONE, and that is deliberate. The parser has no
      way to know which OCR line is the honest one, and inventing a preference would be
      guessing dressed as logic. What changed is that it no longer decides SILENTLY —
      the reviewer sees both and picks, which is the mechanism this app already trusts
      everywhere else.
      The wrapper merges the two channels with the POOLED verdict winning where both
      exist: it has counts and files behind it, where the learned one knows only that
      two approved phrases read differently.

  31.2 REJECTION IS NOW RECORDED, NOT DELETED

      ⚠ A CORRECTION FIRST: I told the user the reject button was "not implemented".
      It IS — the desk has had one all along. What it did was SOFT-DELETE.
      That is the part worth fixing, and §28.1 had already shown the cost from the other
      end: a deleted row is invisible to the ordinary query, so collect() could not see
      that a phrase had been refused, and the same wording could be re-proposed,
      re-judged and re-deleted forever with no memory of the decision. It also misled my
      own reading of that table — a query without withTrashed() concluded the loop had
      never learned from a human, because the human's rows had been deleted.
      NOW: destroy() sets status='rejected' and KEEPS the row; the listing accepts the
      new status; the desk has a Rejected tab and a grey badge; and collect() still
      increments the count — "this wording has now been seen eleven times" is exactly
      what would justify revisiting a refusal — but NEVER lets a rejected row climb back
      to proposed or live. Three more sightings is not a rebuttal to a reviewer.
      Verified against the live database: rejected, visible WITHOUT withTrashed,
      occurrences still rising, status unchanged after three further collects.

  31.3 VERIFICATION

      JS 252/252/0 · PHP 1231 passed / 0 failed / 4598 assertions · both PHP files lint.
      The existing reject test asserted the OLD contract (assertSoftDeleted) and was
      rewritten to the new one: the row survives with status='rejected', is NOT
      soft-deleted, and three further collect() calls leave the status alone while the
      occurrence count keeps rising. A behaviour change should move its test, not be
      shaped around it.

  31.4 ⬜ WHAT IS OWED

      Both are DOM paths. ⚠ AND THE FIRST NEEDS THE BLINDING OFF: while it is ON the
      pooled ABC track sleeps (§28.4), which is fine for testing the learned
      disagreement on its own but means the MERGE of the two channels cannot be
      exercised.
        · approve BOTH ABC phrases again and confirm the amber "I found two different
          values" note now appears with .00 and .59, and that picking .59 fills it;
        · reject a proposal and confirm it moves to the Rejected tab rather than
          vanishing, and that re-uploading the same document does not resurrect it.
===============================================================================
  END OF RESTUDY
===============================================================================
