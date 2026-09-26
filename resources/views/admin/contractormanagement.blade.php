@extends('layouts.app')

@section('content')
@php use Illuminate\Support\Str; @endphp

<style>
:root {
  --ink: oklch(24% .035 273);
  --ink-soft: oklch(39% .035 273);
  --muted: oklch(57% .035 273);
  --quiet: oklch(70% .026 273);
  --line: oklch(88% .022 273);
  --canvas: oklch(96.8% .014 273);
  --surface: oklch(99% .006 273);
  --indigo: oklch(53% .195 275);
  --indigo-deep: oklch(42% .17 275);
  --indigo-soft: oklch(92% .055 275);
  --coral: oklch(67% .145 32);
  --coral-soft: oklch(93% .05 32);
  --green: oklch(52% .12 157);
  --green-soft: oklch(92% .055 157);
  --amber: oklch(62% .13 78);
  --amber-soft: oklch(94% .065 82);
  --shadow-sm: 0 1px 2px oklch(24% .035 273 / .05), 0 8px 24px oklch(24% .035 273 / .035);
  --shadow-md: 0 20px 55px oklch(24% .035 273 / .14);
  --ease: cubic-bezier(.16,1,.3,1);
}
* { box-sizing: border-box; }

.eyebrow {
  color: var(--indigo);
  font-size: 10px; line-height: 1;
  letter-spacing: .14em; text-transform: uppercase;
  font-weight: 800; margin-bottom: 10px;
}
h1 { font-size: clamp(28px, 3.2vw, 42px); letter-spacing: -.06em; line-height: 1.03; margin: 0; }
.header-copy p { margin-top: 11px; color: var(--muted); font-size: 14px; max-width: 56ch; }
.header { padding: 32px 0 24px; display: flex; align-items: end; justify-content: space-between; gap: 24px; }
.primary {
  min-height: 40px; border: 0; border-radius: 9px; padding: 0 15px;
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  background: var(--indigo); color: oklch(98% .008 273);
  font-size: 12px; font-weight: 750;
  box-shadow: 0 5px 12px oklch(53% .195 275 / .2);
  transition: transform .2s var(--ease), background .2s, box-shadow .2s;
  white-space: nowrap;
}
.primary:hover { background: var(--indigo-deep); transform: translateY(-2px); box-shadow: 0 8px 19px oklch(53% .195 275 / .25); }
.primary:active { transform: translateY(0); }
.primary i { font-size: 15px; }

.metrics {
  display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 11px; margin-bottom: 25px;
}
.metric {
  background: var(--surface); border: 1px solid var(--line);
  border-radius: 13px; padding: 16px; box-shadow: var(--shadow-sm);
  position: relative; overflow: hidden;
}
.metric::after {
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 3px;
  background: var(--indigo);
  opacity: .8;
}
.metric:nth-child(2)::after { background: var(--green); }
.metric:nth-child(3)::after { background: var(--amber); }
.metric:nth-child(4)::after { background: var(--coral); }
.metric-top {
  display: flex; align-items: center; justify-content: space-between;
  color: var(--muted); font-size: 11px;
}
.metric-icon {
  width: 28px; height: 28px; border-radius: 8px;
  display: grid; place-items: center;
}
.metric:nth-child(1) .metric-icon { background: var(--indigo-soft); color: var(--indigo); }
.metric:nth-child(2) .metric-icon { background: var(--green-soft); color: var(--green); }
.metric:nth-child(3) .metric-icon { background: var(--amber-soft); color: var(--amber); }
.metric:nth-child(4) .metric-icon { background: var(--coral-soft); color: var(--coral); }
.metric-icon i { font-size: 15px; }
.metric-value { margin-top: 15px; font-size: 26px; line-height: 1; letter-spacing: -.06em; font-weight: 800; font-variant-numeric: tabular-nums; }
.metric-foot { margin-top: 7px; color: var(--muted); font-size: 10px; }
.metric-foot.positive { color: var(--green); font-weight: 700; }

.layout {
  display: grid; grid-template-columns: minmax(0, 1fr) 304px;
  align-items: start; gap: 16px;
}
.panel {
  background: var(--surface); border: 1px solid var(--line);
  border-radius: 15px; box-shadow: var(--shadow-sm); overflow: hidden;
}
.panel-head {
  min-height: 70px; padding: 15px 18px;
  border-bottom: 1px solid var(--line);
  display: flex; align-items: center; justify-content: space-between; gap: 14px;
}
.panel-title { display: flex; align-items: center; gap: 10px; }
.panel-icon {
  width: 29px; height: 29px; border-radius: 8px;
  display: grid; place-items: center;
  background: var(--indigo-soft); color: var(--indigo);
}
.panel-icon i { font-size: 14px; }
.panel h2 { font-size: 15px; letter-spacing: -.025em; margin: 0; }
.panel-title p { color: var(--muted); font-size: 11px; margin: 2px 0 0; }
.panel-count {
  color: var(--muted); background: var(--canvas);
  border: 1px solid var(--line); padding: 5px 8px; border-radius: 18px;
  font-size: 10px; font-weight: 750;
}

.toolbar {
  padding: 14px 18px; border-bottom: 1px solid var(--line);
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
}
.tabs { display: flex; gap: 5px; }
.tab {
  border: 0; border-radius: 7px; background: transparent; color: var(--muted);
  padding: 7px 9px; font-size: 11px; font-weight: 700;
  transition: background .2s, color .2s;
}
.tab:hover { background: var(--canvas); color: var(--ink); }
.tab.active { background: var(--indigo-soft); color: var(--indigo-deep); }
.toolbar-right { display: flex; align-items: center; gap: 8px; }

.search { position: relative; }
.search i {
  position: absolute; left: 10px; top: 50%;
  transform: translateY(-50%); color: var(--quiet); font-size: 12px;
}
.search input {
  height: 34px; width: 200px; border: 1px solid var(--line); border-radius: 8px;
  background: var(--canvas); color: var(--ink);
  padding: 0 10px 0 31px; font-size: 11px;
  transition: border-color .2s, box-shadow .2s;
}
.search input:focus { border-color: oklch(64% .14 275); box-shadow: 0 0 0 3px oklch(53% .195 275 / .12); outline: 0; }
.select {
  height: 34px; border: 1px solid var(--line); border-radius: 8px;
  background: var(--canvas); color: var(--muted); padding: 0 8px; font-size: 11px;
}

.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 720px; }
th {
  height: 42px; padding: 0 18px; border-bottom: 1px solid var(--line);
  color: var(--quiet); font-size: 10px; text-align: left;
  letter-spacing: .08em; text-transform: uppercase; font-weight: 800; white-space: nowrap;
}
td {
  padding: 13px 18px; border-bottom: 1px solid var(--line);
  color: var(--ink-soft); font-size: 12px; vertical-align: middle;
}
tbody tr { transition: background .2s; }
tbody tr:hover { background: var(--indigo-soft); }
tbody tr:last-child td { border-bottom: 0; }
tr.row-disabled { opacity: 0.55; }

.user-cell { display: flex; align-items: center; gap: 10px; min-width: 220px; }
.user-avatar {
  width: 34px; height: 34px; border-radius: 11px;
  display: grid; place-items: center;
  color: var(--indigo-deep); background: var(--indigo-soft);
  font-size: 11px; font-weight: 800;
  flex-shrink: 0; position: relative; overflow: hidden;
}
.user-avatar .avatar-initials { position: relative; }
.user-avatar img.avatar-img {
  position: absolute; inset: 0; width: 100%; height: 100%;
  object-fit: cover; border-radius: inherit; z-index: 1;
}
.user-avatar.coral { background: var(--coral-soft); color: oklch(45% .12 32); }
.user-avatar.gray { background: oklch(91% .018 273); color: var(--muted); }
.user-cell strong { display: block; color: var(--ink); font-size: 12px; line-height: 1.2; }
.user-cell small { display: block; color: var(--muted); font-size: 10px; margin-top: 3px; }

.id-chip {
  display: inline-flex; padding: 5px 8px;
  background: var(--canvas); border: 1px solid var(--line); border-radius: 7px;
  color: var(--ink-soft); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 10px;
}
.email { color: var(--muted); white-space: nowrap; }

.status {
  display: inline-flex; align-items: center; gap: 5px;
  border-radius: 20px; padding: 5px 8px;
  font-size: 10px; font-weight: 750; white-space: nowrap;
}
.status::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
.status.active { background: var(--green-soft); color: var(--green); }
.status.disabled { background: oklch(92% .018 273); color: var(--muted); }

.actions { display: flex; align-items: center; gap: 7px; white-space: nowrap; }
.row-icon {
  width: 31px; height: 31px; border: 1px solid var(--line);
  border-radius: 8px; background: var(--surface);
  color: var(--muted); display: grid; place-items: center;
  transition: color .2s, border-color .2s, background .2s;
}
.row-icon:hover { color: var(--indigo); border-color: oklch(77% .08 275); background: var(--indigo-soft); }
.row-icon.danger:hover { color: oklch(53% .16 25); border-color: oklch(79% .09 25); background: var(--coral-soft); }
.row-icon i { font-size: 14px; }

.side-stack { display: grid; gap: 14px; }
.side-section { padding: 18px; }
.side-section + .side-section { border-top: 1px solid var(--line); }
.side-title { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 15px; }
.side-title h2 { font-size: 14px; margin: 0; }
.side-title span { color: var(--muted); font-size: 10px; }

.profile-card { display: grid; gap: 12px; }
.selected-profile { display: flex; align-items: center; gap: 11px; }
.selected-profile .user-avatar { width: 42px; height: 42px; border-radius: 13px; }
.selected-profile strong { display: block; font-size: 13px; }
.selected-profile small { display: block; color: var(--muted); font-size: 10px; margin-top: 3px; }
.selected-avatar {
  width: 42px; height: 42px; border-radius: 13px; object-fit: cover;
  background: var(--indigo-soft); flex-shrink: 0;
  border: 2px solid rgba(255,255,255,.6); box-shadow: 0 0 0 2px oklch(53% .195 275 / .25);
}
.profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.profile-stat {
  padding: 10px; border-radius: 9px; background: var(--canvas);
}
.profile-stat span { display: block; color: var(--muted); font-size: 10px; }
.profile-stat strong { display: block; margin-top: 3px; font-size: 14px; font-variant-numeric: tabular-nums; }
.profile-link {
  width: 100%; min-height: 34px; border: 1px solid var(--line);
  border-radius: 8px; background: var(--surface);
  color: var(--indigo); font-size: 11px; font-weight: 750;
  transition: background .2s, border-color .2s;
}
.profile-link:hover { background: var(--indigo-soft); border-color: oklch(77% .08 275); }

.activity-list { display: grid; gap: 13px; }
.activity { display: flex; align-items: start; gap: 9px; }
.activity-dot {
  width: 7px; height: 7px; margin-top: 5px;
  border-radius: 50%; background: var(--indigo);
  flex: 0 0 auto;
}
.activity:nth-child(2) .activity-dot { background: var(--coral); }
.activity:nth-child(3) .activity-dot { background: var(--green); }
.activity p { font-size: 11px; line-height: 1.35; margin: 0; }
.activity small { display: block; color: var(--quiet); font-size: 10px; margin-top: 2px; }

.empty { padding: 38px 18px; text-align: center; color: var(--muted); }
.empty strong { display: block; color: var(--ink); font-size: 13px; margin-bottom: 4px; }
.empty p { font-size: 11px; margin: 0; }

dialog {
  width: min(440px, calc(100% - 32px));
  border: 1px solid var(--line); border-radius: 16px;
  background: var(--surface); color: var(--ink);
  padding: 0; box-shadow: var(--shadow-md);
}
dialog::backdrop { background: oklch(24% .035 273 / .38); }
.dialog-head {
  padding: 20px 22px 16px; border-bottom: 1px solid var(--line);
  display: flex; align-items: start; justify-content: space-between; gap: 14px;
}
.dialog-head h2 { font-size: 17px; letter-spacing: -.035em; margin: 0; }
.dialog-head p { color: var(--muted); font-size: 11px; margin: 4px 0 0; }
.dialog-close {
  width: 30px; height: 30px; border: 0; border-radius: 8px;
  background: var(--canvas); color: var(--muted);
  display: grid; place-items: center;
}
.dialog-body { padding: 20px 22px; display: grid; gap: 14px; }
.field { display: grid; gap: 6px; }
.field label { color: var(--ink-soft); font-size: 11px; font-weight: 750; }
.field input, .field select {
  height: 38px; width: 100%; border: 1px solid var(--line);
  border-radius: 8px; background: var(--canvas); color: var(--ink);
  padding: 0 10px; font-size: 12px;
}
.dialog-foot {
  padding: 16px 22px 20px; border-top: 1px solid var(--line);
  display: flex; justify-content: end; gap: 8px;
}
.secondary {
  min-height: 38px; border: 1px solid var(--line);
  border-radius: 8px; background: var(--surface);
  color: var(--muted); padding: 0 13px; font-size: 12px; font-weight: 700;
}
.secondary:hover { background: var(--canvas); color: var(--ink); }

.view-modal-overlay {
  display: none; position: fixed; inset: 0;
  background: oklch(24% .035 273 / .38); z-index: 200;
  align-items: center; justify-content: center; padding: 20px;
}
.view-modal-overlay.open { display: flex; }
.view-modal-box {
  background: var(--surface); border-radius: 16px; border: 1px solid var(--line);
  width: 580px; max-width: 100%; max-height: 88vh; overflow-y: auto;
  display: flex; flex-direction: column; box-shadow: var(--shadow-md);
}
.view-modal-head {
  padding: 20px 22px 16px; border-bottom: 1px solid var(--line);
  display: flex; align-items: start; justify-content: space-between; gap: 14px;
  flex-shrink: 0;
}
.view-modal-head h2 { font-size: 17px; letter-spacing: -.035em; margin: 0; }
.view-modal-head p { color: var(--muted); font-size: 11px; margin: 4px 0 0; }
.view-modal-body { padding: 20px 22px; display: grid; gap: 16px; flex: 1; }
.view-modal-foot {
  padding: 16px 22px 20px; border-top: 1px solid var(--line);
  display: flex; justify-content: end; gap: 8px; flex-shrink: 0;
}
.profile-header { text-align: center; }
.profile-avatar-lg {
  width: 72px; height: 72px; border-radius: 50%; object-fit: cover;
  margin: 0 auto 12px;
}
.profile-header h3 { font-size: 16px; font-weight: 600; margin: 0 0 4px; }
.profile-header p { color: var(--muted); font-size: 12px; margin: 0; }
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.info-item {
  background: var(--canvas); padding: 12px; border-radius: 9px;
}
.info-item .label { display: block; color: var(--muted); font-size: 10px; margin-bottom: 4px; }
.info-item .value { display: block; font-size: 13px; font-weight: 600; color: var(--ink); }

@media (max-width: 1120px) {
  .layout { grid-template-columns: minmax(0, 1fr) 270px; }
  .metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 820px) {
  .layout { grid-template-columns: 1fr; }
  .side-stack { grid-row: 1; }
  .toolbar { flex-direction: column; align-items: stretch; }
  .toolbar-right { width: 100%; }
  .search { flex: 1; }
  .search input { width: 100%; }
  .header { display: block; }
  .header .primary { width: 100%; margin-top: 18px; }
}
@media (max-width: 500px) {
  .metrics { gap: 8px; }
  .metric { padding: 13px; }
  .metric-value { font-size: 22px; }
}
</style>

<!-- HEADER -->
<header class="header">
  <div class="header-copy">
    <div class="eyebrow">Administrative control panel</div>
    <h1>Contractor management</h1>
    <p>Administrative control for contractor records and access.</p>
  </div>
  <button class="primary" onclick="document.getElementById('addDialog').showModal()">
    <i class="bi bi-plus-lg"></i> Add contractor
  </button>
</header>

<!-- METRICS -->
<section class="metrics" aria-label="Contractor summary">
  @php
    $total = count($contractors);
    $active = $contractors->where('is_active', true)->count();
    $inactive = $contractors->where('is_active', false)->count();
    $withCompany = $contractors->filter(fn($c) => $c->contractor && $c->contractor->company_name)->count();
  @endphp
  <article class="metric">
    <div class="metric-top"><span>Total contractors</span><div class="metric-icon"><i class="bi bi-people"></i></div></div>
    <div class="metric-value">{{ $total }}</div>
    <div class="metric-foot">Across all projects</div>
  </article>
  <article class="metric">
    <div class="metric-top"><span>Active now</span><div class="metric-icon"><i class="bi bi-check-lg"></i></div></div>
    <div class="metric-value">{{ $active }}</div>
    <div class="metric-foot positive">{{ $total > 0 ? round($active / $total * 100) : 0 }}% of contractors active</div>
  </article>
  <article class="metric">
    <div class="metric-top"><span>With company</span><div class="metric-icon"><i class="bi bi-building"></i></div></div>
    <div class="metric-value">{{ $withCompany }}</div>
    <div class="metric-foot">Registered companies</div>
  </article>
  <article class="metric">
    <div class="metric-top"><span>Inactive</span><div class="metric-icon"><i class="bi bi-person-slash"></i></div></div>
    <div class="metric-value">{{ $inactive }}</div>
    <div class="metric-foot">Accounts disabled</div>
  </article>
</section>

<!-- LAYOUT -->
<section class="layout">
  <section class="panel" aria-labelledby="roster-title">
    <div class="panel-head">
      <div class="panel-title">
        <div class="panel-icon"><i class="bi bi-people"></i></div>
        <div>
          <h2 id="roster-title">Contractor directory</h2>
          <p id="directorySub">{{ $total }} contractor{{ $total !== 1 ? 's' : '' }} in your workspace</p>
        </div>
      </div>
      <span class="panel-count" id="rowCount">{{ $total }} total</span>
    </div>

    <div class="toolbar">
      <div class="tabs">
        <button class="tab active" data-filter="all">All <span>({{ $total }})</span></button>
        <button class="tab" data-filter="active">Active <span>({{ $active }})</span></button>
        <button class="tab" data-filter="disabled">Disabled <span>({{ $inactive }})</span></button>
      </div>
      <div class="toolbar-right">
        <label class="search">
          <i class="bi bi-search"></i>
          <input id="searchInput" type="search" placeholder="Search contractors..." aria-label="Search contractors">
        </label>
      </div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>User</th>
            <th>ID</th>
            <th>Email</th>
            <th>Company</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="contractorBody">
          @forelse($contractors as $contractor)
          @php
            $user = $contractor;
            $photo = $user?->profile_photo;
            if ($photo && !Str::startsWith($photo, 'http')) {
                $photo = asset('storage/' . $photo);
            }
            $photo = $photo ?? 'https://ui-avatars.com/api/?name=' . urlencode($user->name ?? 'Contractor');
            $initials = '';
            if ($user->name) {
                $parts = explode(' ', $user->name);
                foreach ($parts as $p) { if (strlen($p)) $initials .= strtoupper($p[0]); }
                $initials = substr($initials, 0, 2);
            }
            $tone = $user->is_active ? 'coral' : 'gray';
          @endphp
          <tr class="{{ $user->is_active ? '' : 'row-disabled' }}" data-id="{{ $contractor->id }}" data-photo="{{ $photo }}">
            <td>
              <div class="user-cell">
                <div class="user-avatar {{ $tone === 'coral' ? 'coral' : '' }} {{ $tone === 'gray' ? 'gray' : '' }}">
                  @if($photo)<img class="avatar-img" src="{{ $photo }}" alt="" loading="lazy" onerror="this.remove()">@endif
                  <span class="avatar-initials">{{ $initials ?: '?' }}</span>
                </div>
                <div>
                  <strong>{{ $user->name ?? 'Pending' }}</strong>
                  <small>Contractor</small>
                </div>
              </div>
            </td>
            <td><span class="id-chip">{{ optional($contractor->contractor)->contractor_id ?? 'N/A' }}</span></td>
            <td><span class="email">{{ $user?->email ?? 'N/A' }}</span></td>
            <td><span class="email">{{ optional($contractor->contractor)->company_name ?? '—' }}</span></td>
            <td><span class="status {{ $user->is_active ? 'active' : 'disabled' }}">{{ $user->is_active ? 'Active' : 'Disabled' }}</span></td>
            <td>
              <div class="actions">
                <button class="row-icon viewBtn" data-id="{{ $contractor->id }}" aria-label="View">
                  <i class="bi bi-eye"></i>
                </button>
                <button class="row-icon danger toggleBtn" data-id="{{ $contractor->id }}" data-active="{{ $user->is_active ? '1' : '0' }}" aria-label="{{ $user->is_active ? 'Disable' : 'Enable' }}">
                  <i class="bi {{ $user->is_active ? 'bi-slash-circle' : 'bi-check-circle' }}"></i>
                </button>
              </div>
            </td>
          </tr>
          @empty
          <tr>
            <td colspan="6">
              <div class="empty">
                <strong>No contractors yet</strong>
                <p>Add your first contractor to get started.</p>
              </div>
            </td>
          </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>

  <aside class="side-stack">
    <section class="panel">
      <div class="side-section">
        <div class="side-title">
          <h2>Selected contractor</h2>
          <span id="selectedLabel">Overview</span>
        </div>
        <div class="profile-card" id="profileCard">
          <div class="empty" style="padding:20px 0">
            <p style="font-size:11px;color:var(--muted)">Click a contractor to view details.</p>
          </div>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="side-section">
        <div class="side-title">
          <h2>Recent activity</h2>
          <span>Today</span>
        </div>
        <div class="activity-list">
          @forelse($recentActivity as $i => $log)
          @php $acolors = ['var(--indigo)', 'var(--coral)', 'var(--green)', 'var(--amber)']; @endphp
          <div class="activity">
            <span class="activity-dot" style="background:{{ $acolors[$i % 4] }}"></span>
            <div>
              <p>{{ $log->description }}</p>
              <small>{{ $log->created_at->diffForHumans() }} · {{ $log->user?->name ?? 'System' }}</small>
            </div>
          </div>
          @empty
          <div class="activity">
            <span class="activity-dot" style="background:var(--green)"></span>
            <div>
              <p>No recent activity</p>
              <small>Waiting for events</small>
            </div>
          </div>
          @endforelse
        </div>
      </div>
    </section>
  </aside>
</section>

<!-- ADD DIALOG -->
<dialog id="addDialog">
  <div class="dialog-head">
    <div>
      <h2>Add contractor</h2>
      <p>Invite a contractor to the PMU workspace.</p>
    </div>
    <button class="dialog-close" onclick="document.getElementById('addDialog').close()" aria-label="Close">
      <i class="bi bi-x-lg"></i>
    </button>
  </div>
  <form method="POST" action="{{ url('admin/contractors') }}">
    @csrf
    <div class="dialog-body">
      <div class="field">
        <label for="companyInput">Company name</label>
        <input id="companyInput" type="text" name="company_name" required maxlength="255" placeholder="ABC Construction Corp.">
      </div>
      <div class="field">
        <label for="emailInput">Email address</label>
        <input id="emailInput" type="email" name="email" required placeholder="email@example.com">
      </div>
    </div>
    <div class="dialog-foot">
      <button type="button" class="secondary" onclick="document.getElementById('addDialog').close()">Cancel</button>
      <button type="submit" class="primary">Send invitation</button>
    </div>
  </form>
</dialog>

<!-- VIEW MODAL -->
<div class="view-modal-overlay" id="viewOverlay">
  <div class="view-modal-box">
    <div class="view-modal-head">
      <div>
        <h2>Contractor information</h2>
        <p>Detailed profile and account information.</p>
      </div>
      <button class="dialog-close" onclick="closeViewModal()" aria-label="Close">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <div class="view-modal-body">
      <div class="profile-header">
        <img id="view_photo" class="profile-avatar-lg" src="" alt="">
        <h3 id="view_name"></h3>
        <p id="view_email"></p>
        <div style="margin-top:8px;display:flex;gap:6px;justify-content:center;">
          <span class="status" style="background:var(--indigo-soft);color:var(--indigo-deep);display:inline-flex;align-items:center;gap:4px;border-radius:20px;padding:3px 10px;font-size:10px;font-weight:750;">Contractor</span>
          <span id="view_status" class="status" style="display:inline-flex;align-items:center;gap:4px;border-radius:20px;padding:3px 10px;font-size:10px;font-weight:750;"></span>
        </div>
      </div>
      <div class="info-grid">
        <div class="info-item">
          <span class="label">Contractor ID</span>
          <span class="value" id="view_id"></span>
        </div>
        <div class="info-item">
          <span class="label">Company</span>
          <span class="value" id="view_company"></span>
        </div>
        <div class="info-item">
          <span class="label">Contact Number</span>
          <span class="value" id="view_contact"></span>
        </div>
        <div class="info-item">
          <span class="label">Landline</span>
          <span class="value" id="view_landline"></span>
        </div>
        <div class="info-item" style="grid-column:1/-1">
          <span class="label">Company Address</span>
          <span class="value" id="view_address"></span>
        </div>
      </div>
    </div>
    <div class="view-modal-foot">
      <button class="secondary" onclick="closeViewModal()">Close</button>
    </div>
  </div>
</div>

<script>
// ================= FILTER TABS =================
let currentFilter = 'all';

document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', function() {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    this.classList.add('active');
    currentFilter = this.dataset.filter;
    filterTable();
  });
});

document.getElementById('searchInput').addEventListener('input', filterTable);

function filterTable() {
  const q = document.getElementById('searchInput').value.toLowerCase();
  const rows = Array.from(document.querySelectorAll('#contractorBody tr[data-id]'));
  let visible = 0;

  rows.forEach(row => {
    const isActive = row.querySelector('.status')?.classList.contains('active');
    const matchFilter = currentFilter === 'all' || (currentFilter === 'active' && isActive) || (currentFilter === 'disabled' && !isActive);
    const text = row.textContent.toLowerCase();
    const matchSearch = !q || text.includes(q);

    if (matchFilter && matchSearch) {
      row.style.display = '';
      visible++;
    } else {
      row.style.display = 'none';
    }
  });

  document.getElementById('rowCount').textContent = visible + ' total';
  document.getElementById('directorySub').textContent = visible + ' contractor' + (visible !== 1 ? 's' : '') + ' in your workspace';

  const emptyRow = document.querySelector('#contractorBody tr:last-child');
  if (emptyRow && emptyRow.querySelector('.empty')) {
    emptyRow.style.display = visible > 0 ? 'none' : '';
  }
}

// ================= PROFILE SIDEBAR =================
function renderProfile(id) {
  const row = document.querySelector(`#contractorBody tr[data-id="${id}"]`);
  if (!row) return;

  const name = row.querySelector('.user-cell strong')?.textContent || 'Unknown';
  const email = row.querySelector('.email')?.textContent || '';
  const isActive = row.querySelector('.status')?.classList.contains('active');
  const avatar = row.querySelector('.user-avatar')?.textContent || '?';
  const avatarClass = row.querySelector('.user-avatar')?.className || 'user-avatar';
  const contractorId = row.querySelector('.id-chip')?.textContent || 'N/A';
  const company = row.querySelectorAll('.email')[1]?.textContent || '—';
  const photo = row.dataset.photo || '';

  const avatarEl = photo
    ? `<img class="selected-avatar" src="${photo}" alt="${name}" onerror="this.remove()">`
    : `<div class="${avatarClass}" style="width:42px;height:42px;border-radius:13px;">${avatar}</div>`;

  document.getElementById('selectedLabel').textContent = isActive ? 'Active profile' : 'Disabled profile';
  document.getElementById('profileCard').innerHTML = `
    <div class="selected-profile">
      ${avatarEl}
      <div>
        <strong>${name}</strong>
        <small>${email}</small>
      </div>
    </div>
    <div class="profile-grid">
      <div class="profile-stat"><span>Status</span><strong>${isActive ? 'Active' : 'Disabled'}</strong></div>
      <div class="profile-stat"><span>Contractor ID</span><strong style="font-size:11px">${contractorId}</strong></div>
      <div class="profile-stat"><span>Company</span><strong style="font-size:11px">${company}</strong></div>
      <div class="profile-stat"><span>Email</span><strong style="font-size:11px">${email}</strong></div>
    </div>
    <button class="profile-link" onclick="document.querySelector('.viewBtn[data-id=\\'${id}\\']')?.click()">Open full profile</button>
  `;
}

// ================= TOGGLE STATUS =================
document.addEventListener('click', function(e){
  const btn = e.target.closest('.toggleBtn');
  if(!btn) return;

  let id = btn.dataset.id;
  let isActive = btn.dataset.active === '1';

  Swal.fire({
    title: isActive ? "Disable this contractor?" : "Enable this contractor?",
    text: isActive ? "The contractor will no longer be able to log in." : "The contractor will regain access to log in.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: isActive ? "#d33" : "#16a34a",
    confirmButtonText: isActive ? "Yes, disable" : "Yes, enable"
  }).then(result => {
    if(result.isConfirmed){
      fetch(`/admin/contractors/${id}/toggle-status`, {
        method: "POST",
        headers: {
          "X-CSRF-TOKEN": "{{ csrf_token() }}",
          "Accept": "application/json"
        }
      })
      .then(res => {
        if (!res.ok) throw new Error("Server returned " + res.status);
        return res.json();
      })
      .then(data => {
        if(data.success){
          Swal.fire({ title: "Success", text: data.message, icon: "success", timer: 1500, showConfirmButton: false }).then(() => location.reload());
        } else {
          Swal.fire("Error", data.message || "Action failed", "error");
        }
      })
      .catch(err => {
        Swal.fire("Error", err.message || "Action failed", "error").then(() => location.reload());
      });
    }
  });
});

// ================= VIEW =================
document.addEventListener('click', function(e){
  const btn = e.target.closest('.viewBtn');
  if(!btn) return;

  let id = btn.dataset.id;

  fetch(`/admin/contractors/${id}`)
  .then(res => res.json())
  .then(data => {
    const viewPhoto = document.getElementById('view_photo');
    const avatarUrl = data.profile_photo || `https://ui-avatars.com/api/?name=${encodeURIComponent(data.name ?? 'Contractor')}`;
    viewPhoto.src = avatarUrl;
    viewPhoto.onerror = () => { viewPhoto.src = `https://ui-avatars.com/api/?name=${encodeURIComponent(data.name ?? 'Contractor')}`; };
    document.getElementById('view_name').innerText = data.name ?? 'Pending';
    document.getElementById('view_email').innerText = data.email ?? '-';
    document.getElementById('view_id').innerText = data.contractor_id ?? '-';
    document.getElementById('view_company').innerText = data.company_name ?? '-';
    document.getElementById('view_contact').innerText = data.contact_number ?? '-';
    document.getElementById('view_landline').innerText = data.company_landline ?? '-';
    document.getElementById('view_address').innerText = data.company_address ?? '-';

    const statusEl = document.getElementById('view_status');
    if (statusEl) {
      statusEl.innerText = data.is_active ? 'Active' : 'Disabled';
      statusEl.style.background = data.is_active ? 'var(--green-soft)' : 'oklch(92% .018 273)';
      statusEl.style.color = data.is_active ? 'var(--green)' : 'var(--muted)';
    }

    document.getElementById('viewOverlay').classList.add('open');
  })
  .catch(() => Swal.fire("Error", "Failed to load contractor", "error"));
});

function closeViewModal() {
  document.getElementById('viewOverlay').classList.remove('open');
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeViewModal();
});

// ================= CLICK ROW TO SELECT =================
document.querySelectorAll('#contractorBody tr[data-id]').forEach(row => {
  row.addEventListener('click', function(e) {
    if (!e.target.closest('button')) {
      renderProfile(this.dataset.id);
    }
  });
});

// ================= INIT =================
filterTable();
</script>
@endsection