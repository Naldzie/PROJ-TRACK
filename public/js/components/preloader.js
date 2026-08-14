/* ============================================================
   PROJ-TRACK Preloader
   Hides the full-screen overlay after the page has loaded
   (window `load` + minimum display time of 600ms), then fades
   the overlay out over 400ms and unmounts it so it never
   blocks clicks afterwards.
   ============================================================ */

(function () {
    'use strict';

    var MIN_DISPLAY_MS = 600;   // never flash — hold at least this long
    var FADE_MS = 400;          // opacity 1 -> 0 (matches CSS transition)

    var preloader = document.getElementById('pt-preloader');

    if (!preloader) {
        return;
    }

    var startedAt = Date.now();
    var loadDone = false;
    var hideFired = false;

    function unmount() {
        if (preloader && preloader.parentNode) {
            preloader.parentNode.removeChild(preloader);
        }
    }

    function hide() {
        if (hideFired) {
            return;
        }
        hideFired = true;

        preloader.classList.add('pt-preloader--hidden');

        // Wait for the CSS fade (400ms) to complete, then unmount so the
        // overlay can't intercept clicks or cover the page afterwards.
        window.setTimeout(unmount, FADE_MS + 50);
    }

    function maybeHide() {
        if (loadDone) {
            hide();
        }
    }

    // Allow app code to signal "ready" manually, e.g. when an `isLoading`
    // state flips to false. window.ProjTrackPreloader.hide();
    window.ProjTrackPreloader = {
        hide: function () {
            loadDone = true;
            hide();
        },
    };

    window.addEventListener('load', function () {
        loadDone = true;

        // Enforce the minimum display time so the preloader never flashes
        // on fast pages.
        var elapsed = Date.now() - startedAt;
        var remaining = Math.max(0, MIN_DISPLAY_MS - elapsed);
        window.setTimeout(hide, remaining);
    });

    // Safety net: if `load` already fired before this script ran (e.g. the
    // element was injected late), hide immediately after the minimum time.
    if (document.readyState === 'complete') {
        loadDone = true;
        window.setTimeout(hide, MIN_DISPLAY_MS);
    }
})();
