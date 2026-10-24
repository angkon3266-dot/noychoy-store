@php
    $tracking = app(\App\Services\Meta\MetaTrackingService::class);
    $pixelId = meta_pixel_id();
    // Never on password-reset pages: their URLs carry reset tokens and the
    // customer's email/phone, and the Pixel reports the full page URL.
    $pixelOn = $pixelId && $tracking->pixelEnabled() && ! request()->is('password/*');
    $events = $tracking->enabledEventsMap();

    // Advanced Matching: pass the visitor's details so the Pixel can hash and
    // match them client-side. The fields mirror what MetaTrackingService sends
    // over CAPI — deduplication joins the two copies on event_id, but matching
    // only benefits if both carry the same identifiers.
    //
    // external_id goes out even for guests: it's the two-year visitor_token,
    // pseudonymised, so an anonymous ViewContent still joins to the Purchase
    // that same browser makes later. It is not personal data on its own.
    $am = [];
    if ($pixelOn && $tracking->advancedMatching()) {
        $am = array_filter([
            ...$tracking->customerMatchData(auth('customer')->user()),
            'external_id' => \App\Support\MetaIdentity::externalIds() ?: null,
        ]);
    }

    // The id SendMetaPageView sends this page's server PageView under. Marking
    // the request is what tells it the browser fired one too.
    $pageViewId = null;
    if ($pixelOn && ($events['PageView'] ?? true)) {
        $pageViewId = request()->attributes->get(\App\Http\Middleware\SendMetaPageView::ATTRIBUTE);
        if ($pageViewId) {
            request()->attributes->set(\App\Http\Middleware\SendMetaPageView::PRINTED, true);
        }
    }

    $ip6Lookup = $pixelOn ? config('meta.ip6_lookup_url') : null;
@endphp
@if($pixelOn)
    <script>
        // The fbq stub goes in now so init/PageView/ViewContent queue at once,
        // but the ~240KB of Pixel JavaScript is fetched only after the page has
        // loaded (or on the first touch, whichever comes first). It was the
        // largest download on the home page and competed with the hero image
        // on the phones most visitors use; the queued events flush unchanged.
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
        n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];
        var loaded=false,load=function(){if(loaded)return;loaded=true;t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)};
        var later=function(){setTimeout(load,1200)};
        if(b.readyState==='complete')later();else f.addEventListener('load',later);
        ['scroll','touchstart','pointerdown','keydown'].forEach(function(ev){f.addEventListener(ev,load,{once:true,passive:true})});
        }(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
        window.META_EVENTS = {!! \Illuminate\Support\Js::from($events) !!};
        fbq('init', {!! \Illuminate\Support\Js::from($pixelId) !!}{!! $am ? ', '.\Illuminate\Support\Js::from($am) : '' !!});
        @if($events['PageView'] ?? true)
        fbq('track', 'PageView', {}{!! $pageViewId ? ', '.\Illuminate\Support\Js::from(['eventID' => $pageViewId]) : '' !!});
        @endif
        // Respects the per-event toggles (Meta → Tracking); disabled events no-op.
        window.track = function (event, params, opts) {
            try {
                if (window.META_EVENTS && window.META_EVENTS[event] === false) return;
                params = params || {};
                // A numeric value above zero, or none at all: a string, 0 or NaN
                // here is a data-quality warning against the whole Pixel.
                if ('value' in params) {
                    var v = Math.round(Number(params.value) * 100) / 100;
                    if (isFinite(v) && v > 0) params.value = v; else delete params.value;
                }
                fbq('track', event, params, opts || {});
            } catch (e) {}
        };
    </script>
    @if($ip6Lookup)
    <script>
        // The phone's own IPv6 address, for the server's copy of each event.
        // The Pixel reaches facebook.com over IPv6 wherever the phone has it,
        // while the same phone often reaches this site over the carrier's
        // shared IPv4. Meta flagged the two addresses not matching
        // (2026-10-11), and its advice is to look the IPv6 up in the browser
        // (MetaIdentity::clientIp() explains the rest). Once an hour: the
        // cookie holds the address, or "-" when there is none, and the
        // address also goes to the server once, for a landing page's events
        // that are waiting for it. No cookies, no referrer to the lookup host.
        (function () {
            try {
                if (/(?:^|;\s*){{ \App\Support\MetaIdentity::IP6_COOKIE }}=/.test(document.cookie) || !window.fetch) return;
                var keep = function (v) {
                    document.cookie = '{{ \App\Support\MetaIdentity::IP6_COOKIE }}=' + v + '; Max-Age={{ \App\Support\MetaIdentity::IP6_MINUTES * 60 }}; Path=/; SameSite=Lax'
                        + (location.protocol === 'https:' ? '; Secure' : '');
                };
                var ctl = window.AbortController ? new AbortController() : null;
                if (ctl) setTimeout(function () { ctl.abort(); }, 4000);
                fetch({!! \Illuminate\Support\Js::from($ip6Lookup) !!}, { credentials: 'omit', referrerPolicy: 'no-referrer', cache: 'no-store', signal: ctl ? ctl.signal : undefined })
                    .then(function (r) { return r.ok ? r.text() : ''; })
                    .then(function (ip) {
                        ip = (ip || '').trim().toLowerCase();
                        if (ip.length > 45 || !/^[0-9a-f:]+$/.test(ip) || ip.indexOf(':') < 0) { keep('-'); return; }
                        keep(ip);
                        var token = document.querySelector('meta[name="csrf-token"]');
                        fetch({!! \Illuminate\Support\Js::from(route('meta.ip6', [], false)) !!}, {
                            method: 'POST', credentials: 'same-origin', keepalive: true,
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' },
                            body: JSON.stringify({ ip: ip }),
                        }).catch(function () {});
                    })
                    .catch(function () { keep('-'); });
            } catch (e) {}
        })();
    </script>
    @endif
    <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id={{ $pixelId }}&ev=PageView&noscript=1"/></noscript>
    @stack('meta-events')
@else
    <script>window.track = function () {};</script>
@endif
