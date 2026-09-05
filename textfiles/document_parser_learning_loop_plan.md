# DOCUMENT PARSER LEARNING LOOP PLAN (CONTINUATION)
# Parent: textfiles/document_parser_accuracy_improvement_plan.md (Steps 1–1i, N-batch, S-batch, P1/P2)
# Date: September 4, 2026 — Status: 🔄 PHASE L1 IMPLEMENTED + tested, browser proof POSTPONED by user (all 5 test files extract accurately — no competing values left to correct). Committing + pushing per user. L2/L3 NOT STARTED.
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

  PHASE L2 — Review list (staff + admin)  ⬜ NOT STARTED
    - Manage modal (BudgetSource clone): list proposals with counts, approve → live,
      reject → delete, live list editable/deletable. Gates per L0a.
    - Tests: policy (contractor 403, staff 200), approve flips status, threshold
      auto-promotion at ≥3 occurrences.
    - Proof: approve one proposal in the browser; audit log shows who/when.

  PHASE L3 — Reader consults the table  ⬜ NOT STARTED
    - Endpoint exposes live pairs (read-only for staff/contractor roles, same pattern as
      budget-sources list); core loads them as LOWEST-priority label fallback (below all
      hardcoded labels — nothing working today can regress); cache 24h keyed by table
      updated_at.
    - Tests: synthetic paraphrase fixture (rules miss → approved pair → hit); hardcoded
      labels still win ties; empty table = today's behavior exactly.
    - Proof: paraphrased-label file fails before approval, passes after, with no other
      fixture changing.

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
    - Next: user browser proof (one corrected box → one proposed row), then L2 on go-ahead.

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

===============================================================================
  7. OPEN QUESTIONS
===============================================================================

  - Synthetic paraphrase fixtures: hand-write 3–5 now, or generate from the 5 known
    contracts at L3 kickoff? Recommendation: generate at L3 (needs the corpus anyway).
  - Should proposals also capture WHICH pattern fired (for the inspector)? Cheap now,
    useful later — decide at L1 kickoff.

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
