@extends('layouts.contractorlayouts')
@section('content')
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

<style>
:root {
  --ink: oklch(24% .035 270);
  --muted: oklch(56% .035 270);
  --quiet: oklch(70% .03 270);
  --line: oklch(88% .025 270);
  --canvas: oklch(97% .012 270);
  --surface: oklch(99% .006 270);
  --blue: oklch(55% .205 265);
  --blue-soft: oklch(92% .055 265);
  --blue-pale: oklch(96% .025 265);
  --gold: oklch(74% .145 78);
  --gold-soft: oklch(94% .065 82);
  --green: oklch(52% .12 160);
  --green-soft: oklch(92% .055 160);
  --purple: oklch(56% .17 304);
  --purple-soft: oklch(94% .05 304);
  --shadow-sm: 0 1px 2px oklch(24% .035 270 / .05), 0 8px 22px oklch(24% .035 270 / .035);
  --shadow-md: 0 18px 45px oklch(24% .035 270 / .12);
  --ease: cubic-bezier(.16,1,.3,1);
}
* { box-sizing: border-box; }

.overview {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 11px;
  margin-bottom: 25px;
}
.metric {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 13px;
  padding: 15px 16px;
  box-shadow: var(--shadow-sm);
}
.metric-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  color: var(--muted);
  font-size: 11px;
}
.metric-icon {
  width: 27px;
  height: 27px;
  border-radius: 8px;
  display: grid;
  place-items: center;
}
.metric-icon i { font-size: 13px; }
.metric:nth-child(1) .metric-icon { background: var(--purple-soft); color: var(--purple); }
.metric:nth-child(2) .metric-icon { background: var(--blue-soft); color: var(--blue); }
.metric:nth-child(3) .metric-icon { background: var(--green-soft); color: var(--green); }
.metric:nth-child(4) .metric-icon { background: var(--gold-soft); color: oklch(48% .12 78); }
.metric-value {
  font-size: 25px;
  font-weight: 750;
  letter-spacing: -.055em;
  line-height: 1;
  margin-top: 15px;
  font-variant-numeric: tabular-nums;
}
.metric-foot {
  margin-top: 7px;
  color: var(--muted);
  font-size: 10px;
}
.metric-foot.positive { color: var(--green); font-weight: 700; }

.main-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 315px;
  align-items: start;
  gap: 16px;
}
.panel {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 15px;
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}
.panel-head {
  min-height: 70px;
  padding: 15px 18px;
  border-bottom: 1px solid var(--line);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
}
.panel-heading {
  display: flex;
  align-items: center;
  gap: 10px;
}
.panel-icon {
  width: 29px;
  height: 29px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  background: var(--blue-soft);
  color: var(--blue);
}
.panel-icon i { font-size: 14px; }
.panel h2 { font-size: 15px; letter-spacing: -.025em; margin: 0; }
.panel-heading p { color: var(--muted); font-size: 11px; margin: 2px 0 0; }
.panel-count {
  color: var(--muted);
  background: var(--canvas);
  border: 1px solid var(--line);
  padding: 5px 8px;
  border-radius: 18px;
  font-size: 10px;
  font-weight: 700;
}
.toolbar {
  padding: 14px 18px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  border-bottom: 1px solid var(--line);
}
.toolbar-right { display: flex; align-items: center; gap: 8px; }
.search-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
  background: var(--canvas);
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 6px 10px;
}
.search-wrap i { font-size: 12px; color: var(--quiet); }
.mail-search {
  border: none;
  background: transparent;
  font-size: 11px;
  color: var(--ink);
  outline: none;
  width: 190px;
}
.mail-search::placeholder { color: var(--quiet); }
.select-sort {
  height: 34px;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--canvas);
  color: var(--muted);
  font-size: 11px;
  padding: 0 8px;
}

.message-list { display: grid; }
.message {
  position: relative;
  display: grid;
  grid-template-columns: 7px 35px minmax(0,1fr) auto;
  gap: 11px;
  padding: 15px 18px;
  border-bottom: 1px solid var(--line);
  transition: background .2s var(--ease);
  cursor: pointer;
}
.message:last-child { border-bottom: 0; }
.message:hover { background: var(--blue-pale); }
.message.unread { background: oklch(98% .01 265); }
.message.unread .message-subject { font-weight: 600; color: var(--ink); }
.unread-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--blue);
  flex-shrink: 0;
  margin-top: 6px;
}
.read-dot {
  width: 7px;
  height: 7px;
  flex-shrink: 0;
  margin-top: 6px;
}
.message-avatar {
  width: 33px;
  height: 33px;
  border-radius: 10px;
  background: var(--blue-soft);
  color: var(--blue);
  display: grid;
  place-items: center;
  font-size: 11px;
  font-weight: 800;
}
.message-main { min-width: 0; }
.message-top {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.message-top strong { font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.message-time { color: var(--quiet); font-size: 10px; white-space: nowrap; margin-left: auto; }
.message-subject {
  display: block;
  color: oklch(36% .06 270);
  font-size: 12px;
  margin-top: 3px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.message-snippet {
  color: var(--muted);
  font-size: 11px;
  margin-top: 4px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 72ch;
}
.message-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 5px;
  margin-top: 7px;
}
.tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-height: 19px;
  padding: 0 7px;
  border-radius: 5px;
  font-size: 9px;
  line-height: 1;
  font-weight: 700;
  white-space: nowrap;
}
.tag-ai { background: var(--purple-soft); color: var(--purple); }
.tag-date { background: var(--blue-soft); color: oklch(45% .15 265); }
.tag-ok { background: var(--green-soft); color: var(--green); }
.tag-warn { background: var(--gold-soft); color: oklch(48% .12 78); }
.tag-crit { background: oklch(93% .05 25); color: oklch(45% .15 25); }
.message-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  opacity: 0;
  transform: translateX(4px);
  transition: opacity .2s var(--ease), transform .2s var(--ease);
}
.message:hover .message-actions { opacity: 1; transform: translateX(0); }
.row-button {
  width: 29px;
  height: 29px;
  border: 1px solid var(--line);
  border-radius: 7px;
  background: var(--surface);
  color: var(--muted);
  display: grid;
  place-items: center;
  cursor: pointer;
}
.row-button:hover { color: var(--blue); border-color: oklch(77% .08 265); background: var(--blue-soft); }
.row-button i { font-size: 12px; }

.inbox-side { display: grid; gap: 14px; }
.side-section { padding: 18px; }
.side-section + .side-section { border-top: 1px solid var(--line); }
.side-title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 15px;
}
.side-title h2 { font-size: 14px; margin: 0; }
.side-title span { color: var(--muted); font-size: 10px; }

.mini-list { display: grid; gap: 11px; }
.mini-item { display: flex; align-items: start; gap: 9px; }
.mini-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--blue);
  margin-top: 5px;
  flex: 0 0 auto;
}
.mini-item:nth-child(2) .mini-dot { background: var(--gold); }
.mini-item:nth-child(3) .mini-dot { background: var(--green); }
.mini-item p { font-size: 11px; line-height: 1.35; margin: 0; }
.mini-item small { display: block; color: var(--quiet); font-size: 10px; margin-top: 2px; }

.empty-state {
  padding: 42px 20px;
  text-align: center;
  color: var(--muted);
}
.empty-state i { font-size: 28px; display: block; margin-bottom: 8px; }
.empty-state strong { display: block; color: var(--ink); font-size: 13px; margin-bottom: 4px; }
.empty-state span { font-size: 11px; }

.overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.35);
  z-index: 200;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.overlay.open { display: flex; }
.modal-box {
  background: var(--surface);
  border-radius: 14px;
  border: 1px solid var(--line);
  width: 580px;
  max-width: 100%;
  max-height: 88vh;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  box-shadow: var(--shadow-md);
}
.modal-head {
  padding: 14px 18px;
  border-bottom: 1px solid var(--line);
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  flex-shrink: 0;
  background: var(--canvas);
}
.modal-head-left { flex: 1; min-width: 0; }
.modal-title { font-size: 13px; font-weight: 600; color: var(--ink); margin: 0 0 4px; line-height: 1.4; }
.modal-meta { font-size: 11px; color: var(--muted); }
.close-btn {
  background: none;
  border: none;
  font-size: 20px;
  cursor: pointer;
  color: var(--quiet);
  line-height: 1;
  padding: 0;
  flex-shrink: 0;
}
.close-btn:hover { color: var(--muted); }
.modal-body { padding: 18px; display: flex; flex-direction: column; gap: 14px; flex: 1; }
.email-banner {
  background: oklch(25% .045 275);
  border-radius: 10px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 14px;
}
.email-banner-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: var(--blue);
  display: grid;
  place-items: center;
  flex-shrink: 0;
}
.email-banner-icon i { color: #fff; font-size: 18px; }
.banner-title { font-size: 13px; font-weight: 600; color: #fff; letter-spacing: 0.03em; }
.banner-sub { font-size: 11px; color: rgba(255,255,255,0.5); margin-top: 2px; }
.detail-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px 16px;
}
.detail-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.detail-item .label {
  font-size: 10px;
  font-weight: 500;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.detail-item .value {
  font-size: 12px;
  font-weight: 600;
  color: var(--ink);
}
.msg-text { font-size: 13px; color: oklch(36% .06 270); line-height: 1.75; }
.analysis-block {
  background: var(--canvas);
  border: 1px solid var(--line);
  border-radius: 10px;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.analysis-label {
  font-size: 10px;
  font-weight: 500;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: 6px;
}
.metric-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  margin-bottom: 5px;
}
.metric-label { color: var(--muted); }
.metric-val { font-weight: 600; color: var(--ink); }
.progress-bar {
  height: 5px;
  background: var(--line);
  border-radius: 99px;
  overflow: hidden;
}
.progress-fill {
  height: 100%;
  border-radius: 99px;
  transition: width 0.4s ease;
}
.ai-note {
  background: oklch(94% .04 225);
  border: 1px solid oklch(85% .06 230);
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 11px;
  color: oklch(35% .08 240);
  line-height: 1.6;
  display: flex;
  gap: 8px;
  align-items: flex-start;
}
.modal-footer {
  padding: 12px 18px;
  border-top: 1px solid var(--line);
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  flex-shrink: 0;
}
.btn-close-modal {
  background: none;
  border: 1px solid var(--line);
  color: var(--muted);
  padding: 7px 16px;
  border-radius: 8px;
  font-size: 12px;
  cursor: pointer;
  transition: background .2s;
}
.btn-close-modal:hover { background: var(--canvas); }
.pagination-wrap {
  padding: 10px 16px;
  border-top: 1px solid var(--line);
  background: var(--surface);
  flex-shrink: 0;
}
.pagination-wrap nav > ul { margin: 0; }
.pagination-wrap .pagination { margin: 0; justify-content: center; }
.loading-overlay {
  position: absolute;
  inset: 0;
  background: rgba(255,255,255,0.7);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 10;
}
.loading-overlay.active { display: flex; }
.spinner {
  width: 28px;
  height: 28px;
  border: 3px solid var(--line);
  border-top-color: var(--blue);
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

@media (max-width: 1110px) {
  .main-grid { grid-template-columns: minmax(0, 1fr) 280px; }
  .overview { grid-template-columns: repeat(2, minmax(0,1fr)); }
}
@media (max-width: 820px) {
  .main-grid { grid-template-columns: 1fr; }
  .inbox-side { grid-row: 1; }
  .toolbar { flex-direction: column; align-items: stretch; }
  .toolbar-right { width: 100%; }
  .search-wrap { flex: 1; }
  .mail-search { width: 100%; }
  .message { grid-template-columns: 7px 33px minmax(0,1fr); }
  .message-actions { display: none; }
}
@media (max-width: 480px) {
  .overview { grid-template-columns: 1fr 1fr; gap: 8px; }
  .metric { padding: 13px; }
  .metric-value { font-size: 22px; }
  .message { padding: 14px 13px; gap: 9px; }
  .message-snippet { white-space: normal; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
}
</style>

<!-- OVERVIEW METRICS -->
@php $totalEmails = $emails instanceof \Illuminate\Pagination\LengthAwarePaginator ? $emails->total() : 0; @endphp
<section class="overview" aria-label="Message summary">
  <article class="metric">
    <div class="metric-top"><span>Messages</span><div class="metric-icon"><i class="bi bi-envelope"></i></div></div>
    <div class="metric-value">{{ $totalEmails }}</div>
    <div class="metric-foot positive">In your inbox</div>
  </article>
  <article class="metric">
    <div class="metric-top"><span>Unread</span><div class="metric-icon"><i class="bi bi-envelope-open"></i></div></div>
    <div class="metric-value">{{ $emails instanceof \Illuminate\Pagination\LengthAwarePaginator ? $emails->sum(fn($e) => $e->is_read ? 0 : 1) : 0 }}</div>
    <div class="metric-foot">Requires attention</div>
  </article>
  <article class="metric">
    <div class="metric-top"><span>AI reports</span><div class="metric-icon"><i class="bi bi-cpu"></i></div></div>
    <div class="metric-value">{{ $totalEmails }}</div>
    <div class="metric-foot">Auto-generated monthly</div>
  </article>
  <article class="metric">
    <div class="metric-top"><span>Status</span><div class="metric-icon"><i class="bi bi-check-circle"></i></div></div>
    <div class="metric-value" style="font-size:22px">Active</div>
    <div class="metric-foot">System operational</div>
  </article>
</section>

<!-- MAIN GRID -->
<div class="main-grid">
  <section class="panel" aria-labelledby="inbox-title">
    <div class="panel-head">
      <div class="panel-heading">
        <div class="panel-icon"><i class="bi bi-inbox"></i></div>
        <div>
          <h2 id="inbox-title">Inbox</h2>
          <p id="inboxCount">{{ $totalEmails }} message{{ $totalEmails !== 1 ? 's' : '' }}</p>
        </div>
      </div>
      <span class="panel-count" id="visibleCount">{{ $totalEmails }} total</span>
    </div>

    <div class="toolbar">
      <div class="toolbar-right" style="margin-left:auto;">
        <div class="search-wrap">
          <i class="bi bi-search"></i>
          <input class="mail-search" id="searchInput"
                 placeholder="Search messages…"
                 value="{{ request('search') }}"
                 onkeydown="if(event.key==='Enter')searchMessages()">
        </div>
      </div>
    </div>

    <div class="message-list" id="msgList" style="position:relative;">
      <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
      </div>
      @include('contractor.partials.email_list')
    </div>

    @if($emails instanceof \Illuminate\Pagination\LengthAwarePaginator && $emails->hasPages())
    <div class="pagination-wrap" id="paginationWrap">
      {{ $emails->links('vendor.pagination.bootstrap-5') }}
    </div>
    @endif
  </section>

  <aside class="inbox-side">
    <section class="panel">
      <div class="side-section">
        <div class="side-title">
          <h2>Recent activity</h2>
          <span>Today</span>
        </div>
        <div class="mini-list">
          @forelse($recentActivity as $i => $re)
          @php
            $colors = ['var(--blue)', 'var(--gold)', 'var(--green)', 'var(--purple)'];
            $statusText = $re->opened_at ? 'opened' : ($re->delivered_at ? 'delivered' : 'received');
          @endphp
          <div class="mini-item" data-email-id="{{ $re->id }}">
            <span class="mini-dot" style="background:{{ $colors[$i % 4] }}"></span>
            <div>
              <p>Report {{ $statusText }} — {{ $re->project?->title ?? 'Project' }}</p>
              <small>{{ $re->sent_at?->diffForHumans() ?? '' }}</small>
            </div>
          </div>
          @empty
          <div class="mini-item">
            <span class="mini-dot" style="background:var(--green)"></span>
            <div>
              <p>No reports yet</p>
              <small>Waiting for first report</small>
            </div>
          </div>
          @endforelse
        </div>
      </div>
    </section>
  </aside>
</div>

<!-- MODAL -->
<div class="overlay" id="viewOverlay">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-head-left">
        <div class="modal-title" id="v-title"></div>
        <div class="modal-meta" id="v-meta"></div>
      </div>
      <button class="close-btn" onclick="closeOverlay()">×</button>
    </div>
    <div class="modal-body">
      <div class="email-banner">
        <div class="email-banner-icon"><i class="bi bi-cpu"></i></div>
        <div>
          <div class="banner-title">PROJ-TRACK SYSTEM</div>
          <div class="banner-sub" id="v-subbanner"></div>
        </div>
      </div>
      <div class="detail-grid" id="v-details"></div>
      <div class="msg-text" id="v-body"></div>
      <div class="analysis-block" id="v-analysis">
        <div class="analysis-label">Project Progress Analysis</div>
      </div>
      <div class="ai-note">
        <i class="bi bi-info-circle" style="font-size:13px;flex-shrink:0;margin-top:1px;"></i>
        <span>This is an automated monthly AI-generated report dispatched every end of the month. Please do not reply directly to this message. All recommendations are generated by the PROJ-TRACK AI engine and do not constitute an official directive.</span>
      </div>
      <div class="ai-note" style="background:#fef9c3;border-color:#fde68a;color:#854d0e;">
        <i class="bi bi-shield-lock" style="font-size:13px;flex-shrink:0;margin-top:1px;"></i>
        <span>This email contains integrity monitoring technology. For audit and security purposes, delivery and open events may be recorded using a secure tracking pixel.</span>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-close-modal" onclick="closeOverlay()">Close</button>
    </div>
  </div>
</div>

<script>
let currentEmailId = null;

function viewMsg(id) {
  currentEmailId = id;
  document.getElementById('loadingOverlay').classList.add('active');
  fetch(`/contractor/emails/${id}`)
    .then(r => r.json())
    .then(m => {
      document.getElementById('v-title').textContent = m.subject;
      document.getElementById('v-subbanner').textContent = 'Contractor AI Status Report';
      document.getElementById('v-meta').textContent = `From: ${m.from}  ·  Project: ${m.project_title}  ·  ${m.month}`;
      document.getElementById('v-details').innerHTML = `
        <div class="detail-item"><span class="label">Project</span><span class="value">${m.project_title}</span></div>
        <div class="detail-item"><span class="label">Location</span><span class="value">${m.project_location}</span></div>
        <div class="detail-item"><span class="label">Status</span><span class="value">${m.project_status}</span></div>
        <div class="detail-item"><span class="label">Generated</span><span class="value">${m.generated_at}</span></div>
      `;
      document.getElementById('v-body').innerHTML = m.body.replace(/\n/g, '<br>');
      const plannedPct = parseFloat(m.planned_progress) || 0;
      const actualPct = parseFloat(m.actual_progress) || 0;
      const variance = m.variance;
      const color = variance >= 0 ? '#10b981' : (variance >= -15 ? '#f59e0b' : '#ef4444');
      document.getElementById('v-analysis').innerHTML = `
        <div class="analysis-label">Project Progress Analysis</div>
        <div>
          <div class="metric-row"><span class="metric-label">Planned Progress</span><span class="metric-val">${m.planned_progress}</span></div>
          <div class="progress-bar"><div class="progress-fill" style="width:${plannedPct}%;background:#3b82f6;"></div></div>
        </div>
        <div>
          <div class="metric-row"><span class="metric-label">Actual Progress</span><span class="metric-val">${m.actual_progress}</span></div>
          <div class="progress-bar"><div class="progress-fill" style="width:${actualPct}%;background:${color};"></div></div>
        </div>
        <div><div class="metric-row"><span class="metric-label">Delay Percentage</span><span class="metric-val">${m.delay_percentage}%</span></div></div>
        <div><div class="metric-row"><span class="metric-label">Risk Level</span><span class="metric-val" style="color:${color};">${m.risk_level}</span></div></div>
      `;
      document.getElementById('loadingOverlay').classList.remove('active');
      document.getElementById('viewOverlay').classList.add('open');

      const row = document.querySelector(`.message[data-email-id="${id}"]`);
      if (row) {
        row.classList.remove('unread');
        const dot = row.querySelector('.unread-dot');
        if (dot) dot.className = 'read-dot';
        const subj = row.querySelector('.message-subject');
        if (subj) subj.style.fontWeight = 'normal';
      }

      const activityItem = document.querySelector(`.mini-item[data-email-id="${id}"]`);
      if (activityItem) {
        const p = activityItem.querySelector('p');
        if (p) p.textContent = p.textContent.replace(/^(Report )\w+/, 'Report opened');
      }
    })
    .catch(() => document.getElementById('loadingOverlay').classList.remove('active'));
}

function closeOverlay() {
  document.getElementById('viewOverlay').classList.remove('open');
}

function searchMessages() {
  const q = document.getElementById('searchInput').value;
  const url = new URL(window.location.href);
  url.searchParams.set('search', q);
  url.searchParams.set('page', '1');
  window.location.href = url.toString();
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeOverlay();
});
</script>
@endsection
