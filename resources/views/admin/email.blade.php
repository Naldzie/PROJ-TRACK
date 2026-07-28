@extends('layouts.app')
@section('content')
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

<style>
* { box-sizing: border-box; }

.mail-wrap {
    display: flex;
    height: calc(100vh - 112px);
    border: 0.5px solid #e2e8f0;
    border-radius: 14px;
    overflow: hidden;
    background: #fff;
}

/* ── SIDEBAR ── */
.mail-sidebar {
    width: 200px;
    min-width: 200px;
    background: #f8fafc;
    border-right: 0.5px solid #e2e8f0;
    padding: 14px 10px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.sidebars-brand {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px 14px;
    border-bottom: 0.5px solid #e2e8f0;
    margin-bottom: 8px;
}

.sidebars-brand-text {
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
}

.sidebars-brand-sub {
    font-size: 10px;
    color: #94a3b8;
    margin-top: 1px;
}

.nav-label {
    font-size: 9px;
    font-weight: 500;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: #cbd5e1;
    padding: 10px 10px 4px;
}

.nav-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 10px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 12px;
    color: #64748b;
    transition: background 0.12s;
    gap: 8px;
}

.nav-item-left { display: flex; align-items: center; gap: 8px; }
.nav-item i { font-size: 13px; flex-shrink: 0; }

.nav-item:hover { background: #f1f5f9; color: #1e293b; }

.nav-item.active {
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 500;
}

/* ── MAIN ── */
.mail-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-width: 0;
}

.mail-topbar {
    padding: 12px 16px;
    border-bottom: 0.5px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    background: #fff;
    flex-shrink: 0;
}

.topbar-left { display: flex; flex-direction: column; }

.topbar-title {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
}

.topbar-sub {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 1px;
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 8px;
}

.search-wrap {
    display: flex;
    align-items: center;
    gap: 6px;
    background: #f8fafc;
    border: 0.5px solid #e2e8f0;
    border-radius: 8px;
    padding: 6px 10px;
}

.search-wrap i { font-size: 12px; color: #94a3b8; }

.mail-search {
    border: none;
    background: transparent;
    font-size: 12px;
    color: #1e293b;
    outline: none;
    width: 180px;
}

.mail-search::placeholder { color: #cbd5e1; }

/* ── MESSAGE LIST ── */
.mail-section {
    flex: 1;
    overflow-y: auto;
}

.msg-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 13px 16px;
    border-bottom: 0.5px solid #f1f5f9;
    cursor: pointer;
    transition: background 0.1s;
    position: relative;
}

.msg-row:hover { background: #f8fafc; }

.msg-dot {
    width: 7px;
    height: 7px;
    flex-shrink: 0;
    margin-top: 6px;
}

.recipient-avatar {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #E6F1FB;
    color: #0C447C;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 500;
    flex-shrink: 0;
}

.msg-content { flex: 1; min-width: 0; }

.msg-row-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 3px;
}

.msg-to {
    font-size: 12px;
    font-weight: 500;
    color: #1e293b;
}

.msg-date {
    font-size: 11px;
    color: #94a3b8;
    flex-shrink: 0;
    margin-left: 10px;
}

.msg-subject {
    font-size: 12px;
    color: #475569;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.msg-preview {
    font-size: 11px;
    color: #94a3b8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.msg-tags {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 5px;
    flex-wrap: wrap;
}

/* ── BADGES ── */
.badge {
    display: inline-flex;
    align-items: center;
    font-size: 10px;
    font-weight: 500;
    padding: 2px 7px;
    border-radius: 5px;
    gap: 3px;
}

.badge-ai       { background: #ede9fe; color: #5b21b6; }
.badge-month    { background: #e0f2fe; color: #075985; }
.badge-opened   { background: #dcfce7; color: #166534; }
.badge-delivered{ background: #dbeafe; color: #1e40af; }

/* ── EMPTY STATE ── */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 200px;
    gap: 8px;
    color: #94a3b8;
    font-size: 13px;
}

/* ── MODAL ── */
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
    background: #fff;
    border-radius: 14px;
    border: 0.5px solid #e2e8f0;
    width: 560px;
    max-width: 100%;
    max-height: 88vh;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

.modal-head {
    padding: 14px 18px;
    border-bottom: 0.5px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    flex-shrink: 0;
    background: #f8fafc;
}

.modal-head-left { flex: 1; min-width: 0; }

.modal-title {
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
    margin: 0 0 4px;
    line-height: 1.4;
}

.modal-meta { font-size: 11px; color: #94a3b8; }

.close-btn {
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: #94a3b8;
    line-height: 1;
    padding: 0;
    flex-shrink: 0;
}

.close-btn:hover { color: #64748b; }

.modal-body {
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    flex: 1;
}

/* Email header banner — dark, matching contractor design */
.email-banner {
    background: #1e2a3b;
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
    background: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.banner-title {
    font-size: 13px;
    font-weight: 600;
    color: #fff;
    letter-spacing: 0.03em;
}

.banner-sub {
    font-size: 11px;
    color: rgba(255,255,255,0.5);
    margin-top: 2px;
}

.msg-text {
    font-size: 13px;
    color: #334155;
    line-height: 1.75;
}

/* Analysis block */
.analysis-block {
    background: #f8fafc;
    border: 0.5px solid #e2e8f0;
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
    color: #94a3b8;
    margin-bottom: 6px;
}

.metric-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    margin-bottom: 5px;
}

.metric-label { color: #64748b; }
.metric-val   { font-weight: 600; color: #1e293b; }

.progress-bar {
    height: 5px;
    background: #e2e8f0;
    border-radius: 99px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    border-radius: 99px;
    transition: width 0.4s ease;
}

/* AI note */
.ai-note {
    background: #f0f9ff;
    border: 0.5px solid #bae6fd;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 11px;
    color: #0369a1;
    line-height: 1.6;
    display: flex;
    gap: 8px;
    align-items: flex-start;
}

.modal-footer {
    padding: 12px 18px;
    border-top: 0.5px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-shrink: 0;
}

.btn-close-modal {
    background: none;
    border: 0.5px solid #e2e8f0;
    color: #475569;
    padding: 7px 16px;
    border-radius: 8px;
    font-size: 12px;
    cursor: pointer;
    transition: background 0.12s;
}

.btn-close-modal:hover { background: #f8fafc; }
</style>

<div class="mail-wrap">

    {{-- ── SIDEBAR ── --}}
    <div class="mail-sidebar">

        <div class="sidebars-brand">
            <i class="bi bi-send" style="font-size:16px;color:#2563eb;"></i>
            <div>
                <div class="sidebars-brand-text">Messages</div>
                <div class="sidebars-brand-sub">Admin portal</div>
            </div>
        </div>

        <div class="nav-label">Mailbox</div>

        <div class="nav-item active" id="nav-sent">
            <div class="nav-item-left">
                <i class="bi bi-send"></i> Sent
            </div>
        </div>

    </div>

    {{-- ── MAIN ── --}}
    <div class="mail-main">

        <div class="mail-topbar">
            <div class="topbar-left">
                <div class="topbar-title">Sent</div>
                <div class="topbar-sub" id="sentCount">Loading messages…</div>
            </div>
            <div class="topbar-right">
                <div class="search-wrap">
                    <i class="bi bi-search"></i>
                    <input class="mail-search" id="searchInput"
                           placeholder="Search messages…"
                           oninput="filterMessages()">
                </div>
            </div>
        </div>

        <div class="mail-section" id="section-sent">
            <div id="msgList"></div>
            <div class="empty-state" id="emptyState" style="display:none;">
                <i class="bi bi-send" style="font-size:28px;"></i>
                <span>No messages found</span>
            </div>
        </div>

    </div>
</div>

{{-- ── VIEW MODAL ── --}}
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
                <div class="email-banner-icon">
                    <i class="bi bi-cpu" style="color:#fff;font-size:18px;"></i>
                </div>
                <div>
                    <div class="banner-title">PROJ-TRACK SYSTEM</div>
                    <div class="banner-sub" id="v-subbanner"></div>
                </div>
            </div>

            <div class="msg-text" id="v-body"></div>

            <div class="analysis-block" id="v-analysis">
                <div class="analysis-label">Project progress analysis</div>
            </div>

            <div class="ai-note">
                <i class="bi bi-info-circle" style="font-size:13px;flex-shrink:0;margin-top:1px;"></i>
                <span>
                    This is an automated monthly AI-generated report dispatched every end of the month.
                    Please do not reply directly to this message. All recommendations are generated by
                    the PROJ-TRACK AI engine and do not constitute an official directive.
                </span>
            </div>

        </div>

        <div class="modal-footer">
            <button class="btn-close-modal" onclick="closeOverlay()">Close</button>
        </div>

    </div>
</div>

<script>
const aiMessages = [
  {
    to: "andalis.liberty@gmail.com",
    toInitials: "AL",
    subject: "AI Project Status Report — Road Widening Phase 2",
    project: "Road Widening Ph.2",
    reportMonth: "April 2025",
    status: "Opened",
    date: "Apr 16",
    subbanner: "Contractor AI Status Report",
    preview: "The project is currently running 14% behind planned physical progress…",
    body: Dear Contractor,\n\nThis is your automated monthly project status report for <strong>April 2025</strong>.\n\nThe PROJ-TRACK AI system has completed its scheduled analysis of your project's progress. Based on the latest submitted accomplishment reports and original project timeline, the following insights have been generated.\n\n<strong>AI Mitigation Suggestion:</strong> The project is currently running 14% behind planned physical progress. Recommend accelerating earthwork activities and deploying additional equipment to close the gap. A schedule recovery plan should be submitted within 5 working days.,
    metrics: [
      { label: "Planned progress", val: "72%", pct: 72, color: "#3b82f6" },
      { label: "Actual progress",  val: "58%", pct: 58, color: "#f59e0b" }
    ]
  },
  {
    to: "juan.builder@gmail.com",
    toInitials: "JB",
    subject: "AI Project Status Report — Multi-Purpose Building",
    project: "Multi-Purpose Bldg",
    reportMonth: "April 2025",
    status: "Delivered",
    date: "Apr 15",
    subbanner: "Contractor AI Status Report",
    preview: "Project is slightly ahead of schedule by 2%. No critical intervention required…",
    body: Dear Contractor,\n\nThis is your automated monthly project status report for <strong>April 2025</strong>.\n\nThis automated AI report summarizes the current project health of the Multi-Purpose Building construction based on submitted data and planned milestones.\n\n<strong>AI Mitigation Suggestion:</strong> Project is slightly ahead of schedule by 2%. No critical intervention required. Continue current resource allocation and maintain quality control standards.,
    metrics: [
      { label: "Planned progress", val: "45%", pct: 45, color: "#3b82f6" },
      { label: "Actual progress",  val: "47%", pct: 47, color: "#10b981" }
    ]
  },
  {
    to: "mdc.construction@gmail.com",
    toInitials: "MC",
    subject: "AI Project Status Report — Drainage Improvement",
    project: "Drainage Improvement",
    reportMonth: "April 2025",
    status: "Opened",
    date: "Apr 14",
    subbanner: "Contractor AI Status Report — CRITICAL",
    preview: "CRITICAL: Project is 27% behind planned progress. Immediate attention required…",
    body: Dear Contractor,\n\nThis is your automated monthly project status report for <strong>April 2025</strong>.\n\nThe PROJ-TRACK AI has detected a significant deviation in the Drainage Improvement project. Immediate attention is required based on the analysis below.\n\n<strong>AI Mitigation Suggestion (CRITICAL):</strong> Project is 27% behind planned progress with only the final phase remaining. Immediate deployment of additional labor force, weekend work scheduling, and daily monitoring reports are strongly advised to avoid project extension penalties.,
    metrics: [
      { label: "Planned progress", val: "88%", pct: 88, color: "#3b82f6" },
      { label: "Actual progress",  val: "61%", pct: 61, color: "#ef4444" }
    ]
  },
  {
    to: "sunrise.infra@gmail.com",
    toInitials: "SI",
    subject: "AI Project Status Report — Barangay Road Concreting",
    project: "Barangay Road",
    reportMonth: "April 2025",
    status: "Delivered",
    date: "Apr 12",
    subbanner: "Contractor AI Status Report",
    preview: "Project is on track and aligned with the planned timeline. No anomalies detected…",
    body: Dear Contractor,\n\nThis is your automated monthly project status report for <strong>April 2025</strong>.\n\nBelow is the AI-generated project status analysis for the Barangay Road Concreting project as of the latest reporting period.\n\n<strong>AI Mitigation Suggestion:</strong> Project is on track and aligned with the planned timeline. AI analysis finds no anomalies. Maintain current work pace and submit next accomplishment report on schedule.,
    metrics: [
      { label: "Planned progress", val: "30%", pct: 30, color: "#3b82f6" },
      { label: "Actual progress",  val: "30%", pct: 30, color: "#10b981" }
    ]
  }
];

let filtered = [...aiMessages];

function renderMessages(data) {
    const list  = document.getElementById('msgList');
    const empty = document.getElementById('emptyState');

    document.getElementById('sentCount').textContent =
        ${data.length} message${data.length !== 1 ? 's' : ''} sent;

    if (!data.length) {
        list.innerHTML = '';
        empty.style.display = 'flex';
        return;
    }

    empty.style.display = 'none';

    list.innerHTML = data.map(m => {
        const globalIdx = aiMessages.indexOf(m);
        const statusClass = m.status === 'Opened' ? 'badge-opened' : 'badge-delivered';

        return `
        <div class="msg-row" onclick="viewMsg(${globalIdx})">
            <div class="msg-dot"></div>
            <div class="recipient-avatar">${m.toInitials}</div>
            <div class="msg-content">
                <div class="msg-row-top">
                    <span class="msg-to">${m.to}</span>
                    <span class="msg-date">${m.date}</span>
                </div>
                <div class="msg-subject">${m.subject}</div>
                <div class="msg-preview">${m.preview}</div>
                <div class="msg-tags">
                    <span class="badge badge-ai">
                        <i class="bi bi-cpu" style="font-size:9px;"></i> AI Report
                    </span>
                    <span class="badge badge-month">${m.reportMonth}</span>
                    <span class="badge ${statusClass}">${m.status}</span>
                </div>
            </div>
        </div>`;
    }).join('');
}

function filterMessages() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    filtered = aiMessages.filter(m =>
        m.to.toLowerCase().includes(q) ||
        m.subject.toLowerCase().includes(q) ||
        m.project.toLowerCase().includes(q) ||
        m.preview.toLowerCase().includes(q)
    );
    renderMessages(filtered);
}

function viewMsg(idx) {
    const m = aiMessages[idx];

    document.getElementById('v-title').textContent     = m.subject;
    document.getElementById('v-subbanner').textContent = m.subbanner;
    document.getElementById('v-meta').textContent      =
        To: ${m.to}  ·  Project: ${m.project}  ·  ${m.date};
    document.getElementById('v-body').innerHTML        =
        m.body.replace(/\n/g, '<br>');

    document.getElementById('v-analysis').innerHTML =
        <div class="analysis-label">Project progress analysis</div> +
        m.metrics.map(mt => `
            <div>
                <div class="metric-row">
                    <span class="metric-label">${mt.label}</span>
                    <span class="metric-val">${mt.val}</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:${mt.pct}%;background:${mt.color};"></div>
                </div>
            </div>
        `).join('');

    document.getElementById('viewOverlay').classList.add('open');
}

function closeOverlay() {
    document.getElementById('viewOverlay').classList.remove('open');
}

renderMessages(filtered);
</script>

@endsection