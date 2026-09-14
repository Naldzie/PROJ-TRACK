# OCR PAGE PREVIEW, FREE ROTATION, AND AN AI PAGE PICKER (NORMAL-DOC PARSER)
# Parent refs: textfiles/document_parser_learning_loop_plan.md (§6 — the OCR batch:
#              R1/R2 orientation, P-ROT, F1/F2/F3', scale-2 and crop fallbacks, snapshots)
#              textfiles/document_parser_ai_fallback_plan.md (AI-1..AI-5 — the slice + merge)
# Date: September 14, 2026 — Status: ⬜ PLANNED, RESTUDY DONE, NO CODE
# Branch on build: phase-<n>-ocr-preview (Rule 12 — no merge to main without confirmation)

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

  Construction Project Monitoring System (PROJ-TRACK) for CSPC PMU.
  Laravel 12 / PHP 8.2 / MySQL. Frontend convention: app JS/CSS lives in public/
  via asset()/$jsv() — never @vite, never resources/ for new feature scripts.

  This is the NORMAL-DOC parser (contract/supporting PDFs that pre-fill the
  create-project wizard), NOT the S-curve track. The S-curve row-role work is
  PARKED — see textfiles/document_parser_pdf_teach_grid_plan.md §13, the two
  2026-09-14 restudy entries.

===============================================================================
  2. PROBLEM STATEMENT (user, 2026-09-14)
===============================================================================

  When OCR finds nothing, the amber "Auto-fill notice" offers per-page rotate
  buttons, a rotate-all, per-page snapshot downloads and "Try AI assist".

  THE COMPLAINT, verbatim: "each button for individual page rotate is shown BUT
  the file itself do not (so the user do not know if the page or all page are
  already in a proper position)."

  Exactly right. The reviewer is asked to fix an orientation they cannot see. The
  only way to look is to DOWNLOAD a snapshot per page — which is what the snapshot
  buttons are for, and it is a poor substitute for looking at the thing.

  Three asks:
    (a) show the document, rotate it live, per page and whole file;
    (b) mark which pages OCR could read and which it could not;
    (c) show which two pages would go to the AI, and let the user change them —
        "the 2 page that get sent might not contain an actual usable data".

===============================================================================
  3. RESTUDY FINDINGS (read-only, 2026-09-14)
===============================================================================

  3.1 THE DOCUMENT VIEWER MODAL CANNOT BE REUSED — two reasons, second decisive
  ---------------------------------------------------------------------------
    The user asked whether the rotate button already in the document viewer could
    drive this. It cannot.

    (i) THAT BUTTON IS CHROME'S, NOT OURS. document-viewer-modal.blade.php:76
        renders `<iframe id="dvIframe">` and :577 comments "PDF: use iframe
        (browser built-in viewer)"; the src is a server route streaming raw bytes
        inline (DocumentController.php:241-245). Chrome mounts its own PDFium
        viewer in a context our JS cannot reach — no DOM handle, no
        contentDocument, no postMessage. There is no rotation URL fragment, and
        Chrome's rotate turns the WHOLE document, never one page. The only
        `rotate` in that 647-line file is a CSS spinner at :353.

    (ii) THE FILE HAS NOT BEEN UPLOADED YET. The viewer takes a viewUrl to a
        server route. At autofill time the PDF is a pending local File with no
        URL. THIS WOULD RULE THE MODAL OUT EVEN IF CHROME'S VIEWER WERE
        SCRIPTABLE, and it is the cleaner reason to state.

    Blast radius, recorded so nobody revisits it casually: that modal is included
    once (documents.blade.php:171) and serves EIGHT call sites — documents rows,
    the preview quick-action, the generated summary report, progress and baseline
    attachments, issues, risks, extensions. Not a component to experiment in.

  3.2 BUT THE RENDERING MACHINERY ALREADY EXISTS
  ----------------------------------------------
    _saveOcrPageImage (document-parser.js:999-1044) is ALREADY "render page N at
    angle D to a canvas"; only its tail is download-specific:
      const rotDeg = rots[fileName + '#' + pageNum] || 0;
      const page   = await pdf.getPage(pageNum);
      const viewport = page.getViewport({ scale: 1.5, rotation: rotDeg || 0 });
      await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

    Rotation is handled natively by pdf.js via getViewport({rotation}) — there is
    no CSS transform anywhere in the OCR path, and there must not be one.

    window._ocrRotations — flat map keyed `${fileName}#${pageNum}` → degrees
    (multiple of 90). Reset ONLY in cancelDocumentAutofill() (:48), i.e. on modal
    open and close, so rotations survive re-runs within a session.

    window._autofillPdfFiles (:122-124) retains the original File objects for the
    whole modal session and is rebuilt at the start of every run. The bytes are
    available with no server round-trip. Known collision: lookups are by NAME
    (:1006), so two uploads with the same filename collide — pre-existing.

    AND THERE IS A PAGING CANVAS VIEWER TO MIRROR: _proposal_detail_modal.blade.php
    :249-345 already does prev/next/page-number over a pdf.js canvas with a render
    queue (rendering/pendingPage, :310-312). No rotation — but that is one extra
    argument on its getViewport at :283. Caveat: it loads its OWN pdf.js 3.11.174
    from cdnjs rather than the shared window.loadPdfJs. Mirror its STRUCTURE, use
    the shared loader.

  3.3 "EVERY ROTATE TRIGGERS A PARSE" — CONFIRMED, AND WORSE THAN REPORTED
  -----------------------------------------------------------------------
    All three rotate entry points end identically — bump the angle, close the
    preview, then runDocumentAutofill():
      _rotateOcrPage      :1101-1112   (per page, +90)
      _rotateAllScanned   :1076        (per file, no landscape gate)
      _straightenAllAndRescan :1051    (all files, gated on r.allLandscape)

    runDocumentAutofill() re-runs the ENTIRE pipeline over EVERY uploaded PDF —
    not just the rotated page. extractTextFromPDF re-reads the bytes and re-OCRs
    every page whose embedded text is under 10 chars, up to ocrPageLimit() (10 by
    default, user-selectable to 50). Per dead page that is not one recognize but
    up to ~6: the PSM ladder (recognizeEnglish, :520) plus ocrPageScale2Fallback
    (:580) plus ocrPageCropFallback (:606).

    MEASURED COST: the code's own estimate is ~2s/page (:1589), so one rotate
    click on a 10-page scan is ~20s, and three files is three times that. The
    comment at :1097 accepts this explicitly — "rotation is a rare manual rescue".
    It stops being rare the moment the user can SEE the page and starts nudging it.

    Mitigation already present: myRunId = ++_autofillRunId (:93) supersedes an
    in-flight run, so rapid clicks abort the previous scan at the next guard.

    ⇒ DECOUPLING ROTATE FROM RESCAN IS THE BIGGEST SINGLE WIN HERE, and it is
      independent of the preview. Nothing caches per-page text or angle today.

  3.4 THE READ/UNREAD INDICATORS ARE ALREADY KNOWN DATA
  -----------------------------------------------------
    Per-file scan report, built at :157 and stashed to window._autofillScanReports:
      { fileName, scannedTotal, scannedRead, truncated, ocrPages[], allLandscape,
        scannedPageNums[], landscapePages[], pagesVisited, numPages }

    ALREADY RECORDED, no new plumbing:
      which pages were photo-read   scannedPageNums (:790)
      which of those YIELDED text   ocrPages (:806-810, the ≥10-char bar)
      which pages are landscape     landscapePages (:791-799)
    The preview note already derives and shows this — :1560 `const unread =
    read.indexOf(pn) === -1;` renders a `*` and a "no text yet" badge. THE CONCEPT
    EXISTS; it is simply nowhere near a picture of the page.

    NOT recorded, would be new (both cheap):
      per-page CHARACTER COUNT — computed and console-logged only, at :815 under
        window._parserDebug. A one-line push beside ocrPages.push(pageNum).
      per-page SOUPINESS — core.scoreOcrSoup (document-parser-core.js:1422) runs
        inside recognizeEnglish and is discarded (it returns a bare string).
        Either widen that return or re-score the page text in extractTextFromPDF,
        which is pure and cheap.

  3.5 HOW THE TWO AI PAGES ARE CHOSEN — and why the user's worry is right
  ----------------------------------------------------------------------
    buildAiSlices(maxN), :1166-1219. The ordering is :1178:
      let ordered = scanned.filter(pn => !read[pn])      // 1. yielded NOTHING
                    .concat(scanned.filter(pn => read[pn])); // 2. then yielded something
      if (!ordered.length && r.numPages) { ...first 2 pages... }  // 3. text files

    So the rank is PURELY "which pages failed OCR". THERE IS NO NOTION OF WHETHER
    A PAGE IS LIKELY TO CONTAIN THE FIELDS. A blank page, a signature page or a
    photo of a stamp ranks FIRST precisely because it yielded nothing. The user's
    instinct — "the 2 page that get sent might not contain an actual usable data"
    — is exactly the failure mode, and the system has no way to know better.

    Already correct and worth surfacing: the slice render HONOURS the rotations
    (:1194 reads _ocrRotations, :1204 passes rotation into getViewport). Rotating
    before AI assist already improves what is sent; that link is invisible today.

  3.6 TWO GOTCHAS FOR WHOEVER BUILDS THIS
  ---------------------------------------
    (a) THE BUTTONS EXIST IN TWO PLACES. The preview modal's gray note
        (showAutofillPreview :1529-1593) and the amber notice (showAutofillWarning
        :2066-2158) each build their own rotate + snapshot loops, both capped
        .slice(0, 12) PER FILE with no global cap. Any change must cover both, or
        factor them out first. Factoring first is the better order.
    (b) _saveOcrPageImage NEVER CALLS pdf.destroy(). buildAiSlices does (:1215).
        A preview that re-renders on every click and inherits that omission will
        leak a PDFDocumentProxy per render.
    (c) window._ocrRotateTargets (reportIdx → fileName, :1568/:2090) is assigned
        by index and never truncated, so a shorter later run leaves stale tail
        entries until the modal reopens. Pre-existing; do not build on it without
        clearing it.

  3.7 OUT OF SCOPE BUT FOUND (flagging, not fixing)
  -------------------------------------------------
    - getViewerType() (document-viewer-modal.blade.php:442-448) NEVER returns
      'image' — IMAGE_EXTS feeds only isPreviewable/getFileIcon. So the
      viewerType === 'image' branch at :563 is DEAD CODE and every jpg/png falls
      through to the fallback panel. isPreviewable (:418) is never called at all.
      This matches the Phase-4 note that image preview was disabled, but the dead
      branch and the dead helper were not recorded.
    - pdfjs-loader.js is included by app.blade.php:381 and stafflayouts:389 but
      NOT by contractorlayouts. Irrelevant to this plan (contractors never create
      projects) but load-bearing if the document viewer is ever pdf.js-ified.

===============================================================================
  4. DECISIONS BANKED (user, 2026-09-14)
===============================================================================

  Decision O1 — The preview lives in the autofill notice, not the document viewer.
    Context: the viewer needs a server URL; the file is not uploaded yet (§3.1).
    Options: (a) a panel inside the amber notice / preview note; (b) a new
      full-screen preview modal; (c) extend the shared document viewer.
    Decision: (a).
    Rationale: reuses the existing render code, touches only the autofill flow,
      and cannot break the eight flows that depend on the shared viewer.
    Consequences: the DOM side is new — there is ZERO on-screen PDF rendering in
      the wizard today. Mirror _proposal_detail_modal's paging structure.

  Decision O2 — Rotate is free; re-reading is a deliberate second action.
    Context: every rotate re-OCRs every page of every file, ~20s for one click on
      a 10-page scan (§3.3).
    Options: (a) rotate re-renders only, a button re-reads ONLY the pages whose
      angle changed; (b) same but the button re-runs the full pipeline; (c) keep
      auto-rescan and just add the picture.
    Decision: (a).
    Rationale: turns a ~20s blind click into an instant visible one, and makes the
      expensive step explicit and proportional to what actually changed.
    Consequences: a partial-rescan path is NEW and is the main correctness risk in
      this plan — see §6.

  Decision O3 — Show the AI ranking, let the reviewer override it.
    Context: the rank is "which pages failed OCR", not "which pages hold fields".
    Decision: mark the two pages that would be sent AND why, with the ability to
      choose different ones. Default behaviour unchanged when nobody intervenes.
    Rationale: keeps the one-click path, makes a hidden heuristic legible, and
      gives the reviewer the only knowledge the system lacks.

===============================================================================
  5. IMPLEMENTATION STEPS (each proved before the next; Rule 10 between them)
===============================================================================

  STEP 1 — Factor the duplicated button blocks  ✅ BUILT 2026-09-14
    The rotate/snapshot/page-button loops exist twice (§3.6a). NO behaviour change;
    this exists so every later step is written once.

    WHAT SHIPPED — five helpers above _saveOcrPageImage, replacing expressions that
    were copy-pasted between showAutofillPreview and showAutofillWarning:
      ocrListedPagesOf(report)     the displayed page list (scanned ?? read,
                                   capped 12) — was duplicated FOUR times
      ocrRotatablePagesOf(report)  the rotate-all target list, UNCAPPED
      ocrRotationOf(file, page)    the _ocrRotations lookup, with the key format
                                   ocrPage/_saveOcrPageImage/buildAiSlices share
      ocrPageIsUnread(report, pn)  the 10-char-bar test behind the `*` badge
      ocrPageFactsOf(report)       {pageNum, rotation, read, landscape} per page —
                                   THE structure steps 3-5 consume
      stashOcrRotateTargets(reports)  the index→name map both surfaces must publish
                                   or every button is a silent no-op

    A DISTINCTION THAT WAS IMPLICIT AND IS NOW NAMED: the surfaces show at most 12
    buttons per file, but "rotate all" has always rotated EVERY scanned page,
    including ones with no button. That is why there are two page-list helpers
    rather than one. Behaviour unchanged; it simply reads as deliberate now.

    PROOF — 38 comparisons over 7 ledger shapes (typical, ocrPages-only fallback,
    nothing read, 20 pages against the 12 cap, two files with live rotations, empty
    ledgers, a filename needing HTML escaping). The OLD inline expressions and the
    NEW helpers were transcribed side by side and run over the same fixtures:
    ALL IDENTICAL — no rendered output changed. Probe kept in the session
    scratchpad (step1_parity.mjs).
    JS suite 158 tests, 156 passed + the 2 intended blinding fails — unchanged, and
    expected to be: this file is the wrapper, not the pure core the suite covers.

  STEP 2 — Decouple rotate from rescan  ✅ BUILT 2026-09-14, BY A DIFFERENT ROUTE
    THE PLAN SAID: stop the rotate handlers calling runDocumentAutofill(), track a
    dirty set, and add a button that re-OCRs only the changed pages. §6 flagged the
    partial re-read as the highest risk in this plan, because the ledgers drive both
    the indicators and the AI ranking and a partial path could update text without
    them.

    WHAT WAS BUILT INSTEAD: a photo-read CACHE, keyed file#size#page@rotation.
    THE RISK THE PLAN WORRIED ABOUT IS GONE BECAUSE THE PARTIAL PATH WAS NEVER
    BUILT. The full pipeline still runs and still recomputes every ledger from the
    same walk it always did; the only thing that changes is that a page already
    read at its current angle is served from memory instead of re-recognised.
    Because the ROTATION IS IN THE KEY, a page the reviewer turns always misses and
    is genuinely re-read, while untouched pages never are — which IS Decision O2a's
    property ("re-OCR only the pages whose angle actually changed"), obtained by
    construction rather than by bookkeeping.

    PLACEMENT MATTERS AND IS DELIBERATE: the cache sits INSIDE ocrPage, AFTER the
    budget gate, and a cache hit still increments _ocrPagesDone. Skipping that
    accounting would quietly admit later pages into a budget meant to be spent, and
    would move the truncation flag. Pages attempted, ledgers and warnings all stay
    byte-identical to an uncached run.

    EMPTY READS ARE CACHED TOO, and the tradeoff is real rather than free: a dead
    page is the most expensive one (the PSM ladder plus the scale-2 and crop
    fallbacks, up to ~6 recognitions) and is exactly what a rotate-heavy session
    revisits — but photo-reading wobbles slightly run to run (learning-loop plan §6,
    2026-09-07), so remembering an empty forgoes the chance an unchanged page reads
    next time. The read-depth "Re-scan" button now CLEARS the cache, which is the
    deliberate way to ask for a genuinely fresh read. Re-read (rotations) reuses the
    cache; Re-scan does not.

    MEASURED (step2_cache.mjs, transcribing the patched ocrPage gate and the
    per-page block of extractTextFromPDF over a 10-page test4-shaped file):
      run 1  cold                    10 recognitions   ledgers X
      run 2  unchanged, all cached    0 recognitions   ledgers identical to run 1
      run 3  ONE page turned 90°      1 recognition    that page now reads
      run 4  same rotations again     0 recognitions   ledgers identical to run 3
      run 5  after Re-scan clears    10 recognitions   ledgers identical to run 3
    So one rotate on a ten-page scan costs ONE page of work, not ten — the ~20s
    the plan measured becomes ~2s — and a cached run and a fresh run agree exactly.

    STILL NOT DONE, and deliberately left for a decision: the rotate handlers STILL
    call runDocumentAutofill(), so a rotate re-parses and rebuilds the preview even
    though it now re-reads almost nothing. There is no "Re-read the N pages I
    rotated" button. That remainder is now a UX question (batching several rotates
    before one rebuild, and removing the preview flicker) rather than the
    correctness-critical change it looked like — the expensive half is already
    solved, and solved without the ledger risk.
    - _rotateOcrPage / _rotateAllScanned / _straightenAllAndRescan stop calling
      runDocumentAutofill(). They update _ocrRotations and re-render the preview.
    - Track which (fileName#page) angles changed since the last successful OCR of
      that page — a dirty set, so the re-read button knows its scope.
    - A "Re-read the N pages I rotated" button runs OCR for ONLY those pages and
      merges their text back into the existing per-file text before re-voting.
    ⚠️ THIS IS THE RISKY STEP. extractTextFromPDF currently owns the whole-file
      walk and returns one joined string plus the ledgers; a partial re-read must
      update the ledgers consistently or the indicators and the AI ranking drift
      out of sync with reality. If that proves invasive, fall back to Decision
      O2(b) (full re-run behind the button) and say so — a slow honest button
      beats a fast wrong ledger.

  STEP 3 — The preview panel  ✅ BUILT 2026-09-14 (with step 2's remainder folded in)
    WHAT SHIPPED
      - ocrPagePreviewShellHtml() — one panel, one set of ids, injected by BOTH
        surfaces (the preview's gray note and the amber notice). Hidden until asked
        for by a "👁 see pages" button added beside the rotate controls.
      - renderOcrPagePreview() — draws the selected page at its CURRENT angle onto
        a canvas, with a caption (file, page N of M, turned D°, and whether the
        reader got text), prev/next, the two rotate buttons, a page strip, and the
        Re-read button. Screen scale is fitted to the panel width and is
        deliberately independent of the OCR render (which uses scale 3).
      - Per-file pdf.js documents are MEMOISED (_ocrPreviewDocs) — reopening the
        PDF on every click is the slow part — and released in
        cancelDocumentAutofill() via releaseOcrPreviewDocs(). The plan flagged
        _saveOcrPageImage's missing destroy() (§3.6b); this path does not repeat it.
      - Renders are token-guarded (_ocrPreviewToken), the same idea as the run id,
        so a fast prev/next cannot paint out of order.

    STEP 2'S REMAINDER, FOLDED IN AS AGREED: _rotateOcrPage and _rotateAllScanned
    NO LONGER RE-RUN THE PIPELINE. They turn the page, refresh the labels, redraw
    the picture, and stop. Re-reading is now the explicit Re-read button, whose
    label names the cost — "Re-read 2 pages" — computed by ocrPendingRereadPages(),
    which asks the photo-read cache which pages have never been read at their
    current angle. DERIVED, not a tracked dirty set, so it cannot drift from what
    the reader would really do.
      _straightenAllAndRescan is deliberately LEFT rescanning: it is the one-shot
      rescue on an empty run and its label has always said "& re-read". Verified
      its tooltip is still accurate rather than stale.
      The per-page rotate also SELECTS the page it just turned, so the button and
      the picture always agree.

    LABEL HONESTY: every rotate tooltip that used to promise "90° and re-read" now
    says "turn ... and show it", because that is what it does. The degree suffix
    moved into its own <span data-rot-deg> so refreshOcrRotateLabels() can rewrite
    it surgically after an instant rotate — no surface rebuild, no flicker.

    THE PANEL SURVIVES A RE-READ. Both surfaces redraw it after rebuilding, so
    pressing Re-read does not appear to close the page being worked on.

    VERIFIED (structural, since this is DOM code the suite does not reach):
      every id renderOcrPagePreview asks for exists in the shell (5/5);
      neither per-page nor per-file rotate calls runDocumentAutofill, and both
      redraw; _ocrPreviewReread does re-run the pipeline; the only surviving
      "and re-read" tooltip belongs to _straightenAllAndRescan, which still does.
      node --check clean; JS suite 158/156 + the 2 intended blinding fails
      (unchanged — the pure core is untouched); the step-2 cache probe still shows
      ledgers preserved.
    NOT BROWSER-PROVEN — that is §7.4, on sampledocs/pdfdocstest4.pdf.

    KNOWN, NOT FIXED: the panel shows pages from ocrRotatablePagesOf(), i.e. the
    SCANNED pages. A text-layer page in the same file cannot be previewed. That is
    the right default (only scanned pages are rotatable and only they fail), but it
    means "page 3 of 10" can skip numbers. The caption says the true page number.
    - A canvas in the notice showing one page at its current angle, with prev/next
      and a page strip. Reuse the _saveOcrPageImage render body; ADD pdf.destroy()
      (§3.6b); keep one PDFDocumentProxy per file rather than reopening per click.
    - Rotate acts on the page being shown; rotate-all on the file.
    - Render scale chosen for screen (~1.0-1.5), independent of the OCR scale 3.

  STEP 3b — Clear the notice of what the panel replaced  ✅ BUILT 2026-09-14
    User, on seeing the panel working: "can you remove all the unnecessary buttons
    now while hide the button for downloading (that is for debugging purpose same
    for the copy what was read button)". Both halves done, and they are different
    actions on purpose — REMOVED vs HIDDEN.

    REMOVED, because the panel does the job better:
      the per-page ⟳ wall (up to 12 per file) and the per-file "⟳ all".
      On the ten-page test4 scan that was twenty-odd controls, each doing something
      whose result the reviewer could not see. Both surfaces now carry ONE button
      per file — "👁 See & straighten <file>", with an "N unread" badge when the
      reader got nothing from some pages — and the panel owns page choice (its
      strip), turning (per page and per file) and re-reading.

    HIDDEN behind window._parserDebug (new helper ocrDebugAffordances()):
      the ⤓ snapshot downloads and both "Copy what was read" / "Copy read text".
      These exist to get pixels and raw OCR text into a chat for diagnosis. Before
      the live preview they were the ONLY way to see a page at all, which is why
      they were promoted to the front; now they are noise. Handlers are untouched,
      so one flag brings them back.

    CONSOLIDATED WHILE HERE: _ocrPreviewRotate / _ocrPreviewRotateFile now DELEGATE
    to _rotateOcrPage / _rotateAllScanned instead of repeating the angle arithmetic.
    Step 3 had introduced a second rotation path; two paths for one idea is the
    exact shape of the defects that cost four rounds on the S-curve desk, so it is
    gone while it is still one day old.

    DEAD CODE REMOVED RATHER THAN LEFT: refreshOcrRotateLabels() and its
    data-rot-deg spans existed to update the degree suffix on per-page buttons that
    no longer exist. Deleted with its two call sites.

    VERIFIED (structural, 13 assertions): no orphaned refreshOcrRotateLabels,
    data-rot-deg or data-rot-file; no per-page or per-file rotate buttons remain;
    both handlers still defined and reached by the panel's delegation; the debug
    gate applied in exactly four places with both handlers intact; exactly one
    See-and-straighten entry per surface. node --check clean; JS suite 158/156 +
    the 2 intended blinding fails. Net −64/+57 lines.

  STEP 4 — Read/unread indicators on the page strip  ✅ BUILT 2026-09-14
    The read/not-read/landscape half shipped with step 3 (ocrPageFactsOf already
    had it). What landed here is the part that says WHY.
      - extractTextFromPDF now RECORDS what it was already computing: pageChars
        per photo-read page, and soupyPages via core.scoreOcrSoup. The count was
        being thrown into a debug console line at :815 and discarded.
      - Soupiness is RE-SCORED from the page text rather than plumbed out of
        recognizeEnglish (which returns a bare string). The scorer is pure and
        cheap, so this costs nothing and changes no signature — the alternative
        was widening two return types for a display detail.
      - New ocrPageWhy() turns the three facts into a sentence, and both the
        caption and the strip tooltips use it.
    WHY IT MATTERS, and it is the point of the step: "nothing at all" and "forty
    characters of garbage" are DIFFERENT PROBLEMS. The first is usually
    orientation or a dark scan and is worth turning the page for; the second means
    the page was read fine and simply holds nothing useful. The strip used to say
    only "unread" for both.
      p.1  nothing at all — try turning it
      p.2  text read (900 characters)
      p.3  only garbled marks (40 characters)
      p.4  read, but the text looks garbled

  STEP 5 — The AI page picker  ✅ BUILT 2026-09-14
    THE RANKING IS NOW VISIBLE AND OVERRIDABLE. It was inline inside buildAiSlices,
    unseen and unchangeable, and it ranks by "which pages OCR handled worst" with
    NO notion of whether a page contains the fields — so a blank page, a signature
    sheet or a photo of a stamp ranks FIRST precisely because it yielded nothing.
    That blind spot cannot be fixed from the inside; only the person looking at the
    page knows. Hence: show it, explain it, let it be changed.
      - ocrAiCandidates(maxN) — the ranking, extracted UNCHANGED, now carrying a
        reason per page ("the reader got nothing here" / "read, but among the
        weakest" / "first pages of a file with no photo-read pages").
      - ocrAiSelection(maxN) — what will ACTUALLY be sent: the reviewer's picks
        when they have made any, else the ranking. buildAiSlices calls this, so
        what the panel SHOWS and what is SENT cannot drift apart.
      - Panel: a ✦ on each selected page in the strip, plus a row naming the
        chosen pages with their reason and a "+ this page" / "− this page" toggle
        for the page on screen, and a link back to the suggestion.
      - AI_SLICE_PAGES names the cap (was a bare 2 at each call site). The picker
        caps to it, oldest choice first, so slice-only stays a rule not a habit.
      - window._ocrAiPick is cleared in cancelDocumentAutofill — a choice belongs
        to the documents it was made against.

    STALE PICKS CANNOT SMUGGLE A PAGE. Every pick is re-validated against the
    CURRENT reports before it is used: an unknown file or an out-of-range page is
    dropped, and if nothing survives the ranking takes over. This matters because a
    Re-read rebuilds the reports underneath a selection made before it.

    VERIFIED (step5_aipick.mjs, 12 assertions over transcribed logic): the four
    yield explanations; the default ranking still picks the two pages that yielded
    nothing and still caps; an override is honoured and labelled as the user's; an
    entirely stale pick falls back to the ranking; a partly-stale pick keeps the
    valid half; a text-layer file still falls back to its first two pages with the
    right reason; and two files still send only AI_SLICE_PAGES between them.
    node --check clean; JS suite 158/156 + the 2 intended blinding fails.
    NOT BROWSER-PROVEN.

  STEP 6 — Logs + browser proof  ⬜ NOT STARTED
    Log each step here before committing (Rule 11).

===============================================================================
  6. RISKS
===============================================================================

  - PARTIAL RE-READ DRIFT (step 2) — the highest risk in this plan. The ledgers
    (ocrPages / scannedPageNums / scannedRead) drive BOTH the indicators and the
    AI ranking. A partial re-read that updates the text but not the ledgers, or
    vice versa, produces a screen that confidently states the wrong thing. Treat
    the ledger update as the contract, not an afterthought.
  - MEMORY. Holding a PDFDocumentProxy per file for the modal session is the point
    (re-rendering per click is slow), but it must be released in
    cancelDocumentAutofill() alongside _autofillPdfFiles.
  - SCOPE CREEP INTO THE DOCUMENT VIEWER. §3.1 says why it is out. If a shared
    viewer is ever wanted, that is its own plan, and the contractor layout is
    missing the pdf.js loader (§3.7).
  - NOTHING HERE MAY CHANGE EXTRACTION on files that already work. The OCR path,
    the ladder, the fallbacks and the vote are untouched by every step above.

===============================================================================
  7. VERIFICATION
===============================================================================

  1. npm run test:js — 158 tests, 156 passed + the 2 intended blinding fails.
     The pure core is untouched by this plan; a change in that count means
     something leaked into document-parser-core.js.
  2. php artisan test — the same 5 pre-existing failures BY NAME. No PHP is
     touched by any step.
  3. REGRESSION ON A FILE THAT ALREADY WORKS: a clean text-layer PDF must still
     fill the preview with no OCR, no notice, and no preview panel.
  4. BROWSER (the user's step), on sampledocs/pdfdocstest4.pdf — the 10-page
     landscape-baked scan this whole complaint came from:
       - the notice shows the page, not just buttons;
       - rotating a page is INSTANT and visibly turns the picture;
       - the strip marks which pages OCR read;
       - "Re-read" re-OCRs only what was rotated, and the fields fill;
       - the AI picker shows the two default pages with a reason and allows a
         different choice.
  5. Watch for the documented 2-vs-3-page yield wobble (learning-loop plan, §6
     2026-09-07) — photo-reading is slightly non-deterministic, so a page moving
     between read and unread across runs is NOT a regression.

===============================================================================
  8. CONVERSATION CONTINUATION GUIDE
===============================================================================

  1. Read §3 before designing anything — it is all measured, not inferred.
  2. Steps are ordered so the duplicated-button factoring lands first; do not
     start at step 3 because it is the visible one.
  3. Step 2 is where this plan can go wrong quietly. Its fallback is written down.
  4. Rule 10 between steps; Rule 11 before each commit; no merge to main without
     explicit confirmation (Rule 12).

===============================================================================
  END OF PLAN
===============================================================================
