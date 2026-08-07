{{--
    components/loading-overlay.blade.php
    ─────────────────────────────────────────────────────────────────────────────
    Reusable full-screen / panel loading overlay.

    INCLUDE ONCE — near the closing </body> of a page (or in a shared layout):

        @include('components.loading-overlay', ['id' => 'pageLoadingOverlay'])

    MODES (via the element class):
        .loading-overlay--full    fixed, covers the whole viewport (default)
        .loading-overlay--inline  absolute, covers the nearest positioned parent
                                  (set the parent to position: relative)

    USAGE — call from any JS on the same page:

        showLoadingOverlay('pageLoadingOverlay', 'Deleting project…');
        hideLoadingOverlay('pageLoadingOverlay');

    The label text can be set/overridden at call time via the optional second
    argument. Toasts render above the overlay (overlay z-index < #toastContainer
    9999), so showToast() stays visible on top.
--}}

@php($overlayId = $id ?? 'loadingOverlay')
@php($label = $label ?? '')
@php($mode = $mode ?? 'full')

<style>
    .loading-overlay {
        display: none;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        gap: 12px;
        background: rgba(255, 255, 255, 0.45);
        backdrop-filter: blur(3px);
        z-index: 2000;
        color: #334155;
    }
    .loading-overlay.active { display: flex; }
    .loading-overlay--full { position: fixed; inset: 0; }
    .loading-overlay--inline { position: absolute; inset: 0; }
    .loading-overlay-card {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 16px 22px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.92);
        box-shadow: 0 6px 24px rgba(15, 23, 42, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.6);
    }
    .loading-overlay-card .spinner-border { color: #2563eb; width: 1.8rem; height: 1.8rem; }
    .loading-overlay-label {
        margin: 0;
        font-size: 14px;
        font-weight: 600;
        color: #1e293b;
    }
</style>

<div id="{{ $overlayId }}" class="loading-overlay loading-overlay--{{ $mode }}" role="status" aria-live="polite">
    <div class="loading-overlay-card">
        <span class="spinner-border" aria-hidden="true"></span>
        @if($label)
            <p class="loading-overlay-label" data-loading-label>{{ $label }}</p>
        @endif
    </div>
</div>

<script>
/**
 * showLoadingOverlay — Global loading-overlay helper.
 *
 * @param {string} id      Element id of the overlay (default 'loadingOverlay').
 * @param {string} label   Optional text to display under the spinner.
 */
window.showLoadingOverlay = function (id, label) {
    const el = document.getElementById(id || 'loadingOverlay');
    if (!el) {
        console.warn('[showLoadingOverlay] overlay not found. id:', id);
        return;
    }
    if (label) {
        const lbl = el.querySelector('[data-loading-label]');
        if (lbl) lbl.textContent = label;
    }
    el.classList.add('active');
};

/**
 * hideLoadingOverlay — Global loading-overlay helper.
 *
 * @param {string} id   Element id of the overlay (default 'loadingOverlay').
 */
window.hideLoadingOverlay = function (id) {
    const el = document.getElementById(id || 'loadingOverlay');
    if (!el) {
        console.warn('[hideLoadingOverlay] overlay not found. id:', id);
        return;
    }
    el.classList.remove('active');
};
</script>
