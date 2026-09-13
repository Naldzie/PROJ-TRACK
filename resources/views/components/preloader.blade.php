{{--
    Full-screen PROJ-TRACK preloader.

    Shows over the viewport while the page loads, then fades out once the
    document is ready (DOMContentLoaded + minimum display time) — it does
    NOT wait for slow images/fonts/CDN (window `load`), so refreshes never
    feel stuck or flash raw HTML first.

    Signaling "ready" manually (e.g. when an isLoading state flips to false):
        window.ProjTrackPreloader.hide();

    Files:
        public/css/components/preloader.css
        public/js/components/preloader.js
--}}
{{-- First-paint cover: :where() keeps specificity at zero, so the full
     preloader.css overrides seamlessly once loaded — but even if external
     CSS is still downloading, first paint is this fully-styled branded
     loader (inner content included), never unstyled HTML or bare text. --}}
<style>
:where(#pt-preloader){position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:#071120;opacity:1;visibility:visible;transition:opacity 400ms ease-in-out,visibility 0s linear 0s;}
:where(#pt-preloader .pt-preloader__inner){display:flex;flex-direction:column;align-items:center;gap:20px;padding:24px;text-align:center;max-width:320px;}
:where(#pt-preloader .pt-preloader__brand){display:flex;align-items:center;gap:12px;}
:where(#pt-preloader .pt-preloader__mark){width:44px;height:44px;border-radius:10px;flex-shrink:0;}
:where(#pt-preloader .pt-preloader__name){font-family:'Space Grotesk','Segoe UI',sans-serif;font-size:17px;font-weight:600;letter-spacing:3.5px;text-transform:uppercase;color:#ffffff;}
:where(#pt-preloader .pt-preloader__chart){width:200px;height:auto;display:block;}
:where(#pt-preloader .pt-preloader__crane){fill:none;stroke:rgba(255,255,255,0.3);stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}
:where(#pt-preloader .pt-preloader__bar){fill:#f2a93b;opacity:0.85;}
:where(#pt-preloader .pt-preloader__label){font-family:'JetBrains Mono',Consolas,monospace;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,0.55);}
:where(#pt-preloader.pt-preloader--hidden){opacity:0;visibility:hidden;pointer-events:none;transition:opacity 400ms ease-in-out,visibility 0s linear 400ms;}
</style>
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
