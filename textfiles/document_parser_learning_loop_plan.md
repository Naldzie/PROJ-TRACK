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

  2026-09-07 — F1 RENDER CAP IMPLEMENTED (code, committed with this push).
    - Wrapper only (ocrPage): scale stays 3, but viewport longest side capped at ~3000px
      (aspect preserved, scale recomputed, debug-gated cap log). Small pages byte-identical
      path; giant 12MB-photo pages render capped. Best-effort try/catch (uncapped fallback).
    - Tests: node --check clean; full JS 73/73 green (no core changes; OCR render is
      canvas timing — browser proof covers it). No PHP touched.

  2026-09-07 — F1 BROWSER VERDICT: FALSIFIED, SUSPECT #1 DEAD (no code).
    - Cap log fired on all 10 pages both runs — but longest=3024 → scale=2.98, a 0.7% shrink.
      Canvases were never giant: 3024px @scale 3 = ordinary landscape Letter, normal for
      tesseract. The 12MB lives in JPEG stream quality, not rendered dimensions.
    - Yields identical modulo wobble (p8 soup 2917 vs 2942; p8 clean 2480 vs 2513; p1/p2 0 at
      both angles). Bonus: p6 @90° now shows chars=1 ×3 (a stamp speck segments, but no text
      lines — engine alive, segmentation starved). F1 isolation worked; it acquitted its suspect.
    - Remaining, in order: (1) unconditional contrast stretch [now prime]; (2) PSM-3 on sparse
      tables. Scale 3-vs-2 held constant throughout (test5 reads at scale 3 — scale acquitted
      as a single cause; kept as a later isolated experiment only if F2 fails).
    - User decisions 2026-09-07: F2 ALONE next; KEEP F1 in place (0.7% no-op, harmless safety
      rail for true giants — cannot confound F2). No implementation yet — plan + push only.

  2026-09-07 — F2 CONDITIONAL CLEANUP IMPLEMENTED (code, committed with this push).
    - Wrapper only (preprocessOcrCanvas): sampling loop now also accumulates mean; stretch
      applies ONLY when washed (lo>=100, no true blacks + range<=140 compressed), else skip
      (pre-group1 behavior on clean pages). Flat-image early-out (<8) kept. Per-decision
      debug log (applied/skipped + lo/hi/mean) under _parserDebug. Best-effort throughout.
    - Tests: node --check clean; full JS 73/73 green (no core changes; canvas timing needs
      browser). No PHP touched.

  2026-09-07 — F2 BROWSER VERDICT: PASS, PRE-GROUP1 YIELD RESTORED (no code).
    - Gate logs: EVERY page on both files Skipped (lo=0..2 everywhere — signatures/stamps give
      true blacks), so the stretch now survives only as a washed-page rescue, its proper role.
    - Test4 after straighten: p2 @90° 1643 clean (attempt 0, soupy=false, ladder idle) + p8 @90°
      2544 clean in ONE run; copy text carries full 19-item statement + clause. Preview fills
      price (2 competing values → amber Choice B as designed), start + end dates.
    - Causality is a true A/B on the stretch (same angle/scale): pre-F2 p2 @90° +stretch → 0;
      post-F2 p2 @90° −stretch → 1643 clean. Unconditional remap convicted (forced
      grayscale+remap on noisy phone-photo pixels, not the math endpoint).
    - Test5 unharmed: same 3 pages, same first-attempt clean reads (wobble-only diffs), all
      skipped — gate threshold (lo>=100, range<=140) vindicated, stands as-is.
    - Residue: p1 ABC page still 0 at both angles (upright, clean, stretch skipped) — stretch was
      never its problem; fits sparse-table PSM-3 failure. Queued F3. (Price/dates don't need p1;
      p1 matters for ABC auto-fill only.)

  2026-09-07 — F3′ BUILT (code, committed with this push; replaces literal F3).
    - Pre-build verification stopped literal F3: the ladder already runs PSM 6 on EVERY empty
      page (gate only breaks on usable best), and each recognize() is an independent throwaway
      worker — so a "PSM-6-first retry" would re-run an already-failed mode on identical pixels.
    - Built instead: PSM 4 (single column of variable sizes) appended as an empty-pages-only
      rung (control flag emptyOnly stripped before the tesseract call; any text — even soupy —
      skips it, so yielding pages pay nothing extra). Worst case now 4 reads per dead page.
    - Tests: node --check clean; full JS 73/73 green (no core changes; canvas timing needs
      browser). No PHP touched.

  2026-09-07 — F3′ BROWSER VERDICT: MODES ACQUITTED, P1 STILL DARK (no code).
    - p1 @0°: cap + cleanup-skip + 0, zero attempt lines → all 4 modes (default/6/11/4) empty.
      Attempt-3 gate verified live elsewhere: p6 @90° (chars=5 ×3, attempts 0–2) correctly
      SKIPPED attempt 3 (has text, below yield bar) — emptyOnly works on both branches.
    - Unchanged holdings: run-1 p2 @0° 1512 (OSD-branch) + p8 soup 2783; run-2 p2 @90° 1643 clean
      + p8 @90° 2544 clean (statement + clause, price/dates/amber-note all working).
    - Segmentation modes exhausted as a class on p1. Next: scale-2 fallback experiment (last
      pre-group1 delta), already queued.

  2026-09-07 — SCALE-2 FALLBACK BUILT (code, NOT committed).
    - New ocrPageScale2Fallback(page, tesseract, rotationDeg, markEngineFailed): re-renders the
      still-empty page at scale 2, bypasses cleanup, single default-PSM shot (verbatim pre-group1
      recipe). Wired into all three short-text return points (OSD-empty retry, OSD-throw retry,
      steady path; run-alive guarded); OSD-success (≥10 by construction) and engine-dead paths
      excluded. Keeps whichever yields (10-char rule); budget still counts pages (no double
      increment). Debug log per firing. Best-effort throughout.
    - Tests: node --check clean; full JS 73/73 green (no core changes; canvas timing needs
      browser). No PHP touched. No commit per standing instruction.
    - Next: user browser proof — (a) `OCR scale-2 fallback: chars=` lines on dead pages;
      (b) p1 @0° yields ABC table into pool = scale convicted → decide durable form; still 0 =
      render inputs acquitted → park p1 (manual ABC residual), close batch → L3.

  ARCHITECTURE NOTE (user Q 2026-09-07 — banked): text-layer PDFs and OCR PDFs share EVERYTHING
  downstream of text acquisition. pdf.js items (≥10 chars/page) vs ocrPage canvas→tesseract are
  just two front doors into one pipeline: parseProjectInfo + pooled vote + disagree/Choice-B +
  mismatch banner + copy-text + mining/harvest + L1 proposals serve both identically. OCR-only
  work (scale, F1 cap, wash-gate, ladder + PSM 4, soup/guard, OSD probe/pin, rotation, snapshots,
  truncation notes) never executes for text PDFs — and text-PDF accuracy never depended on it
  (73/73 + test5 proofs held through the whole arc).

  USER DIRECTIVE 2026-09-07 — ABC-OR-BUST: keep pushing until the ABC (4,834,297.59) extracts on
  test4 (p1) or test5 (its ABC page among currently-dark pages) before closing the OCR batch.
  Sequence reaffirmed: OCR batch (through scale-2 at least) → L3 → AI fallback last → S-curve
  Steps 2–4. Original 2-page issue stands fixed; p1 ABC is the last residue.

  2026-09-07 — ABC LABEL-GAP FINDINGS: 2 NEW PHRASINGS, DOUBLE MISS (screenshots, no code).
    - (A) Certificate of Availability of Funds (test5 p3 = test4 p9): "with an approved budget for
      the contract of FOUR MILLION EIGHT HUNDRED THIRTY FOUR THOUSAND TWO HUNDRED NINETY SEVEN
      PESOS & 59/100 (Php 4,834,297.59)". Misses TWICE today: page yields 0 (dark), AND even with
      perfect OCR the label fails — extractABC's pattern stops at "contract" then asMoney reads
      words → null (PESOS-words fallback exists only in extractBudget, never extractABC).
    - (B) Cost estimate bottom row (test4 p3 = test5 p7): "TOTAL ESTIMATED PROJECT COST P
      4,834,297.59" (+ "TOTAL OF II ... 4,834,297.59"). Page dark (both files) AND label absent
      from extractABC patterns. This is the banked loop-first rediscovery target — DO NOT hand-add;
      the loop must discover this exact phrasing from a real correction (strongest L1→L2 proof).
    - Consequence for ABC-or-bust: TWO layers per page — (1) OCR must yield (P-ROT/crop path),
      (2) extraction must cover words-shape ABC + loop-discovered TOTAL ESTIMATED label. Layer 2
      for (A): extend PESOS-words fallback to extractABC (rule fix, NOT loop — same wordsToNumber
      machinery as budget). Layer 2 for (B): loop-first only (no hand-code, reservation stands).
    - At least 3 ABC-bearing sheets per bundle now known: abstract/bidders table (test4 p7 class),
      funds certificate (A), cost estimate (B). All dark today; all upright human-legible pixels.

  2026-09-07 — P-ROT + AUTO-CROP APPROVED (research done: codebase map + web sources, no code yet).
    - User: build BOTH together; ABC-or-bust stays (loop-first label test needs a yielding ABC page
      first — proposals are born from corrections, and dark pages can't be corrected).
    - P-ROT completion + rotate-all variant (document-parser.js): per-page ⟳ feed widens from
      ocrPages-only (:1124) to scannedPageNums ?? ocrPages (snapshot pattern :1140); failed pages
      badged "no text yet". New window._rotateAllScanned = _straightenAllAndRescan shape minus the
      allLandscape gate (:910), targeting scannedPageNums per file. Buttons: gray note (beside
      per-page row) + empty warning (beside straighten slot :1637, whose allLandscape+read gate
      goes too — fully-unread files have no rescue today). No loop changes (:656 honors any key).
    - Auto-crop dark margins (no new dep): inside ocrPage between render (:513) and
      preprocessOcrCanvas (:517) — canvas already rotation-aware there. Border-luminance inward
      scan → largest near-white box → drawImage sub-rect back → fall through existing chain.
      Backed by tessdoc guidance (dark borders → "empty page"; crop + ~10px white border) and the
      ABC-sheet pixels (dark surround, holes, curl, banding). Best-effort + uncropped fallback +
      debug log. Scale-2 fallback canvas (:463) gets parity only if crop proves out.
    - Ranked alternatives (researched, deferred): perspective warp — OpenCV.js (~8–30MB, item 4)
      vs Scanic (~100KB Rust/WASM edge-detect + GPU warp) — only if crop leaves test5-p1's
      trapezoid unreadable; adaptive threshold for banding/curl (either engine); column-split +
      upscale explicitly REJECTED for this target (failure is zero text, normal-size type).
    - Proof: ABC 4,834,297.59 in pool on either file (any of the 3 sheets); p2/p8/test5 hold.
      No PHP touched. NEXT BUILD on go-ahead.

  2026-09-07 — P-ROT + AUTO-CROP BUILT (code, committed with this push).
    - P-ROT: new window._rotateAllScanned(ri) (per-file bulk +90°, no landscape gate; closes
      preview where present, works from empty warning too). Gray note: per-page feed widened to
      scannedPageNums ?? ocrPages with "*" + "(no text yet)" badges + per-file "⟳ all" button.
      Empty warning: per-page ⟳ for all scanned pages + per-file "⟳ all of <file>" (straighten-all
      kept as-is). User note honored: bulk rotates ALL pages of the file, not one.
      Browser proof: user CONFIRMED buttons present on unread pages (test5 run).
    - Auto-crop: new autocropOcrCanvas(canvas) between render and preprocess — border-luminance
      inward scan (paper at ≥50% light pixels ≥180, stride-capped sampling), light-only ≤10px
      margin back out, drawImage sub-rect in place. Skips when full-bleed (>95% kept),
      degenerate (<50% kept or <50px side), or tiny canvas. Debug log per decision.
      Scale-2 fallback canvas: parity deferred (only if crop proves out).
    - Miner answer banked (user Q): mineCorrectionLabel is source-agnostic — _autofillDocTexts is
      stashed for EVERY file per run; text-layer and OCR PDFs share the full downstream (vote,
      Choice-B, harvest, L1). Loop-first ABC test needs a yielding ABC page first (corrections
      are born from readable text); (A) words-shape → rule fix, (B) TOTAL ESTIMATED → loop only.
    - Tests: node --check clean; full JS 73/73 green (no core changes; canvas/DOM timing needs
      browser). No PHP touched.

  2026-09-07 — CROP-HARM VERDICT: PRE-STEP CROP CHOPPED P2, RESTRUCTURE APPROVED (no code yet).
    - Browser proof (test4 straighten run): statement page came back with left ~15 chars/line gone
      ("Ori"→"ginal", "185"→"18", amounts→bare "Ph", dates→"Ju/Ja/Fe/Me") vs 1643 clean pre-crop.
      Downstream: start/end null, duration "18" outscoring truth, budget runner-up "20.00"
      (duration line misread as money). The end-date failure is a crop failure, not a date failure.
    - Mechanism: ≥50%-light stop rule walks inward through the binder curl-shadow gradient
      (~50–70% dark) deep into text. Corroborated by aggressive crop logs (p7: y=606 h=1215 —
      half the page removed). Folds/dark sides CONFIRMED interacting (user Q) — with the nuance
      that uncropped tesseract reads through them (p2 1643 pre-crop): poison degrades, cure
      amputated.
    - Approved fix (alone): crop-as-fallback + strict-surround — uncropped ladder → scale-2 →
      THEN crop + one default shot (verbatim order: proven path first, experiments as rungs);
      cut rule tightened (strip must be ≥85% DARK to count as surround; gradients no longer
      qualify). Yielding pages return to byte-identical F2-verdict behavior; only dead pages pay
      extra reads.

  2026-09-07 — CROP-FALLBACK FIX BUILT (code, committed with this push).
    - Pre-step autocropOcrCanvas(canvas) call REMOVED (harm neutralized at the source; helper kept
      vendored). Cut rule tightened to strict-surround (walk while light < 0.15); helper now
      RETURNS the kept fraction (0 = skipped/failed) instead of void.
    - New ocrPageCropFallback(page, tesseract, rotationDeg, markEngineFailed, scale): re-renders at
      main scale, crops (skips the read entirely when crop skips — default already ran on identical
      pixels), then one default shot WITHOUT preprocess (isolates the geometry variable).
    - New tryDeadPageFallbacks(current) closure in ocrPage replaces the 3 scale-2 blocks: ladder
      result → scale-2 → crop, first ≥10 wins, later rungs never fire, run-alive guarded throughout.
      OSD-success (≥10) and engine-dead paths excluded. Budget still counts pages.
    - Tests: node --check clean; full JS 73/73 green (no core changes; canvas timing needs
      browser). No PHP touched.

  2026-09-07 — CROP-FALLBACK BROWSER VERDICT: FIX VERIFIED + FIRST RESCUE (no code).
    - Chop gone: zero crop lines on yielding pages; p2 @90° full 1643 clean, p8 2544 clean.
    - p10 crop fallback FIRED correctly once (x=335 left dark band — the poison profile) →
      3177 chars BOM content yielded (was dark through ladder + scale-2). Strict rule behaves:
      degenerate/full-bleed skips correct; p5 vertical crop still empty = geometry not its problem.
    - Minor note: p6 scale-2 (chars=8) shows NO crop line after it — helper catch path is silent.
      Speck page, correctly 0 either way; polish item = debug log inside the helper catch.
    - ABC still open (no ABC text in new yields; abstract/funds/estimate sheets dark).

  2026-09-07 — ROTATION MATRIX VERDICT, TEST4 FULL 360° (user browser runs, no code).
    - @0°: p2 1512 (OSD-branch) + p8 ~2800 soup, partial. @90°: p2 1643 clean + p8 2544 clean +
      p10 3177 via crop = ONLY working angle (full preview: price/dates/duration). @180°: all
      upside-down soup, zero fields (honest empty notice — correct behavior). @270°: MIRRORED
      content (letterwise backwards, e.g. funds-cert lines reversed) scored soupy=false.
    - FINDING (plan-logged): soup scorer is blind to mirroring — mirrored English has normal
      symbol stats, so "clean" ≠ "correct". No code action: the pooled vote backstopped it (zero
      label hits → no fields → honest notice). P-ROT bulk button earned its keep (4-angle matrix
      in clicks, no re-uploads). ABC sheets dark at every angle tried (0/90/180).
    - Pre-step autocropOcrCanvas(canvas) call REMOVED (harm neutralized at the source; helper kept
      vendored). Cut rule tightened to strict-surround (walk while light < 0.15); helper now
      RETURNS the kept fraction (0 = skipped/failed) instead of void.
    - New ocrPageCropFallback(page, tesseract, rotationDeg, markEngineFailed, scale): re-renders at
      main scale, crops (skips the read entirely when crop skips — default already ran on identical
      pixels), then one default shot WITHOUT preprocess (isolates the geometry variable).
    - New tryDeadPageFallbacks(current) closure in ocrPage replaces the 3 scale-2 blocks: ladder
      result → scale-2 → crop, first ≥10 wins, later rungs never fire, run-alive guarded throughout.
      OSD-success (≥10) and engine-dead paths excluded. Budget still counts pages.
    - Tests: node --check clean; full JS 73/73 green (no core changes; canvas timing needs
      browser). No PHP touched. No commit per standing instruction.
    - Next: user browser proof — (a) NO crop lines on yielding pages (pre-step gone); statement
      back to ~1643 clean, dates/duration/price restored; (b) dead pages show scale-2 then crop
      fallback lines; (c) ABC in pool = pass. Still dark → tile/strip stay cut; warp decision.
    - NOTED, NOT BUILT (user 2026-09-07 — focus is normal-doc → AI without worsening accuracy):
      tile/strip rung (same-resolution partition; fixes scrambled-not-empty tables, never our empty
      pages) and region-zoom rung (sub-rect upscale for tiny type; scale 2-vs-3 already acquitted DPI
      on our sheets) are CUT from the normal-doc queue. Tile/strip is FLAGGED FOR S-CURVE TABLE
      PDFs (user Q: yes — monthly-column/weekly-grid schedule tables are exactly the long-table
      segmentation case tiling serves; column-strip OCR joins the S-curve Tier-2 toolbox on resume).
      Zoom stays a one-line future note (legible-but-tiny type failing all whole-page scales).
      Both revivable in one build each; neither is owed anything by current evidence.
    - Engine verdicts banked (user Q 2026-09-07): STAY on Tesseract.js (free, in-browser, no doc
      leaves server; all failures to date pipeline-side, never engine-side). PaddleOCR/EasyOCR
      REJECTED (Python runtime the deploy stack lacks; browser ONNX 12–40MB for unproven gain —
      see PYTHON-RUNTIME NOTE below). Cloud (Google/AWS/Azure) REJECTED (paid + data leaves).
      OCRmyPDF noted as server-binaries-only alternative (same wall as poppler Tier-4).
      Perspective warp ordered Scanic (~100KB) before OpenCV.js (~8–30MB), only if trapezoid/
      banding persists after fixed crop.

  PYTHON-RUNTIME NOTE (user Q 2026-09-07 — banked, no action).
    - Possible? YES, technically. Dev: install Python 3.11+ + venv on the XAMPP Windows box,
      requirements.txt (paddleocr/easyocr pull torch/CUDA-optional wheels ≈ 0.5–2GB!), first-run
      model downloads (12–40MB+ per model, cached). App: new python/ scripts returning JSON over
      stdio, called from Laravel via Symfony Process or queued jobs (QUEUE_CONNECTION=database
      worker already required); timeouts/retries, concurrency caps (OCR is CPU-heavy, no GPU on
      VPS), temp-file hygiene, shell-escaping discipline.
    - Deployment meaning: shared hosting = BLOCKED (no Python, no binaries, no long processes).
      VPS/dedicated required: provision + pin Python version, system packages, model cache warming,
      process supervision, request timeouts (Paddle CPU ≈ seconds/page — preview UX becomes
      queued/async, not instant), security surface (shell-outs, uploads to disk), and a second
      dependency universe to maintain beside composer/npm. Roughly: a new service to own for a
      problem the browser path hasn't finished failing at yet.
    - Standing recommendation: exhaust browser path first (fixed crop → tiles → Scanic warp);
      revisit Python only if ABC-class pages survive ALL of it. See final answer for full detail.

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

  2026-09-07 — SELECTABLE-RECREATION VERDICT: RECREATION AT FAULT, NOT PARSER (no code).
    - User had another model recreate test5 as selectable-text pdfdocstest5_selectable.pdf: instant
      (no OCR) but garbage preview (title/location swapped, contractor "CONSTRUCTION", ABC "59.00",
      dates null, ghosts 12884.00/4204.12). Cause is the FILE, not the reader: broken word breaks
      (AbstractofBidsasRead, "4 834-297 69") + broken char mapping (July 14→"duly", 2026→2028) =
      defective spacing operators + bad font ToUnicode. Parser behaved correctly on corrupt input
      (duration 185 labeled-clean, Revised wins 6-find deep, ABC paren-php finds present).
    - Rule going forward (banked): Ctrl+A-paste validity check before any recreation test; regenerate
      via ReportLab (proven: contract_test_sample.pdf) / Office export / Chrome print-to-PDF.
    - LOOP-FIRST TEST SPEC (user-approved prongs): (a) paraphrase set — verbatim controls
      (Original Contract Amount, Date of Completion: must hit), paraphrased must-miss
      (Effectivity Date, Contract Sum), loop-first target verbatim (TOTAL ESTIMATED PROJECT COST);
      all values NUMERIC (words-shape is rule-A territory, not loop-testable). (b) L3 BEFORE loop
      testing: L1 collects + L2 displays, but nothing reads the table until L3 consults it — the
      end-to-end proof (second file fills free) requires L3 built first (synthetic fixtures per plan).
    - NEXT BUILD = L3 (seam already studied: optional-param injection, 24h loader cache, lowest
      priority). No code yet.

  2026-09-07 — CLEAN-TEXT PROOF: OCR WAS THE ENTIRE BOTTLENECK (user browser, no code).
    - Redone selectable pdfdocstest5_selectable_text.pdf (clean spacing + Unicode): instant, no OCR,
      budget 31 finds / duration 11, start 2025-07-14 (count 2, labeled 2) + end 2026-03-13 (count 2,
      labeled 2) both clean, Revised wins deep (12 finds/score 9.5), ABC 4834297.59 IN-POOL (count 2,
      labeled 3). Rules win outright on readable text — scan pixels were the whole war. Pivot to AI
      fallback for the unreadable remainder stands vindicated.
    - TEST GUIDANCE banked (user Q): temp-removing ABC keywords is a legitimate harness BUT
      novel-label file preferred (no tree risk, honest miss; removal blinds every file + restore risk
      — local-only, checklist-guarded, never committed if used). Either path still needs L3 first:
      mined proposals sit unread until the reader consults the table.
    - INSPECTOR SCOPE banked (user Q): pooled tables cover budget/duration/dates only; title
      (greedy join), location/contractor/category/source (single-label), description (prose) have no
      candidate lists — full pooling for all seven = second system, NOT approved. Scoped YES:
      promote ABC into the pooled track (collector + vote + debug + disagree, mirroring budget) —
      highest-value field under ABC-or-bust; rest keep per-file vote + red banner (already
      cross-file accountable). Console stays one extra 10-row table.
    - NEXT BUILD = L3 (decreed before ABC-pooling: L3's loader serves the same pooled machinery
      the ABC table will ride). No code yet.

  2026-09-07 — L3 BUILT (code, NOT committed).
    - Core (document-parser-core.js, pure intact): normalizeKeywordList + optional threading —
      extractTitle(lines, text, extra) with titleJoinHit OR-widening at all 3 greedy-join sites;
      isTitle/ContractorValueCandidate(line, extra) with learned fallback AFTER hardcoded
      (length bounds stay, heuristic shape gates skipped for learned hits — reviewed vocabulary
      outranks heuristics); parseProjectInfo(doc, opts) with order hardcoded-labels →
      learned-labels → hardcoded-value → learned-value per field. Learned-label pass is generic
      (all fields except prose description, each with its own normalizer); description skipped.
      Omitted opts = today's behavior exactly (all 53 pre-existing tests green untouched).
    - Label-side strictness (caught by new fixture, fixed before green): learned patterns anchored
      at line start with explicit separator-or-EOL — unanchored first draft matched mid-line and
      captured tails ("Apex Infrastructures Incorporated" → "Incorporated"). Mid-line labels
      knowingly unsupported (labels introduce lines; F-GATE shapes agree).
    - Wrapper (document-parser.js): loadLearnedKeywords() once per run (role-base like harvest,
      contractors skip; GET .../all?status=live; 24h sessionStorage TTL — TTL-only deviation noted:
      no updated_at-max endpoint exists, so staleness is time-bounded not version-keyed); wired
      into per-file parseProjectInfo({text},{learned}). No PHP touched (endpoint reused).
    - Tests (+5 L3 fixtures, synthetic phrases ONLY — never the real banked loop-first wording):
      learned-ABC miss→hit, hardcoded-wins-ties (learned line first), title learned-value,
      contractor learned-value, empty-table identity (deepEqual). Full JS 78/78
      (core 58 + scurve 20). Loader is fetch/DOM timing — browser proof covers it.
    - Next: user browser proof — (a) approve one real proposal in L2 desk → re-run file fills with
      zero corrections (loop closes); (b) empty desk = byte-identical behavior. Then ABC-pooling.

  2026-09-07 — L3 ROUND-TRIP, FIRST BLOOD + PATTERN BUG (user browser, no code).
    - Pipeline proven end to end: blinded miss → typed 4834297.59 → 1 proposed row (label "Approved
      Budget for the Contract: Php" + p1 excerpt) → approved → Live. Collection, review, going-live
      all work; L1/L2/L3 wiring (loader, endpoint, desk) correct.
    - BUG (mine, not user's): re-run still missed — learned builder appends "then a separator" but the
      mined label already swallowed separator + money marker, leaving a bare value no pattern accepts.
      Fixtures passed only because synthetic labels end cleanly. APPROVED FIX (user 2026-09-07):
      anchored phrase + lookahead (next char separator/space/digit/end — also blocks ABC-vs-ABCs
      prefix collisions) + optional separator + capture. Clean "Label: value" shapes unaffected.
    - MINER-CROWNING NOTE: M2 crowned the p1 abstract line (paraphrase of an existing hardcoded
      label), not the novel TOTAL ESTIMATED phrasing — this round proves machinery, not discovery.
      Genuine loop-first proof needs a file where the novel label is the sole anchor (estimate page
      alone), else the loop learns what the rules already knew post-blinding.
    - DESCRIPTION-NOISE ITEM (open): untouched description box harvested a null-label row — applied
      ≠ snapshot somewhere in render/Apply (likely long-text round-trip). Harmless (counts only)
      but harvest infidelity; next step is a before/after JSON diff on long boxes, not guessing.
    - MULTI-LABEL DESK (user Q, DECISION: YES with cap — design, not yet built): show every gated
      candidate per correction (top ~3 distinct labels, F-GATE filtered), not just M2's winner, so
      approvers can pick novel phrasings (TOTAL ESTIMATED would have surfaced as its own row).
      Touchpoints: expose M2's ranked survey list (already collected internally), harvest posts N
      rows (batch caps respected), reader ORs all live rows (already does — lists, not singletons).
      Junk risk carried by existing F-GATE + one-click reject. Scheduled after pattern fix.

  2026-09-08 — PATTERN FIX BUILT + BROWSER-PROVEN on merge15-baseline (committed; blinding KEPT ON for demo).
    - Fix (core learnedPatterns only): anchored phrase + lookahead (?=[:\-\d]|$|\s+\d) +
      optional separator + capture. Swallowed-marker labels ("...: Php" + " 4,834,297.59")
      now fill; bare space-then-letters still rejected so learned VALUE phrases (title
      "erection of", contractor suffixes) fall through with the full line intact (first
      draft was too loose and clipped them — caught by the title fixture, tightened pre-green).
      Prefix collisions (ABC vs ABCs) blocked; mid-line labels stay unsupported; clean
      "Label: value" + label-alone next-line behavior unchanged.
    - Tests: +1 swallowed-marker fixture (synthetic "Estimated Project Outlay: Php" wording —
      proves mechanism without leaking the real banked phrasing). Suite 77/79: the only 2
      failures are the intended blinding pair (F-B1 reroute + hardcoded-wins). PHP proposal
      tests 8/8. No PHP touched. Blinding flag untouched (still true).
    - User round-trip record (2026-09-07, confirmed 2026-09-08): typed 4834297.59 → label
      "Approved Budget for the Contract: Php" + p1 excerpt → approved Live → re-upload missed
      (the bug above). Next: user browser proof on test5_selectable_text (empty → teach →
      approve → re-upload fills). Blinding restore stays a tracked pre-merge-to-main step.
    - 2026-09-08 BROWSER PROOF (incognito, user): table wiped → re-upload test5_selectable_text
      → ABC EMPTY (blinding check passes; budget pool 31 finds, Revised 4323196.77 winner 12/9.5
      over Original 11/6.85 + 4834297.59 7/2.89; duration 185, dates clean) → typed ABC → 2 DB rows
      (id 19 abc + label/excerpt; id 18 description NULL/NULL = known DESCRIPTION-NOISE item, open,
      harmless count-only) → re-upload pre-approval still empty → APPROVED → re-upload FILLS ABC.
      LOOP PROVEN end-to-end (miss → teach once → live → free). Blinding KEPT ON per user for demo.

  2026-09-07 — L3-TEST SETUP: BLINDING + NO-EMPTY-CACHE (code, NOT committed).
    - Temp ABC blinding (core extractABC, L3_TEST_BLIND_ABC flag, RESTORE BEFORE ANY COMMIT):
      patterns=[] + F-B1 push gated off; original regexes preserved verbatim in dead branch.
      Suite impact is exactly 2 intended failures (F-B1 reroute + hardcoded-wins priority fixtures);
      all other 76 green, including L3 miss→hit (proves the blinded tree misses honestly).
    - Loader fix (document-parser.js): never cache an empty live-set — the first approval must be
      visible on the next run, not after the TTL. (Without this, the round-trip below would
      false-negative on a stale empty cache; sessionStorage also survives hard refresh.)
    - Round-trip script (browser, user runs): (1) hard refresh (new ?v= serves working tree);
      (2) upload clean selectable test5 → ABC box MUST be empty (blinding check); (3) type
      4834297.59 → Apply → (4) Learned Keywords desk shows 1 proposed row (label+excerpt mined
      from the best-anchored occurrence) → Approve → (5) re-upload same file → ABC fills, zero
      corrections = LOOP PROVEN. Restore blinding immediately after, suite must return 78/78.

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
   3. F1 RENDER CAP. ✅ IMPLEMENTED + BROWSER-TESTED 2026-09-07, VERDICT: FALSIFIED.
      Cap longest side ~3000px (aspect preserved, debug-gated log). Fired on all pages but
      3024→2.98 (0.7%) — canvases were never giant; yields identical modulo wobble.
      KEPT IN PLACE per user as a harmless safety rail (cannot confound F2). See §6 verdict.
   4. F2 CONDITIONAL CLEANUP. ✅ IMPLEMENTED + BROWSER-TESTED 2026-09-07, VERDICT: PASS.
      Wash-gate (lo>=100 + range<=140, else skip; flat <8 kept; per-decision debug log).
      All pages logged Skipped (true blacks everywhere) — stretch now a washed-page rescue only.
      Test4: p2 @90° 0 → 1643 clean + p8 @90° 2544 clean in ONE run (true stretch A/B, same
      angle/scale); preview fills price (amber Choice B as designed) + dates. Test5 unharmed
      (same 3 pages, wobble-only diffs) — threshold stands. Residue: p1 ABC still 0 (never a
      stretch problem) → F3. See §6 verdict.
   5. F3′ PSM-4 EMPTY-ONLY RUNG. ✅ BUILT + BROWSER-TESTED 2026-09-07, VERDICT: MODES
      ACQUITTED (p1 still 0; all 4 modes empty; emptyOnly gate verified both branches live).
      ABC-OR-BUST directive (user 2026-09-07): keep pushing until ABC 4,834,297.59 extracts on
      test4 p1 or test5's ABC page before closing the OCR batch. See §6 F3′ entries.
   6. SCALE-2 FALLBACK. ✅ BUILT + BROWSER-TESTED 2026-09-07, VERDICT: ALL-ZERO.
      Fired on every dead page (chars=0 everywhere; p6 5→8 sub-threshold, correctly discarded).
      Render inputs now FULLY acquitted (angle/size/stretch/4 modes/2 scales). See §6.
   7. P-ROT + AUTO-CROP. ✅ BUILT 2026-09-07; CROP VERDICT: HARMFUL AS PRE-STEP, FIX VERIFIED.
      P-ROT browser-proven by user (buttons present on unread pages; full 360° matrix run in
      clicks). Crop chopped yielding p2 (left ~15 chars/line) — ≥50%-light stop walks through
      curl-shadow gradients. FIX BUILT (this push): pre-step call removed, strict-surround
      (light < 0.15), kept-fraction return, ocrPageCropFallback + tryDeadPageFallbacks closure
      (ladder → scale-2 → crop). Browser proof: chop gone (p2/p8 full), p10 rescued 0 → 3177 via
      strict crop (x=335 left band), skips taxonomy correct (degenerate/full-bleed), p6 silent-catch
      noted as polish item. Tile/strip + region-zoom CUT from normal-doc queue 2026-09-07
      (mechanisms never matched empty-pages failure; logged §6, revivable in one build each) —
      tile/strip FLAGGED FOR S-CURVE TABLE PDFs (column-strip OCR for monthly/weekly grids).
      Engine verdicts: Tesseract stays; Python (Paddle/EasyOCR) + cloud REJECTED (see
      PYTHON-RUNTIME NOTE); Scanic before OpenCV, gated. ROTATION MATRIX 2026-09-07: @90° sole
      working angle (2–3 pages + fields); @180° upside-down soup; @270° MIRRORED content scored
      "clean" (scorer blind spot logged, vote backstopped it); ABC sheets dark at all angles.
      S-curve Steps 2–4 + Tier-2/3 MOVED BACK to backlog 2026-09-07 (finish normal-doc → AI first).
      See §6 entries.
   8. ABC LAYER-2 (with/after item 7 fix-build): (A) PESOS-words fallback extended to extractABC
      (rule fix, same wordsToNumber machinery — NOT loop); (B) TOTAL ESTIMATED PROJECT COST
      loop-first ONLY (no hand-code; reservation stands — the loop must discover it from a real
      correction). O2 try-angles stays cheap insurance; O1 demoted stands.
   9. L3 READER-CONSULTS-TABLE. ✅ BUILT 2026-09-07 (NOT committed; fixtures 78/78 green).
      Optional-param threading (join sites + candidate fns + per-field order), generic learned-label
      pass (all fields but description, own normalizers), loader once/run (role-base, contractors
      skip, 24h TTL, never caches empty). Line-start-anchored patterns (mid-line capture bug caught
      by fixture pre-green). ROUND-TRIP 2026-09-07: pipeline proven (blind miss → correct → propose
      → Live) BUT re-run still missed — pattern bug: mined label swallowed separator+marker, builder
      demanded a second separator. APPROVED FIX (user): anchored phrase + lookahead + optional
      separator + capture. MINER-CROWNING NOTE: M2 crowned p1 abstract (existing-rule paraphrase),
      not novel TOTAL ESTIMATED — genuine discovery needs sole-anchor file (estimate page alone).
      DESCRIPTION-NOISE ITEM (open): untouched long box harvested null-label row — needs before/
      after JSON diff, not guessing. MULTI-LABEL DESK (user Q, DECISION YES with cap ~3, F-GATE
      filtered): expose M2's ranked survey list as separate rows so approvers pick novel phrasings;
      reader ORs all live rows already. See §6 entries.
   10. ABC-POOLING (after pattern fix): promote ABC into pooled track (collector + vote + debug +
      disagree, mirroring budget). THEN, in standing order: ABC words-rule (A) alongside/after
      L3 -> AI fallback (trigger list finalized from all verdicts) -> S-curve Steps 2–4 +
      Tier-2/3 (backlog; inherits the hardened OCR path + tile/strip column technique free).

  2026-09-08 — ABC WORDS-RULE (A) + INSPECTOR POLISH BUILT on merge15-baseline (committed; blinding ON).
    - Rule A: shared readAbcWordsAmount/abcWordsAtWindow (single-match fallback + pooled
      'words-shape' candidate use ONE rule): strict ABC context (approved-budget/funds-
      availability) + ALL-CAPS amount run (MILLION/THOUSAND … PESOS) + & cents/100 on a 2-line
      window. Only the amount run must be caps (funds-cert lines are prose around it).
      Blinded-gated everywhere (collector sleeps wholesale; single-match words path gated too).
    - CAUGHT PRE-GREEN (2, both fixed before green): (1) wordsToNumber minted 400,000,834,297
      for "[X million] Y hundred…" compounds (million never flushed its group — empirically
      proven via scratch script; same latent flaw lived in budget's fourth fallback, never fired
      in production because numerics always won first). Fixed with thousand+million flush
      (>= 1000); all previously-correct outputs byte-identical (90/350/120/495049 re-verified).
      (2) First ALL-CAPS gate demanded the WHOLE line be caps — real funds-cert lines are prose
      with only the amount in caps. Gate now checks the amount run itself.
    - Polish: pooled inspector logs "no pooled finds — single-match/learned value kept" for
      empty tracks instead of "winner: null" (generic, permanent — no blinding mention).
    - Tests: +4 words fixtures (strict read incl. split lines, 4 guard rejections, blinded-sleep
      asserts marked for restore flip, e2e on real funds-cert wording). JS 87/89 — only the 2
      intended blinding fails. PHP proposal tests 10/10 (no PHP touched this round). No commit.

  2026-09-08 — MULTI-LABEL DESK + ABC-POOLING BUILT on merge15-baseline (code, NOT committed).
    - Part A (multi-label): core mineCorrectionLabels(docText, value, cap=3) — up to 3 distinct
      gated labels in M2 order, near-twin dedupe, single count-only row when nothing gated;
      mineCorrectionLabel kept as legacy winner-wrapper (6 old fixtures untouched). Wrapper
      harvestCorrections posts up to 3 rows per corrected box (33-row batch guard); backend
      collect max:11 → max:33 (11 fields × 3); desk Blade unchanged (renders generically).
    - Part B (ABC-pooling): hoisted L3_TEST_BLIND_ABC to shared core scope (same marker, single
      switch); new collectAbcCandidates (ABC labelRes + F-B1 conditional, labeled-only, full
      guard mirror: date-exclusion/placeholder/same-line/table-halving/weights/excerpts);
      pooled pile/_debug/_fileCounts/_disagree gain abc; wrapper override + inspector + amber
      note (abc: 'projectAbc') mirror budget. While blinded the collector sleeps ([]) so the
      demo misses honestly; learned single-match path carries approved rows (unit-proven).
    - Tests: +3 multi-label +3 ABC-pooling fixtures. JS 83/85 — only the 2 intended blinding
      fails. PHP proposal tests 10/10 (+2 cap tests). Full PHP suite: 1011 passed / 7 failed,
      ALL 7 reproduced on the clean tree with changes stashed (2 ContractorDashboard + 1
      OverdueInspection badge + 2 EmailDraft + 2 baseline) → zero regressions from this build.
    - Next: user browser proof (a) ≤3 rows per correction, novel phrasing pickable; (b) abc vote
      table in console; (c) demo intact (blinding ON). No commit without user OK.
    - 2026-09-08 BROWSER PROOF (incognito, user — ALL THREE PASS):
      (a) Multi-label proven: ABC correction → 2 proposed rows, duration correction (via amber
      pick) → 2 proposed rows. Counts are per DISTINCT label (deduped) — 2 rows because 2 unique
      wordings existed, not because of a cap. Amber-pick corrections harvest as designed
      (corrected implies resolved).
      (b) abc vote table present in console AND honestly empty (winner null, finds 0 — collector
      asleep while blinded); budget/duration/dates tables unchanged. Preview ABC box STILL FILLED
      4834297.59 post-approval via the learned single-match path — behavior correct, log line
      confusing (prints the pool winner). POLISH (next build): skip or tag the asleep abc line
      (e.g. "asleep (blinded)") instead of "winner: null".
      (c) Demo intact: table wiped → ABC empty pre-approval → fills post-approval (blinding ON).
      LOOP-FIRST REDISCOVERY (organic): the loop mined "TOTAL ESTIMATED PROJECT COST"
      (+ estimate-page excerpt) as its own ABC row — the exact banked novel phrasing, discovered
      from a real correction with zero hand-coding. Winner-only harvest would have buried it under
      the abstract line; multi-label surfaced it. Blinding KEPT ON per user for demo.

  2026-09-08 — MULTI-LABEL DESK + ABC-POOLING COMBINED BUILD PLAN (plan only, no code yet).
    User: build step 1 (multi-label desk) WITH step 2 (ABC-pooling) in one implementation.
    Blinding STAYS ON through this build (demo-safe by construction — see gates below).

    PART A — MULTI-LABEL DESK (cap 3, F-GATE filtered; approved design §6 2026-09-07).
    - Core mineCorrectionLabel (core:1337-1395): add mineCorrectionLabels(docText, finalValue,
      cap=3) returning up to 3 DISTINCT gated {label, excerpt} (ranked gated-first, doc-order
      ties — the existing M2 order; dedupe case-insensitively so ') -Savings' vs ')-Savings'
      near-twins collapse to one). Keep mineCorrectionLabel as a wrapper returning [0] so the
      6 existing L1/M1/M2/F-GATE fixtures and harvest fallback keep passing unchanged.
    - Wrapper harvestCorrections (document-parser.js:1458-1495): per corrected field post up to 3
      rows (same {field, label, excerpt, had_disagree} shape — values never leave the browser);
      null-label count-only rows stay max 1 per field (counts don't need triplicates).
    - Backend collect (ParserKeywordProposalController:46): 'proposals' max:11 → max:33
      (11 fields × 3). Upsert / auto-promote ≥3 / soft-delete desk all unchanged; no test asserts
      the old cap (verified — no cap test in ParserKeywordProposalTest). Desk Blade renders rows
      generically: no Blade change. Junk risk carried by F-GATE + one-click reject (unchanged).
    - Tests: (i) 3 distinct gated labels surface in M2 order; (ii) near-twin labels dedupe;
      (iii) 5 distinct → capped at 3; (iv) legacy single-label wrapper still returns the winner;
      (v) PHP: over-33-cap posts 422. Full JS suite green (blinding pair still the only 2 fails).

    PART B — ABC-POOLING (collector + vote + debug + disagree, mirroring budget; scoped YES §6).
    - Core: new collectAbcCandidates(lines, fileName) mirroring collectBudgetCandidates
      (:1041-1143) shape {value, labeled, weight, fileName, pattern, excerpt}: ABC labelRes
      (standalone abc + (ABC) variant, approved-budget-for-the-contract, F-B1 budget-Amount
      conditional gated on fileHasOrigRevised, total/approved-budget conditional) with the same
      guards both tracks share — date-exclusion (1h-2), placeholder break (1i-1), same-line ×1.0
      / slid ×0.8 (1i-2), table halving without contract word nearby (1f-3), ctxOf ±2 + excerptOf,
      getSectionWeight. LABELED-ONLY: no unlabeled Php fallbacks (any amount isn't ABC — budget's
      paren-php/net-total/p-prefix/bare-php stay budget-exclusive so the two tracks never
      double-count the same figure).
    - Core parseProjectInfoPooled (:1402-1441): pile.abc + aDbg + _debug.abc + _fileCounts.abc +
      _disagree.abc (existing voteCandidatePoolDebug/pooledDisagree reused untouched); export the
      new collector; update the return-shape comment (still {budget, duration, startDate, endDate}
      → now + abc).
    - BLINDING GATE (demo-safe): hoist L3_TEST_BLIND_ABC (now const inside extractABC :768) to a
      shared core-scope const with the same RESTORE-BEFORE-COMMIT marker; BOTH extractABC and
      collectAbcCandidates consult it. While true: collector returns [] → pooled.abc null,
      _debug.abc [], _disagree.abc null → single-match learned path (pattern-fixed) still carries
      the demo; inspector shows an empty abc table honestly. Post-restore: full pooling, no code
      change needed — the gate is the only switch.
    - Wrapper (document-parser.js): override list :249 + inspector loop :260 gain 'abc';
      DISAGREE_INPUT :1207 gains abc: 'projectAbc' (verified AUTOFILL_CORE_FIELDS :952) so the
      amber pick-one note mirrors budget (user-approved); _pickDisagreeValue + had_disagree harvest
      already generic — no changes. Pooled-override order keeps hardcoded-wins-ties (pooled vote
      replaces learned single-match, same as today).
    - Tests: (i) standalone-ABC + approved-budget label fixtures; (ii) F-B1 conditional
      (corroborated → ABC claims budget-Amount; lone → price behavior kept); (iii) date-ghost
      exclusion for ABC; (iv) pooled abc winner + _debug shape + disagree flag (true on close
      labeled pair, null when solved); (v) blinded-collector-empty plumbing (abc keys present,
      rows [] — documents demo state; flipped to full collection post-restore). No PHP changes
      beyond the Part-A cap (PHP proposal tests stay 8/8 + 1 new cap test).
    - Proof (user browser, post-build): (a) correction surfaces ≤3 proposed rows, approver picks
      the novel phrasing; (b) console shows the abc vote table alongside budget/duration/dates;
      (c) demo intact — test5_selectable ABC empty pre-approval, fills post-approval (blinding ON).
    - Out of scope (stays): TOTAL ESTIMATED PROJECT COST hand-adding (loop-first only); ABC
      words-rule (Part A of layer-2, next step); red-banner WARNING track for abc (getMismatchInfo
      covers per-file singles — extend only if trivial, else follow-up).

  Resume state (2026-09-07, L3 built+round-tripped, pattern fix approved):
    - Branch: phase-6-parser-learning. L3 code + blinding + this plan edit uncommitted.
    - JS suite 76 green + 2 intended blinding failures (F-B1, hardcoded-wins); restores 78/78 on
      blinding restore. PHP proposal tests 8/8.
    - vendor/ healthy (artisan 12.51.0).
    - NEXT = pattern fix alone on go-ahead (re-run must fill from Live row); then blinding
      RESTORE + suite green → multi-label desk design → ABC-pooling → words-rule → AI →
      S-curve backlog. No merge without explicit user confirmation (Rule 12).

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
       Parked non-priority ideas live in textfiles/document_parser_future_improvements_plan.md
       (P1 passive mining, P2 lifetime counting + collision warning) — do NOT build unless
       the user explicitly unparks them.
    2. Resume state: merge15-baseline, pattern fix BUILT + BROWSER-PROVEN (575db42, blinding ON
       for demo). JS 77/79 (2 intended blinding fails); PHP proposal tests 8/8.
    3. NEXT = combined build: MULTI-LABEL DESK + ABC-POOLING per the 2026-09-08 plan entry above
       (blinding gate hoisted, demo-safe). Then: ABC words-rule → loop-first novel-label round on
       the estimate-page-only file → AI fallback → S-curve backlog.
    4. Run JS suite + ParserKeywordProposalTest before/after; browser proof (a)(b)(c) above.
    5. Blinding RESTORE stays a tracked pre-merge-to-main prerequisite (not now — demo).
    6. No merge without explicit user confirmation (Rule 12).

===============================================================================
  END OF PLAN
===============================================================================
