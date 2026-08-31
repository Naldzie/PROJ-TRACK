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
        show: function (label) {
            if (hideFired) {
                hideFired = false;
            }

            preloader = document.getElementById('pt-preloader');
            if (!preloader) {
                var temp = document.createElement('div');
                temp.innerHTML = '<div id="pt-preloader" class="pt-preloader" role="status" aria-label="Loading PROJ-TRACK"><div class="pt-preloader__inner"><div class="pt-preloader__brand"><img class="pt-preloader__mark" src="/faviconpmu.png" alt="PROJ-TRACK logo" /><span class="pt-preloader__name">PROJ-TRACK</span></div><svg class="pt-preloader__chart" viewBox="0 0 180 180" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path class="pt-preloader__crane" d="M20 150 L160 150" /><line class="pt-preloader__crane" x1="90" y1="150" x2="90" y2="40" /><g class="pt-preloader__crane"><line x1="90" y1="40" x2="140" y2="40" /><line x1="140" y1="40" x2="140" y2="80" /></g><g class="pt-preloader__bars"><rect class="pt-preloader__bar" x="24" y="116" width="10" height="34" rx="2" /><rect class="pt-preloader__bar" x="40" y="100" width="10" height="50" rx="2" /><rect class="pt-preloader__bar" x="56" y="84" width="10" height="66" rx="2" /><rect class="pt-preloader__bar" x="72" y="68" width="10" height="82" rx="2" /><rect class="pt-preloader__bar" x="88" y="52" width="10" height="98" rx="2" /></g></svg><div class="pt-preloader__label">' + (label || 'BUILDING YOUR DASHBOARD&hellip;') + '</div></div></div>';
                document.body.appendChild(temp.firstChild);
                preloader = document.getElementById('pt-preloader');
            }

            var labelEl = preloader.querySelector('.pt-preloader__label');
            if (labelEl) {
                labelEl.innerHTML = label || 'BUILDING YOUR DASHBOARD&hellip;';
            }

            preloader.classList.remove('pt-preloader--hidden');
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
