# PROGRESS PACE — FIX OF "PROGRESS SPEED COMPARISON" (AI Prediction panel)
# Branch: phase-43-progress-pace-fix (from develop d705b2d)
# Date: 2026-09-22
# Status: 🔄 BUILT — suites green (PHP 1358 / 0, JS 279 / 0), browser check owed

===============================================================================
  1. PROBLEM (user question, 2026-09-22: "are those actually working or correct?")
===============================================================================

  The card "Progress Speed Comparison" showed EARLY PHASE SPEED "4%/week" and
  RECENT PHASE SPEED "26%/week" for project #142 (Construction of Barangay
  Evacuation Center, actual 2, 3, 7, 15, 25, 38 % for Apr–Sep 2026).

  Root cause — AIPredictionPresenter::calculateSpeedComparison() and an identical
  copy in RecommendationEngine split the history in half and took the AVERAGE OF
  THE CUMULATIVE PROGRESS of each half: avg(2,3,7) = 4, avg(15,25,38) = 26.
    · not a speed (a level, not a change);
    · not per week (inspections are monthly);
    · the real pace was +2.5 / month (Apr–Jun) and +11.5 / month (Jul–Sep).
  Second defect: RecommendationEngine's rule "recent speed < early speed →
  verify progress data" compared those cumulative averages. Cumulative progress
  never falls, so recent ≥ early always — the rule could NEVER fire.

  Nothing else read the values (no report, no AI prompt, no JS).

===============================================================================
  2. DECISION
===============================================================================

  User: "fix and improve this (on a different branch)" — option 1 of the two
  offered (fix vs remove the card).

===============================================================================
  3. WHAT WAS BUILT
===============================================================================

  app/Services/AI/ProgressPaceService.php (new, pure — no DB)
    · one inspection per month_label (last record of a month wins);
    · gaps between consecutive inspections, each gap's length = months between
      month_labels (a skipped month = a 2-month gap);
    · early window = first half of the gaps, recent = last half (the middle gap
      dropped when odd, so windows never share a gap);
    · pace = points gained ÷ months spanned, per window; needs ≥ 3 inspections;
    · direction: slowing_down (recent < 0.8 × early AND ≥ 1 point/month lower),
      speeding_up (> 1.2 × early AND ≥ 1 point/month higher), else steady;
    · required pace = (100 − current) ÷ months to the planned end date
      (30.44-day months, DateHelper::now()); finish_status on_pace /
      behind_pace / past_deadline / complete / no_end_date.
  AIPredictionPresenter + RecommendationEngine: both old copies REMOVED; both call
    the service. early_speed / recent_speed keys kept (now points/month); the full
    result is under 'pace'. Category-baseline projects (no real data) get none.
  RecommendationEngine rules:
    · monitoring (fixed): pace slowing_down → "Progress has slowed — verify on
      site and ask the contractor what is holding the work back"; reason names
      both paces and windows. Old DECELERATING-variance condition dropped — the
      pace drop itself is the signal.
    · corrective (new, priority 2): finish_status behind_pace AND recent pace
      ≥ 2 points/month AND ≥ 25 % below the required pace → "Ask the contractor
      for a catch-up plan — the recent pace will not finish the work on time".
  View (tabs/ai-prediction.blade.php): card renamed "Progress Pace — actual
    progress gained per month"; tiles EARLY PACE, RECENT PACE (each with its
    months), NEEDED TO FINISH ON TIME (+ months left); a Speeding up / Slowing
    down / Steady badge; a finish line (enough / not enough / end date passed /
    100 %); a note when fewer than 3 inspections.
  Live #142 now reads: early +2.5 / month (Apr–Jun 2026), recent +11.5 / month
    (Jul–Sep 2026), speeding up; needed pace depends on its planned end date.

===============================================================================
  4. TESTS
===============================================================================

  · tests/Unit/Services/AI/ProgressPaceServiceTest.php (9): #142's real history,
    slowing, tiny change = steady, skipped month, odd middle gap, < 3
    inspections, duplicate month, past deadline / complete, year-crossing labels.
  · tests/Unit/Services/AI/RecommendationPaceRuleTest.php (4): slowing rule fires
    (impossible under the old code) / not on steady; catch-up rule fires / not
    when the pace is enough.
  · AIPredictionPresenterTest +1 (3,5,7,9,11 → 2 / month, was 5 and 9).
  · tests/Feature/ProgressPaceCardTest.php (2): the page renders the card with
    +2.5% / +11.5%, "NEEDED TO FINISH ON TIME", "Speeding up", and no "%/week".
  · Full suites: PHP 1358 passed / 0 failed; JS 279 / 0.

===============================================================================
  5. BROWSER CHECK — ✅ DONE 2026-09-24
===============================================================================

  Confirmed working by the user on 2026-09-24. The three things checked:

  1. Project #142 → AI prediction panel: EARLY PACE +2.5% / month (Apr–Jun 2026),
     RECENT PACE +11.5% / month (Jul–Sep 2026), "Speeding up", the needed pace
     and the finish line.
  2. A project with fewer than 3 inspections: the note instead of paces.
  3. Narrow window (phone width): the three tiles wrap, nothing overflows.

  ⚠ THIS WAS THE ONLY THING THAT COULD CLOSE THIS WORK. The suites were green
  before the check and said nothing about whether the card reads correctly on a
  real project — a pace service can be arithmetically right and still render a
  card nobody can act on.

===============================================================================
  6. SESSION LOG
===============================================================================

  2026-09-22 — investigated, fixed and tested on phase-43 (this file).

  2026-09-24 — BROWSER-CONFIRMED by the user, then MERGED into develop. phase-43
    was merged into phase-44 (--no-ff, 62feebf) before phase-44 went to develop,
    so ONE merge carried both. ⚠ The restudy plan's §0-A called the two branches
    "siblings, not stacked"; that was written before 62feebf and is now corrected
    there. Logged in textfiles/pilot_version_changelog.md as A-2 (✅).
    Deployment impact unchanged: no migration, no .env key, no dependency —
    3 PHP + 1 Blade, a file copy.
