# DOCUMENT PARSER LEARNING LOOP PLAN (CONTINUATION)
# Parent: textfiles/document_parser_accuracy_improvement_plan.md (Steps 1–1i, N-batch, S-batch, P1/P2)
# Date: September 4, 2026 — Status: HANDOFF (user changing session). Guard item 1 BUILT (JS 73/73, uncommitted); NEXT = item 2 snapshots (all pages) then O1/O2 auto-orientation. No implementation beyond guard.
# Branch: phase-6-parser-learning (FROM phase-5-parser-accuracy, Rule 12)

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

System Name / Client / Framework
  Construction Project Monitoring System (PROJ-TRACK) for CSPC PMU.
  Laravel 12 / PHP 8.2 / MySQL (live), SQLite :memory: (tests).

Why this file exists
  The parent plan grew past ~1850 lines. Per user 2026-09-04, learning-loop work
  continues HERE; the parent keeps history + the POST-1i roadmap pointer only.
  S-curve Steps 2-4 + parser_templates stay deferred in the parent (§11).

Feature area
  Normal-document parser vocabulary: replace the frozen hardcoded TITLE_VALUE_KEYWORDS /
  CONTRACTOR_VALUE_KEYWORDS lists (document-parser-core.js) with a living dictionary
  grown from real user corrections — the §11 parser_keywords item, with the journey added.

Frontend convention (IMPORTANT)
  App UI JS/CSS lives in `public/` linked via asset('...') — NO @vite for new features.

===============================================================================
  2. DECISIONS BANKED (user, plain words)
===============================================================================

  Decision L0a — Approvers: STAFF + ADMIN (not admin-only).
    Context: the extension-approval workflow taught that admin-only approval rots —
      the client's admin dislikes being the sole approver.
    Decision: correction COLLECTION open to every role making corrections; approve /
      reject / delete on the review list allowed for admin AND staff. Contractors
      excluded (they barely touch project creation).
    Consequences: policy gates check role in (admin, staff), not role === admin.

  Decision L0b — Learn LABELS ONLY (not values, not patterns).
    Context: user asked whether the loop covers the earlier ghost/tail/starvation fixes.
    Answer banked: NO — those were structural (glue, weights, placeholders, voting, OCR
      budgets, stale banners), covered permanently by the 62 automatic checks + browser
      proofs. The loop covers the VOCABULARY axis only (a future "Contract Sum" or
      "Effectivity Date" miss fixed after one correction). Values (names, amounts) are
      never learned — privacy + garbage risk.
    Consequences: harvested pairs are (label-phrase → field) only; the reader consults
      them as lowest-priority fallback, below every hardcoded label.

  Decision L0c — HYBRID trust (neither pure-auto nor pure-manual).
    System silently COLLECTS candidates from every correction; a pair goes LIVE only
      after 2–3 independent confirmations across files OR one-click approval in the review
      list; any live entry deletable in one click. Never learns from unresolved-disagree
      files until the user picks (ambiguity must not become "knowledge").

  Sequence (reaffirmed): bundle accuracy (done, proven) → L1 → L2 → L3 → AI fallback last.
    The AI trigger list is drawn from whatever the rules provably cannot solve after L3.

===============================================================================
  3. STATUS SUMMARY
===============================================================================

  Parent reader work (Steps 1–1i, N/S-batch, P1/P2)  ✅ BUILT + COMMITTED, browser-proven
    (P1 verdict buttons confirmed working by user 2026-09-04).
  L1 silent collection                               ⬜ NOT STARTED
  L2 review list                                      ⬜ NOT STARTED
  L3 reader consults table                            ⬜ NOT STARTED
  AI fallback                                         🔜 AFTER L3 (scope in parent POST-1i roadmap §A)

===============================================================================
  4. WHERE THE HOOKS GO (codebase study 2026-09-04, read-only)
===============================================================================

  Correction capture (verified — corrections are INVISIBLE today):
    - showAutofillPreview() builds editable previewInput-<id> rows for 11 fields
      (document-parser.js ~522-534, inputs ~569-571); the original `parsed` is discarded.
    - applyAutofillFromPreview() (~687-719) reads possibly-edited inputs and overwrites;
      _autofilledFields tracks element ids only (~450-454), never values.
    - Insertion point: snapshot guesses after tbody render; diff at Apply start; emit
      detail {original, applied, correctedIds}. Submit-time (_create_project_modal.js:728)
      is a noisier fallback (post-Apply date recalc muddies values).

  Review UI template (verified — photocopy job):
    - Clone BudgetSource (cleaner than Category): model + migration (name unique,
      description nullable, is_active, softDeletes) + Policy (create/update/delete =
      admin OR staff per L0a; view open) + explicit Gate registration in
      AppServiceProvider (BudgetSource missed this — do not repeat) + Controller with
      triple gates (route middleware role, controller 403, @can button) + manage modal
      (+ seeder NOT wired into DatabaseSeeder — run manually).
    - Table: parser_keywords + columns (label_phrase unique, field, occurrences,
      status enum proposed/live, source). Threshold ≥3 occurrences echoes §11.

===============================================================================
  5. IMPLEMENTATION STEPS
===============================================================================

  PHASE L1 — Silent collection only (zero behavior change)  🔄 IMPLEMENTED + tested (2026-09-04), NOT COMMITTED per user (Option 2: harvest candidate pairs immediately)
    - Backend: migration 2026_09_04_000001 parser_keyword_proposals (field indexed, label_phrase
      nullable, excerpt nullable, occurrences default 1, status default proposed, softDeletes);
      ParserKeywordProposal model (live/proposed scopes); Admin\ParserKeywordProposalController::collect
      (roles admin+staff per L0a, 11-field allowlist, upsert by field+label with occurrences++,
      auto-promote at ≥3, batch cap 11, no activity-log spam by design); routes admin+staff POST
      .../parser-keyword-proposals/collect (contractors excluded by middleware).
    - Core: mineCorrectionLabel(docText, finalValue) pure helper + export — digit-signature
      anchoring across separator variants (4+ digits both sides, so "90" never anchors a date),
      case-insensitive text anchoring, {label ≤80ch, excerpt ≤200ch} or {label null} or null.
    - Wrapper: AUTOFILL_CORE_FIELDS map; snapshot at preview render (date-normalized like inputs);
      window._autofillDocTexts stashed per run (never sent); harvestCorrections(applied) diffs +
      mines + fire-and-forget POST with CSRF (role-aware URL, contractor guard, keepalive,
      all failures swallowed). Corrected ⟹ resolved: amber picks and typed fixes both learn;
      had_disagree recorded as context. Untouched/cleared/<3ch boxes teach nothing.
    - Deviation from plan noted honestly: L0c said "skip unresolved-disagree files" — implemented
      as "corrected implies resolved" (Apply IS the user's decision; winner-kept boxes are not
      corrections). No silent learning from ambiguity remains possible.
    - Tests: 2 core mining fixtures (separator-crossing money, text + null/tiny guards);
      4 PHP feature tests (store, repeat-promote, contractor 403-via-redirect on both routes,
      unknown-field 422). JS 64/64. Full PHP suite: 985 passed + the SAME 6 pre-existing
      failures as the clean tree (2 EmailDraft known + 2 ContractorDashboard + 2 baseline;
      all reproduced with changes stashed — zero regressions from L1).
    - Incident: stale route cache (bootstrap/cache/routes-v7.php) hid the new routes (405s);
      cleared once; consider `route:clear` note in testing runbook. No PHP touched beyond L1.
    - Proof for user browser: correct one box on the CHS bundle → one proposed row in
      parser_keyword_proposals; extraction output byte-identical (reader untouched).

  PHASE L2 — Review list (staff + admin)  🔄 IMPLEMENTED + tested (2026-09-04), NOT COMMITTED per user
    - Clone: _manage_budget_sources_modal.blade.php shell (modal-lg, header/body/footer),
      helpers (bsHeaders/bsExtractErrorMessage/bsEscapeHtml pattern → pkp* names), CRUD fetch
      shapes, show/hidden modal wiring, shared showToast + showDeleteConfirmation (do NOT
      re-implement). Drop the add-form (rows arrive from harvesting); list-group → table
      (label | field | occurrences | status badge | excerpt w/ title=full); add status-filter
      tabs (All/Proposed/Live), ORDER BY occurrences DESC. Approve→PATCH live, Reject→DELETE,
      live rows deletable; NO edit-prompt copy; NO projects_count/422 guard; show the ≥3
      auto-live hint instead. Dropdown-refresh half of hidden.bs.modal does NOT apply
      (standalone screen, not stacked on addProjectModal).
    - Placement (user: BOTH): (a) standalone menu entry for periodic review + (b) launcher
      behind the create-project popup (same stacking opener pattern as budget sources).
    - Backend: ParserKeywordProposalController += getAllForManagement (?status= filter;
      id/field/label_phrase/excerpt/occurrences/status/created_at) + approve + destroy
      (soft-delete suffices — no unique index to release); LogsActivity on each; staff+admin
      gates (mirror collect); new ParserKeywordProposalPolicy (viewAny/view open,
      write ops admin||staff) AND register it in AppServiceProvider (BudgetSource missed
      this — do not repeat); routes mirrored in admin + staff groups (contractors: nothing).
    - Tests: staff 200 / contractor redirect / approve flips proposed→live / auto-promote
      via review path. Proof: approve one row in browser; audit shows who/when.

  2026-09-04 — L2 IMPLEMENTED (code + tests, NOT committed per user).
    - Backend: ParserKeywordProposalPolicy (write ops admin||staff, view open) + explicit
      Gate registration (BudgetSource gap NOT repeated); controller += getAllForManagement
      (?status=, occurrences DESC), approve (→live + activity log), destroy (soft-delete +
      activity log); routes mirrored admin + staff (PATCH approve, DELETE, GET all).
    - Frontend: _manage_parser_keywords_modal.blade.php (table + Proposed/Live/All tabs,
      data-* delegated approve/reject, excerpt tooltips, auto-live hint; no add-form, no
      edit-prompt); included in app + stafflayouts (contractor layout excluded); sidebar
      "Learned Keywords" menu entries in both layouts (modal launcher, no dedicated page);
      launcher button behind the create-project popup (staff+admin role check).
    - Tests: 8/8 ParserKeywordProposalTest (L1 4 + L2 4: list+filter, staff approve, admin
      reject-soft-delete, contractor blocked everywhere). JS 64/64 untouched. Blade compiles
      (view:cache clean). Incidents: stale route cache hid new endpoints (cleared; same as L1);
      my own test wrongly used an admin URL as staff (302 by design — fixed to staff URL).
    - Proof for user browser: open Learned Keywords (menu or popup button) → empty-table
      message (no proposals exist yet — correct for L1-postponed state); approve/reject flow
      awaits first real proposal. No PHP/JS regressions. No commit.

  PHASE L3 — Reader consults the table  ⬜ NOT STARTED (seam verified 2026-09-04, no code)
    - Seam: optional-parameter injection (NOT globals/setters — preserves pure/node --test).
      isTitleValueCandidate(line, keywords=TITLE_VALUE_KEYWORDS),
      isContractorValueCandidate(line, keywords=CONTRACTOR_VALUE_KEYWORDS),
      parseProjectInfo(doc, opts)/parseProjectInfoPooled(docs, opts) forward to both loops
      AND the 3 greedy-join sites in extractTitle (core :461/:508/:553). Old calls unchanged.
    - Loader (classic script): fetch live pairs ONCE at top of runDocumentAutofill (same
      role-base + CSRF pattern as harvestCorrections); browser cache 24h keyed by table
      updated_at (user approved 24h; NO storage-TTL precedent in public/js — new
      sessionStorage fetchedAt wrapper). Lowest-priority fallback only (below hardcoded).
    - Tests: synthetic paraphrase fixture (miss → approve → hit); hardcoded wins ties;
      empty table = today's behavior exactly. Paraphrase corpus generated at L3 kickoff
      (open question resolved: generate, not hand-write).
    - Proof: paraphrased-label file fails before approval, passes after, nothing else moving.

===============================================================================
  6. SESSION LOGS
===============================================================================

  2026-09-04 — File created (plan only, no code).
    - User: plan file too long → learning loop continues here after P1/P2. P1/P2 browser
      proof received (verdict + remove buttons working). Decisions banked: staff+admin
      approval (extension-workflow lesson), labels-only learning (structural fixes stay
      rule-tested), hybrid trust, sequence L1→L2→L3→AI-last.
    - No code, no commit instruction — NEXT = build L1 on go-ahead.

  2026-09-04 — L1 IMPLEMENTED (code + tests, NOT committed per user; Option 2).
    - Branch phase-6-parser-learning cut FROM phase-5-parser-accuracy (Rule 12, clean tree).
      User chose Option 2 (harvest candidate pairs immediately) + no commit.
    - See PHASE L1 section above for the full build record. Open question from §7 answered
      in code: proposals capture the firing context via excerpt + had_disagree flag.
    - Backend: migration + model + collect endpoint (admin+staff) + routes; core
      mineCorrectionLabel + export; wrapper snapshot/diff/harvest + fire-and-forget POST.
      Tests: 2 core mining fixtures + 4 PHP feature tests. JS 64/64; full PHP suite zero
      regressions (6 pre-existing failures reproduced on clean tree).
    - Committed + pushed as 69cf1a4 on phase-6-parser-learning per user (upstream tracking set).

  2026-09-04 — L2/L3 fully studied (read-only) + decisions logged, NO CODE per user.
    - User: placement BOTH (standalone menu + popup launcher); cache 24h; log + commit +
      push now, implementation later. Study via two explore agents: manage-modal clone map
      (shell/helpers/CRUD shapes/event wiring + shared toast/delete-confirm deps) and L3 seam
      (optional-param injection; greedy-join sites core :461/:508/:553 must also thread;
      no keyword channel exists today; no storage-TTL precedent — new wrapper needed).
    - Open questions §7 resolved: paraphrases GENERATED at L3; pattern-capture folded into
      excerpt + had_disagree (already shipped in L1).
    - Committing + pushing per user.

  2026-09-04 — Browser proof POSTPONED by user (nothing to correct).
    - All 5 test files now extract accurately — no competing values anywhere, so no
      correction exists to trigger a proposal. This is itself the success signal for the
      whole Steps 1–1i + N/S/P1/P2 arc: the loop idles precisely because the rules won.
    - L1 stands proven by unit + feature tests (mining, gates, promotion); the browser half
      (corrected box → proposed row) awaits the first genuinely new/weird file, which is
      exactly the file the loop was built for. No code change from this entry.
    - Amber-note coverage note (user Q, answered): the amber pick-one note covers the FOUR
      pooled fields (budget/duration/startDate/endDate) — trigger per field: top-2 pooled
      finds both labeled + different values + winner score < 2× runner-up. It appeared only
      on Contract Price in tests because only budget ever produced a close labeled
      disagreement; duration/dates always had single winners. Title/contractor/location/etc.
      have NO amber (red mis-upload banner covers cross-file disagreement by design).
    - L1 harvest coverage is WIDER than amber: ANY corrected box among all 11 preview fields
      (typed fix or amber pick) becomes a proposal — amber is merely one way a correction
      happens. Trigger detail: snapshot at preview render → diff at Apply → mine the final
      value's source line for the words before it (≤80ch label, ±1-line excerpt) → POST.
      Values never leave the browser; had_disagree recorded as context.
    - Committing + pushing all per user.

  2026-09-04 — Style-capture finding + case-shape enrichment PLANNED (no code).
    - User Q: does the loop capture bold/ALL-CAPS/underline? Verified in core: ALL-CAPS is
      PRESERVED (label/excerpt keep original casing); bold/underline are NEVER captured —
      the pipeline is characters-only (pdf.js font info discarded pre-parse; OCR text has no
      style). So no proposal can ever carry bold/underline signal; case shape it can.
    - Companion finding (user's non-bold ALL-CAPS price-words file): SAFE, no fix needed.
      The "strict ALL-CAPS" word-fallback rule (core :723) tests CASE only
      (pesosLine === pesosLine.toUpperCase()); "bold" lives in the comment alone because both
      original test files happened to be bold. ALL-CAPS non-bold passes identically.
    - Enrichment (user approved, NOT STARTED): case_shape tag per proposal (UPPER / Title Case /
      lower / mixed), computed at harvest from the label text — scan-safe (characters only),
      shown as a review-table column, consumable by L3 same-shape preference later. Bold/
      underline detection explicitly REJECTED (text-PDF-only via font names, never on scans —
      half a signal is worse than none).
    - Awaiting user scan test (5-page cropped photocopy PDF in progress): if its price fills,
      the finding is browser-proven; any miss becomes L1's first real proposal.

  2026-09-04 — First REAL L1 firing (CAN Food Lab cropped photocopy, sampledocs/pdfdocstest3.pdf).
    - User test (5 of N images): title found via value keywords (CANTEEN AT lost to crop/OCR,
      joiner can't join absent words); location truncated by crop (parser read it exactly);
      price picked ABC 4,834,297.59 from cropped "…budget Amount Php …" while true Original
      4,204,128.84 + Revised 4,323,196.77 (page 1) show ZERO pool finds — absent, not outvoted;
      contractor missed (no label survived + OCR "CONTRUCTION" defeats exact suffix match);
      duration/dates empty (summary pages unprocessed). Runner-up 23.00 harmless (0.43).
    - Environment fact (verified read-only via node pdfjs): pdfdocstest3.pdf = 6 pages, ZERO
      text items — pure image, everything via OCR. Assistant CANNOT re-run OCR here (needs
      browser canvas); pixels/OCR stay user-side, structure/text-layer inspectable any time.
    - L1 fired end-to-end: 5 proposals, desk renders. 4× "(field count only)" = correct
      (unanchorable values counted, not labeled). Row 4 label ")F CANTEEN AT Public" =
      junk-adjacent title fragment → LABEL-QUALITY GATE planned below.
    - Title/location have NO vote tables BY DESIGN (pooled = budget/duration/dates only;
      title = greedy join, location = single label, contractor = label/suffix; red banner
      covers cross-file). No extension planned — no proven gain.
    - B1 revised per user hesitation → CONDITIONAL (see follow-up B1 below).

  FOLLOW-UPS (approved, no code yet).
    - F-COPY. Copy-extracted-text debug button in preview (stashed fullText → clipboard):
      lets the user paste exactly what the system read (e.g., the page-1 Original/Revised
      lines) so misses diagnose to the character without screenshots. ~20 lines, no Blade
      change (dynamic DOM like the banners).
    - F-GATE. Label-quality gate in miner: accept candidate only with label-ish words
      (amount/budget/price/cost/total/contract/…) or :/- terminator, else null. Existing
      junk row stays (user rejects it in review — proves the desk works).
    - F-B1. CONDITIONAL budget-amount→ABC reroute: only when an Original/Revised-labeled
      figure ALSO exists in the same file (corroborated reassignment, this file's shape);
      lone budget-Amount figures keep today's price behavior (boxes never emptied on files
      that mean price by it).
    - F-TEST. Re-test verdict on fixed-crop FULL pdfdocstest3.pdf: Original/Revised in pool,
      ABC in ABC, contractor hit (needs B2 fuzzy suffix — still queued, NOT in this batch).

  ORIENTATION BATCH (approved 2026-09-04, no code yet — user: tilted pages differ per page).
    - Context: orientation detection exists NOWHERE in code (verified: no osd/orientation/
      deskew/rotate hits in public/js; ocrPage uses tesseract 'eng' only). The eng+osd design
      lives only in the deferred S-curve Tier 3 plan. User reports some bundle pages tilted
      differently from others — matches cropped-photocopy handling. Tier-2 note: the fix lands
      in the SHARED ocrPage helper every OCR path funnels through, so deferred S-curve Tier 2
      inherits it free when built (nothing taken from Tier 2, nothing owed to it).
    - R1. Auto orientation (eng+osd): photo-reader call gains orientation detection + small
      OSD data file beside the cached reader; tilted/sideways/upside-down pages straighten
      silently pre-read. Same offline-after-first-load; ~1–2s extra first load only.
    - R2. Manual rotate, PER-PAGE (user confirmed — bundles mix tilted + straight pages):
      per-page rotate control (90° steps) re-rendering + re-reading that page only. Honestly
      the costliest item (render + OCR plumbing per page, not just spinning pixels); the only
      rescue when auto misdetects.
    - B2 left OUT (user to call separately): fuzzy contractor-suffix for OCR typos.
    - Tests: (i) tilted-figure fixture reads post-orientation; (ii) manual rotate re-reads
      exactly one page; (iii) straight pages byte-identical (auto is a no-op upright);
      (iv) full suite green + bundle re-check.
  NEXT BUILD BATCH (all approved, no code): R1 + R2 + F-COPY + F-GATE + F-B1, then F-TEST.

  2026-09-04 — BATCH IMPLEMENTED (code + tests, NOT committed per user).
    - R1: ocrPage recognize 'eng' → 'eng+osd' (orientation auto-straighten pre-read) with
      plain-'eng' retry on OSD load failure; orientation logged when _parserDebug is on.
      Shared helper → deferred S-curve Tier 2 inherits free. No test possible headless
      (needs canvas+tesseract); browser proof covers it.
    - R2: per-page rotation map (window._ocrRotations file#page → 0/90/180/270) applied at
      render (getViewport rotation); extractTextFromPDF records ocrPages[]; gray note renders
      one ⟳-button per photo-read page (index-indirected, live degrees in label, max 12 shown);
      window._rotateOcrPage cycles +90 and full re-runs; cancel path resets map + targets.
    - F-COPY: window._copyExtractedText (stashed texts+names → clipboard, textarea fallback,
      toast confirm) + "Copy read text" button node in preview (S1 rebuild pattern); cancel
      path clears all L1/copy stashes (docTexts/docNames/original).
    - F-GATE: miner accepts label only with label-ish words (incl. bare ABC — added after my
      own test caught the gate rejecting it) or :/- terminator on the raw slice; else null.
    - F-B1: extractABC gains budget-Amount alternative gated on same-file Original/Revised;
      extractBudget generic drops bare-'budget' ONLY under the same condition (lone lines keep
      today's price); collector mirrors both (generic skip + total-row unaffected since its
      label carries the contract word); asBudget() single-match path already date-guarded.
    - Tests (+2 fixtures, core 46/46): junk-fragment→null + genuine labels (incl. ABC) pass;
      conditional split (corroborated → price=orig/abc=budget-figure; lone → price fills,
      abc null; pooled generic skipped, orig kept). Full JS 66/66 (core 46 + scurve 20).
      R1/R2/F-COPY are DOM/canvas paths — browser proof covers them. No PHP touched. No commit.
    - Next: user browser proof — (a) tilted pages read upright via auto (R1) or one ⟳ click
      (R2); (b) copy button pastes full read text; (c) CAN file: ABC box fills 4,834,297.59,
      price fills Original/Revised once its pages are processed.

  2026-09-04 — OSD-STABILITY batch IMPLEMENTED (code, NOT committed per user).
    - Trigger: user's fixed-crop Buhi re-test showed a red "LSTM requested, but not present"
      storm (new with R1's 'eng+osd'; never seen under plain 'eng') + an empty "no fields"
      first run followed by a full preview — same root cause (orientation-data load failure).
      F-COPY simultaneously proven (pasted dump carried final-amount/duration/title lines).
    - Fix: probe-once OSD (window._ocrLangMode null→'osd'→'eng'; first page tries osd, ANY
      failure pins plain English for the session — one red line max, later pages full speed);
      warmTesseract() script preload called from resetModalToCreateMode (language data still
      lazy on first recognize); distinct engine-failure warning when the reader itself never
      loads (no longer mislabeled "no fields found"); probe/flag reset per modal session in
      cancel path (re-probes when network may have changed).
    - Tests: node --check on both touched files + JS 66/66 suite green (core untouched by this
      batch). OCR paths are canvas/network timing — browser proof covers them (tilted page
      upright without red storm; offline-first-run shows the honest engine message). No PHP
      touched. No commit.
    - Next: user browser proof on tilted/retry runs, then order a commit or the next batch.

    2026-09-04 — OSD CORRECTION (user) + revised plan (no code yet).    - Correction accepted: the Buhi fills that "proved" breakage came from the ORIGINAL
      scanned image WITH the storm — not the text recreation. So the orientation attempt
      fails loudly but the plain-English retry DOES fill upright pages. The "broken all
      along" verdict is withdrawn; the implemented throw-only probe is INSUFFICIENT (not wrong).
    - Corrected model: orientation fails loudly every run; sometimes by throwing (retry fills
      upright pages — the Buhi run), sometimes by resolving empty with no throw (no retry runs —
      plus tilted content defeats even plain retry: the pdfdocstest4 run). Empty-result pinning
      is therefore REQUIRED, not optional.
    - Revised plan: (a) pin on EMPTY results too (first decisive outcome — throw or empty —
      picks the session mode); (b) warm preload + honest engine message stand as built;
      (c) R2 manual per-page rotate PROMOTED from backstop to primary tilt remedy (automatic
      orientation effectively dead in this CDN build — never succeeds, only fails loud/silent);
      (d) pdfdocstest4 remains unjudged until retried post-fix, ideally with one manual
      straightening click on visibly tilted pages.

  2026-09-04 — EMPTY-AWARE PINNING IMPLEMENTED (code, NOT committed per user).
    - ocrPage: osd-branch success now requires ≥10 chars of text; empty resolves count as
      failure with immediate plain-English retry for that page. Session pinning needs TWO
      CONSECUTIVE empties (window._ocrOsdMisses) — a single blank page must not rob later
      tilted pages (a blank genuinely yields nothing under either mode). Throw path unchanged
      (immediate pin + retry). _ocrOsdMisses resets per modal session in the cancel path.
    - Caught during build (self-review): my first draft pinned on ANY single empty, which
      would strand later tilted pages after one blank page — fixed before testing.
    - Tests: node --check clean + JS 66/66 green (core untouched; OCR paths are canvas/network
      timing — browser proof covers them). No PHP touched. No commit.
    - Next: user retries pdfdocstest4 — expect at most ~2 red lines then silence, boxes filled
      from plain retry on upright pages; tilted pages via one ⟳ click each.

  2026-09-04 — COPY-ON-EMPTY IMPLEMENTED (code, NOT committed per user).
    - Trigger: user's pdfdocstest4 empty run left nothing to diagnose (no preview → no stash
      button path); verified the file is 10 pages / zero text items (pure image, node pdfjs
      read-only inspection), so the honest-empty verdict stands pending content evidence.
    - Fix: doc text/name stash rebuilt for EVERY processed file (even empty/unparsed — moved
      out of the non-empty guard); showAutofillWarning(message, opts) gains an opt-out
      "Copy what was read" button shown whenever any file yielded text (engine-failure runs
      correctly show none — nothing was read). Both empty branches inherit it by default.
    - Tests: node --check clean + JS 66/66 green (wrapper-only change; DOM-timing path needs
      browser). No PHP touched. No commit.
    - Next: user retests pdfdocstest4 → pastes copy-text (or 1–2 page screenshots) → fork:
      fragments-everywhere = correct behavior stands (cropping-side fix); intact missed
      phrases = real gap with evidence → next build with failing-then-passing check.

  2026-09-04 — TEST5 VERDICT + AUTO-ROTATE/SCAN/ABC DECISIONS (plan only, no code yet).
    - Test5 (same 10 images, portrait assembly) vs test4 (landscape-baked 1008×612, verified
      via node pdfjs): file itself was the problem — sideways pixels + dead deskewer + preview-
      locked manual control = no path at all. Test5 extracts: price = Revised wins (6 finds /
      score 5 vs Original 3/2 + stray 4204128.00), dates accurate across 3 extensions (end =
      March 13 completion, not expiry), duration amber correctly offers 185 vs 205.
    - Q1 auto-rotate status (user: "I thought it was implemented"): YES built (R1 eng+osd +
      probe/pin + warm preload), but EFFECTIVELY DEAD in this CDN build — orientation never
      succeeds, only fails loud (throw → retry fills upright, the Buhi run) or silent (empty
      resolve → nothing fills, the test4 run). Manual R2 rotate is therefore THE working tilt
      remedy, not the backstop. No code change from this verdict; R-reach plan (pre-preview
      straighten-all + landscape heuristic) stands.
    - Q2 early-stop (user: default to full 10 regardless): AGREED — remove the countFilled≥11
      early break so every file scans all pages up to its per-file budget. Rationale banked:
      early exit can false-complete on WEAK finds (ghost price fills 11 boxes → truth pages
      never read); fuller piles vote better; text pages cost ~nothing, scanned pages already
      capped at 10/file. Cost accepted: slower worst case (~20s per 10-page scan), mitigated
      by the per-file budget + selector. Becomes default; no opt-out needed (budget IS the cap).
    - Q3 ABC missing on test5 (user: stopped-before-page vs label-miss?): BOTH as hypotheses —
      (a) early-exit stopped at page 3 before ABC pages (fixed by the full-scan default above);
      (b) ABC label coverage: current ABC labels are "ABC:" / "Approved Budget for the Contract
      (ABC)" / F-B1 budget-Amount (conditional). Test5's copy-text will show the exact ABC line
      shape → if unmatched, add its label with a fixture (failing-then-passing). No code until
      that evidence lands.
    - NEXT BUILD BATCH (all approved, no code): R-reach (pre-preview straighten-all + landscape
      heuristic) + D-total (Total Contract Time outranks Original; amber otherwise unchanged) +
      N-note (complete-vs-cut wording) + full-scan default (remove early break) + ABC label only
      IF copy-text evidence requires it. Then F-TEST on fixed crops.

  2026-09-04 — BATCH BUILT (R-reach + D-total + N-note + full-scan; ABC label still evidence-gated).
  Code, NOT committed per user.
    - Full-scan default: countFilled≥11 early break DELETED from extractTextFromPDF — every file
      now reads all pages up to its per-file budget. Rationale (banked): false-complete risk.
    - D-total: extractDuration tries /(?:total\s*contract\s*time)/ FIRST (binding duration wins;
      Original fallback unchanged); pooled collector tags pattern 'total-contract-time' with ×1.25
      weight. Amber behavior preserved by design: 1.25-vs-1.0 still trips the <2× disagree rule,
      so the box fills Total while the note keeps offering Original.
    - N-note: truncated files keep "— later pages were not scanned."; finished files now read
      "— all pages read, scan complete." (was a bare period — previously indistinguishable).
    - R-reach: extractTextFromPDF records landscapeCount/scannedPageNums/allLandscape per file;
      scanReports carry them; BOTH empty-warning branches stash reports + pass {straighten:true};
      showAutofillWarning renders "Straighten sideways pages & re-read (files)" for all-landscape
      scanned files; window._straightenAllAndRescan sets 90° on those pages and full re-runs
      (no cancel → rotations preserved). Preview-locked rescue is gone as a bug class.
    - Tests (+2 D-total fixtures, core 50/50): Total-beats-Original single-match + Original-only
      unchanged + pooled pattern/weight/winner. Full JS 70/70 (core 50 + scurve 20). R-reach/N-note
      are DOM-timing — browser proof covers them. No PHP touched. No commit.
    - Next: user browser proof — (a) test4 straighten-all path (empty warning → button → fields);
      (b) duration fills 205 with amber offering 185; (c) finished files read "all pages read".

  2026-09-04 — SCAN-COUNT SEMANTICS CLARIFIED + C1+C2 LOGGED (no code yet).
    - User Q ("still exits early?"): NO — full-scan removal verified live in working tree AND
      proven by the counters: scannedTotal counts textless pages VISITED (reads 10 on test5;
      old early-break code could never exceed ~3 there). scannedRead counts pages yielding 10+
      usable chars (3 on test5 — 7 fragment pages OCR to sub-threshold soup). Different counters,
      different meanings; the note mashed them together.
    - C1. Per-page shape ledger: record landscape bool per scanned page (not file-level only)
      through extract→reports→rescan; straighten-all turns ONLY sideways pages (mixed bundles
      stop getting vandalized; uniform test4 behaves identically). Fixes the over-rotation flaw
      the user caught: current code rotates ALL scanned pages of flagged files.
    - C2. Three-state note: "visited all N pages; M yielded text" (complete) vs "stopped at the
      page budget — later pages unscanned" (cut). Never one sentence meaning both. Selector +
      counts plumbing already exists; wording + ledger only.
    - Per-page ⟳ buttons: CONFIRMED correct as-is (offered for every photo-read page; the user
      — not the system — is the tilt detector). No change.
    - ABC: still no new evidence either way; tiebreaker remains the user's copy-text.

  2026-09-04 — C1+C2 IMPLEMENTED (code, NOT committed per user).
    - C1: landscapePages[] ledger per file (extract→reports→rescan); straighten-all turns ONLY
      ledgered sideways pages, with graceful fallback to scannedPageNums/ocrPages for old
      stashes predating the ledger. Uniform test4 behaves identically; mixed bundles no longer
      vandalized. Cancel path unchanged (rotations already reset per session).
    - C2: note renders "visited all N pages; M of K scanned pages yielded text (…per file
      selected) — scan complete." vs "visited N of T pages; … — later pages were not scanned."
      using the carried pagesVisited/numPages (no new plumbing — fields already flowed).
    - Tests: node --check clean + JS 70/70 green (no core changes this round; C-paths are
      DOM-timing — browser proof covers them). No PHP touched. No commit.
    - Next: user browser proof — (a) mixed-bundle straighten-all leaves straight pages alone
      (degrees labels); (b) both note states render with real numbers.

  2026-09-04 — P-ROT + F1/F2 LOGGED + ABC REACHABILITY VERDICT (no code yet).
    - Auto-vs-rotation (user Q, answered): auto orientation = reader DETECTS the angle (dead in
      this CDN build — fails loud/silent, pinned to plain); rotation buttons = user DECLARES the
      angle per page (deterministic, always works). Same photo-reader underneath, opposite trust
      models; buttons never needed auto to function.
    - P-ROT (approved pattern): ⟳ buttons for ALL scanned pages (scannedPageNums ledger), failed
      pages badged "no text yet — straighten?". Fixes the flaw the user caught: buttons currently
      list only photo-read successes, so tilted-but-unread pages (the ones needing it most) are
      unactionable — and the 2-vs-3 button flicker is marginal pages crossing the 10-char bar
      between runs (photo-reading is slightly non-deterministic; NOT a regression).
    - F1/F2 (still unbuilt): note-suffix de-duplication ("scan complete. — all pages read, scan
      complete." stutter) + one-line ⟳ legend in the gray note.
    - ABC verdict (user's 6 page photos as evidence): read pages 4/6/10 (scope list, statement,
      accomplishment table) contain NO ABC figure; ABC lives on unread pages (abstract "Approved
      Budget for the Contract: P …" — already labeled; funds cert "approved budget for the
      contract of … PESOS" — already matched; cost estimate "TOTAL ESTIMATED PROJECT COST" —
      banked for loop-first). Miss = reachability, proven by inventories both sides. NO label
      changes. Small-skew pages (abstract ~2-3°) stay within reader tolerance; arbitrary-angle
      rotation DEFERRED as over-engineering unless a skewed page returns soup post-straighten.
    - Thresholds FROZEN (re-affirmed): no loosening for fragments — the 23.00 lesson stands
      with test4's soup as second witness.

  2026-09-04 — F1b+D1+T2 LOGGED (mechanisms verified read-only, no code yet).
    - Test4 straighten-all PROVEN in production (soup → full statement page: 19 items, both
      amounts, contractor, word-amount clause). R-reach closed as a bug class.
    - F1b (was F1): note stutter root-caused — wrapper line 931 builds the sentence WITH its
      ending, line 949 appends the ending AGAIN (both states affected). Fix = delete the
      line-949 duplicate append (one line).
    - D1: test5 end-date miss MECHANISM FOUND — file writes "Date of Completion March 13. 2026"
      (period = OCR-misread comma); wordy-date regex (core :98) accepts `,?` only → null. Start
      survived via the comma-style duplicate elsewhere. Fix = accept `[,.]?` (one char-class);
      comma behavior byte-identical; "July 14. 2025"-style variants come free.
    - T2: two-file empty is genuinely anomalous (pooled voting only ADDS finds; banner track can't
      blank filled boxes). Suspects ranked: (a) stale code — check the `v=` build number after
      Ctrl+F5; (b) superseded run winning the race — S2 guard LOGS when it blocks, so its
      absence/presence decides; (c) worker-level failure (least likely — engine failure has its
      own message, not seen). Needs the `[Parser] preview run N: files=[...]` line + refresh
      status. NO code until evidence names it.

  2026-09-04 — F1b+D1 IMPLEMENTED (code + tests, NOT committed per user).
    - F1b: line-949 duplicate suffix append deleted; base strings (lines 931-932) keep their single
      endings. Both note states now render exactly once — verified by reading, browser to confirm.
    - D1: wordy-date `,?` → `[,.]?` (core :98) + comment noting the OCR-comma origin; comma-form
      behavior unchanged by construction (same class, comma still first alternative in effect).
    - Tests: +2 asserts inside the existing parseToInputDate fixture ('March 13. 2026' → 2026-03-13,
      'July 14. 2025' → 2025-07-14). Full JS 70/70 (core 50 + scurve 20). No PHP touched. No commit.
    - Next: user browser proof — single suffix per state; test5 end date fills 2026-03-13 with no
      manual fix. T2 still evidence-gated (no code).

  2026-09-04 — G-SOUP IMPLEMENTED (code + tests, NOT committed per user).    - Trigger: group-1 ladder only fired on SHORT text — test4's LONG garbage never retried.
    - Core scoreOcrSoup() + export: symRatio + weirdFrac, rule symRatio≥0.02 AND sum≥0.32.
      Calibrated live on pasted samples (SOUP 0.37 / TABLE 0.29 / CLEAN 0.00); single-signal
      rules rejected (symbols-alone hits currency lines, weird-alone hits legit tables).
      Noisy-but-legit rows (TABLE2 ≈0.58) knowingly trip the bar — the retry KEEPS THE BETTER
      text, so worst case is one wasted attempt, never a replacement.
    - Wrapper recognizeEnglish(): best-of ladder (default → 6 → 11) gated per attempt — later
      attempts run only while best is short or soupy; keep-cleaner (non-soupy wins, ties keep
      first, no churn); per-attempt debug line under _parserDebug; engine-failure marking only
      when best is empty AND something threw. Page budget still counts pages, not attempts.
    - Yield investigation (user: 3 pages → 1 after group 1): NOT explained by group 1 mechanics
      (retries only ADD attempts; budget counts pages) — prime suspects remain run-to-run OCR
      wobble (documented 2-vs-3 flicker) or a stale-code run. New per-page yield log
      ("OCR page N: yielded=M rot=D") under _parserDebug makes the next occurrence self-reporting:
      compare yielded counts across two runs to separate wobble from regression.
    - Tests (+1 G-soup fixture, core 51/51): soupy soup / clean-table-table / empty-trivial.
      Full JS 71/71 (core 51 + scurve 20). OCR retry paths are canvas/network timing — browser
      proof covers them (failing pages improve or hold; clean pages byte-identical path).
      No PHP touched. No commit.
    - Next: user browser proof with per-page yield lines on — test4-class pages improve or hold;
      any 3→1 repeat pastes its yield lines for verdict.

  2026-09-04 — A/B VERDICT: GROUP 1 REGRESSED TEST4 YIELD (keep-and-fix, no revert).
    - Method (user-approved, pre-written rules): working tree committed → temp worktree of f39e39a
      (verified pre-group-1: scale 2, no ladder/cleanup/soup) served on :8001 with junctioned vendor
      + shared session store (single-window sequential testing after the OAuth-pinning diagnosis).
    - Result: baseline (B) reads test4's statement page + contract clause (2 pages, near-test5
      extraction incl. start/end dates); current (A) reads contract clause only (1 page). Direction
      held across the runs taken (user: pre-group-1 "always" 2 pages; post-group-1 "always" 1) —
      accepted as consistent, matrix closed early per user.
    - User decision: NO revert — keep group-1 code, fix forward. Suspect ranking for the fix:
      (1) contrast stretch crushing faint pages that barely passed at scale 2 (prime suspect —
      global stretch re-maps washed text into clipping); (2) scale-3 canvas changing tesseract
      line segmentation on marginal pages; (3) PSM ladder last (adds attempts only; cannot remove
      a passing read — but CAN change WHICH text wins via keep-cleaner on mis-scored pages).
    - Fix direction banked: make cleanup CONDITIONAL (apply stretch only when the page looks
      washed-out/low-contrast by sample metrics; skip on already-clean pages), keep scale + ladder.
      Failing-then-passing proof: test4 statement page yields again with cleanup gated.
    - Temp worktree + :8001 server removed after verdict (zero residue).

  2026-09-04 — L1 FIRST REAL FIRING analyzed + M1-M3 logged (no code yet).
    - User test (test5, hard refresh): corrected ABC + picked duration 205 → DB rows id 8 (abc)
      + id 9 (duration), BOTH label_phrase NULL + excerpt NULL. Pipeline proven (harvest ran,
      fields right, statuses right); miner found no anchor in either case.
    - Diagnosis banked: (a) page-limit starvation — miner searches only read text; the run
      stopped at 3/10 pages, so values past page 3 are unfindable (second independent reason
      for the full-scan default); (b) first-match-only — miner returns the first anchored line,
      never surveys; (c) duration "205" refused BY DESIGN (numeric anchoring needs 4+ digits
      so "90" can't anchor inside "2024-12-09"); (d) ABC figure genuinely unanchored (digits
      absent in matching form — garbled line or past page 3; needs the typed digits to tell).
    - M1. Whole-token anchoring for short numerics (<4 digits): \b-bounded match only —
      "205" anchors "Total Contract Time 205" but can never hit inside "2025". Same safety,
      durations included.
    - M2. Multi-occurrence survey: gather ALL anchor lines, rank by label-ish neighborhood
      (serves the F-GATE quality rule directly), store best + count in excerpt.
    - M3. Falls out of the full-scan default already queued (miner inherits full text) —
      recorded as dependency, not separate work.
    - Tests: 205-anchors-labeled-line; 90-in-2024-12-09 still refuses; multi-occurrence prefers
      labeled line; suite green + browser proof on next real correction.

  2026-09-04 — M1+M2 IMPLEMENTED (code + tests, NOT committed per user).
    - M1: short digit strings (<4) anchor whole tokens only via (?<![\d,.])sig(?![\d,.]) —
      stronger than the planned \b (which still hits inside "1,205,000"). Verified by the new
      refusal fixtures (2025 / grouped-thousands / date-embedded).
    - M2: miner collects ALL hits [{label, excerpt, gated, i}] and returns best-gated first,
      document order breaking ties (stable sort + explicit index). F-GATE rule reused for
      ranking instead of duplicated. First-hit-wins is gone as a behavior.
    - Tests (+2 M fixtures, core 48/48): whole-token hits + 3 refusal shapes; survey prefers
      the labeled neighborhood over an earlier bare occurrence. Full JS 68/68
      (core 48 + scurve 20). No PHP touched. No commit.
    - Next: remaining batch (R-reach, D-total, N-note, full-scan, ABC label) + F-TEST on go-ahead.

  2026-09-04 — ABC-LABEL POSTPONED FOR LOOP-FIRST TEST (plan only, no code).
    - User decision: item 1 (hand-adding "TOTAL ESTIMATED PROJECT COST") waits — the next ABC
      correction should let the LOOP discover that exact phrasing as a mined label instead.
      Banked label text (must not be lost): "TOTAL ESTIMATED PROJECT COST" (+ optional
      "ESTIMATED … EXPENSES/COST" context). If the harvested proposal carries this phrasing,
      the loop independently rediscovers the hand-written rule — strongest possible L1→L2 proof.
    - Caveat banked: discovery ≠ auto-extraction until L3 is built; approval is the finish line
      for now. Title-page ABC shape (Photo 3) + bare-total prohibition + conditional F-B1 all stand.
    - Comma finding (verified live via node: 'I budget Amount Php 4,834,297.59' mined identically
      from typed '4834297.59' AND '4,834,297.59' → label 'I budget Amount Php'): separators are
      stripped pre-compare both sides, so missing commas NEVER block anchoring. The NULL/NULL
      rows are therefore page-limit/garbling, not formatting — full-scan default stays the remedy.

  2026-09-04 — OCR BATCH APPROVED (research done: web sources + full code audit, no code yet).
    - Audit finding (agents): pipeline runs tesseract STOCK — zero preprocessing (render→recognize
      untouched), default segmentation, English-only, fixed 2× render scale, no deskew, no snapshot
      export, S-curve path has no OCR at all. All additive from here — nothing working can regress.
    - User approved: group 1 (render-sharper 2→3 + PSM retry ladder + light canvas cleanup) first,
      then headline item 4 (OpenCV.js deskew + adaptive threshold + denoise), with item 5 (page
      snapshots so the assistant truly sees pixels) bundled alongside either.
    - Vendoring answer (user Q: re-download on deploy/copy?): NO — OpenCV.js ships INSIDE
      public/js/vendor/ like the PDF reader (committed to git, travels with the project directory).
      One ~8MB download PER BROWSER on first tilted-page use, then cached. Residential wifi is
      fine; campus deployment inherits it with zero extra steps. No server install, no PHP dep.
    - Tilt-ABC hypothesis (user: unread ABC pages 1/3/7 may be tilt victims): PLAUSIBLE and
      consistent (abstract + funds-cert photos both lean; statement/accomplishment pages that DID
      read are the upright ones). Decisive test stays the same: straighten-button re-read of those
      pages — fills ABC proves tilt, still-empty proves content/garbling. Workaround live today
      (per-page ⟳); systematic fix is item 4 above.
    - Sequence reaffirmed: OCR batch → L3 when the desk holds real rows → AI fallback last. L3 desk
      still empty; no fuel to consult however built. S-curve OCR stays out of scope (deferred tier).

  2026-09-04 — OCR GROUP 1 IMPLEMENTED (code, NOT committed per user).
    - Sharper render: ocrPage scale 2→3 (~300 DPI, tesseract's documented sweet spot; ~2.25× pixels
      per page — per-file page budget still caps total time). No other render change.
    - Canvas cleanup: new preprocessOcrCanvas() (grayscale via luminosity + contrast stretch to full
      range, sampled min/max, flat-image early-out, all best-effort in place) runs once per scanned
      page after render, before every recognize attempt. Plain 2D APIs — no new library.
    - PSM ladder: new recognizeEnglish() helper — default segmentation first, then a single retry as
      uniform block (PSM 6: tables/forms) and one as sparse text (PSM 11: fragments); first result
      with 10+ chars wins. Retries fire ONLY on failure (clean pages pay nothing); page budget counts
      pages, not attempts (worst case 3 reads per failing page — bounded). Wired into all three
      plain-eng call sites (OSD-empty retry, OSD-throw fallback, steady path); OSD branch itself
      unchanged. Engine-failure marking preserved (all-attempts-threw → flag).
    - Tests: node --check clean + JS 70/70 green (core untouched; OCR paths are canvas/network
      timing — browser proof covers them). PSM option shape ({tessedit_pageseg_mode}) follows the
      tesseract.js-recognize options pass-through; if the build rejects unknown options the helper's
      per-attempt try/catch degrades each retry to a logged skip — verify in browser console that
      retries actually attempt (no silent no-op). No PHP touched. No commit.
    - Next: user browser proof on faint/fragment pages (test4-class retest: more yielded text per
      page, same-or-better boxes); then item 4 (OpenCV.js) on go-ahead.

  2026-09-06 — INCIDENT: vendor/ wiped by worktree removal, recovered via reinstall.
    - Cause: temp A/B worktree linked its vendor/ to the main tree (junction, to avoid a slow
      re-download); `git worktree remove --force` followed the link and emptied the TARGET —
      main vendor/ reduced to an empty shell (autoload.php gone → `php artisan serve` fatal).
      Same failure class as the earlier sessions-dir wipe (placeholder restored then), worse:
      sessions were ephemeral, vendor/ is reinstallable-only. Rule hardened: NEVER link live
      framework directories into throwaway trees — copy (slow, safe) or fully isolate; any
      future A/B rig uses copies, and removal is verified with `git status` BEFORE reporting done.
    - Recovery (user-approved, build mode): `composer install --no-interaction --prefer-dist`
      from intact composer.lock → byte-identical dependency versions, no drift. Verified:
      vendor/autoload.php present, `php artisan --version` = Laravel 12.51.0, all 7
      parser-keyword routes registered, ParserKeywordProposalTest 8/8, JS 71/71.
      (`composer` found at C:\composer\composer.bat; an early direct call hung — transient,
      subsequent calls fine. First artisan boot after reinstall was slow — cache rebuild.)
    - No product code changed by this incident; working tree otherwise exactly as left.

  2026-09-07 — TEST4 TWO-RUN EVIDENCE: 1-PAGE SWAP, NOT 1-PAGE YIELD (no code).
    - Run 1 (all 0°): pages 1-7,9,10 yielded=0; page 8 yielded=2942 soup (3 PSM attempts
      byte-identical 2942 soupy=true — guard correctly kept first, no churn; identical counts
      suggest the CDN ignores tessedit_pageseg_mode, logged as suspect, not verdict).
      OSD pinning behaved as designed (2× LSTM red → plain-ENG session pin).
    - Run 2 (straighten-all +90°): one clean clause page reads (Rehabilitation/Refurbishment
      of Canteen, 185 days, P 4,204,128.84; pool budget count 2 labeledCount 0 = word-amount
      fallback; start/end null = correct, no explicit dates on that page). DIFFERENT page won
      than run 1 — rotation fixed one page, broke another; net still 1 page.
    - User screenshots prove the mechanism: p.1 ABC upright-on-landscape (must NOT rotate),
      p.2 statement sideways-on-landscape (must rotate once). Paper aspect (1008×612
      landscape-baked, MS Print-to-PDF; test5 is portrait via website tool) cannot separate
      them — straighten-all's allLandscape + landscapePages ledger turns BOTH. Paper-landscape
      ≠ content-upright; aspect trigger is the wrong signal for mixed-content files.

  2026-09-07 — OSD RESEARCH VERDICT: DEAD BY DESIGN IN THIS BUILD (no code).
    - Codebase audit (explore agent): ocrPage calls Tesseract.recognize(canvas,'eng+osd') with
      no worker/langPath/cached osd.traineddata (document-parser.js:451); data.orientation is
      debug-log-only (never routed to viewport.rotation); rotation comes solely from manual
      window._ocrRotations (default 0); vendor/ holds pdfjs-loader only, zero tesseract/OSD files.
    - Web research (tesseract.js v5): Legacy model removed by default for size (~54% EN smaller);
      OSD = Legacy-only. recognize() convenience creates a throwaway LSTM-only worker per call,
      so OSD can never succeed — loud throw (LSTM requested) or silent empty, then session pins
      plain eng. Fix requires createWorker('eng', 2, {legacyCore:true, legacyLang:true}) +
      worker.detect (or oem 2 + OSD PSM + osd:true + rotateAuto accounting), per naptha docs
      and issues #820/#859. iOS ≤17.1 Legacy-crash caveat noted (campus desktops unaffected).
    - User decisions (2026-09-07): auto-orientation = A+B COMBINED (legacy detect + try-angles
      scoring safety net); snapshots = ALL scanned pages (not failed-only — rotated-but-yielding
      soup must stay visible). No implementation yet — plan updated, then commit+push all.

  2026-09-07 — KEEP-CLEANER GUARD IMPLEMENTED (code + tests, committed 1cc779a).
    - Core: new pure shouldReplaceOcrBest(best, bestSoupy, challenger, challengerSoupy) +
      export — first fill always true; clean challenger dethrones soupy incumbent only
      at 10+ chars; soupy challengers never dethrone; clean-vs-clean keeps first.
    - Wrapper: recognizeEnglish() delegates to the helper with legacy-rule fallback
      (best-effort try/catch when core lacks it). Comment updated; no other OCR path touched.
    - Tests (+2 guard fixtures, core 53/53): long-soupy incumbent holds vs short-clean
      fragment; short-soupy upgrades to clean 10+ / first-fill / no-churn cases. Full JS
      73/73 (core 53 + scurve 20). node --check clean on both touched files. No PHP touched.

  2026-09-07 — SNAPSHOTS IMPLEMENTED (code, NOT committed per user).
    - Wrapper only (document-parser.js): window._autofillPdfFiles stash per run (original Files,
      cleared per session in cancelDocumentAutofill); window._saveOcrPageImage(ri, pn) helper —
      index-indirect lookup via _autofillScanReports, live _ocrRotations angle, getPdfJs re-render
      offscreen @1.5 scale → canvas.toBlob PNG download (snapshot-<file>-p<page>-rot<deg>.png),
      toast confirm, all failures swallowed.
    - Buttons: gray preview note per report (ALL scannedPageNums ledger, fallback ocrPages, max 12
      shown) + empty-warning path (all reports × all scanned pages; identities stashed for handler).
      Approved scope honored: every scanned page gets a button, not failed-only.
    - Tests: node --check clean on wrapper (+ core untouched); full JS 73/73 green (snapshot paths
      are canvas/DOM timing — browser proof covers them). No PHP touched. No commit.
    - Next: user browser proof — open test4 preview/empty note → save p.1 (expect upright ABC) +
      p.2 (expect sideways statement) → paste both images here; then O1/O2 on go-ahead.

  2026-09-07 — PIXEL VERDICT: ANGLE ACQUITTED, RENDER PATH CONVICTED (9 snapshots, no code).
    - User supplied 9 PNGs (sampledocs/snapshots/): test4 p1/p2/p8 @0°+@90°, test5 p4/p6/p10 @0°.
    - p1 @0° upright clean ABC table → 0 chars. p2 @90° upright crystal-clear statement (all 19
      items legible: 185 days, July 14 2025, both amounts, Total 205, March 13 2026) → 0 chars.
      p1 @90° sideways (straighten-all broke it — over-rotation harm confirmed on upright pages).
      p8 @90° upright dense clause → 2513 clean. test5 p6 @0° (same statement as test4 p2,
      portrait sheet) → 1625 clean first-try.
    - Same words, different outcome: test4-p2 @90° vs test5-p6 @0° differ only in sheet geometry
      + source resolution (test4 12MB phone photos ≈1.2MB/page; test5 1.4MB compressed).
      Fault is render/scale/preprocess, never rotation or parser logic (test5 proves all logic).
    - Suspects re-ranked: (1) oversized canvas — scale-3 on 12MB photos chokes tesseract to silent
      empty (pre-group1 scale-2 read this very page); (2) unconditional contrast stretch clipping
      faint cell text; (3) PSM-3 segmentation failing on sparse tables (dense p8 reads).
    - User decision: F1 render cap FIRST AND ALONE (isolate the canvas variable), then F2/F3.
      O1 legacy-detect DEMOTED (orientation was never the blocker — try-angles stays as cheap
      insurance only).

===============================================================================
  6B. NEXT BUILD QUEUE (ordered, approved 2026-09-06 — user changing session)
===============================================================================

  Order rationale (user Q: snapshots before or after the guard fix?): GUARD FIX FIRST.
  The guard fix restores known-good behavior, is tiny, and is fully provable headless
  (failing-then-passing fixture). Snapshots are diagnostic infrastructure: most valuable
  on a stable pipeline, and they touch the same gray-note UI the guard fix does not.
  Building diagnostics first would photograph the regression instead of fixing it.

   1. KEEP-CLEANER MINIMUM-LENGTH GUARD (the yield fix). ✅ IMPLEMENTED 2026-09-07
      (core shouldReplaceOcrBest + export; wrapper delegates with legacy fallback; +2 fixtures;
      full JS 73/73). Browser proof still open: test4 back to 2 yielding pages (now blocked by
      the paper-vs-content rotation issue below, not the guard — guard held correctly on run 1).
   2. PAGE-PIXEL SNAPSHOTS (diagnostic). SCOPE APPROVED 2026-09-07: ALL scanned pages
      (scannedPageNums, gray note + empty warning; downscaled ~1.5 offscreen re-render, PNG
      download, no server). Rationale: rotated-but-yielding soup pages must stay visible.
      Proof: one saved page opens readable in chat. ✅ IMPLEMENTED 2026-09-07 (wrapper only,
      browser proof open — see §6 snapshots entry). No commit per user.
   3. F1 RENDER CAP (approved 2026-09-07, NEXT BUILD, ALONE — no code yet).
      Cap OCR canvas longest side (~3000px, aspect preserved) before preprocessOcrCanvas +
      recognize in ocrPage(); log capped size under _parserDebug. Targets suspect #1 (12MB
      landscape photos → giant scale-3 canvases → silent empty). Failing-then-passing proof:
      test4 p1 @0° + p2 @90° yield (both 0 today with upright pixels), p8 holds. Alone first
      per user (isolate canvas variable); F2/F3 follow only if F1 leaves pages dark.
   4. F2 CONDITIONAL CLEANUP (no code yet). Apply stretch only when sampled page metrics look
      washed-out; skip on clean pages. Targets suspect #2. Proof: same pages yield-or-hold.
   5. F3 PSM-6-FIRST TRIAL FOR SPARSE TABLES (no code yet, last resort before OpenCV).
      Targets suspect #3. Proof: table pages yield.
   6. O2 TRY-ANGLES SCORING NET (cheap insurance, no code yet). Re-render 0°/90°/270° on
      failing/low-yield pages, score soup+chars+field-hits, best wins and is ledgered.
      O1 LEGACY-DETECT WORKER DEMOTED 2026-09-07 (pixels prove orientation was never the
      blocker on test4; revisit only if a future file shows genuine content-angle misses).
   7. THEN, in standing order: OpenCV.js deskew (item 4) -> L3 (needs live desk rows) -> AI
      fallback last. S-curve OCR stays out of scope (deferred tier).

  Resume state (2026-09-07, snapshots built, F1 queued alone):
    - Branch: phase-6-parser-learning. Snapshots code + this plan edit uncommitted.
    - JS suite 73/73 green (core 53 incl. 2 guard fixtures + scurve 20); node --check clean.
    - PHP ParserKeywordProposalTest 8/8 (unchanged); full PHP suite 6 pre-existing failures
      on clean tree (zero regressions from parser work).
    - vendor/ healthy (autoload OK, artisan 12.51.0) after 09-06 reinstall.
    - NEXT = build F1 render cap ALONE on go-ahead (F2/F3 gated on F1 outcome; O1 demoted).
      No merge without explicit user confirmation (Rule 12).

===============================================================================
  7. OPEN QUESTIONS
===============================================================================

  - Synthetic paraphrase fixtures: hand-write 3–5 now, or generate from the 5 known
    contracts at L3 kickoff? Recommendation: generate at L3 (needs the corpus anyway).
  - Should proposals also capture WHICH pattern fired (for the inspector)? RESOLVED 2026-09-04:
    folded into excerpt + had_disagree flag (shipped in L1) — no separate column.

===============================================================================
  8. CONVERSATION CONTINUATION GUIDE
===============================================================================

  For the next session:
    1. Read this file's sections 6B (build queue) and 6 (latest session entries) first —
       they supersede older "NEXT =" lines elsewhere in this file and the parent plan.
    2. Resume state: branch phase-6-parser-learning, guard + snapshots implemented
       (see §6 2026-09-07; pixel verdict acquits angle). JS 73/73 green; PHP proposal tests 8/8.
    3. Implement F1 render cap ALONE; F2/F3 gated on its outcome; O1 demoted, O2 insurance.
    4. Run `composer test` + `node --test tests/js/document-parser-core.test.mjs` before/after.
    5. No merge without explicit user confirmation (Rule 12).

===============================================================================
  END OF PLAN
===============================================================================
