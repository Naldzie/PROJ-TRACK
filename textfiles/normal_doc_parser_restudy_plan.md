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
#         question in the restudy: F1 — SEVEN OF TEN PAGES of a real scanned document
#         yield nothing, through a three-rung OCR ladder, on pages a human reads at a
#         glance. 70% of the document is invisible to the parser. That is a READ-PATH
#         problem (stage 2), not a UI wording problem.
#         ⚠ F2 NOTED 2026-09-18: the SAME file yielded 3 read pages one run and 4 the
#         next — a fixed file should read deterministically, and F1 is not measurable
#         until it does. Stage 2 now opens with "establish determinism".
#         Stage 2 (OCR/READ) — NOT started, now the priority. Stage 3 (EXTRACTION) ·
#         Stage 4 (LEARNING LOOP) — not started.
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

  ── F1. SEVEN OF TEN PAGES YIELD NOTHING THROUGH A THREE-RUNG LADDER ── ⚠ THE REAL FINDING
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

  ── F2. THE SAME FILE GIVES A DIFFERENT NUMBER OF READ PAGES EACH RUN ── ⚠ NOTED, NOT INVESTIGATED
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
     for trust than a consistent miss, and it also undermines any measurement of F1 —
     tuning against a moving baseline proves nothing.
     ⇒ STAGE 2 MUST ESTABLISH DETERMINISM BEFORE IT MEASURES ANYTHING ELSE. The first
     stage-2 action is now: run the same file three times and record scannedRead each
     time, BEFORE touching render scale or preprocessing. If the number moves, fix that
     first; F1's "7 of 10 yield nothing" is not a stable measurement until it stops.

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
  (F1). NO amount of better wording fixes that — the best possible UI on this run would
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

  STAGE 2 — THE READ PATH (OCR).  ⬜ NOT STARTED — ⬆ NOW THE PRIORITY (F1).
    The client's documents are scans, so this is where the system either works or does
    not, and on the one real specimen we have it does NOT: 7 of 10 pages yield nothing
    (§3 step 2b). Extraction is demonstrably fine on whatever text it is handed — the
    vote log read both dates correctly off the 3 pages that worked — so the loss is
    entirely upstream.
    ⚠ FIRST, BEFORE ANY OTHER MEASUREMENT: establish DETERMINISM (F2). The same file gave
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

  2026-09-18 (same day, second pass) — H1 MEASURED AND FALSIFIED; F1 FOUND. NO APP CODE.
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
    WHAT REPLACED IT (F1): 7 of 10 scanned pages yield nothing, through all three rungs of
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

  2026-09-18 — STAGE 1b, THIRD PASS: the spinner, properly this time. Plus F2 noted.
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

    F2 NOTED, NOT INVESTIGATED (§4): the same file yielded 3 read pages on one run and 4
    on the next. Recorded as a finding because a rules-based OCR read of a fixed file
    should be deterministic, and because it makes F1 unmeasurable until it stops. Stage 2
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

    A FAILING PAGE COSTS ABOUT 3x A SUCCEEDING ONE. ocrPage runs a dead-page ladder
    (:749-756): plain recognise, then if under 10 characters a scale-2 re-render, then
    if still under 10 a cropped re-render. A page that yields text stops at rung 1; a
    page that yields nothing pays all three, each with its own worker under the one-shot
    API.
    ON THE USER'S OWN FILE that is the difference between roughly 10 recognitions and
    roughly 24: 3 pages read (1 each) plus 7 dead pages (about 3 each). The pages that
    give us NOTHING are the ones we spend the most on — see F1.

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
       blind, and F1 says accuracy is already short.

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
    Dropping resolution trades accuracy directly, and F1 says accuracy is already the
    problem — 7 of 10 pages yield nothing today. Spending less per page to read even
    less is the wrong direction.

    SKIP PAGES BY "IMPORTANCE". The picker's ranking is known to be poor (it ranks by
    which pages OCR handled WORST, with no notion of whether a page holds the fields),
    so using it to skip work would skip the wrong pages.

  9.5 THE ORDER THAT MAKES SENSE, AND ITS PREREQUISITE

    F2 COMES FIRST. The same file yielded 3 read pages one run and 4 the next (see F2).
    No speed change can be judged against a baseline that moves on its own — a "faster"
    run that happens to read one page fewer would look like a win.
    A CONNECTION WORTH TESTING: one of F2's candidate causes was warm-versus-cold
    tesseract state. Under the one-shot API every page gets a FRESH worker, so if F2 is
    state-related, lever A (one reused worker) may make runs MORE deterministic as well
    as faster — which would make A the first thing to try for BOTH problems.
    THEN: A (reuse) -> measure -> B (parallel) -> measure -> D's measurement -> C if the
    corpus says orientation is uniform -> E.
===============================================================================
  END OF RESTUDY
===============================================================================
