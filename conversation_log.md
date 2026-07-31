# Conversation Log — PMU System Development

> **Project:** PROJ-TRACK (PMU System)
> **Date:** July 30, 2026
> **Scope:** Email module, tracking, ratings

---

## 1. Initial Email Module Implementation

Created a complete email subsystem for sending AI-generated project reports to contractors.

### What was built:
- **Models & Migrations**: `EmailTracking` table with contractor/project/AI-solution relationships, tracking pixel support
- **Service**: `AIReportSendingService` with `sendToAllProjects()` (batch) and `sendForProject()` (single)
- **Controllers**: `Admin\EmailController`, `Staff\EmailController`, `Contractor\EmailController`
- **Views**: Admin/staff/contractor email inbox pages with search, pagination, modal preview
- **Mailable**: `AiAnalysisMail` — sends the AI analysis report as HTML email
- **Tracking pixel**: Route `GET /track/{token}` logs `sent_at`, `delivered_at`, `opened_at`, `is_read`, `open_count`
- **Scheduler**: `reports:send-ai-monthly` command scheduled daily at 08:00

### Key files:
- `database/migrations/2026_07_29_000001_create_email_trackings_table.php`
- `database/migrations/2026_07_30_000001_add_tracking_fields_to_email_trackings_table.php`
- `app/Services/AIReportSendingService.php`
- `app/Http/Controllers/Admin/EmailController.php`
- `app/Http/Controllers/Staff/EmailController.php`
- `app/Http/Controllers/contractor/EmailController.php`
- `app/Mail/AiAnalysisMail.php`
- `resources/views/emails/ai-analysis.blade.php`
- `resources/views/admin/email.blade.php`
- `resources/views/staff/email.blade.php`
- `resources/views/contractor/email.blade.php`

---

## 2. Fix: Email Duplicate Records

**Problem:** Clicking "Send Now" multiple times created duplicate `EmailTracking` rows (same `ai_solution_id` + `contractor_id`).

**Fix (two layers of protection):**

| Layer | Location | What it does |
|-------|----------|-------------|
| Service check | `AIReportSendingService.php:67-77` | Before creating a record, checks if one already exists for `(ai_solution_id, contractor_id)` |
| DB constraint | `2026_07_30_031232_...` migration | Adds `UNIQUE (ai_solution_id, contractor_id)` after deduplicating existing rows |

**Result:** Clicking "Send Now" multiple times now skips already-sent projects with "Already sent — skipped".

---

## 3. Email Enhancements

### 3a. Send 1-by-1 or All
- **Admin sidebar** added "Send to Project" section with project dropdown + "Send to Selected" button
- New route: `POST /admin/email/send-one` → `sendOne()` → `sendForProjectById()`

### 3b. Auto-send Based on Project Creation Date
- Rewrote `SendMonthlyAiReports.php`: instead of a fixed 30-day interval, each project sends on the monthly anniversary of its `created_at` date
  - Project created June 30 → sends July 30, Aug 30, etc.
  - If the day exceeds the current month's length, uses the last day
- Removed the `auto_send_interval_days` setting from the UI

### 3c. Email Header Image
- `ai-analysis.blade.php` header matches the other email templates: uses Google Drive image via `<td background="https://drive.google.com/uc?export=view&id=1H4iWVbIjMo4syTlfl8y9kXlCRtyQFCTm">`

### 3d. Auto-send on Regeneration
- `ProjectController::regenerateAnalysis()` now calls `sendForProject()` after successful AI solution creation
- Frontend JS in `ai-prediction.blade.php` shows email status inline (e.g., "Sent to contractor@email.com")

---

## 4. Fix: Email Tracking Not Working

### Problems identified:
1. **`display:none` on tracking pixel** — Email clients (Gmail, Outlook) strip or block hidden `<img>` elements as an anti-tracking measure
2. **`delivered_at` never set** — The tracking pixel at `http://127.0.0.1:8000/track/{token}` is unreachable from webmail (Gmail proxies image requests through their servers; `127.0.0.1` resolves to their localhost, not yours). The contractor `show()` method only updated `opened_at`/`is_read`, never `delivered_at`
3. **Data verified**: Records 21-23 had `sent_at` set but `delivered_at`/`opened_at` all null — pixel never fired

### Fixes applied:
| Issue | File | Change |
|-------|------|--------|
| Hidden pixel | `ai-analysis.blade.php:110` | Replaced `display:none` with `width:1px;height:1px;border:0;margin:0;padding:0;outline:none;` |
| Missing delivered_at | `contractor/EmailController.php:54-59` | Now sets `delivered_at` alongside `opened_at` when contractor opens email in-system |
| Unused CSS | `ai-analysis.blade.php` | Removed `.tracking-pixel` class |

---

## 5. AI-Powered Rating System

**Problem:** The rating page (`admin.rating`) was a static HTML mockup with hardcoded contractor names and static AI text.

**Solution:** Full dynamic rating system backed by the actual AI analysis data.

### New artifacts:
| File | Purpose |
|------|---------|
| `database/migrations/2026_07_30_035145_create_contractor_ratings_table.php` | Stores `(contractor_id, project_id, ai_solution_id, rating 1-5, ai_review_text, created_by)` |
| `app/Models/ContractorRating.php` | Model with `contractor()`, `project()`, `aiSolution()`, `createdBy()` relationships |
| `app/Http/Controllers/Admin/RatingController.php` | Controller with 3 methods |

### Controller methods:
- **`index()`** — Loads all contractors with their projects (Ongoing/Completed) and existing ratings
- **`suggest()`** (AJAX) — Takes `project_id`, reads the latest `AiSolution.regression_snapshot`, computes a suggested rating + generates an AI review sentence
- **`store()`** (AJAX) — Saves/updates the rating via `updateOrCreate`

### AI rating logic (`computeRating()`):
Uses `variance`, `delay_percentage`, and model `R²` from the regression snapshot:

| Rating | Criteria |
|--------|----------|
| 5 ★ | Variance ≥ 0%, delay ≤ 0%, R² > 0.7 |
| 4 ★ | Variance ≥ -10%, delay ≤ 15%, R² > 0.5 |
| 3 ★ | Variance ≥ -20%, delay ≤ 30% |
| 2 ★ | Variance ≥ -35%, delay ≤ 50% |
| 1 ★ | Anything worse |

### Routes:
| Method | URL | Handler |
|--------|-----|---------|
| GET | `/admin/rating` | `RatingController::index` |
| POST | `/admin/rating/suggest` | `RatingController::suggest` (AJAX) |
| POST | `/admin/rating/store` | `RatingController::store` (AJAX) |

### View features:
- Dynamic table rows from DB (not hardcoded)
- Rate button → star modal → save via AJAX
- AI button → calls `suggest()` → displays computed rating + generated review in right panel
- Leaderboard shows top 3 rated contractors dynamically
- Unrated contractors show "Not yet rated"

---

## Complete File Inventory

### New files created:
| File | Session |
|------|---------|
| `database/migrations/2026_07_30_031232_add_unique_constraint_to_email_trackings.php` | 2 |
| `database/migrations/2026_07_30_035145_create_contractor_ratings_table.php` | 2 |
| `app/Models/ContractorRating.php` | 2 |
| `app/Http/Controllers/Admin/RatingController.php` | 2 |
| `conversation_log.md` | 2 |

### Files modified:
| File | Change |
|------|--------|
| `app/Services/AIReportSendingService.php` | Added duplicate check + `sendForProjectById()` |
| `app/Http/Controllers/Admin/EmailController.php` | Added `sendOne()`, pass `$projects` to view |
| `app/Http/Controllers/ProjectController.php` | Auto-send on regeneration |
| `app/Http/Controllers/contractor/EmailController.php` | Set `delivered_at` on open |
| `app/Console/Commands/SendMonthlyAiReports.php` | Project-date-based scheduling |
| `routes/web.php` | Added `send-one`, rating routes + imports |
| `resources/views/admin/email.blade.php` | Added "Send to Project" sidebar section |
| `resources/views/admin/rating.blade.php` | Rewritten with DB data + AI |
| `resources/views/emails/ai-analysis.blade.php` | Google Drive header image, fixed pixel |
| `resources/views/projects/project_detail/components/tabs/ai-prediction.blade.php` | Show email status after regeneration |
