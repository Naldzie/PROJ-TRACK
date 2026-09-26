<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | PROJ-TRACK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('faviconpmu.png') }}?v=6">
    <link rel="shortcut icon" type="image/png" href="{{ asset('faviconpmu.png') }}?v=6">

 

    <!-- Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="{{ asset('css/layouts/sweetalert-theme.css') }}">

<style>
:root {
    --white:      #FFFFFF;
    --bg-light:   #F8FAFF;
    --bg-blue:    #EEF4FF;
    --primary:    #2563EB;
    --bright:     #3B82F6;
    --accent:     #60A5FA;
    --navy:       #0F2747;
    --navy-deep:  #0B1F3A;
    --text:       #172033;
    --muted:      #64748B;
    --success:    #22C55E;
    --radius:     24px;
}

* { box-sizing: border-box; }

::selection {
    background: rgba(37,99,235,0.28);
    color: var(--navy-deep);
}

html, body { margin: 0; padding: 0; }

body {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px;
    position: relative;
    overflow-x: hidden;

    font-family: 'Inter', 'Segoe UI', sans-serif;
    color: #EAF2FF;
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;

    background:
        radial-gradient(940px 640px at 50% 50%, rgba(37, 99, 235, 0.30), transparent 65%),
        radial-gradient(700px 520px at 16% 14%, rgba(59, 130, 246, 0.20), transparent 62%),
        radial-gradient(780px 560px at 88% 94%, rgba(96, 165, 250, 0.14), transparent 60%),
        linear-gradient(160deg, #0B1B2E 0%, #081120 45%, #05101C 100%);

    opacity: 0;
    animation: pageFade 0.7s ease forwards;
}
@keyframes pageFade { to { opacity: 1; } }

/* soft edge vignette over the backdrop, adds depth behind the card */
body::after {
    content: "";
    position: fixed;
    inset: 0;
    z-index: 1;
    pointer-events: none;
    background: radial-gradient(125% 105% at 50% 22%, transparent 42%, rgba(4,12,26,0.48) 100%);
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   ANIMATED BLUEPRINT BACKGROUND (white + blue only)
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.bg-layer {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    overflow: hidden;
}

/* faint fixed photo backdrop */
.bg-photo {
    position: absolute;
    inset: 0;
    opacity: 0.12;
    background-image: url("{{ asset('images/login-image1.png') }}");
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
}

/* drifting aura glow blobs behind the card */
.bg-aura {
    position: absolute;
    border-radius: 50%;
    filter: blur(70px);
    mix-blend-mode: screen;
    pointer-events: none;
    will-change: transform;
}
.bg-aura-1 {
    width: 520px; height: 520px;
    top: -130px; left: -90px;
    background: radial-gradient(circle, rgba(37,99,235,0.32), rgba(59,130,246,0.10) 45%, transparent 70%);
    animation: auraDrift1 16s ease-in-out infinite alternate;
}
.bg-aura-2 {
    width: 620px; height: 620px;
    bottom: -170px; right: -130px;
    background: radial-gradient(circle, rgba(96,165,250,0.28), rgba(37,99,235,0.08) 45%, transparent 70%);
    animation: auraDrift2 20s ease-in-out infinite alternate;
}
.bg-aura-3 {
    width: 380px; height: 380px;
    top: 42%; left: 46%;
    background: radial-gradient(circle, rgba(147,197,253,0.20), transparent 70%);
    animation: auraDrift3 24s ease-in-out infinite alternate;
}
@keyframes auraDrift1 {
    from { transform: translate3d(0,0,0) scale(1); }
    to   { transform: translate3d(90px, 60px, 0) scale(1.18); }
}
@keyframes auraDrift2 {
    from { transform: translate3d(0,0,0) scale(1.1); }
    to   { transform: translate3d(-75px, -55px, 0) scale(0.94); }
}
@keyframes auraDrift3 {
    from { transform: translate3d(0,0,0) scale(1); }
    to   { transform: translate3d(-60px, 55px, 0) scale(1.22); }
}

/* running light-reflection sweep across the backdrop */
.bg-reflect {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        linear-gradient(115deg, transparent 0%, transparent 42%, rgba(191,219,254,0.16) 50%, transparent 58%, transparent 100%);
    background-size: 220% 100%;
    mix-blend-mode: screen;
    animation: reflectSweep 9s linear infinite;
}
@keyframes reflectSweep {
    0%   { background-position: 165% 0; opacity: 0; }
    9%   { opacity: 1; }
    36%  { background-position: -65% 0; opacity: 0.35; }
    55%  { opacity: 0; }
    100% { background-position: -140% 0; opacity: 0; }
}

/* soft light pool reflected under the card (water feel) */
.bg-reflect-pool {
    position: absolute;
    left: 50%;
    bottom: -14%;
    width: 1250px;
    height: 420px;
    transform: translateX(-50%);
    pointer-events: none;
    background: radial-gradient(50% 55% at 50% 50%, rgba(96,165,250,0.14), rgba(37,99,235,0.05) 55%, transparent 78%);
    filter: blur(16px);
    mix-blend-mode: screen;
    animation: poolPulse 7s ease-in-out infinite;
}
@keyframes poolPulse {
    0%, 100% { opacity: 0.45; transform: translateX(-50%) scale(1); }
    50%      { opacity: 0.85; transform: translateX(-50%) scale(1.06); }
}

/* subtle blueprint grid + technical dot matrix */
.bg-grid {
    position: absolute;
    inset: 0;
    opacity: 0.24;
    background-image:
        repeating-linear-gradient(to right,  rgba(96,165,250,0.08) 0 1px, transparent 1px 72px),
        repeating-linear-gradient(to bottom, rgba(96,165,250,0.08) 0 1px, transparent 1px 72px),
        repeating-linear-gradient(to right,  rgba(96,165,250,0.14) 0 1px, transparent 1px 360px),
        repeating-linear-gradient(to bottom, rgba(96,165,250,0.14) 0 1px, transparent 1px 360px),
        radial-gradient(rgba(96,165,250,0.18) 1px, transparent 1.2px);
    background-size: 72px 72px, 72px 72px, 360px 360px, 360px 360px, 24px 24px;
    animation: gridDrift 40s linear infinite;
}
@keyframes gridDrift {
    from { background-position: 0 0, 0 0, 0 0, 0 0, 0 0; }
    to   { background-position: 72px 72px, 72px 72px, 360px 360px, 360px 360px, 24px 24px; }
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   LOGIN CARD
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.login-card {
    position: relative;
    z-index: 2;
    isolation: isolate;
    width: 1060px;
    max-width: 100%;
    display: flex;
    flex-direction: row-reverse;
    border-radius: var(--radius);
    overflow: hidden;
    background: var(--white);
    border: 1px solid rgba(37,99,235,0.12);
    box-shadow:
        0 34px 80px -20px rgba(4,12,26,0.55),
        0 14px 36px -14px rgba(37,99,235,0.28),
        inset 0 1px 0 rgba(255,255,255,0.7);

    animation: cardIn 0.85s cubic-bezier(0.22, 1, 0.36, 1) 0.15s both;
}
@keyframes cardIn {
    from { opacity: 0; transform: translateY(30px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* brand signal bar along the top edge of the card */
.login-card::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    z-index: 3;
    pointer-events: none;
    background: linear-gradient(90deg, #1D4ED8, #3B82F6, #93C5FD, #60A5FA, #2563EB);
    background-size: 220% 100%;
    animation: signalIn 1.15s ease 0.5s both;
}
@keyframes signalIn {
    from { opacity: 0; transform: scaleX(0.72); }
    to   { opacity: 1; transform: scaleX(1); }
}

.panel { width: 50%; }

/* â”€â”€ LEFT PANEL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.panel-left {
    background:
        radial-gradient(120% 95% at 0% 0%, rgba(37,99,235,0.07), transparent 55%),
        radial-gradient(120% 90% at 100% 100%, rgba(37,99,235,0.05), transparent 60%),
        var(--white);
    padding: 52px 48px 46px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    border-left: 1px solid rgba(37,99,235,0.08);
    position: relative;
}

/* soft engineering corner marks on the login panel */
.panel-left::before,
.panel-left::after {
    content: "";
    position: absolute;
    width: 26px; height: 26px;
    border: 2px solid rgba(37,99,235,0.16);
    pointer-events: none;
}
.panel-left::before { top: 18px; right: 18px; border-left: none; border-bottom: none; }
.panel-left::after  { bottom: 18px; left: 18px; border-right: none; border-top: none; }

/* entrance stagger for left-panel children */
.anim {
    opacity: 0;
    transform: translateY(16px);
    animation: riseIn 0.7s cubic-bezier(0.22, 1, 0.36, 1) both;
}
.st1 { animation-delay: 0.45s; }
.st2 { animation-delay: 0.52s; }
.st3 { animation-delay: 0.59s; }
.st4 { animation-delay: 0.66s; }
.st5 { animation-delay: 0.73s; }
.st6 { animation-delay: 0.80s; }
.st7 { animation-delay: 0.87s; }
@keyframes riseIn {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* PMU badge */
.badge-pmu {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    width: fit-content;
    padding: 7px 14px;
    border-radius: 999px;
    background: linear-gradient(135deg, var(--bg-blue), #DFEAFE);
    border: 1px solid rgba(37,99,235,0.18);
    color: #1D4ED8;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    margin-bottom: 22px;
    box-shadow: 0 8px 18px -10px rgba(37,99,235,0.40);
}
.badge-pmu i { font-size: 13px; }

.welcome {
    font-size: 30px;
    font-weight: 800;
    letter-spacing: -0.02em;
    line-height: 1.15;
    color: var(--text);
    margin: 0 0 10px;
}

.subtitle {
    font-size: 15px;
    color: var(--muted);
    line-height: 1.55;
    margin: 0 0 26px;
}
.subtitle strong {
    color: var(--primary);
    font-weight: 700;
}

/* feature list */
.feature-list {
    list-style: none;
    padding: 0;
    margin: 0 0 30px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.feature-list li {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13.5px;
    font-weight: 500;
    color: #33405A;
}
.feature-list li i {
    width: 30px;
    height: 30px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    background: linear-gradient(135deg, var(--bg-blue), #DFEAFE);
    border: 1px solid rgba(37,99,235,0.14);
    color: var(--primary);
    font-size: 13.5px;
}

/* custom alerts (kept for session error / lockout) */
.alert-custom {
    width: 100%;
    max-width: 360px;
    border-radius: 12px;
    border: 1px solid;
    padding: 12px 14px;
    font-size: 13px;
    line-height: 1.45;
    margin: 0 auto 18px;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    gap: 9px;
}
.alert-custom i { margin-top: 2px; flex-shrink: 0; }
.alert-danger-custom {
    background: #FEF2F2;
    border-color: #FECACA;
    color: #B91C1C;
}
.alert-warning-custom {
    background: #FFFBEB;
    border-color: #FDE68A;
    color: #92400E;
}
.alert-warning-custom #countdown {
    font-family: 'IBM Plex Mono', monospace;
    background: rgba(0,0,0,0.06);
    padding: 1px 6px;
    border-radius: 5px;
}

/* â”€â”€ GOOGLE BUTTON (only login method) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.google-btn {
    position: relative;
    width: 100%;
    max-width: 360px;
    min-height: 52px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 11px;
    padding: 0 20px;
    background: var(--white);
    border: 1px solid #E5E7EB;
    border-radius: 14px;
    overflow: hidden;
    font-family: 'Inter', sans-serif;
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    cursor: pointer;
    box-shadow:
        0 2px 6px rgba(15,39,71,0.06),
        0 1px 2px rgba(15,39,71,0.05);
    transition:
        transform 0.22s ease,
        box-shadow 0.22s ease,
        border-color 0.22s ease,
        background 0.22s ease;
}
/* light sweep across the button on hover */
.google-btn::after {
    content: "";
    position: absolute;
    top: 0; left: -75%;
    width: 45%; height: 100%;
    background: linear-gradient(100deg, transparent, rgba(255,255,255,0.6), transparent);
    transform: skewX(-20deg);
    opacity: 0;
    pointer-events: none;
    transition: left 0.55s ease, opacity 0.25s ease;
}
.google-btn:hover:not(:disabled)::after {
    left: 135%;
    opacity: 1;
}

.google-btn:hover:not(:disabled) {
    transform: translateY(-2px);
    border-color: rgba(37,99,235,0.45);
    background: #FBFCFF;
    box-shadow:
        0 10px 24px -8px rgba(37,99,235,0.30),
        0 3px 8px rgba(37,99,235,0.12);
}
.google-btn:active:not(:disabled) {
    transform: scale(0.98);
}
.google-btn:focus-visible {
    outline: 3px solid rgba(37,99,235,0.35);
    outline-offset: 2px;
}
.google-btn:disabled {
    cursor: not-allowed;
    opacity: 0.4;
}

/* ── PUBLIC PREVIEW BUTTON (secondary, below Google sign-in) ── */
.preview-btn {
    width: 100%;
    max-width: 360px;
    min-height: 46px;
    margin-top: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    padding: 10px 20px;
    background: var(--bg-light);
    border: 1px dashed rgba(37,99,235,0.35);
    border-radius: 14px;
    font-family: 'Inter', sans-serif;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--primary);
    text-decoration: none;
    transition:
        transform 0.22s ease,
        box-shadow 0.22s ease,
        border-color 0.22s ease,
        background 0.22s ease;
}
.preview-btn:hover {
    transform: translateY(-2px);
    background: var(--bg-blue);
    border-color: rgba(37,99,235,0.6);
    border-style: solid;
    color: #1D4ED8;
    box-shadow: 0 10px 24px -8px rgba(37,99,235,0.25);
}
.preview-btn:focus-visible {
    outline: 3px solid rgba(37,99,235,0.35);
    outline-offset: 2px;
}
.preview-btn i { font-size: 15px; }
.preview-btn .ext { font-size: 11px; opacity: 0.6; }
.google-content {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 11px;
    min-height: 24px;
}

.footer-note {
    margin-top: 20px;
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 11.5px;
    color: #94A3B8;
    max-width: 360px;
}
.footer-note i { color: var(--accent); }

/* spinner */
.spin { display: inline-block; animation: spin 1s linear infinite; }
@keyframes spin { from { transform: rotate(0); } to { transform: rotate(360deg); } }

/* â”€â”€ RIGHT PANEL (construction blueprint centerpiece) â”€â”€â”€â”€â”€â”€ */
.panel-right {
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(120% 90% at 85% 0%, rgba(59,130,246,0.22), transparent 55%),
        linear-gradient(165deg, var(--navy) 0%, #12325E 55%, var(--navy-deep) 100%);
    display: flex;
}

.panel-media {
    position: absolute;
    inset: 0;
    opacity: 0;
    animation: mediaIn 1.1s ease 0.5s both;
}
@keyframes mediaIn { from { opacity: 0; } to { opacity: 1; } }

/* soft vignette so the overlay text settles into the frame */
.panel-media::after {
    content: "";
    position: absolute;
    inset: 0;
    background: radial-gradient(125% 120% at 50% 16%, transparent 52%, rgba(5,14,30,0.55) 100%);
    pointer-events: none;
}

/* construction site photograph */
.panel-bg-img {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 1;
}

/* light navy overlay â€” keeps the construction photo clearly visible
   while preserving text legibility on the upper-left */
.scrim {
    position: absolute;
    inset: 0;
    background: linear-gradient(152deg, rgba(9,26,52,0.78) 0%, rgba(12,36,74,0.50) 42%, rgba(15,39,71,0.26) 100%);
}

/* blueprint grid over the photo */
.bp-grid {
    position: absolute;
    inset: 0;
    mix-blend-mode: screen;
    opacity: 0.16;
    background-image:
        repeating-linear-gradient(to right,  rgba(96,165,250,0.20) 0 1px, transparent 1px 42px),
        repeating-linear-gradient(to bottom, rgba(96,165,250,0.20) 0 1px, transparent 1px 42px),
        radial-gradient(rgba(96,165,250,0.24) 1px, transparent 1.2px);
    background-size: 42px 42px, 42px 42px, 21px 21px;
    animation: gridShift 26s linear infinite;
}
@keyframes gridShift {
    from { background-position: 0 0, 0 0, 0 0; }
    to   { background-position: 42px 42px, 42px 42px, 21px 21px; }
}

/* overlay content */
.overlay-content {
    position: relative;
    z-index: 4;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 46px 44px;
    color: #fff;
}

.badge-platform {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    width: fit-content;
    padding: 6px 12px;
    border-radius: 999px;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(96,165,250,0.30);
    -webkit-backdrop-filter: blur(6px);
    backdrop-filter: blur(6px);
    color: #BFDBFE;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    margin-bottom: 18px;
}
.badge-platform i { font-size: 11px; color: var(--accent); }

.brand-title {
    font-size: clamp(27px, 3vw, 39px);
    font-weight: 900;
    letter-spacing: -0.01em;
    line-height: 1.08;
    margin: 0;
    color: #FFFFFF;
    text-transform: uppercase;
    text-shadow: 0 2px 14px rgba(9,26,52,0.6), 0 1px 4px rgba(9,26,52,0.45);
}
.brand-title .grad {
    background: linear-gradient(120deg, #93C5FD 0%, #60A5FA 55%, #3B82F6 100%);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    color: transparent;
}
.brand-title small {
    display: block;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.34em;
    color: rgba(255,255,255,0.55);
    text-transform: uppercase;
    margin-top: 6px;
}

.tagline {
    margin: 14px 0 0;
    font-size: 13.5px;
    line-height: 1.6;
    color: rgba(255,255,255,0.85);
    max-width: 360px;
    text-shadow: 0 1px 8px rgba(9,26,52,0.55);
}

/* feature badges (monitoring modules) */
.feat-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 20px;
}
.feat-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 14px;
    border-radius: 999px;
    background: rgba(255,255,255,0.07);
    border: 1px solid rgba(96,165,250,0.28);
    -webkit-backdrop-filter: blur(6px);
    backdrop-filter: blur(6px);
    color: #EAF2FF;
    font-size: 12.5px;
    font-weight: 600;
    transition:
        transform 0.2s ease,
        border-color 0.2s ease,
        box-shadow 0.2s ease,
        background 0.2s ease;
}
.feat-badge i { color: var(--accent); font-size: 13px; }
.feat-badge:hover {
    transform: translateY(-2px);
    border-color: rgba(96,165,250,0.60);
    background: rgba(96,165,250,0.14);
    box-shadow: 0 8px 20px -8px rgba(37,99,235,0.6);
}

/* system time card (monitoring dashboard widget) */
.time-card {
    margin-top: 26px;
    width: fit-content;
    padding: 14px 20px 15px;
    border-radius: 16px;
    background: rgba(9,26,52,0.42);
    border: 1px solid rgba(96,165,250,0.26);
    -webkit-backdrop-filter: blur(12px) saturate(1.25);
    backdrop-filter: blur(12px) saturate(1.25);
    box-shadow: 0 12px 30px -12px rgba(0,0,0,0.45);
    transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
    animation: scaleIn 0.6s cubic-bezier(0.22, 1, 0.36, 1) 1.35s both;
}
@keyframes scaleIn {
    from { opacity: 0; transform: scale(0.92); }
    to   { opacity: 1; transform: scale(1); }
}
.time-card:hover {
    transform: translateY(-2px);
    border-color: rgba(96,165,250,0.5);
    box-shadow: 0 16px 34px -12px rgba(37,99,235,0.5);
}
.time-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.18em;
    color: rgba(255,255,255,0.6);
    text-transform: uppercase;
}
.live {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #86EFAC;
    font-size: 9px;
    letter-spacing: 0.16em;
}
.live .dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: var(--success);
    box-shadow: 0 0 0 0 rgba(34,197,94,0.55);
    animation: pulseDot 1.8s ease-in-out infinite;
}
@keyframes pulseDot {
    0%,100% { opacity: 1; box-shadow: 0 0 0 0 rgba(34,197,94,0.5); }
    50%     { opacity: 0.5; box-shadow: 0 0 0 6px rgba(34,197,94,0); }
}
.clock-time {
    font-family: 'IBM Plex Mono', 'Consolas', monospace;
    font-size: 30px;
    font-weight: 600;
    color: #fff;
    letter-spacing: 1px;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.clock-time .blink { animation: blinkColon 1s step-start infinite; }
@keyframes blinkColon { 50% { opacity: 0.3; } }
.clock-time .ampm {
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.06em;
    color: rgba(255,255,255,0.7);
    margin-left: 3px;
}
.clock-date {
    font-size: 11.5px;
    color: rgba(255,255,255,0.62);
    margin-top: 3px;
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   TABLET  (768px â€“ 1199px): two columns, tightened
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
@media (min-width: 768px) and (max-width: 991px) {
    .panel-left { padding: 40px 30px 38px; }
    .overlay-content { padding: 36px 28px; }
    .brand-title { font-size: 26px; }
    .time-card { margin-top: 20px; }
    .clock-time { font-size: 24px; }
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   MOBILE (<768px): single column, image becomes top banner
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
@media (max-width: 767px) {
    body { padding: 20px 16px; align-items: flex-start; }

    .login-card {
        flex-direction: column;
        border-radius: 20px;
        margin: auto 0;
    }

    .panel { width: 100%; }

    /* compact construction/blueprint banner */
    .panel-right {
        order: -1;
        height: 232px;
        flex-shrink: 0;
    }
    .bp-grid { opacity: 0.22; }
    .overlay-content {
        padding: 18px 20px;
        justify-content: center;
    }
    .badge-platform { margin-bottom: 8px; }
    .brand-title { font-size: 22px; }
    .brand-title small { font-size: 9.5px; margin-top: 4px; }
    .tagline { display: none; }
    .feat-badges { display: none; }
    .time-card {
        margin-top: 10px;
        padding: 9px 15px 10px;
        border-radius: 13px;
    }
    .clock-time { font-size: 21px; }
    .clock-date { font-size: 10px; }
    .time-head { font-size: 8.5px; }

    .panel-left {
        border-left: none;
        padding: 34px 24px 40px;
        align-items: center;
        text-align: center;
    }
    .panel-left::before, .panel-left::after { display: none; }

    .badge-pmu { margin-left: auto; margin-right: auto; }
    .welcome { font-size: 25px; }
    .subtitle { font-size: 14px; margin-bottom: 24px; }

    .feature-list {
        align-items: flex-start;
        text-align: left;
        max-width: 340px;
        margin-left: auto;
        margin-right: auto;
    }

    .google-btn {
        max-width: 100%;
        min-height: 50px;
    }
    .google-content { font-size: 14px; }

    .alert-custom { max-width: 100%; }
    .footer-note {
        margin-left: auto;
        margin-right: auto;
        justify-content: center;
    }
}

/* small phones */
@media (max-width: 479px) {
    .panel-right { height: 200px; }
    .badge-platform { display: none; }
    .clock-time { font-size: 19px; }
    .panel-left { padding: 30px 20px 36px; }
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   REDUCED MOTION
   â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.001ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.001ms !important;
    }
    body { opacity: 1; }
    .login-card,
    .panel-media,
    .anim,
    .time-card,
    .bp-grid,
    .bg-grid {
        opacity: 1 !important;
        transform: none !important;
    }
}

/* ---- COOL LOGIN POPUPS (SweetAlert2 theme) ---- */
</style>
</head>

<body>

<!-- â”€â”€ ANIMATED BLUEPRINT BACKGROUND â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="bg-layer" aria-hidden="true">
    <div class="bg-grid"></div>
    <div class="bg-photo"></div>
    <div class="bg-aura bg-aura-1"></div>
    <div class="bg-aura bg-aura-2"></div>
    <div class="bg-aura bg-aura-3"></div>
    <div class="bg-reflect"></div>
    <div class="bg-reflect-pool"></div>
</div>

<!-- â”€â”€ LOGIN CARD â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<main class="login-card" role="main" aria-label="PROJ-TRACK System login">

    <!-- RIGHT: AUTH FORM (rendered on the right via row-reverse) -->
    <section class="panel panel-left" aria-labelledby="welcomeTitle">

        <span class="badge-pmu anim st1"><i class="bi bi-diagram-3-fill"></i> PMU Construction Monitoring</span>

        <h1 class="welcome anim st2" id="welcomeTitle">Welcome Users</h1>

        <p class="subtitle anim st3">
            Login to continue to <strong>PROJ-TRACK SYSTEM</strong>
        </p>

        <ul class="feature-list anim st4">
            <li><i class="bi bi-graph-up-arrow"></i>S-curve tracking</li>
            <li><i class="bi bi-people-fill"></i>Built for admins, staff &amp; contractors</li>
            <li><i class="bi bi-shield-lock-fill"></i>Secured with Google sign-in</li>
        </ul>

        @php
            $lockoutUntil = session('lockout_until');
        @endphp

        @if(session('error'))
            <div class="alert-custom alert-danger-custom anim st5" id="errorAlert" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if($lockoutUntil)
            @php
                $secsLeft = $lockoutUntil - time();
                $tierLabel = $secsLeft > 3600 ? '24-hour' : ($secsLeft > 500 ? '10-minute' : '5-minute');
            @endphp
            <div class="alert-custom alert-warning-custom anim st5" id="lockoutAlert" role="alert">
                <i class="bi bi-lock-fill"></i>
                <span><strong>{{ $tierLabel }} lockout active.</strong> Try again in <span id="countdown">--</span></span>
            </div>
        @endif

        <!-- GOOGLE BUTTON (only login method) -->
        <button onclick="loginWithGoogle()" class="google-btn anim st6" aria-label="Continue with Google account">
            <div class="google-content">
                <svg viewBox="0 0 48 48" width="20" height="20" style="flex-shrink:0;" aria-hidden="true" focusable="false">
                    <path fill="#EA4335" d="M24 9.5c3.5 0 6.7 1.2 9.2 3.6l6.9-6.9C35.7 2.4 30.2 0 24 0 14.8 0 6.7 5.5 2.7 13.4l8 6.2C12.4 13.1 17.7 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.1 24.5c0-1.6-.1-3.2-.4-4.7H24v9h12.4c-.5 2.9-2.2 5.4-4.6 7l7.2 5.6c4.9-4.5 7.1-10.2 7.1-16.9z"/>
                    <path fill="#FBBC05" d="M10.7 28.6c-1-3.1-1-6.4 0-9.5l-8-6.2C.9 16.4 0 20.1 0 24s.9 7.6 2.7 11l8-6.4z"/>
                    <path fill="#34A853" d="M24 48c6.2 0 11.4-2 15.2-5.6l-7.2-5.6c-2 1.3-4.6 2.1-8 2.1-6.3 0-11.6-3.6-13.3-8.9l-8 6.4C6.7 42.5 14.8 48 24 48z"/>
                </svg>
                <span>Continue with Google account</span>
            </div>
        </button>

        <!-- PUBLIC WARRANTY PREVIEW (no sign-in required, opens in new tab) -->
        <a href="{{ route('warranty.preview.public') }}" target="_blank" rel="noopener" class="preview-btn anim st7" aria-label="Preview warranty records without signing in">
            <i class="bi bi-file-earmark-text"></i>
            <span>Preview Warranty Records</span>
            <i class="bi bi-box-arrow-up-right ext"></i>
        </a>

        <div class="footer-note anim st7">
            <i class="bi bi-info-circle"></i> Authorized personnel only &mdash; access is logged
        </div>

    </section>

    <!-- LEFT: CONSTRUCTION BLUEPRINT CENTERPIECE (rendered on the left via row-reverse) -->
    <section class="panel panel-right" aria-label="Construction monitoring overview">

        <div class="panel-media">
            <img src="{{ asset('images/login-image.png') }}" alt="Construction site under monitoring" class="panel-bg-img" loading="lazy" decoding="async">
            <div class="scrim"></div>
            <div class="bp-grid"></div>
        </div>

        <!-- overlay content -->
        <div class="overlay-content">


            <h2 class="brand-title anim st5">
                PROJ-TRACK
                <span class="grad">SYSTEM</span>
                <small>PMU CONSTRUCTION MONITORING</small>
            </h2>

            <p class="tagline anim st6">
                From groundbreaking to turnover &mdash; every milestone, tracked in one place.
            </p>

            <div class="feat-badges anim st7">
                <div class="feat-badge"><i class="bi bi-graph-up-arrow"></i>S-Curve Tracking</div>
                <div class="feat-badge"><i class="bi bi-clipboard-data-fill"></i>Progress Reports</div>
                <div class="feat-badge"><i class="bi bi-exclamation-triangle-fill"></i>Issue Alerts</div>
            </div>

            <div class="time-card">
                <div class="time-head">
                    <span>System Time</span>
                    <span class="live" aria-label="Live"><span class="dot"></span>LIVE</span>
                </div>
                <div class="clock-time" id="liveClockTime" role="timer">--:--:--</div>
                <div class="clock-date" id="liveClockDate">Loading date&hellip;</div>
            </div>
        </div>

    </section>

</main>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
/* Google authentication â€” only login method. The OAuth flow runs in a small
   floating popup (like most modern websites); the login page stays open and
   receives the result via postMessage once the popup closes. */
let authPopup         = null;
let authHandled       = false;
let authPoller        = null;
let loginBtnOriginal  = null;

function resetLoginButton() {
    const btn = document.querySelector('.google-btn');
    if (!btn) return;
    if (loginBtnOriginal !== null) {
        btn.innerHTML     = loginBtnOriginal;
        loginBtnOriginal  = null;
    }
    btn.disabled      = false;
    btn.style.opacity = "1";
}

function loginWithGoogle() {
    const btn = document.querySelector('.google-btn');

    if (btn.disabled) return;

    loginBtnOriginal   = btn.innerHTML;
    authHandled        = false;

    btn.innerHTML = '<div class="google-content"><i class="bi bi-arrow-repeat spin"></i><span>Opening Google sign-in&hellip;</span></div>';
    btn.disabled      = true;
    btn.style.opacity = "0.7";

    const width  = 500;
    const height = 650;
    const left   = window.screenX + Math.max((window.outerWidth  - width)  / 2, 0);
    const top    = window.screenY + Math.max((window.outerHeight - height) / 2, 0);

    const popup = window.open(
        '/auth/google?popup=1',
        'googleLoginPopup',
        'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top
    );

    // Popup was blocked â€” fall back to a normal full-page redirect.
    if (!popup) {
        window.location.href = '/auth/google';
        return;
    }

    authPopup = popup;

    // If the user closes the popup without finishing, restore the button.
    if (authPoller) clearInterval(authPoller);
    authPoller = setInterval(() => {
        if (authPopup && authPopup.closed && !authHandled) {
            clearInterval(authPoller);
            authPoller = null;
            authPopup  = null;
            resetLoginButton();
        }
    }, 600);
}

/* Result posted back by the popup before it closes. */
function handleAuthResult(d) {
    if (!d || d.type !== 'google-auth') return false;
    if (authHandled) return true;

    authHandled = true;
    if (authPoller) { clearInterval(authPoller); authPoller = null; }
    authPopup = null;

    if (d.success && d.redirect) {
        // The auth session is already active for this tab — go to the dashboard.
        window.location.href = d.redirect;
        return true;
    }

    resetLoginButton();
    showLoginError(d.error || 'Google sign-in failed. Please try again.');
    return true;
}

window.addEventListener('message', (e) => {
    if (e.origin !== window.location.origin) return;
    handleAuthResult(e.data);
});

/* Broadcast channel fallback: the popup also broadcasts the result across
   tabs of this origin, so the main tab still redirects even if postMessage
   or sessionStorage are unavailable. */
try {
    const bc = new BroadcastChannel('google-auth');
    bc.onmessage = (e) => handleAuthResult(e.data);
} catch (e) { /* BroadcastChannel unsupported */ }

/* Fallback channel: the popup writes the result into sessionStorage, which is
   shared with this (opener) tab, so the main tab still redirects to the
   dashboard even if postMessage or window.close() fails on mobile. */
(function () {
    const KEY = 'google-auth-result';
    try { sessionStorage.removeItem(KEY); } catch (e) { /* ignore */ }

    const poll = setInterval(() => {
        let raw = null;
        try { raw = sessionStorage.getItem(KEY); } catch (e) { /* ignore */ }
        if (!raw) return;

        let d = null;
        try {
            sessionStorage.removeItem(KEY);
            d = JSON.parse(raw);
        } catch (e) { return; }

        if (handleAuthResult(d)) {
            clearInterval(poll);
        }
    }, 400);
})();

/* Single reusable popup (default white SweetAlert2, matching the rest of the app). */
function coolPopup(kind, title, messageHTML, confirmColor) {
    const iconMap = { error: 'error', warning: 'warning', lock: 'warning' };

    Swal.fire({
        icon: iconMap[kind] || 'error',
        title: title,
        html: messageHTML,
        confirmButtonColor: confirmColor || '#2563eb'
    });
}

/* Small centered popup for popup auth failures. */
function showLoginError(message) {
    if (typeof Swal !== 'undefined') {
        coolPopup('error', 'Sign-in failed', message, 'linear-gradient(135deg,#dc2626,#ef4444)');
        return;
    }
    let toast = document.getElementById('loginErrorToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'loginErrorToast';
        toast.setAttribute('role', 'alert');
        toast.style.cssText =
            'position:fixed;z-index:9999;left:50%;top:50%;transform:translate(-50%,-50%) scale(.95);' +
            'display:flex;align-items:flex-start;gap:10px;max-width:420px;width:calc(100% - 40px);' +
            'padding:14px 16px;border-radius:14px;background:#FEF2F2;border:1px solid #FECACA;' +
            'color:#B91C1C;font-size:13.5px;line-height:1.45;font-family:\'Inter\',sans-serif;' +
            'box-shadow:0 14px 34px -10px rgba(185,28,28,0.35);opacity:0;' +
            'transition:opacity .25s ease, transform .25s ease;';
        toast.innerHTML = '<i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;margin-top:2px"></i><span></span>';
        document.body.appendChild(toast);
    }
    toast.querySelector('span').textContent = message;
    toast.style.opacity     = '1';
    toast.style.transform   = 'translate(-50%,-50%) scale(1)';

    clearTimeout(showLoginError.timer);
    showLoginError.timer = setTimeout(() => {
        toast.style.opacity   = '0';
        toast.style.transform = 'translate(-50%,-50%) scale(.95)';
    }, 6000);
}

/* Live system clock */
(function() {
    const timeEl = document.getElementById('liveClockTime');
    const dateEl = document.getElementById('liveClockDate');
    if (!timeEl || !dateEl) return;

    function pad(n) { return String(n).padStart(2, '0'); }

    function render() {
        const now = new Date();
        let hours = now.getHours();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        if (hours === 0) hours = 12;
        const h = pad(hours);
        const m = pad(now.getMinutes());
        const s = pad(now.getSeconds());
        timeEl.innerHTML = `${h}<span class="blink">:</span>${m}<span class="blink">:</span>${s} <span class="ampm">${ampm}</span>`;
        dateEl.textContent = now.toLocaleDateString(undefined, {
            weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
        });
    }

    render();
    setInterval(render, 1000);
})();

/* Lockout countdown */
(function() {
    const lockoutUntil = {{ $lockoutUntil ?? 'null' }};
    if (!lockoutUntil) return;

    const countdownEl = document.getElementById('countdown');
    const btn         = document.querySelector('.google-btn');

    function tick() {
        const remaining = Math.max(0, lockoutUntil - Math.floor(Date.now() / 1000));
        const m = Math.floor(remaining / 60);
        const s = remaining % 60;
        countdownEl.textContent = m > 0
            ? `${m}m ${String(s).padStart(2,'0')}s`
            : `${s}s`;

        if (remaining <= 0) {
            clearInterval(timer);
            document.getElementById('lockoutAlert').textContent = 'Lockout expired. You may try again.';
            if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
        }
    }

    if (btn) { btn.disabled = true; btn.style.opacity = '0.4'; }
    tick();
    const timer = setInterval(tick, 1000);
})();

/* Pop up login warnings (session error / lockout) with SweetAlert2 */
(function () {
    @if(session('error'))
        coolPopup('warning', 'Sign-in notice', @json(session('error')), 'linear-gradient(135deg,#d97706,#f59e0b)');
    @elseif($lockoutUntil)
        coolPopup('lock', 'Account temporarily locked', 'Too many failed sign-in attempts have locked this account.<small>Wait for the countdown in the notice above to finish before trying again.</small>', 'linear-gradient(135deg,#d97706,#f59e0b)');
    @endif
})();

</script>

</body>
</html>
