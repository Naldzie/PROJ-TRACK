# Phase 10 — AI-Powered Recommendations

CSPC PMU System — AI Module V2

Status: 📋 PLAN (awaiting approval)
Branch: `ai/phase10-ai-recommendations` (from develop)
Date: August 25, 2026
Plan file: textfiles/ai_recommendation_plan.md

Reference:
- textfiles/active_reference/ai_module_ver2_master_plan.txt §Phase 10
- textfiles/active_reference/AI_MODULE_HANDOVER.md §4
- textfiles/ai_recommendation_data_gaps.txt (data gap analysis)
- textfiles/cross_project_industry_research.md (industry benchmark data)

---

## 1. OBJECTIVE

Add an AI-powered recommendation layer on top of the existing rule-based
`RecommendationEngine`. The AI generates context-aware recommendations
using Gemini, leveraging contractor performance history, issue severity
breakdown, regression data, extension patterns, and cross-project patterns.

**Design principle:** AI-first with rule-based fallback. The rule engine is
the safety net — when AI is unavailable (API failure, bad key, timeout,
malformed response), the existing rule-based logic takes over unchanged.

**Why not replace rules entirely?**
- AI availability is not guaranteed (API outages, key rotation, network)
- Rules are deterministic and auditable — valuable for compliance
- Industry norm (SP4N, Zepth, Foretrack) is structured scoring + AI context
- Rules are the baseline; AI adds nuance on top

**Future enhancement (Phase 11+):** Improve the rule-based engine itself
with weighted scoring, temporal patterns, and issue velocity — so the
fallback path is also stronger. This is deferred to keep Phase 10 focused.

---

## 2. INDUSTRY RESEARCH SUMMARY

Research conducted on 8 commercial construction PM AI systems:
FactualIQ, ConstructINS, Kaël, SP4N, Zepth Rx, Foretrack AI,
Constructer.ai, SmartPMO.

### What other systems feed into their recommendation engines:

| Data Category | Systems Using It | Our Status |
|---|---|---|
| Schedule progress (planned vs actual) | All 8 | ✅ Have |
| Risk level + trend direction | All 8 | ✅ Have |
| Issue categorization (type, severity) | 7/8 | ✅ Have |
| Contractor/subcontractor performance history | 6/8 | ✅ Have (Phase 9) |
| Extension/claim history + patterns | 5/8 | ✅ Have |
| Cross-project portfolio benchmarks | 6/8 | ✅ Have (Phase 8) |
| Carryover/deferral tracking | 4/8 | ✅ Have |
| Prediction accuracy (backtesting) | 3/8 | ✅ Have |
| Cost/budget variance | 7/8 | ❌ Not tracked |
| Weather impact | 4/8 | ❌ Not tracked |
| RFI/submittal velocity | 3/8 | ❌ Not tracked |
| Activity-level critical path / float | 5/8 | ❌ Not tracked |
| Material/supplier lead times | 3/8 | ❌ Not tracked |

### Key takeaways:

1. **Rule-first + AI-enhance is the industry norm.** SP4N, Zepth, Foretrack
   all use structured scoring engines as the backbone, with AI adding
   context and narrative. Our rule-based engine is well-aligned.

2. **Recommendation quality comes from differentiated issue inputs, not
   from AI rephrasing.** "3 material issues" vs "3 contractor issues"
   need different recommendations. Our rules already do this.

3. **Contractor performance history is a major differentiator.** Foretrack's
   "GC & Trade Scorecards" and Kaël's "Contractor performance tracking"
   both feed historical completion data into recommendations. Phase 9
   closes this gap for our system.

4. **Feedback loops are rare in practice.** Only SP4N and Zepth mention
   tracking recommendation outcomes. Confirms this is a future enhancement.

5. **Financial data is the #1 missing input across the industry**, but our
   system is a PMU (project monitoring), not a financial system — budget
   integration is out of scope.

---

## 3. PREREQUISITES

- Phase 9 — Contractor Performance Tracking ✅ DONE
- Issue categorization — `issue_type`, `severity` fields exist ✅
- Extension reasons — available from `project_extensions` table ✅
- Cross-project data — Phase 8 regression thread complete ✅
- Gemini API key configured — `config/services.php` ✅

---

## 4. ARCHITECTURE

### 4.1 New: `app/Services/AI/AIRecommendationService.php`

Calls Gemini API with a structured prompt containing all project context.
Parses the AI response into the existing recommendation format:
`{ message, category, reason, priority }`.

**Data inputs to the prompt:**

| Input | Source | Purpose |
|---|---|---|
| Risk level + trend | `prediction['assessment']` | Core risk context |
| Regression fit quality + R² | `prediction['regression']` | Forecast confidence context |
| Current progress + expected progress | `prediction['current_state']` + computed | Progress gap |
| Days remaining | Computed from project dates | Urgency |
| Open issues by type + severity | `issues()` query | Differentiated issue context |
| Issue aging (30+ days open) | `issues()` query | Resolution velocity |
| Issue resolution rate (last 30d) | `issues()` query | Management effectiveness |
| Contractor performance rating | `prediction['contractor_performance_rating']` | Contractor reliability |
| Contractor on-time % + avg delay | `prediction['contractor_on_time_pct']`, `['contractor_avg_delay']` | Contractor track record |
| Extension count + reasons | `project_extensions` table | Pattern detection |
| Extension reason breakdown | `project_extensions` grouped by `reason_category` | Root cause context |
| Category performance comparison | Computed from contractor's other projects in same category | Relative performance |
| Carryover count + consecutive | `activities()` + `activityCarryovers()` | Systematic deferral |
| Class standing (B5/B6) | `prediction['class_standing']` | Peer benchmark |
| Prediction history accuracy | `prediction_history` backtest | Forecast trust signal |

**Prompt structure (industry-informed):**

```
You are a construction project management analyst. Given the following
project data, generate 3-6 prioritized, actionable recommendations.

PROJECT CONTEXT:
- Title, status, category, progress, days remaining
- Risk level, trend, regression quality

CONTRACTOR PERFORMANCE:
- Rating, on-time %, avg delay, completed project count
- Performance relative to other contractors in same category

ISSUES:
- Open count by type (material, contractor, weather, design, regulatory, financial)
- Severity breakdown (Critical, High, Medium, Low)
- Aging issues (30+ days)
- Resolution velocity (resolved last 30 days)

EXTENSIONS:
- Total count, reason breakdown by category
- Pattern: accelerating, decelerating, or stable

CROSS-PROJECT CONTEXT:
- Class standing tier, peer comparison
- Carryover activities (systematic deferrals)

OUTPUT FORMAT:
Return a JSON array. Each item:
{
  "message": "Actionable suggestion (1-2 sentences)",
  "category": "escalation|corrective|monitoring|administrative",
  "reason": "Why this applies (data-backed)",
  "priority": 1-5
}

Rules:
- Max 6 recommendations
- Priority 1 = urgent (Critical risk, imminent deadline miss)
- Priority 2 = important (High risk, contractor performance concerns)
- Priority 3 = notable (Moderate risk, pattern detection)
- Priority 4 = informational (Low risk, best practices)
- Priority 5 = routine (admin, data quality)
- Each recommendation MUST reference specific data from the project
- Do NOT repeat information — each recommendation must be unique
- Do NOT mention AI or that this is AI-generated
```

**Response parsing:**
1. Extract JSON array from Gemini response (handle markdown code fences)
2. Validate each item has required fields (`message`, `category`, `reason`, `priority`)
3. Validate `category` is one of: `escalation`, `corrective`, `monitoring`, `administrative`
4. Validate `priority` is integer 1-5
5. Clamp array to max 6 recommendations
6. Sort by priority ascending

**Fallback:** If AI fails at any point (timeout, bad key, empty response,
malformed JSON, validation failure) → return empty array.

### 4.2 Modified: `app/Services/AI/RecommendationEngine.php`

Add AI-first logic to `generate()`:

```php
public function generate(Project $project, array $prediction): array
{
    // [existing guards: completed, no observations, etc.]

    // NEW: Try AI-powered recommendations first
    $aiRecs = $this->aiRecommendationService->generate($project, $prediction);
    if (!empty($aiRecs)) {
        return $aiRecs;
    }

    // Fallback: existing rule-based logic (unchanged)
    $factors = $this->extractFactors($project, $prediction);
    // [rest of existing logic unchanged]
}
```

The rule-based methods (`assessEscalation`, `assessCorrective`,
`assessMonitoring`, `assessAdministrative`) remain **untouched** —
they serve as the fallback path.

**Constructor change:** Add `AIRecommendationService` dependency.

### 4.3 No UI changes

Same recommendation format (`{ message, category, reason, priority }`),
same Blade display, same category colors. The AI produces better,
more contextual recommendations within the same data structure.

---

## 5. FILES TO CREATE/MODIFY

| File | Action | Purpose |
|------|--------|---------|
| `app/Services/AI/AIRecommendationService.php` | CREATE | Gemini prompt + parse + validate |
| `app/Services/AI/RecommendationEngine.php` | MODIFY | Add AI-first with rule fallback (constructor + generate method) |
| `textfiles/ai_recommendation_plan.md` | UPDATE | This plan |
| `tests/Unit/Services/AI/AIRecommendationServiceTest.php` | CREATE | Unit tests (mock Gemini) |
| `tests/Feature/Services/AI/AIRecommendationIntegrationTest.php` | CREATE | Integration tests |

---

## 6. TESTING

### Unit Tests (`AIRecommendationServiceTest`)

| Test | Description |
|---|---|
| `test_generate_returns_recommendations_on_valid_ai_response` | Mock Gemini → valid JSON → returns parsed recs |
| `test_generate_returns_empty_on_api_failure` | Mock Gemini → 500 error → returns `[]` |
| `test_generate_returns_empty_on_timeout` | Mock Gemini → timeout → returns `[]` |
| `test_generate_returns_empty_on_empty_api_key` | No key configured → returns `[]` |
| `test_generate_returns_empty_on_malformed_json` | Mock Gemini → invalid JSON → returns `[]` |
| `test_generate_handles_markdown_code_fences` | Gemini wraps in ```json...``` → still parses |
| `test_generate_validates_required_fields` | Missing `message` field → item filtered out |
| `test_generate_validates_category_values` | Invalid category → item filtered out |
| `test_generate_validates_priority_range` | Priority 0 or 6 → clamped to 1-5 |
| `test_generate_clamps_to_max_six` | AI returns 10 items → only 6 returned |
| `test_generate_sorts_by_priority` | Unsorted input → sorted output |

### Integration Tests (`AIRecommendationIntegrationTest`)

| Test | Description |
|---|---|
| `test_ai_recommendations_used_when_available` | Full pipeline with mocked AI → AI recs returned |
| `test_fallback_to_rules_when_ai_fails` | Full pipeline with AI failure → rule-based recs returned |
| `test_recommendation_format_matches_blade_expectations` | Output has all required keys |
| `test_category_color_mapping_works_with_ai_categories` | All 4 category colors resolve correctly |

---

## 7. IMPLEMENTATION SEQUENCE

1. Create `AIRecommendationService.php` with prompt builder, Gemini caller, response parser
2. Write unit tests for the service (mock Gemini HTTP)
3. Modify `RecommendationEngine.php` — add constructor dependency + AI-first path in `generate()`
4. Write integration tests (mock AI service, verify fallback)
5. Manual test: trigger AI recommendations on a real project with Gemini key
6. Plan file update + commit

---

## 8. OUT OF SCOPE (deferred to future phases)

- **Rule-based engine improvements** (Phase 11+): weighted scoring, temporal
  patterns, issue velocity trends, weighted escalation thresholds. The rules
  work well today; improvements are additive, not blocking.
- Recommendation outcome feedback loop (requires outcome logging table)
- Activity-level critical path awareness (requires dependency mapping)
- Budget/financial data integration (not tracked in system)
- Weather impact integration (not tracked in system)
- RFI/submittal velocity (not tracked in system)

---

## 9. DECISION LOG

| # | Decision | Rationale |
|---|---|---|
| 1 | AI-first with rule fallback, not rule replacement | AI availability not guaranteed; rules are auditable; industry norm |
| 2 | Max 6 recommendations (down from 8) | AI produces higher quality per rec; fewer but better |
| 3 | Same output format `{ message, category, reason, priority }` | No UI changes needed; backward compatible |
| 4 | Prompt includes contractor performance + extension patterns | Top differentiator vs rule-only (per industry research) |
| 5 | JSON parsing with markdown fence handling | Gemini commonly wraps JSON in code fences |
| 6 | Rule engine improvements deferred to Phase 11+ | Keep Phase 10 focused; rules are adequate today |
| 7 | No financial data in prompt | System doesn't track it; out of scope for PMU |
