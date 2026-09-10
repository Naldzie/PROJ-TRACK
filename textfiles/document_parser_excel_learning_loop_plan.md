# EXCEL HEADER-LEARNING LOOP PLAN (E1)
# Parent: textfiles/document_parser_accuracy_improvement_plan.md (§11 parser_templates,
#   Decision 8 Opt-in matcher; R0 families; R1/R4 research)
# Sibling: textfiles/document_parser_learning_loop_plan.md (L1-L3 normal-doc loop — the
#   philosophy source and clone template, NOT the target: that loop learns VOCABULARY,
#   this one learns LAYOUTS)
# Date: September 8, 2026 — Status: PLANNED (restudied 2026-09-08, no code yet)
# Branch on build: phase-<n>-excel-learning (FROM merge15-baseline; Rule 12)

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

System Name / Client / Framework
  Construction Project Monitoring System (PROJ-TRACK) for CSPC PMU.
  Laravel 12 / PHP 8.2 / MySQL (live), SQLite :memory: (tests).

Why this file exists
  Normal-doc vocabulary learning (L1-L3, parser_keywords) is proven — but S-curve headers
  are still frozen code: every novel contractor layout 422s until a developer ships new
  synonyms. E1 gives Excel the same collect → review → live journey for LAYOUTS, keyed
  per contractor where known (same-format evidence: BUHI, roof + matching hard copy).

Frontend convention (IMPORTANT)
  App UI JS/CSS lives in `public/` linked via asset('...') — NO @vite for new features.

===============================================================================
  2. DECISIONS BANKED (user, plain words)
===============================================================================

  Decision E1a — HYBRID map scope (contractor-first, global fallback).
    Context: user asked whether maps must be contractor-tied or can be global.
    Decision: store contractor_id when known, NULL otherwise; consult order = that
    contractor's live maps first, then global live maps. Precision when known, coverage
    otherwise. Pure-global rejected (A's layout applied to B's file); pure-contractor
    rejected (unknown-contractor files would never benefit).
    Consequences: dedupe key includes contractor (nullable); reader gates on match.

  Decision E1b — SAME trust as keywords (user 2026-09-08).
    Live after ≥3 independent confirmations OR one-click approve; one-click reject/deletable.
    Staff + admin approve (L0a parity); contractors excluded everywhere.

  Decision E1c — AUTO capture on 422 (user 2026-09-08).
    Every "No S-curve table found" failure stashes its header block silently (fire-and-forget,
    never blocks the error UX). No opt-in button — the failure itself is the signal.

===============================================================================
  3. STATUS SUMMARY
===============================================================================

  Rule importer (Tier 1: 2a/R2a/2b/fallback/GRID/UX)   ✅ BUILT + BROWSER-PROVEN (both families)
  E1-1 capture endpoint                                 ⬜ NOT STARTED
  E1-2 review desk                                      ⬜ NOT STARTED
  E1-3 reader consults maps                             ⬜ NOT STARTED
  AI fallback for Excel (E2)                            ✅ BUILT (normal-doc prompt broadened alongside)
  Blinding                                              🔒 ON (demo proof; parser track — S-curve untouched by it)

===============================================================================
  4. WHERE THE HOOKS GO (restudy 2026-09-08, read-only, two explore agents)
===============================================================================

  Entry A — create wizard (Page 1 docs → Page 5 import button #scurveImportBtn,
  _create_project_modal.blade.php:313; click handler scurve-import-ui.js:261-333).
  Pending docs live in document-manager.js:10-16 ({id, file, typeId, typeName, fileName});
  winner File stashed at window._scurvePendingFile. Contractor at import: CLIENT-ONLY and
  uncommitted — Page 4 #contractorSelect (_create_project_modal.blade.php:227-235); no
  project_id exists yet (docs + baseline ship at submit, _create_project_modal.js:740-747).
  Server resolveSource takes file xor document_id ONLY (SCurveImportController.php:103-137)
  — a capture endpoint must accept contractor/project explicitly.

  Entry B — project-detail Baseline tab (#scurveImportBtnDetail,
  baseline-schedule.blade.php:67-77; handler scurve-import-ui.js:337-397). Context on 422:
  documentId (closure), project_id = window.projectId (blade :10), contractor =
  $project->contractor_id (projects.contractor_id nullable FK→contractors.id, migration
  2026_03_02_100000:50,65; Project.php:36 fillable, :79-81 belongsTo; Contractor PK id,
  company_name/contractor_name keys). Server re-resolves bytes itself
  (Storage::disk('public')->path, SCurveImportController.php:133) via Document→project→
  contractor_id — no client input needed.

  422 hook lines: err.isNoTable set in parseSCurveFile (:40) + parseSCurveDocument (:58);
  wizard catch + offer at :321-327; detail catch + offer at :386-391. Capture slots into
  those branches (ctx already assembles source + dates + flow + button) or atop
  scurveOfferAiAssist. Client has NO xlsx reader (no SheetJS; Excel is server-side
  PhpSpreadsheet) — capture sends bytes/document_id; server serializes (serializeForAi
  precedent, SCurveImportService.php:57-101, maxRows param already exists).

  Clone template (keyword loop inventory): migration 2026_09_04_000001 (field/label_phrase/
  excerpt/occurrences/status + softDeletes) + 2026_09_08_000001 (source); model fillable +
  SOURCES + live/proposed scopes; controller collect (11-field allowlist, max:33, upsert +
  auto-live ≥3, 201) / approve+log / destroy+log / getAllForManagement (?status=, occurrences
  DESC); policy all-six-abilities admin||staff + AppServiceProvider registration; routes ×4
  mirrored admin+staff (contractors: nothing); Blade desk (table + tabs + delegation +
  excerpt tooltips + origin badge); harvestCorrections (snapshot diff, mineCorrectionLabels
  cap 3, batch cap 33, CSRF + keepalive, best-effort); loadLearnedKeywords (role-base, 24h
  sessionStorage TTL, never-cache-empty); core optional-param injection (omitted opts =
  today's behavior exactly). Tests: ParserKeywordProposalTest.php, 11 tests.

  Service purity: SCurveImportService touches NO DB today (zero App\Models/DB/Storage hits).
  Seam (mirror L3): parse(string $path, ?array $liveMaps = null) → findHeader($rows,
  $mergeMap, $liveMaps = null) → detectHeaderRow($cells, $liveMaps = null); old arities keep
  working exactly (omitted param = today's behavior).

===============================================================================
  5. IMPLEMENTATION STEPS
===============================================================================

  PHASE E1-1 — Capture endpoint (silent, zero behavior change)  ⬜ NOT STARTED
    - Backend: migration scurve_header_proposals (contractor_id nullable FK→contractors.id
      nullOnDelete, header_rows_text, column_map JSON nullable, occurrences default 1,
      status default proposed, source default 'auto-capture', softDeletes); model
      (fillable, live/proposed scopes, belongsTo contractor); controller collect endpoint
      (roles admin+staff, accepts file|document_id + contractor_id? + project_id?, serializes
      header slice via serializeForAi($path, 15), stores ONE proposed row per
      (contractor_id, header-hash), occurrences++ on repeats, auto-live ≥3, batch cap 11);
      routes mirrored admin + staff (contractors: nothing).
    - Wrapper: fire-and-forget capture POST in both 422 branches (wizard sends File +
      contractorSelect value; detail sends document_id, server resolves contractor via
      Document→project); all failures swallowed; error UX untouched.
    - Tests: PHP store + repeat-promote + contractor-403 + unknown-field-422 (mirror the 11);
      JS suite untouched (no core changes). Proof: failed roof-style import (pre-R2a shape)
      leaves one proposed row; extraction output byte-identical (reader untouched).

  PHASE E1-2 — Review desk (staff + admin)  ✅ BUILT 2026-09-08 (code, NOT committed)
    - Clone _manage_parser_keywords_modal: table (header-block excerpt | contractor |
      occurrences | source badge | status | approve/reject), Proposed/Live/All tabs,
      occurrences DESC; approve→live + activity log, reject→soft-delete + log; same policy
      shape (admin||staff) + explicit AppServiceProvider registration (BudgetSource gap not
      repeated); routes mirrored admin + staff.
    - Approve semantics: trust THIS (contractor, header-block, column_map) mapping — scoped
      trust, not global vocabulary. Mismatched-contractor maps never leak (reader gates).
    - Placement: standalone menu entry (periodic review). NO popup launcher (unlike keywords:
      S-curve import is infrequent, deliberate review fits).
    - Tests: list+filter, staff approve, admin reject-soft-delete, contractor blocked
      everywhere. Proof: approve one row in browser; audit shows who/when.

  PHASE E1-3 — Reader consults maps (lowest-priority fallback)  ✅ BUILT 2026-09-09 (code, NOT committed; user choices: auto maps + typo-tolerant + v:1 flag)
    - Service (SCurveImportService): MAP_VERSION=1 const; parse($path, $liveMaps=null)
      two-pass (rules on all sheets first, maps only when rules miss everywhere);
      meta gains mapUsed/mapId + one plain-worded in-modal FYI warning on map-built
      curves (rule-built results byte-identical: mapUsed=false, zero new warnings).
      deriveAutoColumnMap() (relaxed detector: ONLY delta vs strict is loose
      description wording — same ≥2-month gate; packs v/sig/cols, null when nothing
      derivable). findHeaderViaMaps(): version gate (stale v never applies) +
      fuzzy signature search (exact / \b-bounded substring / Levenshtein≤2, desc-cell
      anchored, ≥2 cells at ≥0.5) + month-spans-hold-numbers guard (wrong-shape
      sheets skip instead of corrupting). findHeader() refactored behind
      findHeaderWith(callable) — strict path behavior unchanged.
    - Controller: parseExcel loads live maps server-side (contractor's first, then
      global; explicit contractor_id → document→project → global; unknown ids
      degrade; all failures → [] so imports never break). No new routes (stale
      route-cache incident class avoided). collect() auto-derives + stores the map
      (backfills pre-E1-3 null-map rows on repeat); list gains contractor_id,
      header_hash, column_map (additive).
    - Client (one spot, capture pattern): wizard parse appends contractorSelect;
      detail unchanged (server resolves). Map FYI rides the existing warning stash
      → renders in-modal, no new UI code.
    - Caught pre-green: toArray() is 1-based row keys — signature builder read the
      wrong row (empty sig → all 5 map tests failed); fixed with per-shape key
      resolution (single-row = original key, two-row = positions).
    - Tests (+9 in ScurveHeaderProposalTest, 19/19): v:1 auto-map on capture,
      null-map junk, repeat backfill, miss→map→hit exact curve, typo variant
      (TASK DESCRIPTON) hit, rules-win-ties (identical months+warnings), v:999
      ignored, contractor scoping (A applies / B 422s), global applies.
      SCurveImportTest 29/29; JS 90/92 (2 intended blinding fails); full suite
      1064 passed / same 7 pre-existing clean-tree failures — zero regressions.
    - Proof for user browser: third-family/layout-variant Excel that 422s today →
      capture row shows map → approve → re-import parses (mapUsed note in modal).
    - WIDENING 2026-09-09 (user's real 10-month file double-missed: months P1..P10
      + desc WORK DETAILS — relaxed knew neither, so the map stayed null and the
      approved row kept 422ing): matchMonthLabel gains relaxed-only P1–P12 /
      Period / Phase N; isLooseDescriptionLabel gains detail\w*. Both scoped to
      auto-maps (strict path untouched — single call site, default false). The
      file's TOTAL / CUMULATIVE % ACHIEVED / single-column months were already
      friendly, so labels were the only blind spot. Existing approved null-map
      rows self-heal: re-upload backfills the map (status untouched). Tests (+3:
      P1 collect derivation with 10 spans, service miss→map→hit with the exact
      5..100 curve of 1,000,000, endpoint parses after approval without AI offer).
      ScurveHeaderProposalTest 22/22; SCurveImportTest 29/29; full suite 1067
      passed / same 7 pre-existing clean-tree failures — zero regressions.

===============================================================================
  6. SESSION LOGS
===============================================================================

  2026-09-08 — File created (plan only, no code).
    - Ordered by user: own md file for the E1 implementation plan.
    - Restudied via two explore agents (import+contractor context; clone template + purity).
    - Decisions banked: hybrid scope (E1a), same trust (E1b), auto capture (E1c).
    - Awaiting build go-ahead (phase branch per Rule 12). No merge without confirmation.

  2026-09-08 — E1-1 BUILT on merge15-baseline (code, NOT committed; PDF twin deferred per user).
    - Migration scurve_header_proposals (contractor_id nullable FK nullOnDelete, source_type
      default excel, header_rows_text, header_hash indexed, column_map nullable, occurrences,
      status, source auto-capture, softDeletes) + model (fillable, SOURCES, live/proposed
      scopes, belongsTo contractor) + policy (all-six admin||staff) + explicit
      AppServiceProvider registration (BudgetSource gap not repeated).
    - Endpoint Admin\SCurveHeaderProposalController::collect (file|document_id XOR, Excel
      only, contractor explicit-wins → Document→project → null; header slice via
      serializeForAi($path, 15); sha256 of normalized text; upsert by (contractor,
      hash)+auto-live ≥3; 201/422 shapes mirroring keywords); routes mirrored admin+staff.
    - Wrapper scurveCaptureHeaders() fire-and-forget in both 422 branches (wizard: File +
      contractorSelect; detail: document_id; contractors: no path; all swallowed).
    - Caught pre-green: stale route cache hid new routes (same L1/AI-2 incident — route:clear
      fixed); multipart validation failures redirect without JSON Accept header (tests now
      send it); seeded 'Schedule' DocumentType collides with create (firstOrCreate in tests).
    - Tests ScurveHeaderProposalTest.php: 6/6 green (store global-NULL, repeat-promote,
      contractor-403 both routes, bad payloads, document→contractor resolution, explicit
      contractor wins). S-curve suites 35/35 with SCurveImportTest; both real files
      byte-identical (E1-1 touches no parse path). JS syntax clean. No commit without OK.

  2026-09-08 — E1-2 BUILT on merge15-baseline (code, NOT committed).
    - Backend: getAllForManagement (?status=, occurrences DESC, contractor name resolved,
      source + has_map flags) + approve (→live + activity log) + destroy (soft-delete +
      log); staff+admin gates; routes mirrored admin+staff (contractors: nothing).
    - Desk _manage_scurve_headers_modal.blade.php: table (header block + excerpt tooltip |
      contractor/Global | seen | origin badge | status | approve/reject), Proposed/Live/All
      tabs, data-* delegation, empty-state colspan 6; menu entries + includes in both layouts;
      no popup launcher (deliberate review fits infrequent imports — plan deviation noted).
    - Approve trusts the header block's existence; column_map attaches in E1-3 (auto-proposal
      or reviewer assignment — open question below).
    - Tests: +4 mirrored (list+filter, staff approve, admin reject, contractor blocked).
      ScurveHeaderProposalTest 10/10; combined suites 50/50; Blade view:cache clean;
      both real files byte-identical. No commit without OK.
    - BROWSER PROOF 2026-09-08 (user): S-Curve Layouts desk renders in both layouts with the
      third-family capture Proposed (header text + contractor/Global + seen-count visible).
      Review flow closed for display (approve/reject paths covered by tests).

  2026-09-10 — A POINT-AND-TEACH RESTUDY (read-only, three probes, no code).
    - User complaint (screenshot): the Header-block column's 120-char raw dump is
      incomprehensible — letters visible but no table picture. Restudy confirms the
      stored text already carries everything needed (COLUMNS legend + [ROLE] tags).
    - Decisions banked (user): text-only (no re-upload — approval IS the trust event;
      consult-time signature + numbers guards still refuse bad maps); variant-creating
      teaches (alternating contractor formats never forgotten — consult already tries
      all live maps per contractor; explicit warned replace reserved for genuine mistakes
      and drops to proposed); clickable mini-grid primary (list cell + teach modal),
      letter confirmation readout, never typed-letters-only.
    - Full build design in §7A below. No implementation yet.

===============================================================================
  7. OPEN QUESTIONS
===============================================================================

  - Header-hash function for dedupe (normalize whitespace/case + SHA256? fuzzy-hash for typo
    drift between captures?). Decided at E1-1 build: start exact-normalized, loosen if the
    same contractor's headers wobble.
  - column_map schema version flag (v1 now; bump if span semantics change in 2c). Decided at
    E1-3 build.
  - Wizard contractorSelect empty at import time (user skips Page 4): capture stores
    contractor_id NULL → consults as global. Accepted per E1a; revisit if noisy.
  - E1-2 column_map timing ("attaches in E1-3 (auto-proposal or reviewer assignment —
    open question)"): RESOLVED 2026-09-10 — BOTH. Auto-proposal built in E1-3;
    reviewer assignment is Phase A below.

===============================================================================
  7A. PHASE A — POINT-AND-TEACH (PLANNED, NOT STARTED — user-approved design)
===============================================================================

  Goal: a reviewer teaches column positions by CLICKING a rendered mini-grid —
  no developer, no re-upload, no typed letters. Covers map-less rows AND lets a
  contractor's alternating formats coexist as variants.

  A-1 Teach endpoint: PATCH .../scurve-header-proposals/{proposal}/columns
    (mirrored admin+staff; ensureReviewer + update policy + activity log, mirroring
    approve). Accepts letters (desc + optional code/weight/contract + per-month
    letter groups + header R-line picker defaulting to the desc line); server
    converts (letter→0-based), stamps v=MAP_VERSION, rebuilds sig from taught
    columns, validates (≥2 months, int desc, no overlaps/desc-in-months, sig≥2,
    ascending month keys), stores. Approve stays the sole live flip.

  A-2 Desk UI (Blade-inline JS per convention):
    - List cell: raw dump REPLACED by a compact grid (first ~8 rows × ~16 cols,
      scrollable, role badges, dimmed empties; proper CSV split — quoted cells
      break naive comma-split; R<n> labeled kept-order-not-Excel-rows; 4000-char
      truncation badged).
    - Per-row "Teach columns" button (always rendered; styled by mapped/unmapped).
    - Standalone teach modal (never stacked): full grid (≤15 stored rows, both-way
      scroll) as the click surface (desc column, month spans single/paired, contract
      column) + letter confirmation readout + contractor context + existing-map prefill.
    - Teach CREATES A VARIANT row (same contractor, own occurrences, needs own
      approval — alternating formats never forgotten; consult tries all live maps).
      Explicit warned "replace" reserved for genuine mistakes and drops to proposed.

  A-3 Proof: teach a map-less layout by clicking only → re-import parses with the
    learned-layout note; full + JS suites green; logs; commit/push only on user word.

===============================================================================
  8. CONVERSATION CONTINUATION GUIDE
===============================================================================

  For the next session:
    1. This file supersedes all older E1 mentions (accuracy plan §11/E1 section stays as
       history + pointer; update it with a pointer to HERE on build start).
    2. Build order E1-1 → E1-2 → E1-3 (each proved before the next). Blinding stays ON.
    3. Run SCurveImportTest + full suite before/after; BUHI/roof byte-identical re-verify.
    4. No merge without explicit user confirmation (Rule 12).

===============================================================================
  END OF PLAN
===============================================================================
