# Cross-Project Intelligence — Industry Research & Gap Analysis

CSPC PMU System — August 24, 2026

**Purpose:** Document how cross-project data is used in industry PM systems
and compare against our current implementation. Used to inform future phases
(Phase 9 contractor tracking, Phase 10 AI recommendations, and potential
portfolio analytics).

---

## 1. CURRENT STATE — How Cross-Project Data Is Used Today

### 1.1 What Exists (post-v2.6)

| Effect | Component | How It Works |
|--------|-----------|-------------|
| Forecast blending | `ForecastService`, `AIPredictionService::predictNextMonth()` | Every forecast point = `w × own(t) + (1-w) × classMedian(t)` where `w = obs/(obs+4)`. 4 obs → 50/50; 20 obs → 95% own. |
| Next-month prediction blend | `AIPredictionService::predictNextMonth()` | Same formula — pulls prediction toward class median. |
| Prediction band widening | `AIPredictionService::computeNextMonthBand()` | Band = union of fit-margin band and class P25/P75 band. |
| Percentile benchmark (B5) | `AIPredictionService::buildClassStanding()` | "Your progress is in the Nth percentile of similar completed projects." |
| At-risk anomaly flag (B6) | `AIPredictionService::buildClassStanding()` | Actual below class P25 for 2+ consecutive months → flag. |
| B6 feeds RecommendationEngine | `RecommendationEngine::assessCorrective()` | "Progress has trailed similar projects for N months — investigate." |
| Completion sanity fallback (A2) | `CompletionEstimationService::estimateFromClassReference()` | When polynomial gives implausible completion, uses class-derived estimate. |
| Reliability shrink | `ForecastReliabilityService::blendShrink()` | Discount reliability when class data is used (small class ×0.80, wide band ×0.85). |
| Dataset for sparse projects | `HistoricalSimilarityService::generateFallbackDataset()`, `generateSupplementDataset()` | 0 obs → full synthetic; 1 obs → hybrid. |

### 1.2 What Does NOT Exist Yet

| Gap | Impact |
|-----|--------|
| No contractor performance scoring from cross-project history | Cannot answer "which contractor is most reliable?" with data |
| No project-type risk patterns | Cannot detect "Type X projects with N issues by Month M usually need extension" |
| No recommendation outcome feedback loop | No record of whether "behind similar projects" rec was followed or correct |
| Gemini prompt doesn't know data is blended | AI analysis text doesn't disclose cross-project data origin |
| No similar-project comparison table in UI | Breakdown logged to console.log only, not visible to users |
| No completion date range from class | Completion date is single number, not class-derived range |
| No industry benchmarks (CII-style) | Only internal peer comparison, not industry-wide norms |
| No portfolio-level health dashboard | No aggregated cross-project view for program managers |

---

## 2. INDUSTRY PRACTICE — How Other Systems Use Cross-Project Data

### 2.1 Portfolio-Level Dashboards

**Procore Analytics** — "Turn your project information into meaningful, actionable
insights that will help meet your business goals." Cross-project reporting with
unified data across all projects. Traffic-light status, drill-down to detail.

**Oracle Primavera Analytics** — "Understand performance and trends across all your
projects and portfolios." Prebuilt analytics dashboards, exception-based reporting,
root-cause analysis from portfolio view → project → activity.

**Matrak** — "Standardised performance indicators across all jobs make it easy to
spot risk early and report clearly." AI-powered portfolio dashboard pulls and
standardises data from every project.

**ProjectTeam.com** — "Cross-project dashboards give program managers real-time
visibility across the full portfolio." Connected data model where every project
feeds the same reporting layer.

### 2.2 Contractor/Vendor Performance Scoring

**Procore Vendor Performance Report** — "Detailed insights into the performance of
vendors and subcontractors involved in construction projects." Helps project managers
assess and manage vendor performance across quality, schedule, and budget.

**CII Benchmarking** — Standard metrics submitted to a data warehouse for peer
comparison. Covers cost, schedule, safety, and execution data.

**Industry practice:** On-time delivery %, average delay days, quality scores,
completed projects count — all computed historically per contractor.

### 2.3 Benchmark Comparison

**Procore Benchmark Analysis** — "Compare one or more projects against a benchmark
set of data categorized by project type, office, square footage, estimated value."
Helps determine "if an ongoing project is producing data in a way similar to past
projects of the same type."

**Procore Construction Benchmarks** — "Benchmarks are a stepping stone towards
predictive analytics that will enable customers to know both where they stand and
how they can take specific actions." Project budget is directly compared to similar
budgets; ML models tag RFIs by trade for trade-level benchmarking.

**MeridianIQ** (open-source) — "Benchmark Database — anonymized cross-project
percentile comparison" + "Duration Prediction — RF+GB ensemble trained on benchmark
data." Uses completed project patterns to predict outcomes for new projects.

### 2.4 Root-Cause Analysis & Drill-Down

**Oracle Primavera Analytics** — "Insight to Action" capability: drill from
portfolio view → project → activity to find root cause. Exception-based reporting
flags projects that deviate from norms.

**PTAG CII Benchmarking** — Data collected during execution (not reconstructed
afterward). Forms routed to the right team members at the right stage.

### 2.5 Predictive Analytics from Patterns

**nPlan** — "Cross-project aggregation for portfolio level comparisons and trend
monitoring when projects share the same reporting template." Converts structured
daily inputs into forecast-ready dashboards.

**BuiltWorlds 2026 Benchmark** — "87% of organizations report using document
management solutions on every project." AI capabilities that work with the
platform's data and configuration are now a baseline expectation.

**Seamview** — "Built-in best practices from analyzing thousands of megaprojects.
Automated benchmarking, predictive analytics, and strategic recommendations that
typically require million-dollar consulting engagements."

### 2.6 Industry Maturity Model (US Tech Automations 2026)

| Stage | Name | Cross-Project Capability |
|-------|------|------------------------|
| 1 — Ad Hoc | Spreadsheets, email | None |
| 2 — Documented | Single PM tool | Basic project-level reporting |
| 3 — Connected | PM + accounting integration | Cross-project data exchange |
| 4 — Automated | Cross-system workflow engine | Event-triggered cross-project alerts |
| 5 — Optimized | Predictive analytics, AI assist | Cross-project pattern detection, self-tuning |

Most mid-market firms are at Stage 2-3. Our system is at Stage 3 (Connected) with
elements of Stage 4 (automated alerts via notification system) and early Stage 5
(AI prediction + cross-project regression).

---

## 3. GAP ANALYSIS — Our System vs Industry

| Industry Capability | Our System | Status | Relevant Phase |
|---------------------|------------|--------|----------------|
| Portfolio health dashboard | Admin dashboard (partial) | ⬜ Missing | Future (not in V2 scope) |
| Contractor performance metrics | Manual 1-5 rating only | ⬜ Missing | **Phase 9** |
| Industry benchmarks (CII-style) | None | ⬜ Missing | Future (needs external data) |
| Root-cause drill-down | Partial (activity carryovers) | ⬜ Partial | Future |
| Recommendation feedback loop | None | ⬜ Missing | **Phase 10** foundation |
| Cross-project risk pattern detection | B6 (2+ months behind) | ✅ Basic | Enhanced by Phase 9/10 |
| Forecast blending with class reference | Full blend pipeline | ✅ Done | v2.6 |
| Percentile benchmark vs peers | B5 tier system | ✅ Done | v2.6 |
| Completion fallback from class data | A2 sanity fallback | ✅ Done | v2.6 |
| Cross-project data in AI prompt | Not disclosed | ⬜ Gap | Should be in Phase 10 |
| Similar projects table in UI | Console.log only | ⬜ Missing | Low priority |
| Prediction accuracy by data source | C7 dev view | ✅ Done | v2.6 |

---

## 4. RECOMMENDATIONS FOR FUTURE PHASES

### Phase 9 (Contractor Performance Tracking) — HIGH PRIORITY
Add: on-time delivery %, avg delay days, completed projects count.
This is the most impactful missing capability — directly comparable to
Procore's Vendor Performance Report.

### Phase 10 (AI-Powered Recommendations) — HIGH PRIORITY
Add: Gemini-generated recs that consume contractor performance, issue
categorization, extension reasons, and cross-project patterns.
Key: disclose cross-project data origin in the AI analysis text.

### Portfolio Dashboard — MEDIUM PRIORITY (future)
Add: aggregated cross-project health view for admin/program managers.
This is a major feature (Stage 4→5 on the maturity model) but would
be a capstone-worthy addition.

### Industry Benchmarks — LOW PRIORITY (needs data)
Add: CII-style standard metrics. Requires either external data import
or seeded industry averages by project type. Deferred until the system
has enough internal data for meaningful comparison.

---

## 5. SOURCES

- Procore Analytics: https://www.procore.com/platform/analytics
- Procore Benchmarks: https://www.procore.com/blog/procore-benchmark
- Procore Vendor Performance: https://support.procore.com/.../vendor-performance
- Oracle Primavera Analytics: https://www.oracle.com/construction-engineering/primavera-analytics/
- Matrak Portfolio Dashboard: https://matrak.com/portfolio-dashboard/
- ProjectTeam.com: https://blog.projectteam.com/.../construction-project-portfolio-management-systems
- MeridianIQ (open-source): https://github.com/VitorMRodovalho/meridianiq
- nPlan: referenced in Gitnux 2026 Buyer's Guide
- PTAG CII Benchmarking: https://ptaginc.com/.../ptag-benchmarking-autodesk-integration/
- BuiltWorlds 2026 Benchmark: https://builtworlds.com/.../2026-annual-project-software-benchmarking-report/
- Seamview: https://www.seamview.com/
- US Tech Automations Maturity Model: https://ustechautomations.com/.../construction-automation-benchmark-report-2026

---

*Document created August 24, 2026. Update as new phases are implemented.*
