@php
    $tracking = app(\App\Services\Meta\MetaTrackingService::class);
    $pixelId = meta_pixel_id();
    $pixelOn = $pixelId && $tracking->pixelEnabled();
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
@endphp
@if($pixelOn)
    <script>
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
        n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
        document,'script','https://connect.facebook.net/en_US/fbevents.js');
        window.META_EVENTS = {!! \Illuminate\Support\Js::from($events) !!};
        fbq('init', {!! \Illuminate\Support\Js::from($pixelId) !!}{!! $am ? ', '.\Illuminate\Support\Js::from($am) : '' !!});
        @if($events['PageView'] ?? true)
        fbq('track', 'PageView');
        @endif
        // Respects the per-event toggles (Meta → Tracking); disabled events no-op.
        window.track = function (event, params, opts) {
            try { if (window.META_EVENTS && window.META_EVENTS[event] === false) return; fbq('track', event, params || {}, opts || {}); } catch (e) {}
        };
    </script>
    <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id={{ $pixelId }}&ev=PageView&noscript=1"/></noscript>
    @stack('meta-events')
@else
    <script>window.track = function () {};</script>
@endif
