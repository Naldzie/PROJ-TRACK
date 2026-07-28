{{--
    components/toast.blade.php
    ─────────────────────────────────────────────────────────────────────────────
    Reusable toast notification component.

    INCLUDE ONCE — near the closing </body> of your shared layout, OR at the top
    of project_detail.blade.php (above all tabs/modals), so it is available to
    every tab and every modal on the page:

        @include('components.toast')

    USAGE — call from any JS on the same page:

        showToast('File uploaded successfully!', 'success');
        showToast('Something went wrong.', 'error');
        showToast('Check your inputs.', 'warning');
        showToast('Processing...', 'info');

    SIGNATURE:
        showToast(message, type = 'success', duration = 3000)

        message  – string displayed in the toast
        type     – 'success' | 'error' | 'warning' | 'info'
        duration – auto-dismiss delay in ms (default 3000)

    MIGRATION:
        • Remove the   <div id="toastContainer">   from baseline-schedule.blade.php
          (it now lives here).
        • Remove the   showToast()   function from baseline-schedule-utils.js
          (or keep it there for backward-compat — the one here will simply win
          because project_detail.blade.php loads this component first and the
          function would be redefined; either way is safe).
        • Recommend: delete it from baseline-schedule-utils.js and add a comment
          pointing here so future devs know where it lives.
--}}

{{-- ── Toast container ──────────────────────────────────────────────────── --}}
<div id="toastContainer"
     style="position: fixed; top: 20px; right: 20px; z-index: 9999;
            display: flex; flex-direction: column; gap: 10px;
            pointer-events: none;">
    {{-- Toasts are injected here by showToast() --}}
</div>

<script>
/**
 * showToast — Global toast notification helper.
 *
 * Defined once here (components/toast.blade.php) and available to every
 * tab, modal, and JS file on the page.
 *
 * @param {string} message   Text to display.
 * @param {string} type      'success' | 'error' | 'warning' | 'info'
 * @param {number} duration  Auto-dismiss in ms. Default: 3000.
 */
window.showToast = function (message, type = 'success', duration = 3000) {
    const container = document.getElementById('toastContainer');
    if (!container) {
        // Graceful fallback if component is somehow missing
        console.warn('[showToast] #toastContainer not found. Message:', message);
        return;
    }

    const palette = {
        success: {
            bg: '#f0fdf4', border: '#86efac', text: '#166534',
            icon: 'bi-check-circle-fill', iconColor: '#16a34a',
        },
        error: {
            bg: '#fef2f2', border: '#fca5a5', text: '#991b1b',
            icon: 'bi-exclamation-circle-fill', iconColor: '#dc2626',
        },
        warning: {
            bg: '#fffbeb', border: '#fde047', text: '#92400e',
            icon: 'bi-exclamation-triangle-fill', iconColor: '#f59e0b',
        },
        info: {
            bg: '#f0f9ff', border: '#bae6fd', text: '#0c4a6e',
            icon: 'bi-info-circle-fill', iconColor: '#0369a1',
        },
    };

    const c  = palette[type] ?? palette.info;
    const id = 'toast-' + Date.now() + '-' + Math.random().toString(36).slice(2, 7);

    // Build the toast element
    const toast = document.createElement('div');
    toast.id = id;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.style.cssText = `
        background: ${c.bg};
        border: 1px solid ${c.border};
        border-radius: 8px;
        padding: 14px 16px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        display: flex;
        align-items: flex-start;
        gap: 12px;
        min-width: 260px;
        max-width: 380px;
        opacity: 0;
        transform: translateX(20px);
        transition: opacity 0.25s ease, transform 0.25s ease;
        pointer-events: all;
    `;

    toast.innerHTML = `
        <i class="bi ${c.icon}"
           style="font-size: 18px; color: ${c.iconColor}; flex-shrink: 0; margin-top: 1px;"></i>
        <span style="flex: 1; font-size: 14px; font-weight: 500;
                     color: ${c.text}; line-height: 1.4;">${message}</span>
        <button type="button"
                aria-label="Close"
                onclick="document.getElementById('${id}')?.remove()"
                style="background: none; border: none; cursor: pointer; padding: 0;
                       color: ${c.text}; opacity: 0.6; font-size: 16px; line-height: 1;
                       flex-shrink: 0; margin-top: 1px;">
            <i class="bi bi-x-lg"></i>
        </button>
    `;

    container.appendChild(toast);

    // Animate in (next tick so the initial styles are painted first)
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            toast.style.opacity  = '1';
            toast.style.transform = 'translateX(0)';
        });
    });

    // Auto-dismiss
    setTimeout(() => {
        toast.style.opacity   = '0';
        toast.style.transform = 'translateX(20px)';
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }, duration);
};
</script>