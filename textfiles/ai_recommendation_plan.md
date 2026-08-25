# Phase 10 — AI-Powered Recommendations

## 1. OBJECTIVE

Replace the rule-based `RecommendationEngine` with AI-generated recommendations
that leverage contractor performance history, issue severity, regression data,
and cross-project patterns for more nuanced, context-aware suggestions.

## 2. PREREQUISITES

- Phase 9 — Contractor Performance Tracking (for contractor context)
- Issue categorization (V1) — `issue_type`, `severity` fields exist
- Extension reasons — available from `project_extensions` table
- Cross-project data — Phase 8 regression thread complete

## 3. ARCHITECTURE

### 3.1 New: `app/Services/AI/AIRecommendationService.php`

Calls Gemini API with a structured prompt containing all project context.
Parses the AI response into the existing recommendation format:
`{ message, category, reason, priority }`.

**Prompt includes:**
- Full prediction report (regression, forecast, assessment, risk level, trend)
- Open issues with type and severity breakdown
- Contractor performance summary (rating, on-time %, avg delay)
- Previous extension reasons
- Project type, category, progress gap, days remaining

**Fallback:** If AI fails (timeout, bad key, malformed response), returns
empty array → `RecommendationEngine` falls back to rule-based logic.

### 3.2 Modified: `app/Services/AI/RecommendationEngine.php`

Add AI-first logic to `generate()`:
1. Call `AIRecommendationService::generate()`
2. If AI returns valid recommendations → use them
3. If AI fails → fall back to current rule-based logic (unchanged)

The rule-based methods (`assessEscalation`, `assessCorrective`, etc.)
remain untouched — they serve as the fallback path.

### 3.3 No UI changes

Same recommendation format, same Blade display. The AI simply produces
better, more contextual recommendations within the same data structure.

## 4. FILES TO CREATE/MODIFY

| File | Action | Purpose |
|------|--------|---------|
| `app/Services/AI/AIRecommendationService.php` | CREATE | Gemini prompt + parse |
| `app/Services/AI/RecommendationEngine.php` | MODIFY | AI-first with rule fallback |
| `textfiles/ai_recommendation_plan.md` | CREATE | This plan |
| `tests/Unit/Services/AI/AIRecommendationServiceTest.php` | CREATE | Unit tests |
| `tests/Feature/Services/AI/AIRecommendationIntegrationTest.php` | CREATE | Integration tests |

## 5. TESTING

- Test with valid AI response → recommendations processed correctly
- Test with AI failure → fallback to rule-based recs
- Test with empty AI response → fallback
- Test with malformed AI JSON → fallback
- Verify recommendation format matches existing Blade expectations
- Verify category/color mapping works with AI-generated categories

## 6. OUT OF SCOPE

- Recommendation outcome feedback loop (future enhancement)
- Activity-level critical path awareness (requires dependency mapping)
- Budget/financial data integration (not tracked in system)
