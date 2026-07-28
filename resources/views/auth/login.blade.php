<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | PROJ-TRACK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">

<style>
/* PAGE FADE */
body {
    margin: 0;
    min-height: 100vh;
    font-family: 'Segoe UI', sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    position: relative;
    overflow-x: hidden;

    opacity: 0;
    background: linear-gradient(-45deg, #e5e7eb, #2563eb, #e5e7eb, #2563eb);
    background-size: 300% 300%;
    animation: gradientMove 10s ease infinite, pageFade 0.8s ease forwards;
}

@keyframes pageFade {
    to { opacity: 1; }
}

@keyframes gradientMove {
    0%   { background-position: 0% 50%; }
    50%  { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

/* DECORATIVE FLOATING BLOBS */
.bg-blob {
    position: fixed;
    border-radius: 50%;
    filter: blur(60px);
    z-index: 0;
    pointer-events: none;
    opacity: 0.35;
}
.blob-1 {
    width: 380px; height: 380px;
    background: #1e3a8a;
    top: -120px; left: -100px;
    animation: floatBlob1 14s ease-in-out infinite;
}
.blob-2 {
    width: 320px; height: 320px;
    background: #60a5fa;
    bottom: -100px; right: -80px;
    animation: floatBlob2 16s ease-in-out infinite;
}
.blob-3 {
    width: 220px; height: 220px;
    background: #93c5fd;
    top: 40%; right: 8%;
    animation: floatBlob3 12s ease-in-out infinite;
}
@keyframes floatBlob1 {
    0%,100% { transform: translate(0,0) scale(1); }
    50%     { transform: translate(40px,30px) scale(1.1); }
}
@keyframes floatBlob2 {
    0%,100% { transform: translate(0,0) scale(1); }
    50%     { transform: translate(-30px,-40px) scale(1.05); }
}
@keyframes floatBlob3 {
    0%,100% { transform: translate(0,0) scale(1); opacity: 0.35; }
    50%     { transform: translate(-20px,20px) scale(1.15); opacity: 0.5; }
}

/* CARD */
.login-card {
    width: 900px;
    max-width: 100%;
    position: relative;
    z-index: 1;
    border-radius: 20px;
    overflow: hidden;
    box-shadow:
        0 20px 40px rgba(0,0,0,0.15),
        0 8px 20px rgba(37,99,235,0.15);
    border: 1px solid rgba(0,0,0,0.05);
    animation: fadeSlideUp 0.8s ease forwards, floatCard 7s cubic-bezier(0.45, 0, 0.55, 1) 0.8s infinite;
    transition: all 0.3s ease;
}

.login-card:hover {
    transform: scale(1.01);
    box-shadow:
        0 30px 70px rgba(0,0,0,0.25),
        0 10px 30px rgba(37,99,235,0.25);
}

.login-card::before {
    content: "";
    position: absolute;
    inset: -2px;
    border-radius: 22px;
    background: linear-gradient(135deg, rgba(37,99,235,0.3), transparent);
    z-index: -1;
    filter: blur(20px);
}

@keyframes fadeSlideUp {
    from { opacity: 0; transform: translateY(40px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

@keyframes floatCard {
    0%   { transform: translateY(0); }
    25%  { transform: translateY(-4px); }
    50%  { transform: translateY(-8px); }
    75%  { transform: translateY(-4px); }
    100% { transform: translateY(0); }
}

/* FORM SIDE */
.form-side {
    background: #f3f4f6;
    padding: 50px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    position: relative;
}

/* small brand mark above the heading */
.brand-mark {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    width: fit-content;
    padding: 6px 12px;
    border-radius: 999px;
    background: rgba(37,99,235,0.1);
    color: #2563eb;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    margin-bottom: 16px;
}
.brand-mark i { font-size: 13px; }

/* feature list under the subtitle */
.feature-list {
    list-style: none;
    padding: 0;
    margin: 0 0 30px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.feature-list li {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13.5px;
    color: #374151;
}
.feature-list li i {
    width: 26px;
    height: 26px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: rgba(37,99,235,0.1);
    color: #2563eb;
    font-size: 13px;
}

.google-btn {
    width: 100%;
    max-width: 320px;
}

.footer-note {
    margin-top: 18px;
    font-size: 11.5px;
    color: #9ca3af;
    max-width: 320px;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* alerts — restyled to match the custom theme instead of default Bootstrap look */
.alert-custom {
    width: 100%;
    max-width: 320px;
    border-radius: 10px;
    border: 1px solid;
    padding: 12px 14px;
    font-size: 13px;
    line-height: 1.4;
    margin-bottom: 18px;
    display: flex;
    align-items: flex-start;
    gap: 9px;
}
.alert-custom i { margin-top: 1px; flex-shrink: 0; }
.alert-danger-custom {
    background: #fef2f2;
    border-color: #fecaca;
    color: #b91c1c;
}
.alert-warning-custom {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}
.alert-warning-custom #countdown {
    font-family: 'IBM Plex Mono', monospace;
    background: rgba(0,0,0,0.06);
    padding: 1px 6px;
    border-radius: 5px;
}

/* STAGGER */
.form-side > * {
    opacity: 0;
    transform: translateY(15px);
    animation: staggerFade 0.6s ease forwards;
}
.form-side > *:nth-child(1) { animation-delay: 0.1s; }
.form-side > *:nth-child(2) { animation-delay: 0.2s; }
.form-side > *:nth-child(3) { animation-delay: 0.3s; }
.form-side > *:nth-child(4) { animation-delay: 0.4s; }
.form-side > *:nth-child(5) { animation-delay: 0.5s; }
.form-side > *:nth-child(6) { animation-delay: 0.6s; }
.form-side > *:nth-child(7) { animation-delay: 0.7s; }

@keyframes staggerFade {
    to { opacity: 1; transform: translateY(0); }
}

/* GOOGLE BUTTON */
.google-btn {
    background: #fff;
    border: 1px solid rgba(0,0,0,0.15);
    border-radius: 10px;
    padding: 12px;
    font-weight: 500;
    color: #000000;
    box-shadow:
        0 2px 6px rgba(0,0,0,0.08),
        0 1px 2px rgba(0,0,0,0.05);
    transition: all 0.25s ease;
    position: relative;
    overflow: hidden;
    cursor: pointer;
}
.google-btn:hover {
    border-color: rgba(0,0,0,0.25);
    box-shadow:
        0 6px 18px rgba(0,0,0,0.2),
        0 2px 6px rgba(0,0,0,0.1);
    transform: translateY(-2px) scale(1.01);
}
.google-content {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}
.google-content span {
    font-size: 14px;
}

/* IMAGE SIDE */
.image-side {
    background: linear-gradient(135deg, #2563eb, #1e3a8a);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
}
.image-side img {
    width: 150%;
    height: 150%;
    object-fit: cover;
    animation: zoomImage 20s ease-in-out infinite alternate;
}

@keyframes zoomImage {
    from { transform: scale(1); }
    to   { transform: scale(1.1); }
}

/* soft dark gradient so text stays legible over the photo */
.image-scrim {
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(30,58,138,0.15) 0%, rgba(15,23,42,0.55) 100%);
    z-index: 1;
}

.overlay-content {
    position: absolute;
    inset: 0;
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 30px;
}

.overlay-text {
    font-family: 'Bebas Neue', sans-serif;
    font-weight: 800;
    font-size: 46px;
    letter-spacing: 2px;
    line-height: 1.3;
    color: #ffffff;
    text-transform: uppercase;

    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: optimizeLegibility;

    text-shadow:
        0 2px 4px rgba(0,0,0,0.3),
        0 6px 16px rgba(0,0,0,0.3),
        0 12px 32px rgba(37,99,235,0.35),
        0 0 60px rgba(37,99,235,0.15);

    -webkit-text-stroke: 0.3px rgba(255,255,255,0.15);

    animation: overlayReveal 1.4s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    opacity: 0;
}
@keyframes overlayReveal {
    from { opacity: 0; transform: translateY(14px); }
    to   { opacity: 1; transform: translateY(0); }
}

.overlay-tagline {
    margin-top: 14px;
    color: rgba(255,255,255,0.85);
    font-size: 13.5px;
    letter-spacing: 0.04em;
    max-width: 280px;
    opacity: 0;
    animation: overlayReveal 1.4s cubic-bezier(0.4, 0, 0.2, 1) 0.3s forwards;
}

/* live clock over the image */
.live-clock {
    margin-top: 26px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 14px 24px;
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 14px;
    opacity: 0;
    animation: overlayReveal 1.4s cubic-bezier(0.4, 0, 0.2, 1) 0.5s forwards;
}
.live-clock .clock-time {
    font-family: 'IBM Plex Mono', 'Bebas Neue', monospace;
    font-size: 30px;
    font-weight: 600;
    color: #fff;
    letter-spacing: 1px;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}
.live-clock .clock-time .blink {
    animation: blinkColon 1s step-start infinite;
}
@keyframes blinkColon { 50% { opacity: 0.25; } }
.live-clock .clock-time .ampm {
    font-size: 14px;
    font-weight: 500;
    letter-spacing: 0.05em;
    color: rgba(255,255,255,0.75);
    margin-left: 2px;
}
.live-clock .clock-date {
    font-size: 11px;
    color: rgba(255,255,255,0.75);
    text-transform: uppercase;
    letter-spacing: 0.08em;
}
.live-clock .clock-live {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 9px;
    color: #86efac;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-top: 2px;
}
.live-clock .clock-live .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #4ade80;
    animation: pulseDot 1.6s ease-in-out infinite;
}
@keyframes pulseDot {
    0%,100% { opacity: 1; box-shadow: 0 0 0 0 rgba(74,222,128,0.5); }
    50%     { opacity: 0.6; box-shadow: 0 0 0 5px rgba(74,222,128,0); }
}

/* SPINNER */
.spin {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}

/* ─── MOBILE ─── */
@media (max-width: 767px) {
    body {
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        padding: 24px 20px;
    }

    .login-card {
        width: 100%;
        border-radius: 20px;
        animation: fadeSlideUp 0.8s ease forwards;
    }

    .login-card:hover {
        transform: none;
        box-shadow:
            0 20px 40px rgba(0,0,0,0.15),
            0 8px 20px rgba(37,99,235,0.15);
    }

    .image-side {
        display: none !important;
    }

    .form-side {
        padding: 44px 28px 48px;
        width: 100%;
        max-width: 100%;
        align-items: center;
        text-align: center;
    }

    .brand-mark {
        margin-left: auto;
        margin-right: auto;
    }

    .form-side h3 {
        font-size: 24px !important;
    }

    .form-side p {
        font-size: 13px !important;
        margin-bottom: 28px !important;
    }

    .feature-list {
        align-items: flex-start;
        text-align: left;
        max-width: 320px;
        margin-left: auto;
        margin-right: auto;
    }

    .google-btn {
        max-width: 100%;
        width: 100%;
    }

    .alert-custom {
        max-width: 100%;
    }

    .footer-note {
        margin-left: auto;
        margin-right: auto;
        justify-content: center;
    }

    .google-content span {
        font-size: 13px;
    }

    .google-btn:hover {
        transform: none;
    }
}
</style>
</head>

<body>

<div class="bg-blob blob-1"></div>
<div class="bg-blob blob-2"></div>
<div class="bg-blob blob-3"></div>

<div class="login-card row g-0">

    <!-- LEFT: FORM -->
    <div class="col-md-6 form-side">

        <span class="brand-mark"><i class="bi bi-diagram-3-fill"></i> PMU Construction Monitoring</span>

        <h3 class="fw-bold mb-1" style="font-size:28px;">
            Welcome Users
        </h3>

        <p class="mb-4" style="opacity:0.7; font-size:15px;">
            Login to Continue to <strong style="color:#2563eb;">PROJ-TRACK SYSTEM</strong>
        </p>

        <ul class="feature-list">
            <li><i class="bi bi-graph-up-arrow"></i> Real-time project &amp; S-curve tracking</li>
            <li><i class="bi bi-people-fill"></i> Built for admins, staff &amp; contractors</li>
            <li><i class="bi bi-shield-lock-fill"></i> Secured with Google sign-in</li>
        </ul>

        @php
            $lockoutUntil = session('lockout_until');
        @endphp

        @if(session('error'))
            <div class="alert-custom alert-danger-custom" id="errorAlert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if($lockoutUntil)
            @php
                $secsLeft = $lockoutUntil - time();
                $tierLabel = $secsLeft > 3600 ? '24-hour' : ($secsLeft > 500 ? '10-minute' : '5-minute');
            @endphp
            <div class="alert-custom alert-warning-custom" id="lockoutAlert">
                <i class="bi bi-lock-fill"></i>
                <span><strong>{{ $tierLabel }} lockout active.</strong> Try again in <span id="countdown">--</span></span>
            </div>
        @endif

        <!-- GOOGLE BUTTON -->
        <button onclick="loginWithGoogle()" class="google-btn">
            <div class="google-content">
                <svg viewBox="0 0 48 48" width="22" style="flex-shrink:0;">
                    <path fill="#EA4335" d="M24 9.5c3.5 0 6.7 1.2 9.2 3.6l6.9-6.9C35.7 2.4 30.2 0 24 0 14.8 0 6.7 5.5 2.7 13.4l8 6.2C12.4 13.1 17.7 9.5 24 9.5z"/>
                    <path fill="#4285F4" d="M46.1 24.5c0-1.6-.1-3.2-.4-4.7H24v9h12.4c-.5 2.9-2.2 5.4-4.6 7l7.2 5.6c4.9-4.5 7.1-10.2 7.1-16.9z"/>
                    <path fill="#FBBC05" d="M10.7 28.6c-1-3.1-1-6.4 0-9.5l-8-6.2C.9 16.4 0 20.1 0 24s.9 7.6 2.7 11l8-6.4z"/>
                    <path fill="#34A853" d="M24 48c6.2 0 11.4-2 15.2-5.6l-7.2-5.6c-2 1.3-4.6 2.1-8 2.1-6.3 0-11.6-3.6-13.3-8.9l-8 6.4C6.7 42.5 14.8 48 24 48z"/>
                </svg>
                <span style="font-weight:600;">Continue with Google account</span>
            </div>
        </button>

        <div class="footer-note">
            <i class="bi bi-info-circle"></i> Authorized personnel only &mdash; access is logged
        </div>

    </div>

    <!-- RIGHT: IMAGE -->
    <div class="col-md-6 image-side">
        <img src="{{ asset('images/login-image.png') }}">
        <div class="image-scrim"></div>

        <div class="overlay-content">
            <div class="overlay-text">PROJ-TRACK SYSTEM</div>
            <div class="overlay-tagline">From groundbreaking to turnover &mdash; every milestone, tracked in one place.</div>

            <div class="live-clock">
                <div class="clock-time" id="liveClockTime">--:--:--</div>
                <div class="clock-date" id="liveClockDate">Loading date&hellip;</div>
                <div class="clock-live"><span class="dot"></span> Live</div>
            </div>
        </div>
    </div>

</div>

<script>
function loginWithGoogle() {
    const btn = document.querySelector('.google-btn');

    if (btn.disabled) return;

    const originalContent = btn.innerHTML;

    btn.innerHTML     = '<i class="bi bi-arrow-repeat spin"></i> Logging in...';
    btn.disabled      = true;
    btn.style.opacity = "0.3";

    setTimeout(() => {
        window.location.href = "/auth/google";
    }, 700);

    setTimeout(() => {
        btn.innerHTML     = originalContent;
        btn.disabled      = false;
        btn.style.opacity = "1";
    }, 5000);
}

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
</script>

</body>
</html>