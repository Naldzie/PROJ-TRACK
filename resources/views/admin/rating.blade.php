@extends('layouts.app')

@section('content')

<style>
:root {
  --ink: oklch(23% .028 285);
  --muted: oklch(54% .035 285);
  --faint: oklch(70% .025 285);
  --line: oklch(88% .026 285);
  --canvas: oklch(96.5% .014 92);
  --surface: oklch(99% .008 92);
  --surface-2: oklch(94% .025 92);
  --blue: oklch(54% .21 264);
  --blue-dark: oklch(41% .18 264);
  --blue-soft: oklch(92% .055 264);
  --gold: oklch(73% .15 74);
  --gold-soft: oklch(94% .06 82);
  --mint: oklch(75% .12 160);
  --mint-soft: oklch(92% .055 160);
  --rose: oklch(64% .15 18);
  --shadow-sm: 0 1px 2px oklch(25% .03 285 / .05), 0 8px 20px oklch(25% .03 285 / .035);
  --shadow-md: 0 12px 36px oklch(25% .03 285 / .09);
  --ease-out: cubic-bezier(.16,1,.3,1);
}
* { box-sizing: border-box; }

.page-head {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 24px;
  margin-bottom: 28px;
}
.eyebrow {
  color: var(--blue);
  font: 700 10px/1 'Space Mono', 'SF Mono', monospace;
  letter-spacing: .12em;
  text-transform: uppercase;
  margin-bottom: 10px;
}
.page-head h1 {
  font-size: clamp(28px, 3vw, 42px);
  letter-spacing: -.055em;
  line-height: 1.05;
  margin: 0;
  text-wrap: balance;
}
.page-head p {
  margin: 12px 0 0;
  color: var(--muted);
  max-width: 54ch;
  font-size: 15px;
}
.primary {
  border: 0;
  min-height: 42px;
  border-radius: 10px;
  padding: 0 16px;
  background: var(--blue);
  color: oklch(98% .01 92);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 700;
  box-shadow: 0 5px 12px oklch(54% .21 264 / .2);
  transition: transform .2s var(--ease-out), background .2s, box-shadow .2s;
  white-space: nowrap;
}
.primary:hover { background: var(--blue-dark); transform: translateY(-2px); box-shadow: 0 8px 18px oklch(54% .21 264 / .25); }
.primary:active { transform: translateY(0); }
.primary svg { width: 16px; height: 16px; }

.summary-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 28px;
}
.summary {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 18px 18px 16px;
  box-shadow: var(--shadow-sm);
  min-width: 0;
  position: relative;
  overflow: hidden;
  transition: transform .2s var(--ease-out), box-shadow .2s var(--ease-out), border-color .2s;
}
.summary::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: var(--blue);
  opacity: .85;
}
.summary:nth-child(2)::after { background: var(--gold); }
.summary:nth-child(3)::after { background: var(--rose); }
.summary:hover { transform: translateY(-2px); box-shadow: 0 10px 28px oklch(25% .03 285 / .09); border-color: oklch(84% .04 285); }
.summary-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 18px;
  color: var(--muted);
  font-size: 12px;
}
.summary-icon {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  display: grid;
  place-items: center;
}
.summary-icon svg { width: 15px; height: 15px; }
.summary:nth-child(1) .summary-icon { background: var(--blue-soft); color: var(--blue); }
.summary:nth-child(2) .summary-icon { background: var(--gold-soft); color: oklch(47% .12 74); }
.summary:nth-child(3) .summary-icon { background: var(--mint-soft); color: oklch(42% .11 160); }
.summary:nth-child(4) .summary-icon { background: oklch(92% .04 18); color: var(--rose); }
.summary-value { font-size: 27px; font-weight: 700; letter-spacing: -.055em; line-height: 1; font-variant-numeric: tabular-nums; }
.summary-caption { color: var(--muted); font-size: 12px; margin-top: 7px; }
.summary-delta { color: oklch(43% .11 160); font-size: 11px; font-weight: 700; }

.workspace {
  display: grid;
  grid-template-columns: minmax(240px, .78fr) minmax(440px, 1.45fr) minmax(260px, .85fr);
  gap: 16px;
  align-items: start;
}
.panel {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 16px;
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}
.panel-head {
  padding: 19px 20px 16px;
  border-bottom: 1px solid var(--line);
  display: flex;
  align-items: start;
  justify-content: space-between;
  gap: 12px;
}
.panel-title { display: flex; align-items: center; gap: 10px; }
.panel-title-icon {
  width: 29px;
  height: 29px;
  border-radius: 8px;
  background: var(--surface-2);
  color: var(--blue);
  display: grid;
  place-items: center;
  flex: 0 0 auto;
}
.panel-title-icon svg { width: 15px; height: 15px; }
.panel h2 { font-size: 15px; letter-spacing: -.025em; margin: 0; }
.panel-subtitle { margin-top: 2px; font-size: 11px; color: var(--muted); }
.panel-count { color: var(--muted); font: 700 11px/1 'Space Mono', 'SF Mono', monospace; background: var(--surface-2); border-radius: 20px; padding: 6px 8px; }
.panel-body { padding: 14px; }

.search { position: relative; margin-bottom: 12px; }
.search svg { width: 15px; height: 15px; position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--faint); }
.search input {
  width: 100%; height: 38px; border: 1px solid var(--line); border-radius: 9px;
  background: var(--canvas); color: var(--ink); padding: 0 12px 0 35px; font-size: 12px;
  transition: border-color .2s, box-shadow .2s;
}
.search input:focus { border-color: oklch(65% .14 264); box-shadow: 0 0 0 3px oklch(54% .21 264 / .12); outline: 0; }

.contractor-list { display: grid; gap: 5px; }
.contractor {
  width: 100%; border: 1px solid transparent; border-radius: 11px; padding: 12px 11px;
  background: transparent; display: grid; grid-template-columns: 34px minmax(0,1fr) auto;
  align-items: center; gap: 10px; text-align: left; color: var(--ink);
  transition: background .2s var(--ease-out), border-color .2s, transform .2s var(--ease-out);
}
.contractor:hover { background: var(--canvas); transform: translateX(2px); }
.contractor.selected { background: var(--blue-soft); border-color: oklch(82% .09 264); }
.contractor-avatar {
  width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center;
  background: linear-gradient(135deg, var(--blue-soft), var(--surface-2));
  color: var(--blue-dark); font-size: 11px; font-weight: 700; letter-spacing: .02em;
  transition: background .2s, color .2s, transform .2s var(--ease-out);
}
.contractor:hover .contractor-avatar { transform: scale(1.05); }
.contractor.selected .contractor-avatar { background: linear-gradient(135deg, oklch(83% .11 264), oklch(74% .13 264)); color: #fff; }
.contractor-avatar img.avatar-img {
  width: 100%; height: 100%; border-radius: 10px; object-fit: cover;
  display: block; background: var(--surface-2);
}
.contractor.selected .contractor-avatar img.avatar-img { box-shadow: 0 0 0 1px oklch(72% .13 264 / .35); }
.contractor strong { display: block; font-size: 12px; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.contractor small { display: block; margin-top: 3px; color: var(--muted); font-size: 11px; }
.perf-line { display: flex; align-items: center; gap: 5px; margin-top: 4px; }
.perf-badge {
  display: inline-flex; align-items: center; justify-content: center;
  width: 18px; height: 18px; border-radius: 5px; font-size: 10px; font-weight: 700;
  flex: 0 0 auto;
}
.perf-5 { background: oklch(92% .055 160); color: oklch(35% .12 160); }
.perf-4 { background: oklch(92% .055 160); color: oklch(40% .11 160); }
.perf-3 { background: oklch(94% .06 82); color: oklch(45% .12 74); }
.perf-2 { background: oklch(93% .04 18); color: #b91c1c; }
.perf-1 { background: oklch(93% .04 18); color: #991b1b; }
.rating-compact { text-align: right; }
.stars { color: var(--gold); letter-spacing: 1px; font-size: 11px; line-height: 1; }
.rating-num { color: var(--muted); font: 10px 'Space Mono', 'SF Mono', monospace; margin-top: 4px; }
.not-rated { color: var(--faint); font: 10px 'Space Mono', 'SF Mono', monospace; }

.projects-panel .panel-body { padding: 12px 14px 14px; }
.project-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  gap: 10px; margin-bottom: 10px;
}
.project-toolbar p { color: var(--muted); font-size: 12px; margin: 0; }
select {
  border: 1px solid var(--line); border-radius: 8px; background: var(--canvas);
  color: var(--muted); font-size: 11px; padding: 7px 8px;
}
.project-list { display: grid; gap: 8px; }
.project {
  border: 1px solid var(--line); border-radius: 12px; padding: 14px;
  background: var(--surface); transition: border-color .2s, box-shadow .2s, transform .2s var(--ease-out);
}
.project:hover { border-color: oklch(76% .075 264); box-shadow: 0 5px 15px oklch(25% .03 285 / .06); transform: translateY(-1px); }
.project-row { display: flex; align-items: start; justify-content: space-between; gap: 10px; }
.project h3 { font-size: 13px; letter-spacing: -.015em; margin: 0; }
.project-meta {
  display: flex; align-items: center; gap: 8px; margin-top: 5px; color: var(--muted); font-size: 11px;
}
.status {
  display: inline-flex; align-items: center; gap: 5px; border-radius: 20px;
  padding: 4px 8px; font-size: 10px; font-weight: 700; white-space: nowrap;
}
.status::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
.status.progress { background: var(--blue-soft); color: var(--blue-dark); }
.status.complete { background: var(--mint-soft); color: oklch(40% .11 160); }
.status.warn { background: oklch(93% .04 18); color: #b91c1c; }
.badge-inline {
  display: inline-flex; align-items: center; gap: 4px;
  color: #b91c1c; background: oklch(93% .04 18);
  font: 700 10px/1 'Space Mono', 'SF Mono', monospace;
  padding: 3px 7px; border-radius: 5px; margin-left: 8px;
}
.badge-inline::before { content: ''; width: 4px; height: 4px; border-radius: 50%; background: currentColor; }
.project-bottom {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; margin-top: 15px; padding-top: 11px; border-top: 1px solid var(--line);
}
.project-rating { display: flex; align-items: center; gap: 8px; }
.project-rating .stars { font-size: 12px; }
.project-rating span { color: var(--muted); font: 10px 'Space Mono', 'SF Mono', monospace; }
.project-actions { display: flex; align-items: center; gap: 6px; }

.secondary {
  min-height: 30px; border: 1px solid var(--line); border-radius: 8px; padding: 0 10px;
  color: var(--muted); background: var(--canvas); font-size: 11px; font-weight: 700;
  transition: background .2s, border-color .2s, color .2s;
}
.secondary:hover { color: var(--blue); border-color: oklch(76% .075 264); background: var(--blue-soft); }
.secondary.blue { background: var(--blue); border-color: var(--blue); color: oklch(98% .01 92); }
.secondary.blue:hover { background: var(--blue-dark); }
.secondary:disabled { opacity: 0.5; cursor: not-allowed; }

.analysis-body { padding: 18px 20px 20px; }
.analysis-hero { background: var(--nav, #1a2540); color: oklch(98% .01 92); border-radius: 12px; padding: 16px; margin-bottom: 18px; }
.analysis-hero .eyebrow { color: var(--gold); margin-bottom: 8px; }
.analysis-hero h3 { font-size: 15px; letter-spacing: -.025em; margin: 0; color: #fff; }
.analysis-hero p { color: oklch(82% .02 285); font-size: 12px; margin: 7px 0 0; }

.analysis-note {
  display: flex; gap: 10px; padding-top: 16px; border-top: 1px solid var(--line);
  color: var(--muted); font-size: 12px;
}
.analysis-note svg { width: 16px; height: 16px; color: var(--blue); flex: 0 0 auto; margin-top: 2px; }

.empty-state { padding: 42px 18px; text-align: center; color: var(--muted); }
.empty-state svg { width: 28px; height: 28px; color: var(--faint); margin: 0 auto 10px; }
.empty-state strong { display: block; color: var(--ink); font-size: 13px; margin-bottom: 5px; }
.empty-state p { font-size: 12px; }

/* ── Rating Modal ── */
.cr-modal-overlay {
  display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.35);
  z-index: 1000; align-items: center; justify-content: center;
}
.cr-modal-overlay.show { display: flex; }
.cr-modal {
  background: var(--surface); border: 1px solid var(--line); border-radius: 16px;
  width: 340px; overflow: hidden; box-shadow: var(--shadow-md);
}
.cr-modal-head {
  padding: 16px 18px; border-bottom: 1px solid var(--line);
  display: flex; align-items: center; justify-content: space-between;
}
.cr-modal-title { font-size: 14px; font-weight: 700; letter-spacing: -.02em; }
.cr-modal-subtitle { margin-top: 2px; font-size: 11px; color: var(--muted); }
.cr-modal-close { border: 0; background: none; font-size: 22px; color: var(--muted); cursor: pointer; line-height: 1; padding: 0; }
.cr-modal-close:hover { color: var(--ink); }
.cr-modal-body { padding: 20px; }
.star-select { display: flex; gap: 8px; justify-content: center; margin-bottom: 12px; }
.star-btn {
  font-size: 30px; background: none; border: none; cursor: pointer;
  color: var(--faint); transition: color .1s, transform .1s; line-height: 1; padding: 0;
}
.star-btn.on { color: var(--gold); transform: scale(1.1); }
.star-btn:hover { color: oklch(70% .13 80); transform: scale(1.15); }
.star-btn.on:hover { color: var(--gold); }
.star-hint { text-align: center; font-size: 12px; color: var(--muted); min-height: 16px; margin-bottom: 4px; }
.cr-modal-footer {
  padding: 12px 16px; border-top: 1px solid var(--line);
  display: flex; justify-content: flex-end; gap: 8px;
}
.btn-cancel {
  font-size: 13px; padding: 7px 14px; border-radius: 8px;
  background: var(--surface-2); color: var(--muted); border: 1px solid var(--line); cursor: pointer;
}
.btn-save {
  font-size: 13px; padding: 7px 14px; border-radius: 8px;
  background: var(--blue); color: #fff; border: none; cursor: pointer; font-weight: 600;
}

@media (max-width: 1180px) {
  .workspace { grid-template-columns: minmax(220px, .75fr) minmax(410px, 1.4fr); }
  .analysis { grid-column: 1 / -1; }
  .analysis-body { display: grid; grid-template-columns: 1.2fr 1fr; gap: 20px; align-items: start; }
  .analysis-hero { margin-bottom: 0; }
  .analysis-note { margin-top: 18px; }
}
@media (max-width: 820px) {
  .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .workspace { grid-template-columns: 1fr; }
  .analysis { grid-column: auto; }
  .analysis-body { display: block; }
  .analysis-hero { margin-bottom: 18px; }
  .analysis-note { margin-top: 18px; }
}
@media (max-width: 480px) {
  .summary { padding: 14px; }
  .summary-value { font-size: 23px; }
  .project { padding: 12px; }
  .project-row { display: block; }
  .project-row .status { margin-top: 9px; }
  .project-bottom { align-items: end; }
  .project-actions { flex-wrap: wrap; justify-content: end; }
}
</style>

<div class="page-head">
  <div>
    <div class="eyebrow">Performance workspace</div>
    <h1>Contractor rating system</h1>
    <p>Keep project feedback consistent, visible, and easy to act on.</p>
  </div>
  <button class="primary" id="autoRateBtn">
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
      <path d="M8 2l1.5 3.5H13l-2.8 2.2 1 3.3L8 9.2l-3.2 1.8 1-3.3L3 5.5h3.5L8 2z" fill="currentColor"/>
    </svg>
    Auto-rate all
  </button>
</div>

<section class="summary-grid" aria-label="Performance summary">
  <article class="summary">
    <div class="summary-top"><span>Active contractors</span><div class="summary-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div></div>
    <div class="summary-value">{{ $contractors->count() }}</div>
    <div class="summary-caption"><span class="summary-delta">Total registered</span></div>
  </article>
  <article class="summary">
    <div class="summary-top"><span>Projects reviewed</span><div class="summary-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div></div>
    <div class="summary-value">{{ $ratings->count() }}</div>
    <div class="summary-caption"><span class="summary-delta">{{ $ratings->count() > 0 ? round(($ratings->count() / max($contractors->sum(fn($c) => $c->projects->count()), 1)) * 100) : 0 }}% completion</span></div>
  </article>

  <article class="summary">
    <div class="summary-top"><span>Needs review</span><div class="summary-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div></div>
    @php
      $totalProjects = $contractors->sum(fn($c) => $c->projects->count());
      $unrated = $totalProjects - $ratings->count();
    @endphp
    <div class="summary-value">{{ max(0, $unrated) }}</div>
    <div class="summary-caption">Across {{ $contractors->count() }} contractors</div>
  </article>
</section>

<section class="workspace">
  {{-- ── CONTRACTORS ── --}}
  <section class="panel contractors-panel" aria-labelledby="contractors-title">
    <div class="panel-head">
      <div class="panel-title">
        <div class="panel-title-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
        <div><h2 id="contractors-title">Contractors</h2><p class="panel-subtitle">Select a profile to review</p></div>
      </div>
      <span class="panel-count">{{ $contractors->count() }}</span>
    </div>
    <div class="panel-body">
      <label class="search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input id="contractorSearch" type="search" placeholder="Search contractors" aria-label="Search contractors">
      </label>
      <div class="contractor-list" id="contractorList"></div>
    </div>
  </section>

  {{-- ── PROJECTS ── --}}
  <section class="panel projects-panel" aria-labelledby="projects-title">
    <div class="panel-head">
      <div class="panel-title">
        <div class="panel-title-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg></div>
        <div><h2 id="projects-title">Projects</h2><p class="panel-subtitle" id="projectSubtitle">Select a contractor</p></div>
      </div>
      <span class="panel-count" id="projectCount">0</span>
    </div>
    <div class="panel-body">
      <div class="project-toolbar">
        <p id="projectToolbarLabel">Select a contractor to view projects</p>
        <select id="projectFilter" aria-label="Filter projects">
          <option value="all">All statuses</option>
          <option value="Ongoing">In progress</option>
          <option value="Completed">Completed</option>
        </select>
      </div>
      <div class="project-list" id="projectList"></div>
    </div>
  </section>

  {{-- ── ANALYSIS ── --}}
  <aside class="panel analysis" aria-labelledby="analysis-title">
    <div class="panel-head">
      <div class="panel-title">
        <div class="panel-title-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></div>
        <div><h2 id="analysis-title">Analysis</h2><p class="panel-subtitle">AI performance review</p></div>
      </div>
    </div>
    <div class="analysis-body" id="analysisBody">
      <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 3-3 5"/><circle cx="12" cy="17" r=".5" fill="currentColor"/></svg>
        <strong>No analysis yet</strong>
        <p>Select a project and click <strong>AI review</strong> to generate a performance analysis.</p>
      </div>
    </div>
  </aside>
</section>

{{-- ── RATING MODAL ── --}}
<div class="cr-modal-overlay" id="ratingOverlay">
  <div class="cr-modal">
    <div class="cr-modal-head">
      <div>
        <span class="cr-modal-title">Rate project</span>
        <div class="cr-modal-subtitle" id="modalProjectTitle">—</div>
      </div>
      <button class="cr-modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="cr-modal-body">
      <div class="star-select" id="starSelect">
        <button class="star-btn" onclick="pickStar(1)">&#9733;</button>
        <button class="star-btn" onclick="pickStar(2)">&#9733;</button>
        <button class="star-btn" onclick="pickStar(3)">&#9733;</button>
        <button class="star-btn" onclick="pickStar(4)">&#9733;</button>
        <button class="star-btn" onclick="pickStar(5)">&#9733;</button>
      </div>
      <div class="star-hint" id="starHint">Tap a star to rate</div>
    </div>
    <div class="cr-modal-footer">
      <button class="btn-cancel" onclick="closeModal()">Cancel</button>
      <button class="btn-save" onclick="saveRating()">Save rating</button>
    </div>
  </div>
</div>

<script>
const contractorsData = @json($contractorsJson);
const ratingsData = @json($ratingsJson);

let selectedId = null;
let currentKey = '';
let currentContractorId = null;
let currentProjectId = null;
let currentStar = 0;

const starLabels = ['', 'Poor performance', 'Below average', 'Acceptable', 'Good performance', 'Excellent performance'];

function starsHtml(rating) {
  let s = '';
  for (let i = 1; i <= 5; i++) {
    s += i <= rating ? '&#9733;' : '<span style="color:var(--faint)">&#9733;</span>';
  }
  return s;
}

function renderContractors(filter) {
  const list = document.getElementById('contractorList');
  const visible = contractorsData.filter(c =>
    c.name.toLowerCase().includes((filter || '').toLowerCase())
  );
  if (!visible.length) {
    list.innerHTML = '<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg><strong>No contractors found</strong><p>Try a different name.</p></div>';
    return;
  }
  list.innerHTML = visible.map(c => {
    const ratings = c.projects.map(p => p.rating).filter(r => r > 0);
    const avg = ratings.length ? (ratings.reduce((a,b) => a+b, 0) / ratings.length) : 0;
    const avgR = Math.round(avg * 10) / 10;
    return `<button class="contractor ${c.id === selectedId ? 'selected' : ''}" data-id="${c.id}">
      <div class="contractor-avatar">${c.photo ? `<img class="avatar-img" src="${c.photo}" alt="" loading="lazy" onerror="this.parentElement.textContent='${initials(c.name)}';this.remove()">` : initials(c.name)}</div>
      <div><strong>${escHtml(c.name)}</strong><small>${c.projects.length} project${c.projects.length === 1 ? '' : 's'}</small>
        ${c.performance_rating ? `<small class="perf-line"><span class="perf-badge perf-${c.performance_rating}">${c.performance_rating}</span> ${c.on_time_delivery_pct != null ? c.on_time_delivery_pct + '% on-time' : ''} · ${c.completed_projects_count} completed</small>` : ''}
      </div>
      <div class="rating-compact">
        <div class="stars">${avgR > 0 ? starsHtml(Math.round(avgR)) : '<span class="not-rated">Not rated</span>'}</div>
        ${avgR > 0 ? `<div class="rating-num">${avgR.toFixed(1)}</div>` : ''}
      </div>
    </button>`;
  }).join('');
  list.querySelectorAll('.contractor').forEach(btn => {
    btn.addEventListener('click', () => {
      selectedId = parseInt(btn.dataset.id);
      document.querySelectorAll('.contractor').forEach(b => b.classList.remove('selected'));
      btn.classList.add('selected');
      renderProjects();
      renderAnalysis();
    });
  });
}

function initials(name) {
  return name.split(' ').map(w => w[0]).filter(Boolean).slice(0, 2).join('').toUpperCase();
}

function escHtml(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

function renderProjects() {
  const c = contractorsData.find(x => x.id === selectedId);
  if (!c) {
    document.getElementById('projectSubtitle').textContent = 'Select a contractor';
    document.getElementById('projectCount').textContent = '0';
    document.getElementById('projectToolbarLabel').textContent = 'Select a contractor to view projects';
    document.getElementById('projectList').innerHTML = '<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg><strong>No contractor selected</strong><p>Click a contractor on the left to see their projects.</p></div>';
    return;
  }

  const filter = document.getElementById('projectFilter').value;
  const projects = c.projects.filter(p => filter === 'all' || p.status === filter);

  document.getElementById('projectSubtitle').textContent = c.name;
  document.getElementById('projectCount').textContent = projects.length;
  document.getElementById('projectToolbarLabel').textContent = `${projects.length} project${projects.length === 1 ? '' : 's'} in scope`;

  if (!projects.length) {
    document.getElementById('projectList').innerHTML = '<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3H10l-2-3H2"/><path d="M2 8a2 2 0 0 1 2-2h1.5l1-1h5l1 1H20a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8z"/></svg><strong>No projects in this view</strong><p>Change the status filter to see more.</p></div>';
    return;
  }

  const statusMap = { 'Ongoing': { cls: 'progress', label: 'In progress' }, 'Completed': { cls: 'complete', label: 'Completed' } };

  document.getElementById('projectList').innerHTML = projects.map(p => {
    const s = statusMap[p.status] || { cls: 'progress', label: p.status };
    const rating = p.rating || 0;
    return `<article class="project">
      <div class="project-row">
        <div>
          <h3>${escHtml(p.title)}</h3>
          <div class="project-meta"><span>Status: ${s.label}</span>${p.status === 'Ongoing' ? '<span class="badge-inline">Not rateable — in progress</span>' : ''}</div>
        </div>
        <span class="status ${s.cls}">${s.label}</span>
      </div>
      <div class="project-bottom">
        <div class="project-rating">
          <div class="stars">${rating > 0 ? starsHtml(rating) : '<span class="not-rated">Not rated</span>'}</div>
          ${rating > 0 ? `<span>${rating}/5</span>` : ''}
        </div>
        <div class="project-actions">
          ${p.status === 'Completed'
            ? `<button class="secondary rate-btn" data-key="${p.key}">Rate</button>`
            : `<button class="secondary" disabled style="cursor:not-allowed;">Rate</button>`}
          ${p.hasAi ? `<button class="secondary blue ai-btn" data-key="${p.key}" data-project-id="${p.id}">AI review</button>` : ''}
        </div>
      </div>
    </article>`;
  }).join('');

  document.querySelectorAll('.rate-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const key = btn.dataset.key;
      const parts = key.split('-');
      const projId = parseInt(parts[0]);
      const conId = parseInt(parts[1]);
      const con = contractorsData.find(x => x.id === conId);
      const proj = con ? con.projects.find(p => p.key === key) : null;
      openRating(key, proj ? proj.rating : 0, conId, projId, proj ? proj.title : '');
    });
  });

  document.querySelectorAll('.ai-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const projectId = parseInt(btn.dataset.projectId);
      const key = btn.dataset.key;
      suggestAI(projectId, key);
    });
  });
}

function renderAnalysis(message) {
  const c = contractorsData.find(x => x.id === selectedId);
  if (!c) {
    document.getElementById('analysisBody').innerHTML = '<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 3-3 5"/><circle cx="12" cy="17" r=".5" fill="currentColor"/></svg><strong>No analysis yet</strong><p>Select a project and click <strong>AI review</strong> to generate a performance analysis.</p></div>';
    return;
  }

  const ratings = c.projects.map(p => p.rating).filter(r => r > 0);
  const avg = ratings.length ? (ratings.reduce((a,b) => a+b, 0) / ratings.length) : 0;
  const score = Math.round(avg * 20) || 0;

  document.getElementById('analysisBody').innerHTML = `<div>
    <div class="analysis-hero">
      <div class="eyebrow">Overview</div>
      <h3>${escHtml(c.name)}</h3>
      <p>${message || 'Click a project\'s AI review for detailed performance analysis.'}</p>
      <div style="margin:20px auto 14px;width:124px;height:124px;border-radius:50%;background:conic-gradient(var(--gold) 0 ${score}%, oklch(100% .01 92 / .12) ${score}% 100%);display:grid;place-items:center;position:relative;">
        <div style="position:absolute;inset:9px;background:var(--nav, #1a2540);border-radius:50%;"></div>
        <div style="position:relative;text-align:center;z-index:1;">
          <strong style="display:block;font-size:28px;letter-spacing:-.06em;line-height:1;">${score}</strong>
          <span style="color:oklch(75% .025 285);font:10px 'Space Mono','SF Mono',monospace;">out of 100</span>
        </div>
      </div>
    </div>
  </div>`;
}

function openRating(key, existing, contractorId, projectId, projectTitle) {
  currentKey = key;
  currentContractorId = contractorId;
  currentProjectId = projectId;
  currentStar = existing || 0;
  document.getElementById('modalProjectTitle').textContent = projectTitle || 'Untitled project';
  renderModalStars(currentStar);
  document.getElementById('ratingOverlay').classList.add('show');
}

function closeModal() {
  document.getElementById('ratingOverlay').classList.remove('show');
}

function pickStar(n) {
  currentStar = n;
  renderModalStars(n);
}

function renderModalStars(n) {
  document.querySelectorAll('.star-btn').forEach((btn, i) => {
    btn.classList.toggle('on', i < n);
  });
  document.getElementById('starHint').textContent = n ? starLabels[n] : 'Tap a star to rate';
}

document.querySelectorAll('.star-btn').forEach((btn, i) => {
  const n = i + 1;
  btn.addEventListener('mouseenter', () => {
    document.getElementById('starHint').textContent = starLabels[n];
    document.querySelectorAll('.star-btn').forEach((b, j) => b.classList.toggle('on', j < n));
  });
  btn.addEventListener('mouseleave', () => {
    renderModalStars(currentStar);
  });
});

function saveRating() {
  if (!currentStar) { closeModal(); return; }

  fetch('{{ route('admin.rating.store') }}', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify({
      contractor_id: currentContractorId,
      project_id: currentProjectId,
      rating: currentStar,
    })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      updateProjectStars(currentKey, currentStar);
      showToast('Rating saved. Keep the feedback specific.', 'success');
    }
    closeModal();
  })
  .catch(() => closeModal());
}

function updateProjectStars(key, rating) {
  const con = contractorsData.find(c => c.projects.some(p => p.key === key));
  if (con) {
    const proj = con.projects.find(p => p.key === key);
    if (proj) proj.rating = rating;
  }
  renderProjects();
  renderAnalysis();
}

function suggestAI(projectId, key) {
  const aiDiv = document.getElementById('analysisBody');
  aiDiv.innerHTML = '<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg><strong>Generating AI review…</strong></div>';

  fetch('{{ route('admin.rating.suggest') }}', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify({ project_id: projectId })
  })
  .then(r => r.json())
  .then(data => {
    if (data.error) {
      aiDiv.innerHTML = '<div class="empty-state" style="color:#ef4444;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg><strong>No AI data</strong><p>' + escHtml(data.error) + '</p></div>';
      return;
    }

    const rating = data.rating;
    let cls, icon, head;
    if (rating >= 4) { cls = 'analysis-hero'; icon = '&#10003;'; head = 'Strong performance'; }
    else if (rating === 3) { cls = 'analysis-hero'; icon = '!'; head = 'Acceptable, with room to improve'; }
    else { cls = 'analysis-hero'; icon = '&#10005;'; head = 'Performance needs attention'; }

    const parts = key.split('-');
    const pid = parseInt(parts[0]);
    const cid = parseInt(parts[1]);

    aiDiv.innerHTML = `<div>
      <div class="${cls}" style="${rating >= 4 ? 'border-left:4px solid var(--mint)' : (rating === 3 ? 'border-left:4px solid var(--gold)' : 'border-left:4px solid var(--rose)')}">
        <div class="eyebrow" style="${rating >= 4 ? 'color:var(--mint)' : (rating === 3 ? 'color:var(--gold)' : 'color:var(--rose)')}">${head}</div>
        <h3>${escHtml(data.review.split('.')[0] || '')}</h3>
        <p>${escHtml(data.review)}</p>
      </div>
      <div style="margin-top:14px;display:flex;gap:8px;">
        <button class="primary" style="flex:1" onclick="applyAISuggestion(${cid}, ${pid}, ${rating}, '${key}')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><path d="M20 6L9 17l-5-5"/></svg>
          Apply ${rating}/5
        </button>
        <button class="secondary" onclick="closeModal()" style="flex-shrink:0">Dismiss</button>
      </div>
    </div>`;
  })
  .catch(() => {
    aiDiv.innerHTML = '<div class="empty-state" style="color:#ef4444;"><strong>Failed to generate AI review</strong><p>Please try again later.</p></div>';
  });
}

function applyAISuggestion(contractorId, projectId, rating, key) {
  fetch('{{ route('admin.rating.store') }}', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify({
      contractor_id: contractorId,
      project_id: projectId,
      rating: rating,
    })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      updateProjectStars(key, rating);
      showToast('Rating applied! (' + rating + '/5)', 'success');
      document.getElementById('analysisBody').innerHTML = '<div class="empty-state" style="color:oklch(40% .11 160)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><strong>Rating applied!</strong><p>' + rating + '/5 — AI review complete.</p></div>';
    }
  })
  .catch(() => {});
}

document.getElementById('contractorSearch').addEventListener('input', e => renderContractors(e.target.value));
document.getElementById('projectFilter').addEventListener('change', renderProjects);

document.getElementById('autoRateBtn').addEventListener('click', function() {
  const btn = this;
  btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="animation:spin 1s linear infinite;width:16px;height:16px"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Rating projects…';
  btn.disabled = true;

  fetch('{{ route('admin.rating.auto-rate-all') }}', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      showToast(data.message, 'success');
      location.reload();
    }
  })
  .catch(() => {
    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 2l1.5 3.5H13l-2.8 2.2 1 3.3L8 9.2l-3.2 1.8 1-3.3L3 5.5h3.5L8 2z" fill="currentColor"/></svg> Auto-rate all';
    btn.disabled = false;
  });
});

// Init — select first contractor
if (contractorsData.length > 0) {
  selectedId = contractorsData[0].id;
  renderContractors('');
  renderProjects();
  renderAnalysis();
} else {
  renderContractors('');
  renderProjects();
  renderAnalysis();
}
</script>

<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>

@endsection
