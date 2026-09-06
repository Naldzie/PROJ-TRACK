# DOCUMENT PARSER LEARNING LOOP PLAN (CONTINUATION)
# Parent: textfiles/document_parser_accuracy_improvement_plan.md (Steps 1–1i, N-batch, S-batch, P1/P2)
# Date: September 4, 2026 — Status: 🔄 ABC-label POSTPONED for loop-first test (banked phrasing); M1+M2 built + tested uncommitted. NEXT = remaining batch + F-TEST on go-ahead.
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
    1. Parent reader work is stable on phase-5-parser-accuracy (62 JS green + browser proofs).
    2. Branch L1 work as phase-6-parser-learning FROM phase-5-parser-accuracy (Rule 12).
    3. Before coding L1, re-read §4 hooks above + parent §7 last entries.
    4. Run `composer test` + `node --test tests/js/document-parser-core.test.mjs` before/after.
    5. No merge without explicit user confirmation (Rule 12).

===============================================================================
  END OF PLAN
===============================================================================
