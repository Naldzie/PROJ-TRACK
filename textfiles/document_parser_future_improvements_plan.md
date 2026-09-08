# DOCUMENT PARSER FUTURE IMPROVEMENTS (PARKED, NON-PRIORITY)
# Parent: textfiles/document_parser_learning_loop_plan.md (L1-L3, multi-label, ABC-pooling)
# Date: September 8, 2026 — Status: PARKED per user (core loop already works; build only on go-ahead)
# Rule 12 applies when unparked: separate phase branches, no merge without explicit confirmation.

===============================================================================
  1. PROJECT CONTEXT
===============================================================================

System Name / Client / Framework
  Construction Project Monitoring System (PROJ-TRACK) for CSPC PMU.
  Laravel 12 / PHP 8.2 / MySQL (live), SQLite :memory: (tests).

Why this file exists
  Two user-proposed improvements, discussed 2026-09-08, deliberately parked behind the
  standing queue (ABC words-rule → loop-first novel-label round → AI fallback → S-curve
  backlog). The core learning loop is proven and working (miss → teach once → approve →
  fills free; multi-label surfacing incl. organic TOTAL ESTIMATED discovery; ABC vote
  plumbing built, wakes at blinding restore) — neither item below is needed for that.

Frontend convention (IMPORTANT)
  App UI JS/CSS lives in `public/` linked via asset('...') — NO @vite for new features.

===============================================================================
  2. PARKED ITEM P1 — PASSIVE (SYSTEM-FOUND) ABC MINING
===============================================================================

  Idea (user, 2026-09-08)
  -----------------------
    Mining today needs a correction (the corrected value is the anchor the miner searches
    for). Proposal: when ABC fills confidently from a hardcoded label, ALSO survey the
    file for OTHER lines holding the SAME figure with NOVEL labels (e.g. TOTAL ESTIMATED…)
    and propose those wordings — no correction needed, system only logs + waits for approval.

  Design (agreed direction, ABC-only first)
  ----------------------------------------
    - Anchor rule (safety-critical): EXACT-figure equality with the filled ABC value only.
      Near-matches never qualify — this stops price labels being proposed as ABC wording.
    - Novelty rule: skip labels matching hardcoded ABC patterns or existing live/proposed
      rows (server upsert turns repeats into occurrence bumps = free corroboration).
    - Reuses collectAbcCandidates() output (label + figure + excerpt per line) — the
      ABC-pooling machinery serves this directly (Rule 7: expand, don't redesign).
    - Guards: F-GATE applies; cap rows per file; human approval mandatory; occurrences ≥3
      auto-live (SAME trust rule as correction rows — user decision 2026-09-08).
    - Desk shows a source badge ("system-found" vs "your correction") — user decision
      2026-09-08. Approval/reject/promote behavior identical for both sources.
    - Demo-safe: while blinding is ON, ABC never fills → no anchor → passive mining stays
      quiet automatically. Zero interference with the blinding demo.

  Blinding note (clarifies a misconception from discussion)
  --------------------------------------------------------
    Blinding does not ENABLE mining — mining needs an anchor (a trusted value); today the
    anchor is the user's corrected value, and blinding merely SIMULATES novelty to prove the
    loop end-to-end. Passive mining swaps the anchor to the system's own confident fill.

  Build order (when unparked)
  ---------------------------
    1. Core passive survey over collectAbcCandidates() output (same-figure + novel-label).
    2. Harvest hook on confident hardcoded fills (ABC only) with per-file cap.
    3. `source` column (migration: 'correction' default, 'passive') + desk badge column.
    4. Fixtures: synonym surfaces / price-figure line never surfaces / known labels skipped /
       blinded demo stays quiet. Full suite green + browser proof (TOTAL ESTIMATED row appears
       on test5_selectable_text.pdf WITHOUT any correction).

  Decisions banked (user 2026-09-08): ABC only first · source badge shown · same trust rule.

===============================================================================
  3. PARKED ITEM P2 — LIFETIME LABEL COUNTING + CROSS-FIELD COLLISION WARNING
===============================================================================

  Idea (user, 2026-09-08)
  -----------------------
    Count, over the whole lifetime of the system, how often each label wording is seen and
    under which field — as a basis for warning: if the user tries to approve a label that
    another field already used 50+ times, warn that the keyword is consistently used
    elsewhere before allowing the approval.

  What exists today (verified 2026-09-08)
  --------------------------------------
    - `occurrences` counts CONFIRMATIONS per (field, label_phrase) row — not sightings.
    - Nothing counts lifetime sightings; no cross-field warning exists anywhere.
    - Concrete danger class: approving "Contract Amount" as an ABC label while it fills
      Contract Price 60× → ABC boxes start filling with price figures everywhere.

  Option LEAN — collision warning from existing data (recommended if prioritized)
  -------------------------------------------------------------------------------
    - At approve time, check whether the same label_phrase already lives under a DIFFERENT
      field with high occurrences → confirm dialog ("'X' already fills Contract Price (62×
      confirmed). Approve it for ABC too?"). One endpoint tweak + one modal confirm.
    - Zero storage growth, zero per-run overhead. Limitation: knows only approved/proposed
      labels, not every wording ever seen. Covers ~90% of the danger (collisions only matter
      once a wording actually fills boxes — exactly what the proposals table records).

  Option FULL — lifetime sighting counters (user's described vision)
  -------------------------------------------------------------------
    - New counter table (label_key, field, sightings), bumped once per run per DISTINCT
      label actually USED for a fill (never per line scanned — that would explode).
    - Approve-time check reads lifetime sightings; the 50× threshold becomes config, not code.
    - Needs migration + endpoint/piggyback + fixtures. Worth it only if analytics beyond the
      warning ("which wordings does the system see most?") is wanted later.

  Build order (when unparked, LEAN assumed unless user says FULL)
  -----------------------------------------------------------------
    1. Approve-time cross-field lookup + confirm dialog in the desk (threshold constant).
    2. Fixtures: same-label-other-field-high-occurrences warns / same-field approval silent /
       low-occurrence other-field silent (or soft note — user to decide).
    3. FULL only: migration + sighting hook + threshold config + analytics display decision.

===============================================================================
  4. UNPARKING RULE
===============================================================================

  - Neither item moves until the standing queue clears (words-rule → loop-first round →
    AI fallback → S-curve backlog) unless the user explicitly reorders.
  - P1 before P2 if both unpark (P1 feeds vocabulary; P2 guards it).
  - No merge to main without explicit user confirmation (Rule 12).

===============================================================================
  END OF PLAN
===============================================================================
