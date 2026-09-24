# CONTRACT PRICE RENAME + REVISED CONTRACT PRICE FIELD
# Parent ref: textfiles/budget_precedence_and_source_validation_plan.md (D-A0, reversed)
# Date: September 24, 2026
# Status: ⬜ PLANNED, NOTHING BUILT. Two sub-decisions owed (§5). Needs a build order.
# Branch: not cut. Suggested phase-47-contract-price-revised.

===============================================================================
  §0 — WHAT THIS IS, AND WHY IT REVERSES A DECISION MADE THE SAME DAY
===============================================================================

  D-A0 (budget plan, 2026-09-24) declined a revised-contract-amount field. The user
  REVERSED it hours later, with a reason that defeats the strongest objection:

      "lets add the revised and change the naming to contract price and revised
       contract price. since we are planning to update the version of the system
       we sent to the client for pilot test"

  The main argument against was that this would be the FIRST schema change since
  pilot version 1 and would end the clean-rollback property. That cost does not
  disappear — but a pilot version 2 is going to be cut anyway, so the migration
  rides along with a deployment that is happening regardless, instead of forcing
  one of its own. That is a different trade from the one D-A0 refused.

  ⚠ THE DOMAIN, CONFIRMED AGAINST THE UI (the user's reading was correct):
      ABC              the ceiling set BEFORE bidding; bids above it are disqualified.
                       Labelled "ABC / Approved Budget for the Contract".
      Contract Price   the AWARDED amount, after any voluntary contractor discount.
                       Stored today as approved_budget. Labelled "Contract Price".
      Revised          what a VARIATION / CHANGE ORDER produces when work exceeds the
                       original bid. GPPB allows up to 10% of the original contract
                       price. THE SYSTEM HAS NOWHERE TO PUT THIS — that is the gap.

  ⚠ USER DECISION, VIA THE RENAME QUESTION (2026-09-24): RENAME THE COLUMN TOO.
  Option "add the field only, keep approved_budget" was offered and NOT taken. The
  consequence was stated at the time and is restated in §4: rollback to pilot v1
  stops being a plain file copy.

===============================================================================
  §1 — WHAT ALREADY MATCHES, AND WHAT DOES NOT
===============================================================================

  ✅ EVERY USER-FACING LABEL ALREADY SAYS "CONTRACT PRICE". Measured, not assumed:
     admin/_create_project_modal.blade.php:218 · admin/projects.blade.php:227 ·
     projects/archived.blade.php:45 · projects/index.blade.php:349,:535 ·
     project_detail/components/stats-row.blade.php:4 ·
     project_detail/components/tabs/overview.blade.php:159
     So the rename is about the SCHEMA catching up with the UI, not the reverse.

  ⚠ ONE LABEL DISAGREES, and it is in a client-facing document:
     app/Services/Reports/ProjectReportDataService.php:222 prints the row as
     "Contract Amount". Align it while here, or the Word/PDF monitoring report keeps
     using a third word for the same number.

  ⚠ TWO PLACES USE RAW SQL and will NOT be caught by a rename of the model alone:
     DashboardController.php:114  selectRaw('SUM(abc - approved_budget) as total_savings')
     DashboardController.php:172  selectRaw("... SUM(approved_budget) as total_budget")

===============================================================================
  §2 — FILE INVENTORY (tracked files only; .kilo/ and backup/ are untracked)
===============================================================================

  MIGRATIONS (new one added; the old ones are NOT edited)
     new: rename approved_budget -> contract_price, add revised_contract_price
     ⓘ Laravel 12 renames natively (no doctrine/dbal). SQLite 3.25+ supports
       ALTER TABLE RENAME COLUMN, so the :memory: test DB is fine.

  BACKEND (11)
     app/Models/Project.php                        fillable + casts + new accessor
     app/Http/Controllers/ProjectController.php    validation x2, sort map :146, :939
     app/Http/Controllers/DashboardController.php  ⚠ 2 raw SQL + sums
     app/Http/Controllers/contractor/DashboardController.php   3 sums/sorts
     app/Http/Controllers/ActivityLogController.php            :114
     app/Services/ProjectOverviewService.php       :69, :89
     app/Services/ProjectHealthService.php         comment only
     app/Services/Reports/ProjectReportDataService.php  :222 + the label
     app/Services/AI/HistoricalSimilarityService.php    :485, :514-515  ⚠ see D-C1
     database/factories/ProjectFactory.php
     database/seeders/ProjectSeeder.php

  VIEWS (9)
     admin/_create_project_modal.blade.php   ⚠ also ADDS the new input
     admin/projects.blade.php · admin/warranty.blade.php
     contractor/dashboard.blade.php · contractor/warranty.blade.php
     staff/warranty.blade.php · warranty/preview.blade.php
     projects/archived.blade.php · projects/index.blade.php
     projects/project_detail/components/tabs/overview.blade.php
     (+ stats-row.blade.php for the display marker)

  JS (3)
     public/js/_create_project_modal.js            :404 edit-prefill
     public/js/admin/projects/form-navigation.js   :293 error key
     public/js/admin/projects/document-parser-core.js  :863 comment — and see §6

  TESTS (8) — all reference the column and must move with it
     AIPredictionTabRenderTest · BaselineScheduleDateBoundsTest · ProjectReportTest ·
     ReportExportTest · CrossProjectSupplementIntegrationTest ·
     HistoricalSimilarityHardeningTest · PredictionAccuracyByDataSourceTest ·
     Unit/HistoricalSimilarityServiceTest

===============================================================================
  §3 — THE DESIGN
===============================================================================

  3.1 SCHEMA
        contract_price           decimal(15,2)  NOT NULL   (renamed)
        revised_contract_price   decimal(15,2)  NULL        (new)

      ⚠ NULL UNTIL AN ACTUAL REVISION — never mirrored from contract_price. If every
      project carried a revised value, "never revised" and "revised to the same
      number" would be indistinguishable, destroying the one fact the field exists to
      record. This was the user's first sketch and it is deliberately not built.

  3.2 ONE ACCESSOR, USED EVERYWHERE A SINGLE FIGURE IS WANTED
        Project::effectiveContractPrice() = revised_contract_price ?? contract_price
      Every total, comparison, export and report goes through it. A caller that reads
      contract_price directly is then a deliberate choice meaning "the original",
      not an oversight.

  3.3 DISPLAY
      Where one number shows: the effective amount, with a marker when revised is set
      ("Revised from ₱X"). Where the pair matters (project detail overview): both.

  3.4 FORM
      The revised input appears in BOTH create and edit. Create matters because
      BACK-ENCODING an existing project during the pilot is the likeliest way a
      revised figure ever reaches the system. Validation: nullable, numeric, min 0.
      ⓘ Considered and rejected: requiring revised >= contract_price. A variation
      order can DECREASE a contract (deductive change order), so that rule would be
      wrong. No cross-field rule.

===============================================================================
  §4 — ⚠ DEPLOYMENT AND ROLLBACK (the cost the user accepted)
===============================================================================

  This is the FIRST schema change since pilot version 1, and it is a RENAME, which is
  the destructive kind.

    Upgrading a pilot machine   copy the files, then `php artisan migrate`.
    Rolling back to version 1   NO LONGER A FILE COPY. v1 code SELECTs
                                approved_budget, which will not exist, so the app
                                breaks on a plain restore. The down-migration must be
                                run FIRST, then the v1 files restored.

  ⚠ THE CHANGELOG MUST SAY THIS. textfiles/pilot_version_changelog.md states that
  rollback is complete "because no adjustment since v1 touches the schema", and §4's
  rollback paragraph says that property must be revoked in writing by the first
  adjustment carrying a migration. THIS IS THAT ADJUSTMENT. The entry's DEPLOYMENT
  IMPACT line must state the migration AND the ordering requirement above.

  ⚠ THE DOWN-MIGRATION MUST BE REAL AND TESTED, not a stub. It is the only rollback
  path. It renames back and drops revised_contract_price — which DISCARDS any revised
  amounts entered in the meantime. Say so in the changelog; do not let that be a
  surprise discovered during a rollback.

===============================================================================
  §5 — SUB-DECISIONS OWED BEFORE BUILDING
===============================================================================

  D-C1  DO ANALYTICS USE THE EFFECTIVE AMOUNT OR THE ORIGINAL?
        Three places read the money and mean different things by it:
          (a) DASHBOARD SAVINGS — selectRaw('SUM(abc - approved_budget)').
              A variation order EATS INTO savings, so the effective amount is the
              truthful input. Using the original would overstate savings on every
              revised project. RECOMMEND: effective.
          (b) DASHBOARD / CONTRACTOR TOTALS — portfolio value.
              RECOMMEND: effective.
          (c) AI CROSS-PROJECT SIMILARITY (HistoricalSimilarityService :485, :514).
              Budget is a similarity dimension — "how big was this project". The
              effective amount is the truer size. ⚠ BUT THIS CHANGES PREDICTION
              INPUTS, and the AI tests pin known datasets. RECOMMEND: effective, and
              expect to re-run the AI suite carefully. If it destabilises the pinned
              "Project 7" figures, keep (c) on the ORIGINAL and note the asymmetry.
        ⚠ Whatever is chosen, raw SQL must be changed deliberately — a column rename
        alone will silently keep (a) and (b) on the original figure.

  D-C2  DOES THE PARSER FILL THE REVISED BOX? (and see §6)
        RECOMMEND: yes, but as a SEPARATE phase after the field exists.

===============================================================================
  §6 — ⚠ THIS MAKES ITEM 58.3 SMALLER AND SAFER, NOT BIGGER
===============================================================================

  D-A0 warned that adding the field turns decision 11 from a PRECEDENCE question into
  a ROUTING one, and that item A would otherwise be built and then rebuilt. That has
  now happened, and it is good news:

    BEFORE (precedence)  reorder the budget vote so a `revised` candidate outranks
                         everything else. HIGH RISK — changes which value fills the
                         money box on EVERY multi-amount document, hence the corpus
                         re-measurement requirement.
    AFTER (routing)      `revised`-pattern candidates go to the REVISED box; everyone
                         else votes for Contract Price exactly as today. The main
                         vote is NOT reordered at all.

  Checked against the §58.3-V table: removing rows 3 and 4 (the two revised entries)
  leaves 4204128.84 winning the contract-price pool — which is what fills the box
  today. So on the one measured document the contract-price read DOES NOT MOVE.

  ⚠ WHAT SURVIVES FROM THE OLD PLAN: D-A2, the cents tie-break. The revised pool
  still contains 4323196.00 and 4323196.77, and something must still choose. The
  decision and its reasoning are unchanged.
  ⚠ WHAT LAPSES: D-A1's precedence order, and most of D-A3's corpus-measurement
  burden — there is far less to re-measure when the main vote is untouched. Do not
  delete either from the budget plan; mark them superseded, with this file as the
  reason.

===============================================================================
  §7 — SUGGESTED PHASING
===============================================================================

  P1  Migration + model + accessor + backend + tests green. No UI yet.
  P2  Views: the new input, the display marker, the report label alignment.
  P3  Parser routing (D-C2), which is the rewritten item 58.3.
  Each phase ends with the suite and an inform (Rule 10).

===============================================================================
  END OF PLAN
===============================================================================
