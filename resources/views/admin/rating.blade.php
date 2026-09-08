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
  --violet: oklch(60% .22 292);
  --gold: oklch(73% .15 74);
  --gold-soft: oklch(94% .06 82);
  --mint: oklch(75% .12 160);
  --mint-soft: oklch(92% .055 160);
  --rose: oklch(64% .15 18);
  --grad-brand: linear-gradient(135deg, var(--blue) 0%, var(--violet) 100%);
  --shadow-sm: 0 1px 2px oklch(25% .03 285 / .05), 0 8px 20px oklch(25% .03 285 / .035);
  --shadow-md: 0 12px 36px oklch(25% .03 285 / .09);
  --shadow-lg: 0 24px 60px oklch(41% .18 264 / .16);
  --ease-out: cubic-bezier(.16,1,.3,1);
  --ease-spring: cubic-bezier(.34,1.56,.64,1);
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; }
button:focus-visible, input:focus-visible, select:focus-visible {
  outline: 2px solid var(--blue);
  outline-offset: 2px;
}

/* ── Motion primitives ── */
@keyframes rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
@keyframes pop-in { 0% { opacity: 0; transform: scale(.92) translateY(10px); } 100% { opacity: 1; transform: none; } }
@keyframes shine { from { transform: translateX(-130%) skewX(-18deg); } to { transform: translateX(260%) skewX(-18deg); } }
@keyframes shimmer { from { background-position: 200% 0; } to { background-position: -200% 0; } }
@keyframes pulse-dot { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.55); opacity: .5; } }
@keyframes star-pop { 0% { transform: scale(.4); } 60% { transform: scale(1.4); } 100% { transform: scale(1.1); } }
@keyframes glow-drift {
  0%, 100% { transform: translate(0, 0) scale(1); }
  50% { transform: translate(24px, -12px) scale(1.08); }
}
.rise { animation: rise .55s var(--ease-out) backwards; animation-delay: var(--d, 0s); }
.rise-item { animation: rise .45s var(--ease-out) backwards; }

.page-head {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 24px;
  margin-bottom: 28px;
  flex-wrap: wrap;
  position: relative;
}
.page-head::before {
  content: '';
  position: absolute;
  top: -70px;
  right: -40px;
  width: 340px;
  height: 220px;
  background: radial-gradient(closest-side, oklch(62% .2 292 / .16), transparent 70%);
  pointer-events: none;
  animation: glow-drift 9s ease-in-out infinite;
}
.eyebrow {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  color: var(--blue);
  font: 700 10px/1 'Space Mono', 'SF Mono', monospace;
  letter-spacing: .12em;
  text-transform: uppercase;
  margin-bottom: 10px;
  background: var(--blue-soft);
  border: 1px solid oklch(82% .09 264);
  padding: 7px 11px;
  border-radius: 999px;
}
.eyebrow::before {
  content: '';
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--grad-brand);
  animation: pulse-dot 2s ease-in-out infinite;
}
.page-head h1 {
  font-size: clamp(28px, 3vw, 42px);
  letter-spacing: -.055em;
  line-height: 1.05;
  margin: 0;
  text-wrap: balance;
  background: linear-gradient(100deg, var(--ink) 30%, var(--blue) 75%, var(--violet) 100%);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  color: transparent;
}
.page-head p {
  margin: 12px 0 0;
  color: var(--muted);
  max-width: 54ch;
  font-size: 15px;
}
.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  position: relative;
  z-index: 1;
}
.ai-online {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font: 700 11px/1 'Space Mono', 'SF Mono', monospace;
  color: #15803d;
  background: linear-gradient(180deg, #f0fdf4, #dcfce7);
  border: 1px solid #bbf7d0;
  padding: 6px 12px 6px 6px;
  border-radius: 999px;
  box-shadow: 0 2px 8px rgb(34 197 94 / .15);
  white-space: nowrap;
}
.primary {
  border: 0;
  min-height: 42px;
  border-radius: 12px;
  padding: 0 18px;
  background: var(--grad-brand);
  color: oklch(98% .01 92);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 700;
  box-shadow: 0 6px 16px oklch(54% .21 264 / .3);
  transition: transform .2s var(--ease-out), box-shadow .2s, filter .2s;
  white-space: nowrap;
  position: relative;
  overflow: hidden;
  cursor: pointer;
}
.primary::after {
  content: '';
  position: absolute;
  top: 0;
  bottom: 0;
  width: 45%;
  background: linear-gradient(90deg, transparent, rgb(255 255 255 / .35), transparent);
  transform: translateX(-130%) skewX(-18deg);
  pointer-events: none;
}
.primary:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); filter: saturate(1.1); }
.primary:hover::after { animation: shine .7s ease; }
.primary:active { transform: translateY(0) scale(.98); }
.primary svg { width: 16px; height: 16px; }
.primary:disabled { opacity: .65; cursor: wait; transform: none; }

.summary-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 28px;
}
.summary {
  background: linear-gradient(180deg, var(--surface), oklch(97.5% .012 92));
  border: 1px solid var(--line);
  border-radius: 16px;
  padding: 18px 18px 16px;
  box-shadow: var(--shadow-sm);
  min-width: 0;
  position: relative;
  overflow: hidden;
  transition: transform .25s var(--ease-out), box-shadow .25s var(--ease-out), border-color .25s;
  animation: rise .55s var(--ease-out) backwards;
  animation-delay: var(--d, 0s);
}
.summary::before {
  content: '';
  position: absolute;
  top: -30px;
  right: -30px;
  width: 110px;
  height: 110px;
  border-radius: 50%;
  background: radial-gradient(closest-side, oklch(62% .18 264 / .14), transparent 70%);
  pointer-events: none;
  transition: transform .3s var(--ease-out);
}
.summary:hover::before { transform: scale(1.5); }
.summary::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: var(--grad-brand);
  opacity: .9;
}
.summary:nth-child(2)::after { background: linear-gradient(90deg, var(--gold), oklch(80% .12 60)); }
.summary:nth-child(3)::after { background: linear-gradient(90deg, oklch(70% .14 160), var(--mint)); }
.summary:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); border-color: oklch(78% .08 264); }
.summary-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 18px;
  color: var(--muted);
  font-size: 12px;
}
.summary-icon {
  width: 34px;
  height: 34px;
  border-radius: 11px;
  display: grid;
  place-items: center;
  box-shadow: inset 0 0 0 1px rgb(255 255 255 / .5), 0 3px 8px oklch(25% .03 285 / .1);
}
.summary-icon svg { width: 16px; height: 16px; }
.summary:nth-child(1) .summary-icon { background: linear-gradient(135deg, oklch(70% .16 264), var(--blue-dark)); color: #fff; }
.summary:nth-child(2) .summary-icon { background: linear-gradient(135deg, oklch(82% .13 80), oklch(62% .14 60)); color: #fff; }
.summary:nth-child(3) .summary-icon { background: linear-gradient(135deg, oklch(78% .13 165), oklch(52% .12 165)); color: #fff; }
.summary:nth-child(4) .summary-icon { background: linear-gradient(135deg, oklch(72% .14 18), oklch(55% .15 18)); color: #fff; }
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
  border-radius: 18px;
  box-shadow: var(--shadow-sm);
  overflow: hidden;
  transition: box-shadow .25s var(--ease-out), border-color .25s;
  animation: rise .55s var(--ease-out) backwards;
  animation-delay: var(--d, 0s);
}
.panel:hover { box-shadow: 0 14px 34px oklch(25% .03 285 / .09); border-color: oklch(84% .05 285); }
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
  width: 32px;
  height: 32px;
  border-radius: 10px;
  background: var(--grad-brand);
  color: #fff;
  display: grid;
  place-items: center;
  flex: 0 0 auto;
  box-shadow: 0 4px 10px oklch(54% .21 264 / .3);
}
.projects-panel .panel-title-icon { background: linear-gradient(135deg, oklch(65% .17 250), oklch(55% .2 300)); }
.perf-panel .panel-title-icon { background: linear-gradient(135deg, oklch(70% .14 165), oklch(50% .13 200)); }
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

.contractor-list, .project-list { scrollbar-width: thin; scrollbar-color: oklch(80% .045 285) transparent; }
.contractor-list { display: grid; gap: 6px; max-height: 560px; overflow-y: auto; padding-right: 3px; }
.project-list { display: grid; gap: 10px; max-height: 640px; overflow-y: auto; padding-right: 3px; }
.contractor-list::-webkit-scrollbar, .project-list::-webkit-scrollbar { width: 8px; }
.contractor-list::-webkit-scrollbar-thumb, .project-list::-webkit-scrollbar-thumb {
  background: oklch(82% .045 285); border-radius: 8px; border: 2px solid var(--surface);
}
.contractor-list::-webkit-scrollbar-track, .project-list::-webkit-scrollbar-track { background: transparent; }
.contractor {
  width: 100%; border: 1px solid transparent; border-radius: 12px; padding: 12px 11px;
  background: transparent; display: grid; grid-template-columns: 36px minmax(0,1fr) auto;
  align-items: center; gap: 10px; text-align: left; color: var(--ink); cursor: pointer;
  transition: background .2s var(--ease-out), border-color .2s, transform .2s var(--ease-out), box-shadow .2s;
}
.contractor:hover { background: var(--canvas); transform: translateX(3px); border-color: var(--line); }
.contractor.selected {
  background: linear-gradient(180deg, oklch(94% .05 264), oklch(91% .06 264));
  border-color: oklch(72% .13 264);
  box-shadow: 0 4px 14px oklch(54% .21 264 / .18), inset 0 0 0 1px rgb(255 255 255 / .6);
}
.contractor-avatar {
  width: 36px; height: 36px; border-radius: 12px; display: grid; place-items: center;
  background: linear-gradient(135deg, var(--blue-soft), var(--surface-2));
  color: var(--blue-dark); font-size: 11px; font-weight: 700; letter-spacing: .02em;
  transition: background .2s, color .2s, transform .2s var(--ease-out);
  box-shadow: inset 0 0 0 1px rgb(255 255 255 / .55);
}
.contractor:hover .contractor-avatar { transform: scale(1.06) rotate(-2deg); }
.contractor.selected .contractor-avatar { background: var(--grad-brand); color: #fff; box-shadow: 0 3px 10px oklch(54% .21 264 / .4); }
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

/* ── Performance Panel ── */
.right-col {
  display: flex;
  flex-direction: column;
  gap: 16px;
  align-self: start;
}
.perf-panel {
  margin-top: 0;
}
.perf-panel .panel-head {
  padding: 14px 20px 12px;
}
.perf-panel .panel-body {
  padding: 16px 20px 20px;
}
.perf-grid {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.perf-top {
  display: flex;
  align-items: center;
  gap: 14px;
}
.perf-hero {
  width: 52px; height: 52px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; font-weight: 800; letter-spacing: -.03em;
  flex: 0 0 auto;
}
.perf-hero.perf-5 { background: oklch(92% .055 160); color: oklch(32% .14 160); }
.perf-hero.perf-4 { background: oklch(92% .055 160); color: oklch(38% .12 160); }
.perf-hero.perf-3 { background: oklch(94% .06 82); color: oklch(42% .14 74); }
.perf-hero.perf-2 { background: oklch(93% .04 18); color: #b91c1c; }
.perf-hero.perf-1 { background: oklch(93% .04 18); color: #991b1b; }
.perf-hero.perf-na { background: var(--surface-2); color: var(--faint); font-size: 14px; }
.perf-top-text { display: flex; flex-direction: column; }
.perf-top-label { font-size: 11px; color: var(--muted); }
.perf-top-value { font-size: 15px; font-weight: 700; letter-spacing: -.02em; }
.perf-stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.perf-stat {
  display: flex; flex-direction: column; gap: 3px;
  background: var(--canvas);
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 10px 12px;
  transition: transform .2s var(--ease-out), box-shadow .2s, border-color .2s;
  animation: rise .45s var(--ease-out) backwards;
}
.perf-stats .perf-stat:nth-child(2) { animation-delay: .04s; }
.perf-stats .perf-stat:nth-child(3) { animation-delay: .08s; }
.perf-stats .perf-stat:nth-child(4) { animation-delay: .12s; }
.perf-stats .perf-stat:nth-child(5) { animation-delay: .16s; }
.perf-stats .perf-stat:nth-child(6) { animation-delay: .2s; }
.perf-stats .perf-stat:nth-child(7) { animation-delay: .24s; }
.perf-stats .perf-stat:nth-child(8) { animation-delay: .28s; }
.perf-stat:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); border-color: oklch(80% .06 264); }
.perf-stat-label { font-size: 11px; color: var(--muted); letter-spacing: .02em; }
.perf-stat-value { font-size: 14px; font-weight: 700; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
.rating-compact { text-align: right; }
.stars { color: var(--gold); letter-spacing: 1px; font-size: 11px; line-height: 1; }
.rating-num { color: var(--muted); font: 10px 'Space Mono', 'SF Mono', monospace; margin-top: 4px; }
.not-rated { color: var(--faint); font: 10px 'Space Mono', 'SF Mono', monospace; }
.perf-trend { margin-top: 12px; border-top: 1px solid var(--line); padding-top: 12px; }
.perf-trend-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.perf-trend-title { font-size: 12px; font-weight: 600; color: var(--muted); letter-spacing: .02em; }
.perf-trend-indicator { font-size: 11px; font-weight: 600; }
.trend-up { color: #059669; }
.trend-down { color: #dc2626; }
.trend-flat { color: #9ca3af; }
.perf-trend-chart-wrap {
  height: 150px;
  background: linear-gradient(180deg, oklch(97% .015 250), var(--canvas));
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 10px;
}

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
.project {
  border: 1px solid var(--line); border-radius: 14px; padding: 14px 14px 14px 17px;
  background: linear-gradient(180deg, var(--surface), oklch(98% .01 92));
  position: relative; overflow: hidden;
  transition: border-color .25s, box-shadow .25s, transform .25s var(--ease-out);
}
.project::before {
  content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
  background: var(--grad-brand);
}
.project.proj-completed::before { background: linear-gradient(180deg, oklch(72% .14 165), oklch(55% .12 165)); }
.project.proj-ongoing::before { background: linear-gradient(180deg, oklch(70% .16 264), var(--violet)); }
.project:hover { border-color: oklch(74% .1 264); box-shadow: 0 10px 26px oklch(25% .03 285 / .1); transform: translateY(-2px); }
.project-row { display: flex; align-items: start; justify-content: space-between; gap: 10px; }
.project h3 { font-size: 13px; letter-spacing: -.015em; margin: 0; }
.project-meta {
  display: flex; align-items: center; gap: 8px; margin-top: 5px; color: var(--muted); font-size: 11px;
}
.status {
  display: inline-flex; align-items: center; gap: 6px; border-radius: 999px;
  padding: 5px 10px; font-size: 10px; font-weight: 700; white-space: nowrap;
  box-shadow: inset 0 0 0 1px rgb(255 255 255 / .55);
}
.status::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.status.progress { background: linear-gradient(180deg, oklch(93% .05 264), oklch(89% .07 264)); color: var(--blue-dark); }
.status.progress::before { animation: pulse-dot 1.6s ease-in-out infinite; }
.status.complete { background: linear-gradient(180deg, oklch(93% .05 160), oklch(89% .06 160)); color: oklch(38% .12 160); }
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
  min-height: 32px; border: 1px solid var(--line); border-radius: 9px; padding: 0 12px;
  color: var(--muted); background: var(--canvas); font-size: 11px; font-weight: 700; cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  transition: background .2s, border-color .2s, color .2s, transform .2s var(--ease-out), box-shadow .2s;
}
.secondary:hover { color: var(--blue); border-color: oklch(74% .1 264); background: var(--blue-soft); transform: translateY(-1px); box-shadow: 0 4px 10px oklch(54% .21 264 / .15); }
.secondary:active { transform: translateY(0); }
.secondary.blue { background: var(--grad-brand); border-color: transparent; color: #fff; box-shadow: 0 4px 12px oklch(54% .21 264 / .3); }
.secondary.blue:hover { filter: saturate(1.12) brightness(1.03); color: #fff; }
.secondary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }

.analysis-body { padding: 18px 20px 20px; }
.analysis-hero {
  background:
    radial-gradient(120% 90% at 85% 0%, oklch(60% .2 292 / .35), transparent 55%),
    radial-gradient(100% 80% at 10% 100%, oklch(54% .21 264 / .3), transparent 60%),
    var(--nav, #1a2540);
  color: oklch(98% .01 92); border-radius: 14px; padding: 18px; margin-bottom: 14px;
  border: 1px solid rgb(255 255 255 / .1);
  box-shadow: 0 12px 28px oklch(20% .04 280 / .35);
  position: relative; overflow: hidden;
}
.analysis-hero::after {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(110deg, transparent 30%, rgb(255 255 255 / .06) 48%, transparent 62%);
  background-size: 200% 100%;
  animation: shimmer 5s linear infinite;
  pointer-events: none;
}
.analysis-hero .eyebrow { color: var(--gold); margin-bottom: 8px; background: rgb(255 255 255 / .08); border-color: rgb(255 255 255 / .14); }
.analysis-hero .eyebrow::before { background: var(--gold); }
.analysis-hero h3 { font-size: 15px; letter-spacing: -.025em; margin: 0; color: #fff; }
.analysis-hero p { color: oklch(82% .02 285); font-size: 12px; margin: 7px 0 0; }
.score-ring { transition: box-shadow .3s; }
.score-ring:hover { filter: brightness(1.06); }
.ai-comment {
  background: linear-gradient(180deg, var(--surface), var(--canvas));
  border: 1px solid var(--line); border-radius: 4px 14px 14px 14px;
  padding: 13px 14px; margin-top: 12px; font-size: 12px; line-height: 1.65; color: var(--ink);
  box-shadow: var(--shadow-sm);
  animation: rise .45s var(--ease-out) backwards;
  position: relative;
}
.ai-comment::before {
  content: '';
  position: absolute; left: -1px; top: -1px; bottom: -1px; width: 3px;
  border-radius: 4px 0 0 14px;
  background: var(--grad-brand);
}
.ai-comment.loading { color: var(--muted); }
.ai-comment.loading .ai-sub {
  background: linear-gradient(90deg, var(--surface-2) 25%, oklch(90% .03 285) 50%, var(--surface-2) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.4s linear infinite;
  border-radius: 6px; color: transparent;
}
.metric-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
.chip {
  font: 700 10px/1 'Space Mono', 'SF Mono', monospace;
  background: var(--surface-2); border: 1px solid var(--line); color: var(--muted);
  border-radius: 999px; padding: 7px 10px;
  transition: transform .18s var(--ease-out), box-shadow .18s;
  animation: rise .4s var(--ease-out) backwards;
}
.chip:hover { transform: translateY(-1px); box-shadow: 0 3px 8px oklch(25% .03 285 / .12); }
.chip.good { background: linear-gradient(180deg, oklch(93% .05 160), oklch(89% .06 160)); border-color: transparent; color: oklch(38% .12 160); }
.chip.warn { background: linear-gradient(180deg, oklch(95% .055 82), oklch(91% .06 82)); border-color: transparent; color: oklch(43% .13 74); }
.chip.bad { background: linear-gradient(180deg, oklch(94% .04 18), oklch(90% .045 18)); border-color: transparent; color: #b91c1c; }
.project-tag { font: 700 10px/1 'Space Mono', 'SF Mono', monospace; color: var(--muted); margin-bottom: 6px; }

/* ── AI orb avatar (gradient bot image + live "." dot) ── */
.ai-orb {
  position: relative; flex: 0 0 auto;
  width: 30px; height: 30px; border-radius: 50%;
  background: conic-gradient(from 210deg, #7c9bff, #a78bfa, #5eead4, #7c9bff);
  display: grid; place-items: center;
  box-shadow: 0 0 0 2px oklch(99% .008 92), 0 4px 14px oklch(54% .21 264 / .35);
  animation: orb-float 3.2s ease-in-out infinite;
}
.ai-orb svg { width: 16px; height: 16px; color: #fff; filter: drop-shadow(0 1px 2px rgb(0 0 0 / .3)); }
.ai-orb .dot {
  position: absolute; right: -1px; bottom: -1px;
  width: 10px; height: 10px; border-radius: 50%;
  background: #22c55e; border: 2px solid oklch(99% .008 92);
  box-shadow: 0 0 0 3px rgb(34 197 94 / .25);
  animation: dot-ping 1.8s ease-out infinite;
}
.ai-orb.lg { width: 52px; height: 52px; }
.ai-orb.lg svg { width: 28px; height: 28px; }
.ai-orb.lg .dot { width: 14px; height: 14px; border-width: 3px; }
.ai-orb.sm { width: 18px; height: 18px; box-shadow: 0 0 0 1px rgb(255 255 255 / .6), 0 2px 8px oklch(54% .21 264 / .4); animation: none; }
.ai-orb.sm svg { width: 10px; height: 10px; }
.ai-orb.sm .dot { width: 7px; height: 7px; border-width: 1.5px; animation: none; }
.ai-orb-row { display: flex; align-items: center; gap: 9px; }
.ai-orb-row .ai-name { font: 700 10px/1 'Space Mono', 'SF Mono', monospace; letter-spacing: .1em; text-transform: uppercase; color: var(--blue); }
.ai-orb-row .ai-sub { font-size: 10px; color: var(--muted); margin-top: 3px; }
.ai-btn .ai-orb { vertical-align: -4px; margin-right: 5px; }
@keyframes orb-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-2.5px); } }
@keyframes dot-ping { 0% { box-shadow: 0 0 0 0 rgb(34 197 94 / .45); } 70% { box-shadow: 0 0 0 6px rgb(34 197 94 / 0); } 100% { box-shadow: 0 0 0 0 rgb(34 197 94 / 0); } }

.analysis-note {
  display: flex; gap: 10px; padding-top: 16px; border-top: 1px solid var(--line);
  color: var(--muted); font-size: 12px;
}
.analysis-note svg { width: 16px; height: 16px; color: var(--blue); flex: 0 0 auto; margin-top: 2px; }

.empty-state {
  padding: 36px 20px; text-align: center; color: var(--muted);
  border: 1.5px dashed oklch(84% .035 285);
  border-radius: 14px;
  background: linear-gradient(180deg, oklch(98.5% .01 92), var(--canvas));
}
.empty-state svg { width: 28px; height: 28px; color: var(--faint); margin: 0 auto 10px; }
.empty-state strong { display: block; color: var(--ink); font-size: 13px; margin-bottom: 5px; }
.empty-state p { font-size: 12px; max-width: 40ch; margin: 0 auto; }

/* ── Rating Modal ── */
.cr-modal-overlay {
  display: none; position: fixed; inset: 0; background: oklch(23% .028 285 / .45);
  backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
  z-index: 1000; align-items: center; justify-content: center;
  animation: fade-in .22s ease;
}
@keyframes fade-in { from { opacity: 0; } to { opacity: 1; } }
.cr-modal-overlay.show { display: flex; }
.cr-modal {
  background: var(--surface); border: 1px solid var(--line); border-radius: 20px;
  width: 360px; max-width: calc(100vw - 32px); overflow: hidden; box-shadow: var(--shadow-lg);
  position: relative;
}
.cr-modal-overlay.show .cr-modal { animation: pop-in .32s var(--ease-spring); }
.cr-modal::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
  background: var(--grad-brand);
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
  font-size: 32px; background: none; border: none; cursor: pointer;
  color: var(--faint); transition: color .15s, transform .15s var(--ease-spring); line-height: 1; padding: 2px;
}
.star-btn.on { color: var(--gold); transform: scale(1.12); text-shadow: 0 0 14px oklch(73% .15 74 / .55); }
.star-btn:hover { color: oklch(70% .13 80); transform: scale(1.2) rotate(-6deg); }
.star-btn.on:hover { color: var(--gold); }
.star-btn.pop { animation: star-pop .35s var(--ease-spring); }
.star-hint { text-align: center; font-size: 12px; color: var(--muted); min-height: 16px; margin-bottom: 4px; }
.cr-modal-footer {
  padding: 12px 16px; border-top: 1px solid var(--line);
  display: flex; justify-content: flex-end; gap: 8px;
}
.btn-cancel {
  font-size: 13px; padding: 8px 16px; border-radius: 10px;
  background: var(--surface-2); color: var(--muted); border: 1px solid var(--line); cursor: pointer;
  transition: background .2s, transform .2s var(--ease-out);
}
.btn-cancel:hover { background: oklch(90% .03 285); transform: translateY(-1px); }
.btn-save {
  font-size: 13px; padding: 8px 18px; border-radius: 10px;
  background: var(--grad-brand); color: #fff; border: none; cursor: pointer; font-weight: 700;
  box-shadow: 0 4px 12px oklch(54% .21 264 / .3);
  transition: transform .2s var(--ease-out), box-shadow .2s, filter .2s;
}
.btn-save:hover { transform: translateY(-1px); box-shadow: var(--shadow-lg); filter: saturate(1.1); }
.btn-save:active { transform: translateY(0) scale(.97); }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: .01ms !important; animation-delay: 0s !important; transition-duration: .01ms !important; }
  html { scroll-behavior: auto; }
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
  <div class="head-actions">
    <span class="ai-online"><span class="ai-orb sm" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="2.6" r="1.2" fill="#fff"/><rect x="11.2" y="3.6" width="1.6" height="2.6" rx=".8" fill="#fff"/><rect x="4.2" y="8.6" width="2" height="3.8" rx="1" fill="#fff" opacity=".75"/><rect x="17.8" y="8.6" width="2" height="3.8" rx="1" fill="#fff" opacity=".75"/><rect x="6" y="5.8" width="12" height="9.4" rx="4.2" fill="#fff"/><circle cx="9.6" cy="10.2" r="1.5" fill="#1a2540"/><circle cx="14.4" cy="10.2" r="1.5" fill="#1a2540"/><circle cx="10.1" cy="9.7" r=".5" fill="#fff"/><circle cx="14.9" cy="9.7" r=".5" fill="#fff"/><path d="M9.7 12.7q2.3 1.7 4.6 0" stroke="#1a2540" stroke-width="1.2" fill="none" stroke-linecap="round"/><path d="M7.6 17.4q4.4-2.8 8.8 0l-.7 2.8H8.3l-.7-2.8z" fill="#fff" opacity=".92"/></svg><span class="dot"></span></span>AI online</span>
    <button class="primary" id="autoRateBtn">
      <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
        <path d="M8 2l1.5 3.5H13l-2.8 2.2 1 3.3L8 9.2l-3.2 1.8 1-3.3L3 5.5h3.5L8 2z" fill="currentColor"/>
      </svg>
      Auto-rate all
    </button>
  </div>
</div>

<section class="summary-grid" aria-label="Performance summary">
  <article class="summary" style="--d:.02s">
    <div class="summary-top"><span>Active contractors</span><div class="summary-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div></div>
    <div class="summary-value countup" data-count="{{ $contractors->count() }}">{{ $contractors->count() }}</div>
    <div class="summary-caption"><span class="summary-delta">Total registered</span></div>
  </article>
  <article class="summary" style="--d:.1s">
    <div class="summary-top"><span>Projects reviewed</span><div class="summary-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div></div>
    <div class="summary-value countup" data-count="{{ $ratings->count() }}">{{ $ratings->count() }}</div>
    <div class="summary-caption"><span class="summary-delta">{{ $ratings->count() > 0 ? round(($ratings->count() / max($contractors->sum(fn($c) => $c->projects->count()), 1)) * 100) : 0 }}% completion</span></div>
  </article>

  <article class="summary" style="--d:.18s">
    <div class="summary-top"><span>Needs review</span><div class="summary-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div></div>
    @php
      $totalProjects = $contractors->sum(fn($c) => $c->projects->count());
      $unrated = $totalProjects - $ratings->count();
    @endphp
    <div class="summary-value countup" data-count="{{ max(0, $unrated) }}">{{ max(0, $unrated) }}</div>
    <div class="summary-caption">Across {{ $contractors->count() }} contractors</div>
  </article>
</section>

<section class="workspace">
  {{-- ── CONTRACTORS ── --}}
  <section class="panel contractors-panel" aria-labelledby="contractors-title" style="--d:.08s">
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
  <section class="panel projects-panel" aria-labelledby="projects-title" style="--d:.16s">
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

  {{-- ── RIGHT COLUMN: Analysis + Performance ── --}}
  <div class="right-col">
    <aside class="panel analysis" aria-labelledby="analysis-title" style="--d:.24s">
      <div class="panel-head">
        <div class="panel-title">
          <div class="ai-orb" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="2.6" r="1.2" fill="#fff"/><rect x="11.2" y="3.6" width="1.6" height="2.6" rx=".8" fill="#fff"/><rect x="4.2" y="8.6" width="2" height="3.8" rx="1" fill="#fff" opacity=".75"/><rect x="17.8" y="8.6" width="2" height="3.8" rx="1" fill="#fff" opacity=".75"/><rect x="6" y="5.8" width="12" height="9.4" rx="4.2" fill="#fff"/><circle cx="9.6" cy="10.2" r="1.5" fill="#1a2540"/><circle cx="14.4" cy="10.2" r="1.5" fill="#1a2540"/><circle cx="10.1" cy="9.7" r=".5" fill="#fff"/><circle cx="14.9" cy="9.7" r=".5" fill="#fff"/><path d="M9.7 12.7q2.3 1.7 4.6 0" stroke="#1a2540" stroke-width="1.2" fill="none" stroke-linecap="round"/><path d="M7.6 17.4q4.4-2.8 8.8 0l-.7 2.8H8.3l-.7-2.8z" fill="#fff" opacity=".92"/></svg><span class="dot"></span></div>
          <div><h2 id="analysis-title">Analysis</h2><p class="panel-subtitle">AI performance review</p></div>
        </div>
      </div>
      <div class="analysis-body" id="analysisBody">
        <div class="empty-state">
          <div class="ai-orb lg" style="margin:0 auto 12px;" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="2.6" r="1.2" fill="#fff"/><rect x="11.2" y="3.6" width="1.6" height="2.6" rx=".8" fill="#fff"/><rect x="4.2" y="8.6" width="2" height="3.8" rx="1" fill="#fff" opacity=".75"/><rect x="17.8" y="8.6" width="2" height="3.8" rx="1" fill="#fff" opacity=".75"/><rect x="6" y="5.8" width="12" height="9.4" rx="4.2" fill="#fff"/><circle cx="9.6" cy="10.2" r="1.5" fill="#1a2540"/><circle cx="14.4" cy="10.2" r="1.5" fill="#1a2540"/><circle cx="10.1" cy="9.7" r=".5" fill="#fff"/><circle cx="14.9" cy="9.7" r=".5" fill="#fff"/><path d="M9.7 12.7q2.3 1.7 4.6 0" stroke="#1a2540" stroke-width="1.2" fill="none" stroke-linecap="round"/><path d="M7.6 17.4q4.4-2.8 8.8 0l-.7 2.8H8.3l-.7-2.8z" fill="#fff" opacity=".92"/></svg><span class="dot"></span></div>
          <strong>No analysis yet</strong>
          <p>Select a contractor to see an AI overview of all their projects, or click <strong>AI review</strong> on a project for a per-project analysis.</p>
        </div>
      </div>
    </aside>

    {{-- ── CONTRACTOR PERFORMANCE ── --}}
    <section class="panel perf-panel" aria-labelledby="perf-title" id="perfPanel" style="--d:.3s">
      <div class="panel-head">
        <div class="panel-title">
          <div class="panel-title-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
          <div><h2 id="perf-title">Contractor Performance</h2><p class="panel-subtitle" id="perfSubtitle">Select a contractor</p></div>
        </div>
      </div>
      <div class="panel-body" id="perfBody">
        <div class="empty-state">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
          <strong>No contractor selected</strong>
          <p>Select a contractor to view their performance metrics.</p>
        </div>
      </div>
    </section>
  </div>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
const contractorsData = @json($contractorsJson);
const ratingsData = @json($ratingsJson);

let selectedId = null;
let currentKey = '';
let currentContractorId = null;
let currentProjectId = null;
let currentStar = 0;

const starLabels = ['', 'Poor performance', 'Below average', 'Acceptable', 'Good performance', 'Excellent performance'];

// Reusable AI orb avatar (gradient bot image + live "." dot). Size: '' | 'sm' | 'lg'.
const ORB_SVG = '<svg viewBox="0 0 24 24"><circle cx="12" cy="2.6" r="1.2" fill="#fff"/><rect x="11.2" y="3.6" width="1.6" height="2.6" rx=".8" fill="#fff"/><rect x="4.2" y="8.6" width="2" height="3.8" rx="1" fill="#fff" opacity=".75"/><rect x="17.8" y="8.6" width="2" height="3.8" rx="1" fill="#fff" opacity=".75"/><rect x="6" y="5.8" width="12" height="9.4" rx="4.2" fill="#fff"/><circle cx="9.6" cy="10.2" r="1.5" fill="#1a2540"/><circle cx="14.4" cy="10.2" r="1.5" fill="#1a2540"/><circle cx="10.1" cy="9.7" r=".5" fill="#fff"/><circle cx="14.9" cy="9.7" r=".5" fill="#fff"/><path d="M9.7 12.7q2.3 1.7 4.6 0" stroke="#1a2540" stroke-width="1.2" fill="none" stroke-linecap="round"/><path d="M7.6 17.4q4.4-2.8 8.8 0l-.7 2.8H8.3l-.7-2.8z" fill="#fff" opacity=".92"/></svg><span class="dot"></span>';
function orbHtml(size) {
  return `<span class="ai-orb${size ? ' ' + size : ''}" aria-hidden="true">${ORB_SVG}</span>`;
}

function starsHtml(rating) {
  let s = '';
  for (let i = 1; i <= 5; i++) {
    s += i <= rating ? '&#9733;' : '<span style="color:var(--faint)">&#9733;</span>';
  }
  return s;
}

// Count-up helper for summary values and score rings. Respects reduced motion.
function prefersReducedMotion() {
  return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}
function countUp(el, target, dur) {
  dur = dur || 800;
  if (prefersReducedMotion()) { el.textContent = target; return; }
  const start = performance.now();
  function frame(now) {
    const t = Math.min((now - start) / dur, 1);
    const eased = 1 - Math.pow(1 - t, 3);
    el.textContent = Math.round(target * eased);
    if (t < 1) requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
}
// Animate an overview score ring (conic fill + number) from 0 to `score`.
function animateScore() {
  const ring = document.querySelector('#analysisBody .score-ring');
  if (!ring) return;
  const score = parseInt(ring.dataset.score || '0', 10);
  const num = ring.querySelector('.score-num');
  const gold = getComputedStyle(document.documentElement).getPropertyValue('--gold').trim() || '#d9a441';
  if (prefersReducedMotion()) {
    ring.style.background = `conic-gradient(${gold} 0 ${score}%, oklch(100% .01 92 / .12) ${score}% 100%)`;
    if (num) num.textContent = score;
    return;
  }
  const start = performance.now();
  const dur = 900;
  function frame(now) {
    const t = Math.min((now - start) / dur, 1);
    const eased = 1 - Math.pow(1 - t, 3);
    const v = Math.round(score * eased);
    ring.style.background = `conic-gradient(${gold} 0 ${v}%, oklch(100% .01 92 / .12) ${v}% 100%)`;
    if (num) num.textContent = v;
    if (t < 1) requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);
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
  list.innerHTML = visible.map((c, i) => {
    const ratings = c.projects.map(p => p.rating).filter(r => r > 0);
    const avg = ratings.length ? (ratings.reduce((a,b) => a+b, 0) / ratings.length) : 0;
    const avgR = Math.round(avg * 10) / 10;
    return `<button class="contractor rise-item ${c.id === selectedId ? 'selected' : ''}" data-id="${c.id}" style="animation-delay:${Math.min(i * 35, 350)}ms">
      <div class="contractor-avatar">${c.photo ? `<img class="avatar-img" src="${c.photo}" alt="" loading="lazy" onerror="this.parentElement.textContent='${initials(c.name)}';this.remove()">` : initials(c.name)}</div>
      <div><strong>${escHtml(c.name)}</strong><small>${c.projects.length} project${c.projects.length === 1 ? '' : 's'}</small></div>
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
      renderPerformance();
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

  document.getElementById('projectList').innerHTML = projects.map((p, i) => {
    const s = statusMap[p.status] || { cls: 'progress', label: p.status };
    const rating = p.rating || 0;
    const accent = p.status === 'Completed' ? 'proj-completed' : 'proj-ongoing';
    return `<article class="project rise-item ${accent}" style="animation-delay:${Math.min(i * 40, 400)}ms">
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
          <button class="secondary blue ai-btn" data-key="${p.key}" data-project-id="${p.id}" data-title="${escHtml(p.title)}">${orbHtml('sm')}AI review</button>
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

const overviewCache = {};

function renderAnalysis(message) {
  const c = contractorsData.find(x => x.id === selectedId);
  if (!c) {
    document.getElementById('analysisBody').innerHTML = `<div class="empty-state">${orbHtml('lg')}<strong>No analysis yet</strong><p>Select a contractor to see an AI overview of all their projects, or click <strong>AI review</strong> on a project for a per-project analysis.</p></div>`;
    return;
  }

  const ratings = c.projects.map(p => p.rating).filter(r => r > 0);
  const avg = ratings.length ? (ratings.reduce((a,b) => a+b, 0) / ratings.length) : 0;
  const score = Math.round(avg * 20) || 0;

  // If a per-project message was passed (legacy), show it directly.
  // Otherwise fetch the contractor-wide AI overview comment.
  if (message) {
    document.getElementById('analysisBody').innerHTML = `<div>
      <div class="analysis-hero">
        <div class="eyebrow">Overview</div>
        <h3>${escHtml(c.name)}</h3>
        <p>${escHtml(message)}</p>
        <div class="score-ring" data-score="${score}" style="margin:20px auto 14px;width:124px;height:124px;border-radius:50%;background:conic-gradient(var(--gold) 0 ${score}%, oklch(100% .01 92 / .12) ${score}% 100%);display:grid;place-items:center;position:relative;">
          <div style="position:absolute;inset:9px;background:var(--nav, #1a2540);border-radius:50%;"></div>
          <div style="position:relative;text-align:center;z-index:1;">
            <strong class="score-num" style="display:block;font-size:28px;letter-spacing:-.06em;line-height:1;">${score}</strong>
            <span style="color:oklch(75% .025 285);font:10px 'Space Mono','SF Mono',monospace;">out of 100</span>
          </div>
        </div>
      </div>
    </div>`;
    animateScore();
    return;
  }

  // Cached overview → render instantly.
  if (overviewCache[c.id]) {
    paintOverview(c, score, overviewCache[c.id]);
    return;
  }

  // Loading state, then fetch AI comment about ALL projects of this contractor.
  document.getElementById('analysisBody').innerHTML = `<div>
    <div class="analysis-hero">
      <div class="eyebrow">Overview</div>
      <h3>${escHtml(c.name)}</h3>
      <div class="score-ring" data-score="${score}" style="margin:20px auto 14px;width:124px;height:124px;border-radius:50%;background:conic-gradient(var(--gold) 0 ${score}%, oklch(100% .01 92 / .12) ${score}% 100%);display:grid;place-items:center;position:relative;">
        <div style="position:absolute;inset:9px;background:var(--nav, #1a2540);border-radius:50%;"></div>
        <div style="position:relative;text-align:center;z-index:1;">
          <strong class="score-num" style="display:block;font-size:28px;letter-spacing:-.06em;line-height:1;">${score}</strong>
          <span style="color:oklch(75% .025 285);font:10px 'Space Mono','SF Mono',monospace;">out of 100</span>
        </div>
      </div>
    </div>
    <div class="ai-comment loading"><div class="ai-orb-row">${orbHtml()}<div><div class="ai-name">AI overview</div><div class="ai-sub">Analyzing all ${c.projects.length} project${c.projects.length === 1 ? '' : 's'}…</div></div></div></div>
  </div>`;

  fetch('{{ route('admin.rating.contractor-overview') }}', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
    },
    body: JSON.stringify({ contractor_id: c.id })
  })
  .then(r => r.json())
  .then(data => {
    // Ignore stale responses after switching contractors.
    if (selectedId !== c.id) return;
    const comment = data.comment || 'No performance history available yet.';
    overviewCache[c.id] = comment;
    paintOverview(c, score, comment);
  })
  .catch(() => {
    if (selectedId !== c.id) return;
    paintOverview(c, score, 'Could not generate the AI overview right now. Please try reselecting the contractor.');
  });
}

function paintOverview(c, score, comment) {
  document.getElementById('analysisBody').innerHTML = `<div>
    <div class="analysis-hero">
      <div class="eyebrow">Overview</div>
      <h3>${escHtml(c.name)}</h3>
      <div class="score-ring" data-score="${score}" style="margin:20px auto 14px;width:124px;height:124px;border-radius:50%;background:conic-gradient(var(--gold) 0 ${score}%, oklch(100% .01 92 / .12) ${score}% 100%);display:grid;place-items:center;position:relative;">
        <div style="position:absolute;inset:9px;background:var(--nav, #1a2540);border-radius:50%;"></div>
        <div style="position:relative;text-align:center;z-index:1;">
          <strong class="score-num" style="display:block;font-size:28px;letter-spacing:-.06em;line-height:1;">${score}</strong>
          <span style="color:oklch(75% .025 285);font:10px 'Space Mono','SF Mono',monospace;">out of 100</span>
        </div>
      </div>
    </div>
    <div class="ai-comment"><div class="ai-orb-row">${orbHtml()}<div><div class="ai-name">AI overview — all projects</div></div></div><p style="margin:9px 0 0;">${escHtml(comment)}</p></div>
    <p style="color:var(--muted);font-size:11px;margin:10px 2px 0;">Tip: click <strong>AI review</strong> on any project below for a per-project breakdown.</p>
  </div>`;
  animateScore();
}

function renderPerformance() {
  const c = contractorsData.find(x => x.id === selectedId);
  const panel = document.getElementById('perfBody');
  const subtitle = document.getElementById('perfSubtitle');

  if (!c || !c.performance_rating) {
    subtitle.textContent = c ? c.name : 'Select a contractor';
    panel.innerHTML = `<div class="empty-state">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
      <strong>${c ? 'No performance data yet' : 'No contractor selected'}</strong>
      <p>${c ? 'Performance metrics will appear once this contractor has completed projects.' : 'Select a contractor to view their performance metrics.'}</p>
    </div>`;
    return;
  }

  subtitle.textContent = c.name;
  const ratingInt = Math.round(c.performance_rating);
  const ratingLabels = { 5: 'Superior', 4: 'Good', 3: 'Satisfactory', 2: 'Below Average', 1: 'Poor' };
  const ratingLabel = ratingLabels[ratingInt] || 'N/A';

  panel.innerHTML = `<div class="perf-grid">
    <div class="perf-top">
      <div class="perf-hero perf-${ratingInt}">${ratingInt}</div>
      <div class="perf-top-text">
        <span class="perf-top-label">Performance Rating</span>
        <span class="perf-top-value">${ratingLabel}</span>
      </div>
    </div>
    <div class="perf-stats">
      <div class="perf-stat">
        <span class="perf-stat-label">On-Time Delivery</span>
        <span class="perf-stat-value">${c.on_time_delivery_pct != null ? c.on_time_delivery_pct + '%' : 'N/A'}</span>
      </div>
      <div class="perf-stat">
        <span class="perf-stat-label">Avg Delay</span>
        <span class="perf-stat-value">${c.avg_delay_days != null ? c.avg_delay_days + ' days' : 'N/A'}</span>
      </div>
      <div class="perf-stat">
        <span class="perf-stat-label">Completed Projects</span>
        <span class="perf-stat-value">${c.completed_projects_count}</span>
      </div>
      <div class="perf-stat">
        <span class="perf-stat-label">Ongoing Projects</span>
        <span class="perf-stat-value">${c.ongoing_projects_count}</span>
      </div>
      <div class="perf-stat">
        <span class="perf-stat-label">Total Projects</span>
        <span class="perf-stat-value">${c.total_projects_count}</span>
      </div>
      <div class="perf-stat">
        <span class="perf-stat-label">Last Completed</span>
        <span class="perf-stat-value">${c.last_project_completed_at || 'N/A'}</span>
      </div>
      <div class="perf-stat">
        <span class="perf-stat-label">Avg Extensions per Project</span>
        <span class="perf-stat-value">${c.completed_projects_count > 0 ? (c.total_extensions_count / c.completed_projects_count).toFixed(1) : 'N/A'}</span>
      </div>
      <div class="perf-stat">
        <span class="perf-stat-label">Avg Extension Days per Project</span>
        <span class="perf-stat-value">${c.completed_projects_count > 0 && c.total_extension_days != null ? (c.total_extension_days / c.completed_projects_count).toFixed(1) + ' days' : 'N/A'}</span>
      </div>
    </div>
  </div>
  ${c.trend && c.trend.length >= 2 ? `<div class="perf-trend">
    <div class="perf-trend-header">
      <span class="perf-trend-title">Performance Trend</span>
      <span class="perf-trend-indicator ${c.trend[c.trend.length - 1].on_time_pct > c.trend[0].on_time_pct ? 'trend-up' : (c.trend[c.trend.length - 1].on_time_pct < c.trend[0].on_time_pct - 2 ? 'trend-down' : 'trend-flat')}">
        ${c.trend[c.trend.length - 1].on_time_pct > c.trend[0].on_time_pct
          ? '&uarr; Improving (+' + (c.trend[c.trend.length - 1].on_time_pct - c.trend[0].on_time_pct).toFixed(1) + '% over ' + c.trend.length + ' projects)'
          : (c.trend[c.trend.length - 1].on_time_pct < c.trend[0].on_time_pct - 2
            ? '&darr; Declining (&minus;' + (c.trend[0].on_time_pct - c.trend[c.trend.length - 1].on_time_pct).toFixed(1) + '% over ' + c.trend.length + ' projects)'
            : '&rarr; Stable')}
      </span>
    </div>
    <div class="perf-trend-chart-wrap"><canvas id="perfTrendChart"></canvas></div>
  </div>` : ''}`;

  if (c.trend && c.trend.length >= 2) {
    requestAnimationFrame(() => renderTrendChart(c.trend));
  }
}

function renderTrendChart(trend) {
  const canvas = document.getElementById('perfTrendChart');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');

  const labels = trend.map(p => {
    const d = new Date(p.date);
    return d.toLocaleString('en', { month: 'short', year: '2-digit' });
  });
  const data = trend.map(p => p.on_time_pct);

  const gradient = ctx.createLinearGradient(0, 0, 0, 140);
  gradient.addColorStop(0, 'rgba(79,109,245,0.25)');
  gradient.addColorStop(1, 'rgba(79,109,245,0.02)');

  new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        data: data,
        borderColor: '#4f6df5',
        backgroundColor: gradient,
        fill: true,
        tension: 0.3,
        pointRadius: 4,
        pointBackgroundColor: '#4f6df5',
        pointBorderColor: '#fff',
        pointBorderWidth: 2,
        borderWidth: 2,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(ctx) { return ctx.parsed.y + '% on-time'; }
          }
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { font: { size: 10 }, color: '#9ca3af' }
        },
        y: {
          min: 0,
          max: 100,
          grid: { color: '#f3f4f6' },
          ticks: {
            font: { size: 10 },
            color: '#9ca3af',
            callback: function(v) { return v + '%'; }
          }
        }
      }
    }
  });
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
  const btns = document.querySelectorAll('.star-btn');
  if (btns[n - 1]) {
    btns[n - 1].classList.remove('pop');
    void btns[n - 1].offsetWidth;
    btns[n - 1].classList.add('pop');
  }
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
    delete overviewCache[con.id];
  }
  renderProjects();
  renderAnalysis();
}

function suggestAI(projectId, key) {
  const aiDiv = document.getElementById('analysisBody');
  const btnTitle = document.querySelector(`.ai-btn[data-project-id="${projectId}"]`);
  const fallbackTitle = btnTitle ? btnTitle.dataset.title : 'this project';
  aiDiv.innerHTML = `<div class="empty-state">${orbHtml('lg')}<strong>Analyzing ${escHtml(fallbackTitle)}…</strong><p>Comparing actual vs planned progress, extensions and issues.</p></div>`;

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
    const d = data.details || {};
    let cls, head;
    if (rating >= 4) { cls = 'analysis-hero'; head = 'Strong performance'; }
    else if (rating === 3) { cls = 'analysis-hero'; head = 'Acceptable, with room to improve'; }
    else { cls = 'analysis-hero'; head = 'Performance needs attention'; }

    const parts = key.split('-');
    const pid = parseInt(parts[0]);
    const cid = parseInt(parts[1]);
    const title = data.project_title || fallbackTitle;

    const chipCls = v => v >= 4 ? 'good' : (v === 3 ? 'warn' : 'bad');
    const delayCls = (d.delay_pct ?? 0) <= 0 ? 'good' : ((d.delay_pct ?? 0) <= 15 ? 'warn' : 'bad');
    const r2txt = (d.r_squared === null || d.r_squared === undefined)
      ? 'No model'
      : ('R² ' + Number(d.r_squared).toFixed(3));

    aiDiv.innerHTML = `<div>
      <div class="${cls}" style="${rating >= 4 ? 'border-left:4px solid var(--mint)' : (rating === 3 ? 'border-left:4px solid var(--gold)' : 'border-left:4px solid var(--rose)')}">
        <div class="ai-orb-row" style="margin-bottom:10px;">${orbHtml()}<div><div class="ai-name" style="color:var(--gold);">AI review · ${escHtml(d.status || '')}</div><div class="ai-sub" style="color:oklch(82% .02 285);">${escHtml(title)}</div></div></div>
        <div class="eyebrow" style="${rating >= 4 ? 'color:var(--mint)' : (rating === 3 ? 'color:var(--gold)' : 'color:var(--rose)')}">${head} — ${rating}/5</div>
        <h3>${escHtml((data.review || '').split('.')[0] || title)}</h3>
        <p>${escHtml(data.review || '')}</p>
        ${d.source === 'fallback' ? '<p style="opacity:.75">Note: no regression model exists for this project yet — analysis is based on actual vs planned progress.</p>' : ''}
      </div>
      <div class="metric-chips">
        <span class="chip ${chipCls(rating)}">${rating}/5 rating</span>
        <span class="chip">Actual ${d.actual ?? 0}%</span>
        <span class="chip">Planned ${d.planned ?? 0}%</span>
        <span class="chip ${delayCls}">Delay ${d.delay_pct ?? 0}%</span>
        <span class="chip">Variance ${d.variance ?? 0}%</span>
        <span class="chip">${r2txt}</span>
        <span class="chip">${d.extensions ?? 0} extension${(d.extensions ?? 0) === 1 ? '' : 's'}</span>
        <span class="chip">${d.issues ?? 0} issue${(d.issues ?? 0) === 1 ? '' : 's'}</span>
      </div>
      <div style="margin-top:14px;display:flex;gap:8px;">
        <button class="primary" style="flex:1" onclick="applyAISuggestion(${cid}, ${pid}, ${rating}, '${key}')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><path d="M20 6L9 17l-5-5"/></svg>
          Apply ${rating}/5
        </button>
        <button class="secondary" onclick="renderAnalysis()" style="flex-shrink:0">Back to overview</button>
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
      const con = contractorsData.find(c => c.id === contractorId);
      if (con) delete overviewCache[con.id];
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

// Init — animate summary counters, then select first contractor
document.querySelectorAll('.countup').forEach(el => {
  countUp(el, parseInt(el.dataset.count || '0', 10));
});
if (contractorsData.length > 0) {
  selectedId = contractorsData[0].id;
  renderContractors('');
  renderProjects();
  renderAnalysis();
  renderPerformance();
} else {
  renderContractors('');
  renderProjects();
  renderAnalysis();
  renderPerformance();
}
</script>

<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>

@endsection
