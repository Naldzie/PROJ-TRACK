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

.page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  margin-bottom: 20px;
}
.page-head h1 {
  font-size: 22px;
  font-weight: 800;
  letter-spacing: -.03em;
  margin: 0;
  color: var(--ink);
}
.page-head .breadcrumb {
  font-size: 11px;
  color: var(--muted);
  margin-top: 3px;
}
.page-head .breadcrumb a {
  color: var(--blue);
  text-decoration: none;
}

.overview {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 11px;
  margin-bottom: 25px;
}
.metric {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 13px;
  padding: 15px 16px;
  box-shadow: var(--shadow-sm);
  position: relative;
  overflow: hidden;
}
.metric::after {
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 3px;
  background: var(--blue);
  opacity: .8;
}
.metric:nth-child(2)::after { background: var(--purple); }
.metric:nth-child(3)::after { background: var(--green); }
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
.tabs {
  display: flex;
  gap: 5px;
}
.tab {
  border: 0;
  border-radius: 7px;
  background: transparent;
  color: var(--muted);
  padding: 7px 11px;
  font-size: 11px;
  font-weight: 700;
  transition: background .2s, color .2s;
  cursor: pointer;
}
.tab:hover { background: var(--canvas); color: var(--ink); }
.tab.active { background: var(--blue-soft); color: var(--blue); }
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
.mail-search:focus + .search-wrap { border-color: var(--blue); }
.select-sort {
  height: 34px;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--canvas);
  color: var(--muted);
  font-size: 11px;
  padding: 0 8px;
}
.next-send-box {
  margin-top: 15px;
  background: var(--blue-pale);
  border: 1px dashed oklch(70% .12 265);
  border-radius: 12px;
  padding: 13px 14px;
}
.next-send-head {
  display: flex;
  align-items: center;
  gap: 7px;
  color: var(--blue);
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
}
.next-send-date {
  font-size: 17px;
  font-weight: 800;
  letter-spacing: -.03em;
  color: var(--ink);
  margin-top: 6px;
  font-variant-numeric: tabular-nums;
}
.next-send-sub {
  font-size: 10px;
  color: var(--muted);
  margin-top: 2px;
}
.next-send-count {
  margin-top: 10px;
  display: flex;
  gap: 6px;
  font-variant-numeric: tabular-nums;
}
.next-send-count .unit {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 5px 8px;
  text-align: center;
  min-width: 48px;
}
.next-send-count .unit b {
  display: block;
  font-size: 15px;
  font-weight: 800;
  letter-spacing: -.03em;
  color: var(--ink);
  line-height: 1.1;
}
.next-send-count .unit span {
  display: block;
  font-size: 8px;
  font-weight: 700;
  color: var(--muted);
  margin-top: 2px;
}
.field-label {
  color: var(--muted);
  font-size: 10px;
  font-weight: 700;
  display: block;
  margin: 15px 0 6px;
}
.full-select {
  width: 100%;
  height: 36px;
  border: 1px solid var(--line);
  border-radius: 8px;
  color: var(--ink);
  background: var(--canvas);
  font-size: 11px;
  padding: 0 9px;
}
.btn-send {
  width: 100%;
  margin-top: 13px;
  min-height: 40px;
  border: 0;
  border-radius: 9px;
  background: var(--blue);
  color: oklch(98% .008 270);
  padding: 0 15px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  box-shadow: 0 5px 12px oklch(55% .205 265 / .2);
  transition: transform .2s var(--ease), background .2s, box-shadow .2s;
}
.btn-send:hover { background: oklch(45% .18 265); transform: translateY(-2px); box-shadow: 0 8px 18px oklch(55% .205 265 / .25); }
.btn-send:active { transform: translateY(0); }
.btn-send:disabled { opacity: .45; cursor: not-allowed; box-shadow: none; transform: none; }

.message-list { display: grid; }
.message {
  position: relative;
  display: grid;
  grid-template-columns: 40px minmax(0,1fr) auto;
  gap: 12px;
  padding: 15px 18px;
  border-bottom: 1px solid var(--line);
  transition: background .2s var(--ease);
  cursor: pointer;
}
.message:last-child { border-bottom: 0; }
.message:hover { background: var(--blue-pale); }
.message.unread::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 3px;
  background: var(--blue);
}
.message-avatar {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  background: var(--blue-soft);
  color: var(--blue);
  display: grid;
  place-items: center;
  font-size: 11px;
  font-weight: 800;
  overflow: hidden;
}
.message-avatar img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  padding: 4px;
  border-radius: 10px;
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

.next-send-box {
  background: var(--blue-pale);
  border: 1px dashed oklch(70% .12 265);
  border-radius: 12px;
  padding: 13px 14px;
}
.next-send-date {
  font-size: 17px;
  font-weight: 800;
  letter-spacing: -.03em;
  color: var(--ink);
  font-variant-numeric: tabular-nums;
}
.next-send-sub {
  font-size: 10px;
  color: var(--muted);
  margin-top: 4px;
  line-height: 1.45;
}
.next-send-count {
  margin-top: 11px;
  display: flex;
  gap: 6px;
  font-variant-numeric: tabular-nums;
}
.next-send-count .unit {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 5px 8px;
  text-align: center;
  min-width: 48px;
}
.next-send-count .unit b {
  display: block;
  font-size: 15px;
  font-weight: 800;
  letter-spacing: -.03em;
  color: var(--ink);
  line-height: 1.1;
}
.next-send-count .unit span {
  display: block;
  font-size: 8px;
  font-weight: 700;
  color: var(--muted);
  margin-top: 2px;
}

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
  overflow-y: auto;
}
.overlay.open { display: flex; }
.modal-box {
  background: var(--surface);
  border-radius: 14px;
  border: 1px solid var(--line);
  width: 580px;
  max-width: 100%;
  margin: auto;
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
.modal-body { padding: 18px; display: flex; flex-direction: column; gap: 14px; }
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
/* Parsed AI sections inside the overlay (same blue headings as the project AI tab) */
.msg-text .report-section { margin-bottom: 14px; }
.msg-text .report-section:last-child { margin-bottom: 0; }
.msg-text .report-section-content p { margin: 0 0 10px; }
.msg-text .report-section-content p:last-child { margin-bottom: 0; }
.msg-text .report-section-content ul { margin: 8px 0; padding-left: 20px; }
.msg-text .report-section-content li { margin: 4px 0; }
.msg-text .report-section-content ol { margin: 8px 0; padding-left: 20px; }
.msg-text .report-section-content ol li { margin: 4px 0; }
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

/* ── View overlay: highlighted report sections (mirrors emails/ai-analysis) ── */
.v-status-pill {
  display: none;
  align-items: center;
  gap: 6px;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: .06em;
  text-transform: uppercase;
  border-radius: 999px;
  padding: 4px 10px;
  margin-top: 8px;
  width: fit-content;
}
.v-status-pill.show { display: inline-flex; }
.v-status-pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.v-status-opened { background: var(--green-soft); color: var(--green); }
.v-status-delivered { background: oklch(93% .04 220); color: oklch(40% .12 220); }
.v-status-pending { background: var(--gold-soft); color: oklch(48% .12 78); }
.v-section { border-radius: 12px; border: 1px solid; overflow: hidden; }
.v-section-head { padding: 10px 14px; display: flex; align-items: center; gap: 8px; }
.v-section-num {
  width: 22px; height: 22px; line-height: 22px; text-align: center;
  border-radius: 7px; font-size: 11px; font-weight: 800; color: #fff; flex-shrink: 0;
}
.v-section-title { font-size: 11px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; }
.v-section-body { padding: 12px 14px 14px; background: var(--surface); }
.v-blue { border-color: #bfdbfe; background: #eff6ff; }
.v-blue .v-section-num { background: #2563eb; }
.v-blue .v-section-title { color: #1d4ed8; }
.v-violet { border-color: #ddd6fe; background: #f5f3ff; }
.v-violet .v-section-num { background: #7c3aed; }
.v-violet .v-section-title { color: #6d28d9; }
.v-green { border-color: #bbf7d0; background: #f0fdf4; }
.v-green .v-section-num { background: #059669; }
.v-green .v-section-title { color: #047857; }
#v-analysis.analysis-block { background: transparent; border: 0; padding: 0; }
/* Anti-blowout: long content must wrap, never force tracks wider */
.detail-item,
.modal-head-left,
.v-section-body,
.msg-text,
.v-section-head { min-width: 0; }
.detail-item .value,
.modal-title,
.modal-meta,
.msg-text,
.v-section-title { overflow-wrap: anywhere; word-break: break-word; }
@media (max-width: 620px) {
  .detail-grid { grid-template-columns: 1fr; }
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
  .message { grid-template-columns: 40px minmax(0,1fr); }
  .message-actions { display: none; }
}
@media (max-width: 480px) {
  .overview { grid-template-columns: 1fr 1fr; gap: 8px; }
  .metric { padding: 13px; }
  .metric-value { font-size: 22px; }
  .page-head h1 { font-size: 19px; }
  .tabs { width: 100%; }
  .tab { flex: 1; }
  .message { padding: 14px 13px; gap: 10px; }
  .message-snippet { white-space: normal; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
}
@media (max-width: 380px) {
  .overview { grid-template-columns: 1fr; }
  .detail-grid { grid-template-columns: 1fr; }
  .panel-head, .toolbar { padding: 12px 14px; }
  .email-banner { padding: 16px; }
}
</style>

<!-- PAGE HEAD -->
<div class="page-head">
  <div>
    <h1>Email Reports</h1>
    <div class="breadcrumb"><a href="{{ route('contractor.dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Email</div>
  </div>
</div>

<!-- OVERVIEW METRICS -->
@php
    $totalEmails = $emails instanceof \Illuminate\Pagination\LengthAwarePaginator ? $emails->total() : 0;
    $unreadCount = $emails instanceof \Illuminate\Pagination\LengthAwarePaginator
        ? $emails->sum(fn($e) => $e->is_read ? 0 : 1)
        : 0;
@endphp
<section class="overview" aria-label="Message summary">
  <article class="metric">
    <div class="metric-top"><span>Messages</span><div class="metric-icon"><i class="bi bi-envelope"></i></div></div>
    <div class="metric-value">{{ $totalEmails }}</div>
    <div class="metric-foot positive">In your inbox</div>
  </article>
  <article class="metric">
    <div class="metric-top"><span>Unread</span><div class="metric-icon"><i class="bi bi-envelope-open"></i></div></div>
    <div class="metric-value">{{ $unreadCount }}</div>
    <div class="metric-foot">Requires attention</div>
  </article>
  <article class="metric">
    <div class="metric-top"><span>Next report</span><div class="metric-icon"><i class="bi bi-hourglass-split"></i></div></div>
    <div class="metric-value" style="font-size:22px">{{ $nextAutoSend->format('M d, Y') }}</div>
    <div class="metric-foot">Arrives in <span id="nextAutoSendCountdown" style="font-variant-numeric:tabular-nums"></span></div>
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
      <div class="tabs">
        <button class="tab active" data-filter="all">All <span>({{ $totalEmails }})</span></button>
        <button class="tab" data-filter="unread">Unread <span>({{ $unreadCount }})</span></button>
        <button class="tab" data-filter="read">Read</button>
      </div>
      <div class="toolbar-right">
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
          <h2>Next report</h2>
          <span>Monthly</span>
        </div>
        <div class="next-send-box" id="nextSendBox" data-target="{{ $nextAutoSend->timestamp }}">
          <div class="next-send-head">
            <i class="bi bi-calendar-event"></i>
            <span>Next auto-send</span>
          </div>
          <div class="next-send-date">{{ $nextAutoSend->format('F d, Y') }}</div>
          <div class="next-send-sub">Monthly · every 1st of the month</div>
          <div class="next-send-count" id="nextSendCount">–</div>
        </div>
      </div>
    </section>

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
            <span class="mini-dot" style="background:var(--blue)"></span>
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
        <span class="v-status-pill" id="v-statuspill"></span>
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
      <div class="v-section v-blue">
        <div class="v-section-head"><span class="v-section-num">01</span><span class="v-section-title">Project snapshot</span></div>
        <div class="v-section-body"><div class="detail-grid" id="v-details"></div></div>
      </div>
      <div class="v-section v-violet">
        <div class="v-section-head"><span class="v-section-num">02</span><span class="v-section-title">AI analysis</span></div>
        <div class="v-section-body"><div class="msg-text" id="v-body"></div></div>
      </div>
      <div class="v-section v-green">
        <div class="v-section-head"><span class="v-section-num">03</span><span class="v-section-title">Progress analysis</span></div>
        <div class="v-section-body">
          <div class="analysis-block" id="v-analysis">
            <div class="analysis-label">Project Progress Analysis</div>
          </div>
        </div>
      </div>
      <div class="ai-note">
        <i class="bi bi-info-circle" style="font-size:13px;flex-shrink:0;margin-top:1px;"></i>
        <span>This is an automated monthly AI-generated report dispatched every end of the month. Please do not reply directly to this message. All recommendations are generated by the PROJ-TRACK AI engine and do not constitute an official directive.</span>
      </div>
      <div class="ai-note" style="background:#fef9c3;border-color:#fde68a;color:#854d0e;">
        <i class="bi bi-shield-lock" style="font-size:13px;flex-shrink:0;margin-top:1px;"></i>
        <span>This email contains integrity monitoring technology. For audit and security purposes, delivery and open events may be recorded using a secure tracking pixel. No personal information beyond standard email delivery metadata is collected.</span>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-close-modal" onclick="closeOverlay()">Close</button>
    </div>
  </div>
</div>

<script>
let currentEmailId = null;

function applyFilter(filter) {
  const rows = document.querySelectorAll('.message');
  rows.forEach(row => {
    const read = row.dataset.read;
    const show = filter === 'all' || (filter === 'unread' && read === 'unread') || (filter === 'read' && read === 'read');
    row.style.display = show ? '' : 'none';
  });
  const empty = document.getElementById('emptyState');
  if (empty) empty.style.display = Array.from(rows).some(r => r.style.display !== 'none') ? 'none' : '';
}

document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', function() {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    this.classList.add('active');
    applyFilter(this.dataset.filter);
  });
});

function viewMsg(id) {
  currentEmailId = id;
  document.getElementById('loadingOverlay').classList.add('active');
  fetch(`/contractor/emails/${id}`)
    .then(r => r.json())
    .then(m => {
      document.getElementById('v-title').textContent = m.subject;
      document.getElementById('v-subbanner').textContent = 'Contractor AI Status Report';
      document.getElementById('v-meta').textContent = `From: ${m.from}  ·  Project: ${m.project_title}  ·  ${m.month}`;
      const statusPill = document.getElementById('v-statuspill');
      statusPill.className = 'v-status-pill show v-status-' + (m.status || '').toLowerCase();
      statusPill.textContent = m.status || '';
      document.getElementById('v-details').innerHTML = `
        <div class="detail-item"><span class="label">Project</span><span class="value">${m.project_title}</span></div>
        <div class="detail-item"><span class="label">Location</span><span class="value">${m.project_location}</span></div>
        <div class="detail-item"><span class="label">Status</span><span class="value">${m.project_status}</span></div>
        <div class="detail-item"><span class="label">AI Generated</span><span class="value">${m.ai_generated_at}</span></div>
        <div class="detail-item"><span class="label">Email Sent</span><span class="value">${m.generated_at}</span></div>
      `;
      document.getElementById('v-body').innerHTML = m.body_html || m.body.replace(/\n/g, '<br>');
      const plannedPct = parseFloat(m.planned_progress) || 0;
      const actualPct = parseFloat(m.actual_progress) || 0;
      const variance = m.variance;
      const color = variance >= 0 ? '#10b981' : (variance >= -15 ? '#f59e0b' : '#ef4444');
      const delayPct = parseFloat(m.delay_percentage) || 0;
      let delayLabel = '';
      let delayColor = '';
      if (delayPct > 0) {
        delayLabel = `${delayPct}% Behind`;
        delayColor = '#ef4444';
      } else if (delayPct < 0) {
        delayLabel = `${Math.abs(delayPct)}% Ahead`;
        delayColor = '#10b981';
      } else {
        delayLabel = 'On Track';
        delayColor = '#10b981';
      }

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
        <div><div class="metric-row"><span class="metric-label">Delay Percentage</span><span class="metric-val" style="color:${delayColor};">${delayLabel}</span></div></div>
        <div><div class="metric-row"><span class="metric-label">Risk Level</span><span class="metric-val" style="color:${color};">${m.risk_level}</span></div></div>
      `;
      document.getElementById('loadingOverlay').classList.remove('active');
      document.getElementById('viewOverlay').classList.add('open');

      const row = document.querySelector(`.message[data-email-id="${id}"]`);
      if (row) {
        row.classList.remove('unread');
        row.dataset.read = 'read';
        applyFilter(document.querySelector('.tab.active').dataset.filter);
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

/* Next report countdown (targets the 1st of next month, 08:00). */
(function() {
  const box = document.getElementById('nextSendBox');
  const metricEl = document.getElementById('nextAutoSendCountdown');
  if (!box && !metricEl) return;

  const target = new Date({{ $nextAutoSend->timestamp }} * 1000);
  function pad(n) { return String(n).padStart(2, '0'); }

  function tick() {
    let diff = Math.max(0, target - new Date());
    const d = Math.floor(diff / 86400000);
    const h = Math.floor(diff % 86400000 / 3600000);
    const m = Math.floor(diff % 3600000 / 60000);
    const s = Math.floor(diff % 60000 / 1000);

    if (box) {
      document.getElementById('nextSendCount').innerHTML =
        `<div class="unit"><b>${pad(d)}</b><span>days</span></div>` +
        `<div class="unit"><b>${pad(h)}</b><span>hrs</span></div>` +
        `<div class="unit"><b>${pad(m)}</b><span>min</span></div>` +
        `<div class="unit"><b>${pad(s)}</b><span>sec</span></div>`;
    }
    if (metricEl) {
      metricEl.textContent = `${d}d ${pad(h)}h ${pad(m)}m ${pad(s)}s`;
    }
  }

  tick();
  setInterval(tick, 1000);
})();
</script>
@endsection
