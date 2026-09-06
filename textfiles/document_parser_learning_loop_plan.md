# DOCUMENT PARSER LEARNING LOOP PLAN (CONTINUATION)
# Parent: textfiles/document_parser_accuracy_improvement_plan.md (Steps 1–1i, N-batch, S-batch, P1/P2)
# Date: September 4, 2026 — Status: 🔄 NEXT BUILD BATCH approved (R1+R2 orientation per-page + F-COPY + F-GATE + F-B1, no code); F-TEST retest pending user crops. NEXT = build batch on go-ahead.
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
