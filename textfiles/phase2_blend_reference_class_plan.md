# Phase 2 — Reference-Class Blend (Summary for Review)

**Status:** Implemented, all 635 tests passing (2,394 assertions).
**Decision 8:** the blend applies to ALL projects with ≥2 real observations and a usable category class — not just sparse ones. It replaces the old "2..threshold" supplement path.

---

## 1. What the blend does (plain English)

A project's next-month prediction used to come from **only its own** progress curve. Now, when a usable group of similar *completed* projects exists, the prediction is **pulled toward the group's typical curve** (a Bayesian-style "shrink toward the mean").

- **More own data → trust your own curve more.**
- **Less own data → lean on similar projects more.**

The mix is controlled by a weight: `w = obs / (obs + 4)`

| Real observations | w (own data) | Class influence |
|---|---|---|
| 1 | 0.20 (not blended — hybrid path) | — |
| 4 | 0.50 | 50/50 |
| 6 | 0.60 | 40% |
| 20 | 0.83 | ~17% |

---

## 2. What changes in the prediction output

1. **`data_source` becomes `blended`** (was `project_history`), so the UI can say so honestly.
2. **Next-month point** = `w·own + (1−w)·classMedian` (§5.3).
3. **Next-month band** = the fit-margin band **widened** to the class P25/P75 of the same month (§5.4) — an honest range, not a false-precision point.
4. **Forecast tail** (all future months) blended the same way via `ForecastService`.
5. **Reliability score** shrinks when the class is small or its band is wide (§5.5).

---

## 3. P25 / P75 (what that "band" is)

For a given month, line up similar projects by their % complete. The "middle half" of the group:

- **P25** — 25% of similar projects finished *below* this, 75% *above* (the lower edge).
- **P50 / median** — the typical value in the middle.
- **P75** — 75% finished *below*, 25% *above* (the upper edge).

Narrow band = similar projects behave alike → tighter range.
Wide band = they act differently → the range is honestly wide.

---

## 4. The `data_source` types (one-stop reference)

| Value | Meaning | Wording in UI |
|---|---|---|
| `project_history` | Only this project's own real data | raw fit wording |
| `hybrid` | 1 real obs + borrowed points | "Prediction supplemented with data from N similar project(s) (M additional data point(s))" |
| `similar_projects` | 0 real obs, pure category baseline | "Category baseline estimate based on N similar completed project(s) — not a statement about this project's own history" |
| `blended` | **NEW** — own data (≥2 obs) mixed with class curve | "Prediction blended with category reference curve (N similar project(s), weight 0.60)" |

The disclaimer text is written per source so a user is never misled about where a number came from.

---

## 5. Reliability shrink (§5.5)

When blended, the evidence-based reliability score gets a *discount* multiplier:

| Condition | Multiplier |
|---|---|
| class size < 3 | ×0.80 |
| class size 3–4 | ×0.90 |
| band spread > 25% | ×0.85 |
| band spread 15–25% | ×0.92 |

Multipliers combine; floored at 0.60. Never increases the score.
Exposed in the response as `result['blend']` when the blend is active.

---

## 6. Files touched

| File | Change |
|---|---|
| `app/Services/AI/HistoricalSimilarityService.php` | Bug fix: kNN aggregation closure now binds `$project` (`use ($percentiles, $project)`). |
| `app/Services/AI/AIPredictionService.php` | `buildBlendContext()`, blended `predictNextMonth`, band widening, blend info into regression meta + disclaimer. |
| `app/Services/AI/ForecastReliabilityService.php` | §5.5 blend shrink in `finalize()`. |
| `tests/Unit/Services/AI/ForecastReliabilityServiceTest.php` | +5 tests for the shrink. |
| `tests/Feature/Services/AI/CrossProjectSupplementIntegrationTest.php` | Threshold test made deterministic (no-class path) + new positive `blended` test. |

---

## 7. Test coverage

- `ForecastReliabilityServiceTest` — 27 pass (shrink: small class, wide band, combined, healthy class, absent blend).
- `CrossProjectSupplementIntegrationTest` — 10 pass (including `phase2 blend applies when class matches`, `threshold reached returns none`).
- Full suite — **635 passed**, 0 failures.

---

### Review checklist
- [ ] Blend only activates for own real data (≥2 obs) + usable class (≥3 usable curves, similarity gate).
- [ ] Hybrid / similar_projects (0-1 obs) behavior untouched.
- [ ] Band widens, never wrongly narrows.
- [ ] Reliability shrink is honest and bounded.
- [ ] Disclaimer wording matches each `data_source`.