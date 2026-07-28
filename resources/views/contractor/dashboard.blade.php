@extends('layouts.contractorlayouts')

@section('content')
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

<style>
  :root {
    --primary-color: #2563eb;
    --primary-dark: #1e40af;
    --success-color: #059669;
    --warning-color: #f59e0b;
    --danger-color: #dc2626;
    --text-main: #1f2937;
    --text-muted: #6b7280;
    --text-light: #9ca3af;
    --bg-light: #f3f4f6;
    --bg-lighter: #f9fafb;
    --border: #e5e7eb;
  }

  /* ===== DASHBOARD LAYOUT & ZONES ===== */
  .dashboard-page {
    display: flex;
    flex-direction: column;
    gap: 30px;
  }

  .dashboard-zone {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  .zone-header {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 18px;
    font-weight: 700;
    color: var(--text-main);
    padding-bottom: 12px;
    border-bottom: 2px solid var(--border);
    margin-bottom: 5px;
  }

  .zone-header i {
    font-size: 22px;
    color: var(--primary-color);
  }

  /* ===== PAGE HEADER ===== */
  .dashboard-header {
    background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
    border-radius: 15px;
    padding: 30px;
    color: white;
    margin-bottom: 10px;
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.15);
  }

  .dashboard-header h2 {
    margin: 0;
    font-weight: 600;
    font-size: 28px;
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .dashboard-header p {
    margin: 8px 0 0;
    opacity: 0.9;
    font-size: 14px;
  }

  .dashboard-header-actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
  }

  .quick-action-btn {
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s;
  }

  /* ===== STAT CARDS ===== */
  .stat-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 10px;
  }

  .stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.2s;
    border: 1px solid var(--border);
  }

  .stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
  }

  .stat-label {
    font-size: 12px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .stat-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .stat-val {
    font-size: 32px;
    font-weight: 700;
    line-height: 1;
    color: var(--text-main);
    margin-bottom: 8px;
  }

  .stat-sub {
    font-size: 12px;
    color: var(--text-light);
  }

  /* ===== BODY GRID ===== */
  .body-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr);
    gap: 20px;
  }

  .left-col {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  .right-col {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  /* ===== CARDS ===== */
  .d-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    transition: all 0.2s;
  }

  .d-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  }

  .d-card-title {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
  }

  .d-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--bg-light);
  }

  /* ===== TIMELINE ===== */
  .tl-header {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--bg-light);
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .tl-name-col {
    flex: 0 0 160px;
  }

  .tl-bar-col {
    flex: 1;
    display: flex;
    justify-content: space-between;
  }

  .tl-end-col {
    flex: 0 0 80px;
    text-align: right;
  }

  .tl-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 0;
    border-bottom: 1px solid var(--bg-light);
  }

  .tl-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
  }

  .tl-row:first-child {
    padding-top: 0;
  }

  .tl-name {
    flex: 0 0 160px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-main);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .tl-loc {
    font-size: 11px;
    color: var(--text-light);
    margin-top: 3px;
  }

  .tl-track {
    flex: 1;
    position: relative;
    height: 8px;
  }

  .tl-bg {
    position: absolute;
    height: 6px;
    top: 1px;
    border-radius: 3px;
    background: var(--bg-light);
  }

  .tl-fill {
    position: absolute;
    height: 6px;
    top: 1px;
    border-radius: 3px;
  }

  .tl-pct {
    flex: 0 0 80px;
    text-align: right;
  }

  .status-pill {
    display: inline-block;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
  }

  .pill-completed {
    background: #d1fae5;
    color: #065f46;
  }

  .pill-inprogress {
    background: #dbeafe;
    color: #1e40af;
  }

  .pill-notstarted {
    background: #fed7aa;
    color: #92400e;
  }

  /* ===== BUDGET ===== */
  .budget-total {
    font-size: 28px;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 4px;
  }

  .budget-sub {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 16px;
  }

  .bbar-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    font-size: 12px;
  }

  .bbar-row:last-child {
    margin-bottom: 0;
  }

  .bbar-name {
    flex: 0 0 100px;
    color: var(--text-muted);
    font-weight: 500;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .bbar-track {
    flex: 1;
    height: 8px;
    background: var(--bg-light);
    border-radius: 4px;
    overflow: hidden;
  }

  .bbar-fill {
    height: 8px;
    border-radius: 4px;
  }

  .bbar-amt {
    flex: 0 0 70px;
    text-align: right;
    color: var(--text-main);
    font-weight: 600;
  }

  /* ===== DONUT CHART ===== */
  .donut-wrap {
    display: flex;
    align-items: center;
    gap: 20px;
  }

  .leg-row {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 8px;
    font-weight: 500;
  }

  .leg-row:last-child {
    margin-bottom: 0;
  }

  .leg-sq {
    width: 10px;
    height: 10px;
    border-radius: 3px;
    flex-shrink: 0;
  }

  .leg-val {
    margin-left: auto;
    font-weight: 700;
    color: var(--text-main);
    min-width: 30px;
    text-align: right;
  }

  /* ===== DEADLINES ===== */
  .dl-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--bg-light);
  }

  .dl-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
  }

  .dl-row:first-child {
    padding-top: 0;
  }

  .dl-date {
    flex: 0 0 56px;
    text-align: center;
    background: var(--bg-light);
    border-radius: 8px;
    padding: 8px 0;
    border-left: 3px solid var(--primary-color);
  }

  .dl-month {
    font-size: 10px;
    color: var(--text-light);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .dl-day {
    font-size: 18px;
    font-weight: 700;
    line-height: 1;
    color: var(--text-main);
    margin-top: 2px;
  }

  .dl-info {
    flex: 1;
    min-width: 0;
  }

  .dl-proj {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-main);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .dl-desc {
    font-size: 12px;
    color: var(--text-light);
    margin-top: 2px;
  }

  /* ===== ACTIVITY ===== */
  .act-row {
    display: flex;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid var(--bg-light);
    align-items: flex-start;
  }

  .act-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
  }

  .act-row:first-child {
    padding-top: 0;
  }

  .act-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
    margin-top: 4px;
  }

  .act-body {
    flex: 1;
    min-width: 0;
  }

  .act-proj {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-main);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .act-text {
    font-size: 12px;
    color: var(--text-light);
    margin-top: 2px;
  }

  .act-time {
    font-size: 11px;
    color: var(--text-light);
    white-space: nowrap;
    flex-shrink: 0;
    padding-top: 2px;
  }

  /* ===== RESPONSIVE ===== */
  @media (max-width: 1024px) {
    .body-grid {
      grid-template-columns: 1fr;
    }

    .stat-cards {
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }
  }

  @media (max-width: 768px) {
    .dashboard-header {
      padding: 20px;
    }

    .dashboard-header h2 {
      font-size: 22px;
    }

    .stat-cards {
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
    }

    .stat-card {
      padding: 16px;
    }

    .d-card {
      padding: 16px;
    }

    .donut-wrap {
      flex-direction: column;
      gap: 16px;
    }
  }

  @media (max-width: 480px) {
    .stat-cards {
      grid-template-columns: 1fr;
    }

    .dashboard-header h2 {
      font-size: 18px;
      flex-direction: column;
      align-items: flex-start;
    }
  }
</style>

<div class="dashboard-page" style="padding: 2rem;">

  <!-- ===== PAGE HEADER ===== -->
  <div class="dashboard-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
      <div>
        <h2><i class="bi bi-briefcase me-2"></i>My Projects Dashboard</h2>
        <p>View your assigned projects, track progress, and manage project activities.</p>
      </div>
      <div class="dashboard-header-actions">
        <a href="#" class="btn btn-light quick-action-btn">
          <i class="bi bi-eye me-1"></i> View All Projects
        </a>
      </div>
    </div>
  </div>

  <!-- ===== STAT CARDS ===== -->
  <div class="stat-cards">
    <div class="stat-card">
      <div class="stat-label"><span class="stat-dot" style="background:#888780"></span>Total assigned</div>
      <div class="stat-val">5</div>
      <div class="stat-sub">All projects</div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><span class="stat-dot" style="background:#059669"></span>Completed</div>
      <div class="stat-val" style="color:#059669">2</div>
      <div class="stat-sub">40% of total</div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><span class="stat-dot" style="background:#2563eb"></span>In progress</div>
      <div class="stat-val" style="color:#2563eb">2</div>
      <div class="stat-sub">45% of total</div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><span class="stat-dot" style="background:#f59e0b"></span>Not yet started</div>
      <div class="stat-val" style="color:#f59e0b">1</div>
      <div class="stat-sub">15% of total</div>
    </div>
  </div>

  {{-- MAIN BODY --}}
  <div class="body-grid">

    {{-- LEFT COLUMN --}}
    <div class="left-col">

      {{-- TIMELINE --}}
      <div class="d-card">
        <div class="d-card-head">
          <span class="d-card-title">Project timeline</span>
          <span style="font-size:10px;color:#adb5bd">Jan – Dec 2024</span>
        </div>
        <div class="tl-header">
          <div class="tl-name-col">Project</div>
          <div class="tl-bar-col"><span>Jan</span><span>Apr</span><span>Jul</span><span>Oct</span><span>Dec</span></div>
          <div class="tl-end-col">Progress</div>
        </div>

        {{-- Replace this block with @foreach($projects as $project) in real implementation --}}
        <div class="tl-row">
          <div class="tl-name" title="Building A Renovation">Building A Renovation<div class="tl-loc">Batangas City</div></div>
          <div class="tl-track">
            <div class="tl-bg"   style="left:0%;width:54%"></div>
            <div class="tl-fill" style="left:0%;width:54%;background:#059669"></div>
          </div>
          <div class="tl-pct"><span class="status-pill pill-completed">100%</span></div>
        </div>
        <div class="tl-row">
          <div class="tl-name" title="Road Expansion">Road Expansion<div class="tl-loc">Lipa City</div></div>
          <div class="tl-track">
            <div class="tl-bg"   style="left:15%;width:70%"></div>
            <div class="tl-fill" style="left:15%;width:49%;background:#2563eb"></div>
          </div>
          <div class="tl-pct"><span class="status-pill pill-inprogress">70%</span></div>
        </div>
        <div class="tl-row">
          <div class="tl-name" title="Bridge Construction">Bridge Construction<div class="tl-loc">Tanauan</div></div>
          <div class="tl-track">
            <div class="tl-bg"   style="left:23%;width:77%"></div>
            <div class="tl-fill" style="left:23%;width:35%;background:#2563eb"></div>
          </div>
          <div class="tl-pct"><span class="status-pill pill-inprogress">45%</span></div>
        </div>
        <div class="tl-row">
          <div class="tl-name" title="Water System Upgrade">Water System Upgrade<div class="tl-loc">Sto. Tomas</div></div>
          <div class="tl-track">
            <div class="tl-bg"   style="left:46%;width:31%"></div>
            <div class="tl-fill" style="left:46%;width:0%;background:#f59e0b"></div>
          </div>
          <div class="tl-pct"><span class="status-pill pill-notstarted">0%</span></div>
        </div>
        <div class="tl-row">
          <div class="tl-name" title="Admin Bldg Extension">Admin Bldg Extension<div class="tl-loc">Batangas City</div></div>
          <div class="tl-track">
            <div class="tl-bg"   style="left:8%;width:54%"></div>
            <div class="tl-fill" style="left:8%;width:54%;background:#059669"></div>
          </div>
          <div class="tl-pct"><span class="status-pill pill-completed">100%</span></div>
        </div>
        {{-- End foreach --}}
      </div>

      {{-- BUDGET --}}
      <div class="d-card">
        <div class="d-card-head">
          <span class="d-card-title">Total budget allocation</span>
        </div>
        <div class="budget-total">₱34,550,000</div>
        <div class="budget-sub">Across 5 assigned projects</div>

        {{-- Replace with @foreach in real implementation --}}
        <div class="bbar-row">
          <div class="bbar-name" title="Building A Renovation">Building A</div>
          <div class="bbar-track"><div class="bbar-fill" style="width:12%;background:#059669"></div></div>
          <div class="bbar-amt">₱4.2M</div>
        </div>
        <div class="bbar-row">
          <div class="bbar-name" title="Road Expansion">Road Expansion</div>
          <div class="bbar-track"><div class="bbar-fill" style="width:25%;background:#2563eb"></div></div>
          <div class="bbar-amt">₱8.75M</div>
        </div>
        <div class="bbar-row">
          <div class="bbar-name" title="Bridge Construction">Bridge Constr.</div>
          <div class="bbar-track"><div class="bbar-fill" style="width:35%;background:#2563eb"></div></div>
          <div class="bbar-amt">₱12.0M</div>
        </div>
        <div class="bbar-row">
          <div class="bbar-name" title="Water System Upgrade">Water System</div>
          <div class="bbar-track"><div class="bbar-fill" style="width:10%;background:#f59e0b"></div></div>
          <div class="bbar-amt">₱3.5M</div>
        </div>
        <div class="bbar-row">
          <div class="bbar-name" title="Admin Bldg Extension">Admin Bldg</div>
          <div class="bbar-track"><div class="bbar-fill" style="width:18%;background:#059669"></div></div>
          <div class="bbar-amt">₱6.1M</div>
        </div>
        {{-- End foreach --}}
      </div>

    </div>{{-- end left-col --}}

    {{-- RIGHT COLUMN --}}
    <div class="right-col">

      {{-- STATUS DONUT --}}
      <div class="d-card">
        <div class="d-card-head"><span class="d-card-title">Status overview</span></div>
        <div class="donut-wrap">
          <div style="position:relative;width:96px;height:96px;flex-shrink:0">
            <canvas id="statusDonut" role="img" aria-label="Donut chart: 2 completed, 2 in progress, 1 not started">
              2 completed, 2 in progress, 1 not started.
            </canvas>
          </div>
          <div style="flex:1">
            <div class="leg-row"><span class="leg-sq" style="background:#639922"></span>Completed<span class="leg-val">2</span></div>
            <div class="leg-row"><span class="leg-sq" style="background:#378ADD"></span>In progress<span class="leg-val">2</span></div>
            <div class="leg-row"><span class="leg-sq" style="background:#EF9F27"></span>Not started<span class="leg-val">1</span></div>
          </div>
        </div>
      </div>

      {{-- UPCOMING DEADLINES --}}
      <div class="d-card">
        <div class="d-card-head"><span class="d-card-title">Upcoming deadlines</span></div>

        {{-- Replace with @foreach in real implementation --}}
        <div class="dl-row">
          <div class="dl-date"><div class="dl-month">Aug</div><div class="dl-day">30</div></div>
          <div class="dl-info">
            <div class="dl-proj">Building A Renovation</div>
            <div class="dl-desc">Final turnover &amp; inspection</div>
          </div>
          <span class="status-pill pill-completed">Done</span>
        </div>
        <div class="dl-row">
          <div class="dl-date"><div class="dl-month">Nov</div><div class="dl-day">15</div></div>
          <div class="dl-info">
            <div class="dl-proj">Road Expansion</div>
            <div class="dl-desc">Monthly progress report due</div>
          </div>
          <span class="status-pill pill-inprogress">Active</span>
        </div>
        <div class="dl-row">
          <div class="dl-date"><div class="dl-month">Dec</div><div class="dl-day">10</div></div>
          <div class="dl-info">
            <div class="dl-proj">Road Expansion</div>
            <div class="dl-desc">Target completion date</div>
          </div>
          <span class="status-pill pill-inprogress">Active</span>
        </div>
        <div class="dl-row">
          <div class="dl-date"><div class="dl-month">Oct</div><div class="dl-day">31</div></div>
          <div class="dl-info">
            <div class="dl-proj">Water System Upgrade</div>
            <div class="dl-desc">Target completion date</div>
          </div>
          <span class="status-pill pill-notstarted">Pending</span>
        </div>
        {{-- End foreach --}}
      </div>

      {{-- RECENT ACTIVITY --}}
      <div class="d-card">
        <div class="d-card-head"><span class="d-card-title">Recent activity</span></div>

        {{-- Replace with @foreach in real implementation --}}
        <div class="act-row">
          <div class="act-dot" style="background:#2563eb"></div>
          <div class="act-body">
            <div class="act-proj">Road Expansion</div>
            <div class="act-text">Monthly activities for Oct submitted</div>
          </div>
          <div class="act-time">2h ago</div>
        </div>
        <div class="act-row">
          <div class="act-dot" style="background:#2563eb"></div>
          <div class="act-body">
            <div class="act-proj">Bridge Construction</div>
            <div class="act-text">Monthly activities for Oct submitted</div>
          </div>
          <div class="act-time">1d ago</div>
        </div>
        <div class="act-row">
          <div class="act-dot" style="background:#2563eb"></div>
          <div class="act-body">
            <div class="act-proj">Bridge Construction</div>
            <div class="act-text">Progress updated to 45%</div>
          </div>
          <div class="act-time">1d ago</div>
        </div>
        <div class="act-row">
          <div class="act-dot" style="background:#2563eb"></div>
          <div class="act-body">
            <div class="act-proj">Road Expansion</div>
            <div class="act-text">Progress updated to 70%</div>
          </div>
          <div class="act-time">3d ago</div>
        </div>
        <div class="act-row">
          <div class="act-dot" style="background:#059669"></div>
          <div class="act-body">
            <div class="act-proj">Admin Bldg Extension</div>
            <div class="act-text">Marked as completed</div>
          </div>
          <div class="act-time">2w ago</div>
        </div>
        {{-- End foreach --}}
      </div>

    </div>{{-- end right-col --}}
  </div>{{-- end body-grid --}}

</div>{{-- end dashboard-page --}}

<script>
  new Chart(document.getElementById('statusDonut'), {
    type: 'doughnut',
    data: {
      labels: ['Completed', 'In Progress', 'Not Started'],
      datasets: [{
        data: [2, 2, 1],
        backgroundColor: ['#059669', '#2563eb', '#f59e0b'],
        borderWidth: 0,
        hoverOffset: 3
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '70%',
      plugins: { legend: { display: false } }
    }
  });
</script>

@endsection