@auth
{{-- AUTO LOGOUT AFTER INACTIVITY --}}
<style>
/* ───── Session timeout alert — scoped to .idl-* ───── */
.swal2-container { backdrop-filter: blur(10px) !important; }

.idl-popup {
    border: none !important;
    border-radius: 24px !important;
    padding: 0 !important;
    overflow: hidden;
    background: #FFFFFF !important;
    font-family: 'Segoe UI', sans-serif;
    width: 470px !important;
    box-shadow:
        0 40px 90px -18px rgba(3, 8, 20, 0.45),
        0 14px 34px -12px rgba(220, 38, 38, 0.18) !important;
}

/* animated warning light along the top edge */
.idl-topbar {
    height: 6px;
    background: linear-gradient(90deg, #dc2626, #f87171, #fecaca, #f87171, #dc2626);
    background-size: 220% 100%;
    animation: idlShift 2.4s linear infinite;
}
@keyframes idlShift {
    from { background-position: 0 0; }
    to   { background-position: -220% 0; }
}

/* icon tile with pulsing red glow + wagging bell */
.idl-icon {
    width: 86px;
    height: 86px;
    margin: 28px auto 0;
    border-radius: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    background: linear-gradient(145deg, #ef4444, #b91c1c);
    box-shadow:
        0 22px 44px -12px rgba(220, 38, 38, 0.65),
        inset 0 2px 4px rgba(255, 255, 255, 0.4),
        inset 0 -3px 8px rgba(0, 0, 0, 0.25);
    animation: idlPulse 1.5s ease-in-out infinite;
}
@keyframes idlPulse {
    0%, 100% { box-shadow: 0 22px 44px -12px rgba(220, 38, 38, 0.65), inset 0 2px 4px rgba(255,255,255,0.4); }
    50%      { box-shadow: 0 22px 56px -8px rgba(220, 38, 38, 0.9),  inset 0 2px 4px rgba(255,255,255,0.4); }
}
.idl-icon svg {
    width: 40px;
    height: 40px;
    animation: idlWag 0.7s ease-in-out 3;
}
@keyframes idlWag {
    0%, 100% { transform: rotate(0) translateY(0); }
    20%      { transform: rotate(-16deg); }
    40%      { transform: rotate(12deg); }
    60%      { transform: rotate(-8deg); }
    80%      { transform: rotate(4deg); }
}

.idl-title {
    margin: 18px 24px 0;
    text-align: center;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: #0f172a;
}
.idl-msg {
    margin: 6px 30px 0;
    text-align: center;
    font-size: 13.5px;
    line-height: 1.6;
    color: #64748b;
}
.idl-msg small { display: block; color: #94a3b8; font-size: 11.5px; margin-top: 4px; }

/* big tabular countdown */
.idl-count {
    display: flex;
    align-items: baseline;
    justify-content: center;
    gap: 8px;
    margin: 14px 0 2px;
}
.idl-count b {
    font-family: 'JetBrains Mono', 'Consolas', monospace;
    font-size: 54px;
    font-weight: 700;
    line-height: 1;
    color: #dc2626;
    font-variant-numeric: tabular-nums;
    text-shadow: 0 4px 18px rgba(220, 38, 38, 0.25);
}
.idl-count em {
    font-style: normal;
    font-size: 12px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #94a3b8;
}

/* shrinking progress bar */
.idl-bar { margin: 12px 34px 6px; height: 10px; border-radius: 999px; background: #f1f5f9; overflow: hidden; }
.idl-bar span {
    display: block;
    height: 100%;
    width: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #f87171, #dc2626);
    box-shadow: 0 0 14px rgba(239, 68, 68, 0.55);
    transition: width 1s linear;
}

/* buttons */
.idl-popup .swal2-actions { margin: 22px 30px 26px; display: flex; gap: 12px; justify-content: center; }
.idl-popup .swal2-actions button { flex: 1; min-height: 46px; margin: 0 !important; font-size: 13.5px !important; letter-spacing: 0.02em; transition: transform 0.2s, box-shadow 0.2s, background 0.2s; }
.idl-confirm {
    background: linear-gradient(135deg, #2563eb, #1e40af) !important;
    color: #fff !important;
    border: none !important;
    border-radius: 14px !important;
    font-weight: 700 !important;
    box-shadow: 0 12px 26px -10px rgba(37, 99, 235, 0.7) !important;
}
.idl-confirm:hover { transform: translateY(-2px); box-shadow: 0 16px 32px -10px rgba(37, 99, 235, 0.85) !important; }
.idl-confirm:active { transform: translateY(0) scale(0.97); }
.idl-cancel {
    background: #fff !important;
    color: #dc2626 !important;
    border: 1.5px solid #fecaca !important;
    border-radius: 14px !important;
    font-weight: 700 !important;
}
.idl-cancel:hover { background: #fef2f2 !important; border-color: #f87171 !important; transform: translateY(-2px); }

/* enter / exit animation */
.idl-pop-in { animation: idlIn 0.45s cubic-bezier(0.34, 1.56, 0.64, 1) both !important; }
@keyframes idlIn {
    from { opacity: 0; transform: translateY(-34px) scale(0.92); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
.idl-pop-out { animation: idlOut 0.25s ease-in both !important; }
@keyframes idlOut {
    from { opacity: 1; transform: translateY(0) scale(1); }
    to   { opacity: 0; transform: translateY(-18px) scale(0.94); }
}
</style>

<script>
(function () {
    var LIMIT      = 20 * 60 * 1000;    // 20 minutes without activity
    var WARN_AT    = 30 * 1000;        // alarm + countdown popup during the final 30s

    var lastAction = Date.now();
    var dialogOpen = false;
    var loggedOut  = false;
    var beepTimer  = null;
    var beepCtx    = null;

    /* Repeating warning chime (Web Audio — no audio file needed). */
    function beep() {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (!beepCtx) beepCtx = new Ctx();
            if (beepCtx.state === 'suspended') beepCtx.resume();
            var t = beepCtx.currentTime;
            [880, 1174].forEach(function (freq, i) {
                var osc = beepCtx.createOscillator();
                var gain = beepCtx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                var start = t + i * 0.28;
                gain.gain.setValueAtTime(0.001, start);
                gain.gain.exponentialRampToValueAtTime(0.35, start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, start + 0.24);
                osc.connect(gain);
                gain.connect(beepCtx.destination);
                osc.start(start);
                osc.stop(start + 0.26);
            });
        } catch (e) { /* audio unavailable — ignore */ }
    }

    function startAlarm() {
        beep();
        beepTimer = setInterval(beep, 1000);
    }

    function stopAlarm() {
        if (beepTimer) { clearInterval(beepTimer); beepTimer = null; }
    }

    function performLogout() {
        stopAlarm();
        if (loggedOut) return;
        loggedOut = true;
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = '{{ route('logout') }}';
        var t = document.createElement('input');
        t.type = 'hidden';
        t.name = '_token';
        t.value = '{{ csrf_token() }}';
        f.appendChild(t);
        document.body.appendChild(f);
        f.submit();
    }

    function refresh() {
        lastAction = Date.now();
        stopAlarm();
        dialogOpen = false;
    }

    ['mousedown', 'mousemove', 'keydown', 'scroll', 'touchstart', 'click', 'wheel'].forEach(function (ev) {
        document.addEventListener(ev, refresh, { passive: true });
    });

    var ALERT_ICON =
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
        'stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>' +
        '<path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>';

    function presentWarning(seconds) {
        dialogOpen = true;
        startAlarm();
        Swal.fire({
            customClass: {
                popup: 'idl-popup',
                confirmButton: 'idl-confirm',
                cancelButton: 'idl-cancel'
            },
            html:
                '<div class="idl-topbar"></div>' +
                '<div class="idl-icon">' + ALERT_ICON + '</div>' +
                '<div class="idl-title">Session Ending Soon</div>' +
                '<div class="idl-msg">You have been inactive and will be signed out automatically in</div>' +
                '<div class="idl-count"><b id="swalCountdown">' + seconds + '</b><em>seconds</em></div>' +
                '<div class="idl-bar"><span id="idlBar"></span></div>',
            confirmButtonText: 'Stay signed in',
            showCancelButton: true,
            cancelButtonText: 'Sign out',
            allowOutsideClick: false,
            allowEscapeKey: false,
            backdrop: 'rgba(3, 8, 20, 0.55)',
            position: 'top',
            showClass: { popup: 'idl-pop-in' },
            hideClass: { popup: 'idl-pop-out' }
        }).then(function (result) {
            stopAlarm();
            if (result.dismiss === Swal.DismissReason.cancel) {   // "Sign out"
                performLogout();
            } else {                                              // confirmed or auto-closed = stay
                refresh();
            }
        });
    }

    setInterval(function () {
        var remaining = LIMIT - (Date.now() - lastAction);

        if (remaining <= 0) {
            performLogout();
            return;
        }

        if (typeof Swal === 'undefined') return;

        var seconds = Math.ceil(remaining / 1000);

        if (remaining <= WARN_AT) {
            if (!dialogOpen) {
                presentWarning(seconds);
            } else {
                var el = document.getElementById('swalCountdown');
                if (el) el.textContent = seconds;
                var bar = document.getElementById('idlBar');
                if (bar) bar.style.width = Math.max(0, (remaining / WARN_AT) * 100) + '%';
            }
        } else if (dialogOpen) {
            dialogOpen = false;
            stopAlarm();
            Swal.close();
        }
    }, 1000);
})();
</script>
@endauth