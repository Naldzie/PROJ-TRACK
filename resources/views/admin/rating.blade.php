@extends('layouts.app')

@section('content')

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">

<style>
* { box-sizing: border-box; }

.page-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.page-title {
    font-size: 17px;
    font-weight: 500;
    color: var(--color-text-primary, #111);
}

.page-sub {
    font-size: 12px;
    color: var(--color-text-tertiary, #9ca3af);
    margin-top: 2px;
}

/* ── SECTION CARD ── */
.section-card {
    background: var(--color-background-primary, #fff);
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    border-radius: 14px;
    overflow: hidden;
    margin-bottom: 16px;
}

.section-head {
    padding: 13px 18px;
    border-bottom: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    background: var(--color-background-secondary, #f9fafb);
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-head-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.section-head-icon.blue   { background: #E6F1FB; }
.section-head-icon.gold   { background: #FAEEDA; }
.section-head-icon.neutral { background: #e5e7eb; }

.section-label {
    font-size: 10px;
    font-weight: 500;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--color-text-tertiary, #9ca3af);
    margin-bottom: 1px;
}

.section-title {
    font-size: 13px;
    font-weight: 500;
    color: var(--color-text-primary, #111);
}

/* ── TABLE ── */
.cr-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.cr-table th {
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--color-text-tertiary, #9ca3af);
    padding: 10px 18px;
    border-bottom: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    background: var(--color-background-secondary, #f9fafb);
    text-align: left;
}

.cr-table th:last-child,
.cr-table td:last-child {
    text-align: right;
}

.cr-table td {
    font-size: 13px;
    color: var(--color-text-primary, #111);
    padding: 13px 18px;
    border-bottom: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    vertical-align: middle;
}

.cr-table tr:last-child td {
    border-bottom: none;
}

.cr-table tbody tr:hover td {
    background: var(--color-background-secondary, #f9fafb);
}

/* ── CONTRACTOR CELL ── */
.contractor-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.c-avatar {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 500;
    flex-shrink: 0;
}

.c-avatar.blue   { background: #E6F1FB; color: #0C447C; }
.c-avatar.teal   { background: #E1F5EE; color: #085041; }
.c-avatar.amber  { background: #FAEEDA; color: #633806; }

.c-name { font-size: 13px; font-weight: 500; color: var(--color-text-primary, #111); }
.c-sub  { font-size: 11px; color: var(--color-text-tertiary, #9ca3af); margin-top: 2px; }

/* ── BADGES ── */
.cr-badge {
    font-size: 10px;
    font-weight: 500;
    padding: 3px 8px;
    border-radius: 5px;
}

.cr-badge.done { background: #EAF3DE; color: #27500A; }
.cr-badge.prog { background: #E6F1FB; color: #0C447C; }

/* ── STARS ── */
.stars-display {
    display: flex;
    align-items: center;
    gap: 2px;
}

.star-on  { color: #BA7517; font-size: 14px; }
.star-off { color: #d1d5db; font-size: 14px; }
.star-count { font-size: 11px; color: var(--color-text-tertiary, #9ca3af); margin-left: 5px; }

/* ── ACTION BUTTONS ── */
.btn-row {
    display: flex;
    align-items: center;
    gap: 6px;
    justify-content: flex-end;
}

.btn-rate {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    padding: 5px 10px;
    border-radius: 7px;
    background: #2563eb;
    color: #fff;
    border: none;
    cursor: pointer;
    font-weight: 500;
    transition: background 0.15s;
}

.btn-rate:hover   { background: #1d4ed8; }
.btn-rate:disabled {
    background: var(--color-background-secondary, #f9fafb);
    color: var(--color-text-tertiary, #9ca3af);
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    cursor: not-allowed;
}

.btn-ai {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    padding: 5px 10px;
    border-radius: 7px;
    background: var(--color-background-secondary, #f9fafb);
    color: var(--color-text-secondary, #6b7280);
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    cursor: pointer;
    font-weight: 500;
    transition: background 0.15s;
}

.btn-ai:hover { background: var(--color-background-tertiary, #f3f4f6); }

/* ── BOTTOM GRID ── */
.bottom-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 16px;
}

/* ── LEADERBOARD ── */
.leaderboard {
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.lb-item {
    display: flex;
    align-items: center;
    gap: 12px;
}

.lb-rank {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 500;
    flex-shrink: 0;
}

.lb-rank.r1 { background: #FAEEDA; color: #633806; }
.lb-rank.r2 { background: #F1EFE8; color: #444441; }
.lb-rank.r3 { background: #FAECE7; color: #712B13; }

.lb-name { font-size: 13px; font-weight: 500; color: var(--color-text-primary, #111); }
.lb-sub  { font-size: 11px; color: var(--color-text-tertiary, #9ca3af); }

.lb-bar-wrap {
    width: 100px;
    height: 5px;
    background: var(--color-background-secondary, #f9fafb);
    border-radius: 99px;
    overflow: hidden;
}

.lb-bar {
    height: 100%;
    border-radius: 99px;
    background: #2563eb;
    transition: width 0.4s ease;
}

.lb-score {
    font-size: 11px;
    color: var(--color-text-tertiary, #9ca3af);
    text-align: right;
    margin-top: 3px;
}

/* ── AI PANEL ── */
.ai-body { padding: 16px 18px; }

.ai-empty {
    font-size: 13px;
    color: var(--color-text-tertiary, #9ca3af);
    text-align: center;
    padding: 12px 0;
}

.ai-result {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px;
    border-radius: 10px;
    border: 0.5px solid;
}

.ai-result.good { background: #EAF3DE; border-color: #97C459; }
.ai-result.ok   { background: #FAEEDA; border-color: #EF9F27; }
.ai-result.bad  { background: #FCEBEB; border-color: #F09595; }

.ai-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    font-size: 14px;
    font-weight: 500;
}

.ai-icon.good { background: #C0DD97; color: #27500A; }
.ai-icon.ok   { background: #FAC775; color: #633806; }
.ai-icon.bad  { background: #F7C1C1; color: #791F1F; }

.ai-text-head { font-size: 13px; font-weight: 500; margin-bottom: 3px; }
.ai-text-head.good { color: #27500A; }
.ai-text-head.ok   { color: #633806; }
.ai-text-head.bad  { color: #791F1F; }

.ai-text-body { font-size: 12px; line-height: 1.5; }
.ai-text-body.good { color: #3B6D11; }
.ai-text-body.ok   { color: #854F0B; }
.ai-text-body.bad  { color: #A32D2D; }

/* ── MODAL ── */
.cr-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.35);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.cr-modal-overlay.show { display: flex; }

.cr-modal {
    background: var(--color-background-primary, #fff);
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    border-radius: 14px;
    width: 340px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0,0,0,0.12);
}

.cr-modal-head {
    padding: 14px 16px;
    border-bottom: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    background: var(--color-background-secondary, #f9fafb);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.cr-modal-title {
    font-size: 14px;
    font-weight: 500;
    color: var(--color-text-primary, #111);
}

.cr-modal-close {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 20px;
    color: var(--color-text-tertiary, #9ca3af);
    line-height: 1;
    padding: 0;
}

.cr-modal-body { padding: 20px; }

.star-select {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin-bottom: 12px;
}

.star-btn {
    font-size: 30px;
    background: none;
    border: none;
    cursor: pointer;
    color: #d1d5db;
    transition: color 0.1s, transform 0.1s;
    line-height: 1;
    padding: 0;
}

.star-btn.on { color: #BA7517; transform: scale(1.1); }

.star-hint {
    text-align: center;
    font-size: 12px;
    color: var(--color-text-secondary, #6b7280);
    min-height: 16px;
    margin-bottom: 4px;
}

.cr-modal-footer {
    padding: 12px 16px;
    border-top: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.btn-cancel {
    font-size: 13px;
    padding: 7px 14px;
    border-radius: 8px;
    background: var(--color-background-secondary, #f9fafb);
    color: var(--color-text-secondary, #6b7280);
    border: 0.5px solid var(--color-border-tertiary, #e5e7eb);
    cursor: pointer;
}

.btn-save {
    font-size: 13px;
    padding: 7px 14px;
    border-radius: 8px;
    background: #2563eb;
    color: #fff;
    border: none;
    cursor: pointer;
    font-weight: 500;
    transition: background 0.15s;
}

.btn-save:hover { background: #1d4ed8; }
</style>

<div class="container mt-4" style="max-width: 960px;">

    <div class="page-head">
        <div>
            <div class="page-title">Contractor rating system</div>
            <div class="page-sub">Manage and review contractor performance</div>
        </div>
    </div>

    {{-- ── CONTRACTOR LIST ── --}}
    <div class="section-card">

        <div class="section-head">
            <div class="section-head-icon blue">
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                    <rect x="1" y="1" width="12" height="12" rx="2" stroke="#185FA5" stroke-width="1.2"/>
                    <path d="M4 7h6M4 4.5h6M4 9.5h3" stroke="#185FA5" stroke-width="1.2" stroke-linecap="round"/>
                </svg>
            </div>
            <div>
                <div class="section-label">Records</div>
                <div class="section-title">Contractor list</div>
            </div>
        </div>

        <table class="cr-table">
            <thead>
                <tr>
                    <th style="width:32%">Contractor</th>
                    <th style="width:22%">Project</th>
                    <th style="width:14%">Status</th>
                    <th style="width:16%">Rating</th>
                    <th style="width:16%">Actions</th>
                </tr>
            </thead>
            <tbody>

                {{-- IN PROGRESS: not rateable --}}
                <tr>
                    <td>
                        <div class="contractor-cell">
                            <div class="c-avatar amber">AB</div>
                            <div>
                                <div class="c-name">ABC Builders</div>
                                <div class="c-sub">General contractor</div>
                            </div>
                        </div>
                    </td>
                    <td style="color:var(--color-text-secondary,#6b7280)">Road Expansion</td>
                    <td><span class="cr-badge prog">In progress</span></td>
                    <td><span style="font-size:12px;color:var(--color-text-tertiary,#9ca3af)">Not yet rated</span></td>
                    <td>
                        <div class="btn-row">
                            <button class="btn-rate" disabled>Rate</button>
                        </div>
                    </td>
                </tr>

                {{-- COMPLETED: rateable --}}
                <tr>
                    <td>
                        <div class="contractor-cell">
                            <div class="c-avatar blue">SC</div>
                            <div>
                                <div class="c-name">StrongBuild Corp</div>
                                <div class="c-sub">General contractor</div>
                            </div>
                        </div>
                    </td>
                    <td style="color:var(--color-text-secondary,#6b7280)">School Building</td>
                    <td><span class="cr-badge done">Completed</span></td>
                    <td>
                        <div class="stars-display" id="stars-rate1">
                            <span class="star-on">★</span><span class="star-on">★</span>
                            <span class="star-on">★</span><span class="star-on">★</span>
                            <span class="star-off">★</span>
                            <span class="star-count">4/5</span>
                        </div>
                    </td>
                    <td>
                        <div class="btn-row">
                            <button class="btn-rate" onclick="openRating('rate1', 4)">Rate</button>
                            <button class="btn-ai"   onclick="showAI('rate1', 4)">AI</button>
                        </div>
                    </td>
                </tr>

                <tr>
                    <td>
                        <div class="contractor-cell">
                            <div class="c-avatar teal">PC</div>
                            <div>
                                <div class="c-name">Prime Construction</div>
                                <div class="c-sub">Specialist contractor</div>
                            </div>
                        </div>
                    </td>
                    <td style="color:var(--color-text-secondary,#6b7280)">Bridge Repair</td>
                    <td><span class="cr-badge done">Completed</span></td>
                    <td>
                        <div class="stars-display" id="stars-rate2">
                            <span class="star-on">★</span><span class="star-on">★</span>
                            <span class="star-on">★</span><span class="star-off">★</span>
                            <span class="star-off">★</span>
                            <span class="star-count">3/5</span>
                        </div>
                    </td>
                    <td>
                        <div class="btn-row">
                            <button class="btn-rate" onclick="openRating('rate2', 3)">Rate</button>
                            <button class="btn-ai"   onclick="showAI('rate2', 3)">AI</button>
                        </div>
                    </td>
                </tr>

            </tbody>
        </table>
    </div>

    {{-- ── BOTTOM GRID: Leaderboard + AI ── --}}
    <div class="bottom-grid">

        {{-- TOP CONTRACTORS --}}
        <div class="section-card">
            <div class="section-head">
                <div class="section-head-icon gold">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                        <path d="M7 1l1.5 3.5H12l-2.8 2.2 1 3.3L7 8.2l-3.2 1.8 1-3.3L2 4.5h3.5L7 1z"
                              stroke="#BA7517" stroke-width="1.1" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div>
                    <div class="section-label">Rankings</div>
                    <div class="section-title">Top contractors</div>
                </div>
            </div>

            <div class="leaderboard">
                <div class="lb-item">
                    <div class="lb-rank r1">1</div>
                    <div style="flex:1">
                        <div class="lb-name">StrongBuild Corp</div>
                        <div class="lb-sub">School Building</div>
                    </div>
                    <div>
                        <div class="lb-bar-wrap">
                            <div class="lb-bar" id="bar-rate1" style="width:80%"></div>
                        </div>
                        <div class="lb-score" id="score-rate1">4/5</div>
                    </div>
                </div>
                <div class="lb-item">
                    <div class="lb-rank r2">2</div>
                    <div style="flex:1">
                        <div class="lb-name">Prime Construction</div>
                        <div class="lb-sub">Bridge Repair</div>
                    </div>
                    <div>
                        <div class="lb-bar-wrap">
                            <div class="lb-bar" id="bar-rate2" style="width:60%"></div>
                        </div>
                        <div class="lb-score" id="score-rate2">3/5</div>
                    </div>
                </div>
                <div class="lb-item">
                    <div class="lb-rank r3">3</div>
                    <div style="flex:1">
                        <div class="lb-name">ABC Builders</div>
                        <div class="lb-sub">Road Expansion</div>
                    </div>
                    <div>
                        <div class="lb-bar-wrap">
                            <div class="lb-bar" style="width:0%"></div>
                        </div>
                        <div class="lb-score">—</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- AI ANALYSIS --}}
        <div class="section-card">
            <div class="section-head">
                <div class="section-head-icon neutral">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                        <circle cx="7" cy="7" r="5.5" stroke="#444441" stroke-width="1.2"/>
                        <path d="M5 5.5C5 4.7 5.9 4 7 4s2 .7 2 1.5c0 1.5-2 1.5-2 3"
                              stroke="#444441" stroke-width="1.2" stroke-linecap="round"/>
                        <circle cx="7" cy="10" r="0.6" fill="#444441"/>
                    </svg>
                </div>
                <div>
                    <div class="section-label">Analysis</div>
                    <div class="section-title">AI performance review</div>
                </div>
            </div>
            <div class="ai-body">
                <div id="aiContent">
                    <div class="ai-empty">Rate a contractor to see the AI analysis</div>
                </div>
            </div>
        </div>

    </div>

</div>

{{-- ── RATING MODAL ── --}}
<div class="cr-modal-overlay" id="ratingOverlay">
    <div class="cr-modal">
        <div class="cr-modal-head">
            <span class="cr-modal-title">Rate contractor</span>
            <button class="cr-modal-close" onclick="closeModal()">×</button>
        </div>
        <div class="cr-modal-body">
            <div class="star-select" id="starSelect">
                <button class="star-btn" onclick="pickStar(1)">★</button>
                <button class="star-btn" onclick="pickStar(2)">★</button>
                <button class="star-btn" onclick="pickStar(3)">★</button>
                <button class="star-btn" onclick="pickStar(4)">★</button>
                <button class="star-btn" onclick="pickStar(5)">★</button>
            </div>
            <div class="star-hint" id="starHint">Tap a star to rate</div>
        </div>
        <div class="cr-modal-footer">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-save"   onclick="saveRating()">Save rating</button>
        </div>
    </div>
</div>

<script>
let currentCell = '';
let currentStar = 0;

const ratings = { rate1: 4, rate2: 3 };

const starLabels = ['', 'Poor performance', 'Below average', 'Acceptable', 'Good performance', 'Excellent performance'];

function openRating(id, current) {
    currentCell = id;
    currentStar = current || 0;
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

function saveRating() {
    if (!currentStar) { closeModal(); return; }

    ratings[currentCell] = currentStar;

    // update star display in table
    const cell = document.getElementById('stars-' + currentCell);
    let html = '';
    for (let i = 1; i <= 5; i++) {
        html += `<span class="${i <= currentStar ? 'star-on' : 'star-off'}">★</span>`;
    }
    html += `<span class="star-count">${currentStar}/5</span>`;
    cell.innerHTML = html;

    // update leaderboard bar
    const bar = document.getElementById('bar-' + currentCell);
    const score = document.getElementById('score-' + currentCell);
    if (bar)   bar.style.width = (currentStar / 5 * 100) + '%';
    if (score) score.textContent = currentStar + '/5';

    closeModal();
}

function showAI(id, fallback) {
    const score = ratings[id] !== undefined ? ratings[id] : fallback;
    let cls, icon, head, body;

    if (score >= 4) {
        cls  = 'good'; icon = '✓';
        head = 'Strong performance';
        body = 'This contractor delivered high-quality results. Recommended for future projects with similar scope.';
    } else if (score === 3) {
        cls  = 'ok'; icon = '!';
        head = 'Acceptable, with room to improve';
        body = 'Performance met basic requirements but fell short in some areas. Consider a review before re-engagement.';
    } else {
        cls  = 'bad'; icon = '✕';
        head = 'Performance needs attention';
        body = 'Significant issues were identified. Close monitoring is advised and re-engagement should be carefully evaluated.';
    }

    document.getElementById('aiContent').innerHTML = `
        <div class="ai-result ${cls}">
            <div class="ai-icon ${cls}">${icon}</div>
            <div>
                <div class="ai-text-head ${cls}">${head}</div>
                <div class="ai-text-body ${cls}">${body}</div>
            </div>
        </div>`;
}
</script>

@endsection