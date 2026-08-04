{{-- DEV DATE SIMULATOR + NOTIFICATION SCAN TRIGGER (non-production only) --}}
{{-- Shared across admin/staff/contractor layouts. The SimulateDate middleware
     (bootstrap/app.php) applies session('simulated_date') via Carbon::setTestNow
     for the duration of the HTTP request, so running the scan in-process here
     uses the simulated date end-to-end. --}}
<style>
    #dev-date-widget {
        position: fixed; bottom: 80px; right: 20px; z-index: 9999;
        display: none; font-family: 'Segoe UI', sans-serif;
    }
    #dev-date-widget.visible { display: block; }
    #dev-date-toggle {
        width: 44px; height: 44px; border-radius: 50%; border: none;
        background: #1e3a8a; color: #fff; font-size: 18px; cursor: pointer;
        box-shadow: 0 2px 12px rgba(0,0,0,0.25); transition: 0.2s;
        display: flex; align-items: center; justify-content: center;
    }
    #dev-date-toggle:hover { background: #2563eb; transform: scale(1.05); }
    #dev-date-panel {
        position: absolute; bottom: 54px; right: 0; width: 260px;
        background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.18);
        padding: 16px; display: none;
    }
    #dev-date-panel.visible { display: block; }
    #dev-date-panel h4 {
        margin: 0 0 10px; font-size: 13px; font-weight: 700; color: #1f2937;
    }
    #dev-date-panel .info-row {
        font-size: 11px; color: #6b7280; margin-bottom: 4px;
    }
    #dev-date-panel .info-row strong { color: #374151; }
    #dev-date-input {
        width: 100%; padding: 8px 10px; border: 1px solid #d1d5db;
        border-radius: 6px; font-size: 13px; margin: 8px 0;
    }
    #dev-date-apply, #dev-date-reset, #dev-notif-scan {
        width: 100%; padding: 8px; border: none; border-radius: 6px;
        font-size: 12px; font-weight: 600; cursor: pointer; margin-bottom: 6px;
    }
    #dev-date-apply { background: #1e3a8a; color: #fff; }
    #dev-date-apply:hover { background: #2563eb; }
    #dev-date-reset { background: #f3f4f6; color: #6b7280; }
    #dev-date-reset:hover { background: #e5e7eb; }
    #dev-notif-scan { background: #fef3c7; color: #92400e; }
    #dev-notif-scan:hover { background: #fde68a; }
    #dev-notif-scan:disabled { opacity: 0.6; cursor: wait; }
    .dev-badge {
        display: inline-block; background: #fef3c7; color: #92400e;
        font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 8px;
        margin-left: 4px;
    }
</style>
<div id="dev-date-widget">
    <div id="dev-date-panel">
        <h4><i class="bi bi-calendar3"></i> Date Simulator</h4>
        <div class="info-row">Real date: <strong id="dev-real-date"></strong></div>
        <div class="info-row">Simulated: <strong id="dev-sim-status">none</strong></div>
        <input type="date" id="dev-date-input">
        <button id="dev-date-apply"><i class="bi bi-check-lg"></i> Apply Date</button>
        <button id="dev-date-reset"><i class="bi bi-arrow-counterclockwise"></i> Reset to Real</button>
        <button id="dev-notif-scan"><i class="bi bi-bell"></i> Run Notification Scan</button>
    </div>
    <button id="dev-date-toggle" title="Date Simulator (Ctrl+M)">
        <i class="bi bi-clock"></i>
    </button>
</div>
<script>
(function () {
    const DEV_DATE_URLS = {
        get: '{{ route("dev.date.get") }}',
        set: '{{ route("dev.date.set") }}',
        clear: '{{ route("dev.date.clear") }}',
        scan: '{{ route("dev.notifications.scan") }}',
    };

    const widget = document.getElementById('dev-date-widget');
    const panel = document.getElementById('dev-date-panel');
    const toggle = document.getElementById('dev-date-toggle');
    const input = document.getElementById('dev-date-input');
    const applyBtn = document.getElementById('dev-date-apply');
    const resetBtn = document.getElementById('dev-date-reset');
    const scanBtn = document.getElementById('dev-notif-scan');
    const realDateEl = document.getElementById('dev-real-date');
    const simStatusEl = document.getElementById('dev-sim-status');

    function openPanel() {
        fetch(DEV_DATE_URLS.get)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                realDateEl.textContent = data.real_date;
                if (data.simulated_date) {
                    simStatusEl.textContent = data.simulated_date;
                    input.value = data.simulated_date;
                } else {
                    simStatusEl.textContent = 'none';
                    input.value = data.real_date;
                }
            });
        panel.classList.add('visible');
        widget.classList.add('visible');
        input.focus();
    }

    function closePanel() {
        panel.classList.remove('visible');
        widget.classList.remove('visible');
    }

    function togglePanel(e) {
        e.stopPropagation();
        if (panel.classList.contains('visible')) {
            closePanel();
        } else {
            openPanel();
        }
    }

    toggle.addEventListener('click', togglePanel);

    document.addEventListener('click', function (e) {
        if (!widget.contains(e.target)) {
            closePanel();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.key === 'm') {
            e.preventDefault();
            togglePanel(e);
        }
    });

    applyBtn.addEventListener('click', function () {
        var date = input.value;
        if (!date) return;
        fetch(DEV_DATE_URLS.set, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ date: date }),
        })
        .then(function (r) {
            if (!r.ok) throw new Error('Request failed');
            return r.json();
        })
        .then(function () {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: 'Date set to ' + date, timer: 1200, showConfirmButton: false });
            }
            setTimeout(function () { location.reload(); }, 800);
        })
        .catch(function () {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Failed to set date', text: 'Session may have expired. Try logging in again.' });
            }
            closePanel();
        });
    });

    resetBtn.addEventListener('click', function () {
        fetch(DEV_DATE_URLS.clear, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        })
        .then(function (r) {
            if (!r.ok) throw new Error('Request failed');
            return r.json();
        })
        .then(function () {
            simStatusEl.textContent = 'none';
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'success', title: 'Reset to real date', timer: 1200, showConfirmButton: false });
            }
            setTimeout(function () { location.reload(); }, 800);
        })
        .catch(function () {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Failed to reset date', text: 'Session may have expired.' });
            }
            closePanel();
        });
    });

    if (scanBtn) {
        scanBtn.addEventListener('click', function () {
            var original = scanBtn.innerHTML;
            scanBtn.disabled = true;
            scanBtn.textContent = 'Scanning...';
            fetch(DEV_DATE_URLS.scan, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                scanBtn.disabled = false;
                scanBtn.innerHTML = original;
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: data.success ? 'success' : 'error',
                        title: data.success ? 'Scan complete' : 'Scan failed',
                        html: '<pre style="text-align:left;font-size:12px;max-height:220px;overflow:auto;">' +
                              (data.output || 'No output') + '</pre>',
                        confirmButtonColor: '#2563eb',
                    });
                }
            })
            .catch(function () {
                scanBtn.disabled = false;
                scanBtn.innerHTML = original;
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'error', title: 'Scan request failed', text: 'Session may have expired. Try logging in again.' });
                }
            });
        });
    }
})();
</script>
