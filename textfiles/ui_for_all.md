# UI For All — Rating Page Conversation Guide

**Saved:** 2026-09-07
**Scope:** everything implemented on the Contractor Rating page (`admin/rating`)
**Main file:** `resources/views/admin/rating.blade.php`
**Backend:** `app/Http/Controllers/Admin/RatingController.php`
**Route file:** `routes/web.php`

Read this top to bottom when you are ready to review or re-implement. Each
section is independent: WHAT it is, WHERE it lives, and the KEY CODE to copy.

> Project conventions used throughout (see `AGENTS.md`):
> - App UI CSS/JS for this page lives inline in the Blade file (the page
>   predates the `public/` convention) — new work follows the existing file.
> - Every behavioral change is logged in
>   `textfiles/bugs_and_behavior_adjustments_part2.md`.
> - Verify with: `php artisan test --filter=test_admin_rating_page_uses_shared_toast`

---

## 1. Contractor Overview AI Comment (select contractor → AI talks about ALL projects)

### What
When you click a contractor, the Analysis panel shows a score ring plus an
AI-style paragraph summarizing ALL of that contractor's projects: project
counts, average star rating, on-time %, average delay, extensions, trend
direction, and a closing recommendation.

### Where
- `RatingController::contractorOverview()` — new endpoint.
- `RatingController::generateContractorOverview()` — new private helper that
  builds the paragraph from metrics.
- Route: `POST /admin/rating/contractor-overview`
  (`admin.rating.contractor-overview`).
- Blade JS: `renderAnalysis()` fetches it, `paintOverview()` renders it,
  `overviewCache` object caches one comment per contractor id.

### Key code — route (`routes/web.php`, admin group)
```php
Route::post('/rating/contractor-overview', [RatingController::class, 'contractorOverview'])
    ->name('admin.rating.contractor-overview');
```

### Key code — controller
```php
public function contractorOverview(Request $request)
{
    $request->validate(['contractor_id' => 'required|integer|exists:contractors,id']);
    $contractor = Contractor::with(['projects' => function ($q) {
        $q->whereIn('status', ['Ongoing', 'Completed'])->with('aiSolutions');
    }])->findOrFail($request->contractor_id);

    $name = $contractor->company_name ?? $contractor->contractor_name
        ?? ($contractor->user->name ?? 'Contractor #' . $contractor->id);
    $total     = $contractor->projects->count();
    $completed = $contractor->projects->where('status', 'Completed')->count();
    $ongoing   = $contractor->projects->where('status', 'Ongoing')->count();
    $avgRating = ContractorRating::where('contractor_id', $contractor->id)->avg('rating');
    $ratedCount = ContractorRating::where('contractor_id', $contractor->id)->count();
    // + performance_rating, on_time_delivery_pct, avg_delay_days,
    //   total_extensions_count/total_extension_days, trend via
    //   ContractorPerformanceService::getPerformanceTrend()
    $comment = $this->generateContractorOverview(/* metrics above */);
    return response()->json(['comment' => $comment, 'metrics' => [/* ... */]]);
}
```

The paragraph builder (`generateContractorOverview()`) stacks sentences:
counts → star-rating tone (exceptional ≥ 4.5 / solid ≥ 3.5 / mixed ≥ 2.5 /
concerning) → on-time delivery → extension reliance → trend
(improving/declining/stable) → recommendation line. Tone thresholds are plain
`if/else` — tweak the numbers there to change the voice.

### Key code — Blade JS (fetch + cache + stale guard)
```js
const overviewCache = {};
// inside renderAnalysis(), after computing `score`:
if (overviewCache[c.id]) { paintOverview(c, score, overviewCache[c.id]); return; }
// render loading skeleton, then:
fetch('{{ route('admin.rating.contractor-overview') }}', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json',
             'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
  body: JSON.stringify({ contractor_id: c.id })
})
.then(r => r.json())
.then(data => {
  if (selectedId !== c.id) return;          // ignore stale responses
  overviewCache[c.id] = data.comment || 'No performance history available yet.';
  paintOverview(c, score, overviewCache[c.id]);
});
```
Cache is invalidated on rating save: `delete overviewCache[con.id]` inside
`updateProjectStars()` and `applyAISuggestion()`.

---

## 2. Per-Project AI Review (click AI review → analyzes THAT project)

### What
Every project row has an **AI review** button (no longer gated on having AI
regression data). Clicking it renders a card: project tag, headline,
full review paragraph, metric chips (rating / actual / planned / delay /
variance / R² / extensions / issues), Apply + Back-to-overview buttons.

### Where
- `RatingController::suggest()` — enhanced with a fallback path.
- `RatingController::generateReview()` — extended signature with project
  title, status, actual %, extension/issue context, model-confidence note.
- Blade JS: `suggestAI(projectId, key)`.

### Key code — controller behavior
- If an `aiSolution` exists: use `regression_snapshot` (variance, R²).
- If NOT: fall back to `variance = actual − planned`, `r_squared = null`,
  still compute a rating via `computeRating()` and return
  `'source' => 'fallback'` so the UI can show the "no regression model yet"
  note. Response always includes `project_title` + `details` array.
- Extension/issue counts come from `$project->extensions()` (approved) and
  `$project->issues()`.

### Key code — Blade button (always rendered)
```html
<button class="secondary blue ai-btn" data-key="${p.key}"
        data-project-id="${p.id}" data-title="${escHtml(p.title)}">
  ${orbHtml('sm')}AI review
</button>
```

---

## 3. AI Orb Avatar + Live Dot + Humanoid Robot

### What
A reusable AI avatar: gradient orb (blue → violet → mint), white humanoid
robot bust on top (antenna, head, ears, eyes with highlights, smile,
shoulders), floating animation, glow shadow, and a green **"."** live dot
with ping animation on its corner. Sizes: default 30px, `.sm` 18px,
`.lg` 52px.

### Where
- CSS block: `/* ── AI orb avatar (gradient bot image + live "." dot) ── */`
- Static HTML: Analysis panel header icon + empty-state illustration +
  page-head "AI online" pill.
- JS helper `orbHtml(size)` + `ORB_SVG` const for all dynamic HTML
  (buttons, overview comment, review card, loading states).

### Key code — helper
```js
const ORB_SVG = '<svg viewBox="0 0 24 24">...robot shapes...</svg><span class="dot"></span>';
function orbHtml(size) {
  return `<span class="ai-orb${size ? ' ' + size : ''}" aria-hidden="true">${ORB_SVG}</span>`;
}
```

### Key code — CSS skeleton (full rules are in the file, search `.ai-orb`)
```css
.ai-orb { position: relative; width: 30px; height: 30px; border-radius: 50%;
  background: conic-gradient(from 210deg, #7c9bff, #a78bfa, #5eead4, #7c9bff);
  display: grid; place-items: center; animation: orb-float 3.2s ease-in-out infinite; }
.ai-orb .dot { position: absolute; right: -1px; bottom: -1px; width: 10px; height: 10px;
  border-radius: 50%; background: #22c55e; animation: dot-ping 1.8s ease-out infinite; }
```
To reuse this avatar on another page: copy the CSS block, the robot SVG, and
the `orbHtml()` helper.

---

## 4. Auto-Rate All = Completed Projects Only

### What
Auto-rate skips Ongoing projects (their S-curve is still moving, so any
rating would be premature). Matches the manual Rate button, which is disabled
until a project is Completed.

### Where
`RatingController::autoRateAll()` — eager load changed to
`$q->where('status', 'Completed')` plus a docblock explaining why.

---

## 5. Full UI Polish Pass (visual-only)

### Tokens & primitives (`:root` + top of `<style>`)
- `--violet`, `--grad-brand` (blue→violet), `--shadow-lg`, `--ease-spring`.
- Keyframes: `rise` (entrance), `pop-in` (modal), `shine` (button sweep),
  `shimmer` (skeleton/hero), `pulse-dot`, `star-pop`, `glow-drift`.
- Utilities: `.rise` / `.rise-item` (use `backwards` fill so hover transforms
  keep working after the entrance finishes).
- `prefers-reduced-motion` block disables motion; JS helpers
  `prefersReducedMotion()` guard the count-ups.

### Component by component
| Area | Treatment |
|---|---|
| Page head | Gradient headline text, pill eyebrow with pulsing dot, ambient glow blob, `head-actions` wrapper, `.ai-online` pill, gradient `.primary` with shine sweep |
| Summary cards | Staggered rise (`--d` delays), gradient icon tiles, radial glow that scales on hover, `.countup` numbers animated by JS |
| Panels | Staggered entrance, hover lift, per-panel gradient title icons |
| Contractor list | Slim scrollbars, 560px scroll area, staggered rows, gradient+glow selected state, gradient avatar when selected |
| Project cards | Status accent bar (`.proj-ongoing` violet / `.proj-completed` green), pulsing In-progress dot, staggered entrance, lift on hover |
| Analysis hero | Layered gradient bg, shimmer sweep, chat-bubble `.ai-comment` with gradient spine, shimmer skeleton while loading |
| Score ring | `.score-ring` + `.score-num`, animated 0 → score by `animateScore()` |
| Chips | Gradient good/warn/bad variants, hover lift, entrance stagger |
| Perf stats | Tile cards with staggered entrance + hover lift, restyled chart wrap |
| Modal | Blurred backdrop, spring pop-in, gradient top bar, glowing popping stars (`.pop` class added in `pickStar()`), gradient Save |
| Empty states | Dashed-card style |

### Key code — JS motion helpers
```js
function countUp(el, target, dur) { /* eased 0→target, reduced-motion safe */ }
function animateScore() { /* animates #analysisBody .score-ring conic fill + .score-num */ }
// stagger: style="animation-delay:${Math.min(i * 35, 350)}ms" on .rise-item rows
// init: document.querySelectorAll('.countup').forEach(el => countUp(el, parseInt(el.dataset.count)));
```

---

## 6. How to verify (copy-paste)

```powershell
php -l app\Http\Controllers\Admin\RatingController.php
php artisan route:list --name=admin.rating
php artisan test --filter="test_admin_rating_page_uses_shared_toast"
```

Expected: no syntax errors, 6 rating routes (including
`admin.rating.contractor-overview`), 1 test passed.

---

## 7. Tweak guide (when you are ready)

- **Change the AI voice** → `generateReview()` / `generateContractorOverview()`
  sentence templates in `RatingController.php`.
- **Change tone thresholds** → the `>= 4.5 / 3.5 / 2.5` branches in
  `generateContractorOverview()`; the `computeRating()` variance/delay bands.
- **Restyle the orb** → `.ai-orb` background + robot SVG fills (robot uses
  `#fff` body + `#1a2540` eyes/smile).
- **Faster/slower motion** → keyframe durations; stagger caps
  (`Math.min(i * 35, 350)`); `countUp`/`animateScore` durations
  (800ms / 900ms).
- **Port this UI to another page** → copy in order: `:root` tokens →
  keyframes → component CSS you need → `orbHtml()` helper → markup patterns
  (panel, empty-state, ai-comment bubble, chips).
