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

  PHASE E1-2 — Review desk (staff + admin)  ⬜ NOT STARTED
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

  PHASE E1-3 — Reader consults maps (lowest-priority fallback)  ⬜ NOT STARTED
    - Loader (classic script): fetch live maps ONCE per import run (role-base + CSRF pattern;
      contractors skip; 24h sessionStorage TTL; never cache empty) — mirrors
      loadLearnedKeywords. Shape: { maps: [{contractor_id, header_hash, column_map}] }.
    - Threading (mirror L3 optional-param): SCurveImportController passes maps into
      parse($path, $liveMaps); findHeader tries rule detection FIRST; only when it returns
      null, tries each live map in order (contractor's maps, then global): column_map pins
      {descCol, weightCol, contractCol, monthSpans} directly (header text match by hash +
      fuzzy tolerance for typo drift). Old calls unchanged; empty table = today's behavior.
    - Tests: synthetic unknown-layout fixture (miss → map → approve → hit); rules win ties
      (mapped file that rules now cover ignores the map); empty table = byte-identical.
      Full suite + BUHI/roof re-verify green.
    - Proof: third-layout file fails before approval, parses after, nothing else moving.

===============================================================================
  6. SESSION LOGS
===============================================================================

  2026-09-08 — File created (plan only, no code).
    - Ordered by user: own md file for the E1 implementation plan.
    - Restudied via two explore agents (import+contractor context; clone template + purity).
    - Decisions banked: hybrid scope (E1a), same trust (E1b), auto capture (E1c).
    - Awaiting build go-ahead (phase branch per Rule 12). No merge without confirmation.

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
