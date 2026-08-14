{{--
    ⚠️  SUPERSEDED — KEPT ON DISK FOR REFERENCE ONLY (NO LONGER INCLUDED)
    This component was replaced by components/preloader.blade.php (the branded
    PROJ-TRACK preloader: crane SVG + bars, paired with
    public/css/components/preloader.css + public/js/components/preloader.js).
    The three role layouts now include components.preloader instead of this
    partial. Do not restore the include unless reverting the redesign.

    (Original header, for context)
    components/page-loading-overlay.blade.php
    ─────────────────────────────────────────────────────────────────────────────
    Always-on page-loading overlay that masks the render of every page.

    INCLUDE ONCE — as the VERY FIRST element inside <body> of a shared layout
    (before any sidebar/content), so it paints with the first bytes of the
    page and covers the blank/ugly moment while CSS, JS and images finish
    loading. It is fully self-contained (inline <style> + inline <script>,
    no external deps) so it works from first paint even if assets are slow.

    BEHAVIOUR:
        • Shown unconditionally on every page load (id pageLoadingOverlay,
          marker data-auto-hide="load").
        • Hides on window.load after a ~350ms minimum display so even fast,
          cached navigations show a smooth fade instead of a blink.
        • Hard 5s timeout fallback so a hanging third-party CDN can never
          leave the page covered.
        • <noscript> fallback hides it entirely for no-JS clients.

    INTERACTION:
        • z-index 3000 — above the sidebar (100) and page content, below the
          shared toast container (9999), so showToast() stays visible on top.
        • The delete flow keeps its own pre-navigation overlay; this loader
          covers the render gap after the reload, completing the mask.
--}}

<style>
    .page-loading-overlay {
        position: fixed;
        inset: 0;
        z-index: 3000;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        gap: 12px;
        background: rgba(255, 255, 255, 0.45);
        backdrop-filter: blur(3px);
        color: #334155;
        opacity: 1;
        visibility: visible;
        transition: opacity 0.3s ease, visibility 0.3s ease;
    }
    .page-loading-overlay.is-hidden {
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
    }
    .page-loading-overlay-card {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 16px 22px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.92);
        box-shadow: 0 6px 24px rgba(15, 23, 42, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.6);
    }
    .page-loading-overlay-card .page-spinner {
        width: 1.8rem;
        height: 1.8rem;
        border: 3px solid rgba(37, 99, 235, 0.2);
        border-top-color: #2563eb;
        border-radius: 50%;
        animation: page-spinner-rotate 0.7s linear infinite;
        flex-shrink: 0;
    }
    .page-loading-overlay-label {
        margin: 0;
        font-size: 14px;
        font-weight: 600;
        color: #1e293b;
    }
    @keyframes page-spinner-rotate {
        to { transform: rotate(360deg); }
    }
</style>

<div id="pageLoadingOverlay" class="page-loading-overlay" data-auto-hide="load" role="status" aria-live="polite">
    <div class="page-loading-overlay-card">
        <span class="page-spinner" aria-hidden="true"></span>
        <p class="page-loading-overlay-label">Loading&hellip;</p>
    </div>
</div>

<script>
(function () {
    var el = document.getElementById('pageLoadingOverlay');
    if (!el) return;

    var startedAt = Date.now();
    var hidden = false;

    function hide() {
        if (hidden) return;
        hidden = true;
        el.classList.add('is-hidden');
        el.setAttribute('aria-hidden', 'true');
        setTimeout(function () { el.remove(); }, 350);
    }

    // Hide once the whole page (CSS, images, CDNs) has finished loading,
    // but guarantee a ~350ms minimum display so it never blinks.
    window.addEventListener('load', function () {
        var remaining = 350 - (Date.now() - startedAt);
        setTimeout(hide, Math.max(0, remaining));
    });

    // Hard fallback — never leave the page covered if an asset hangs.
    setTimeout(hide, 5000);
})();
</script>

<noscript>
    <style>#pageLoadingOverlay{display:none!important}</style>
</noscript>
