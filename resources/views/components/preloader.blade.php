{{--
    Full-screen PROJ-TRACK preloader.

    Shows over the viewport while the page loads, then fades out after the
    window `load` event fires and a minimum display time (~600ms) has elapsed.

    Signaling "ready" manually (e.g. when an isLoading state flips to false):
        window.ProjTrackPreloader.hide();

    Files:
        public/css/components/preloader.css
        public/js/components/preloader.js
--}}
<div id="pt-preloader" class="pt-preloader" role="status" aria-label="Loading PROJ-TRACK">
    <div class="pt-preloader__inner">
        <div class="pt-preloader__brand">
            <img class="pt-preloader__mark" src="{{ asset('faviconpmu.png') }}" alt="PROJ-TRACK logo" /> 
            <span class="pt-preloader__name">PROJ-TRACK</span>
        </div>

        <svg class="pt-preloader__chart" viewBox="0 0 180 180" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path class="pt-preloader__crane" d="M20 150 L160 150" />

            <g class="pt-preloader__crane">
                <path d="M118 150 L132 128 M146 150 L132 128" />
                <path d="M132 128 L132 26" />
                <path d="M132 26 L170 32" />
                <path d="M132 50 L170 32" />
                <path d="M170 32 L170 64" />
                <path d="M170 64 L165 70 M170 64 L175 70" />
            </g>

            <g class="pt-preloader__bars">
                <rect class="pt-preloader__bar" x="24" y="116" width="10" height="34" rx="2" />
                <rect class="pt-preloader__bar" x="40" y="100" width="10" height="50" rx="2" />
                <rect class="pt-preloader__bar" x="56" y="84" width="10" height="66" rx="2" />
                <rect class="pt-preloader__bar" x="72" y="68" width="10" height="82" rx="2" />
                <rect class="pt-preloader__bar" x="88" y="52" width="10" height="98" rx="2" />
            </g>
        </svg>

        <div class="pt-preloader__label">BUILDING YOUR DASHBOARD&hellip;</div>
    </div>
</div>
