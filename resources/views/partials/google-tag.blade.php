@php
    $google = app(\App\Services\Google\GoogleTagService::class);
    $ids = $google->tagIds();
    // Password-reset URLs carry a token and the customer's email, and the tag
    // reports the full page URL. Same exclusion the Pixel makes.
    $on = $ids !== [] && ! request()->is('password/*');
    $userData = $on ? $google->userData(auth('customer')->user()) : [];
@endphp
@if($on)
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());

        @foreach($ids as $id)
        gtag('config', {!! \Illuminate\Support\Js::from($id) !!}, { allow_enhanced_conversions: true });
        @endforeach

        @if($userData)
        // Enhanced conversions. Hashed server-side, so the page never carries a
        // customer's email or number in the clear.
        gtag('set', 'user_data', {!! \Illuminate\Support\Js::from($userData) !!});
        @endif

        (function () {
            var ANALYTICS = {!! \Illuminate\Support\Js::from($google->analyticsId()) !!};
            var PURCHASE_SEND_TO = {!! \Illuminate\Support\Js::from($google->purchaseSendTo()) !!};
            var VERTICAL = {!! \Illuminate\Support\Js::from($google->businessVertical()) !!};

            // gtag.js is ~90KB and nothing on the page waits for it: commands
            // queue into dataLayer and flush when the script lands. So it is
            // fetched after load or on the first interaction, whichever comes
            // first — the same treatment the Pixel gets, for the same reason.
            var loaded = false;
            var load = function () {
                if (loaded) return;
                loaded = true;
                var s = document.createElement('script');
                s.async = true;
                s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent({!! \Illuminate\Support\Js::from($ids[0]) !!});
                var f = document.getElementsByTagName('script')[0];
                f.parentNode.insertBefore(s, f);
            };
            var later = function () { setTimeout(load, 1200); };
            if (document.readyState === 'complete') later(); else window.addEventListener('load', later);
            ['scroll', 'touchstart', 'pointerdown', 'keydown'].forEach(function (ev) {
                window.addEventListener(ev, load, { once: true, passive: true });
            });

            // Meta's event names, translated. The storefront fires one set of
            // events through window.track(); this maps them rather than asking
            // every call site to fire twice.
            var NAMES = {
                ViewContent: 'view_item',
                AddToCart: 'add_to_cart',
                InitiateCheckout: 'begin_checkout',
                Purchase: 'purchase',
                Search: 'search',
                Lead: 'generate_lead',
            };

            var num = function (v) {
                var n = Math.round(Number(v) * 100) / 100;
                return isFinite(n) && n > 0 ? n : null;
            };

            // item_id MUST be the same string the product feed sends as g:id
            // ("prod-179"). Dynamic remarketing joins the two on that value; if
            // they drift, Google can never show the piece someone looked at.
            var buildItems = function (params, extra) {
                if (extra && extra.items && extra.items.length) {
                    return extra.items.map(function (i) {
                        var item = { item_id: String(i.id), google_business_vertical: VERTICAL };
                        if (i.name) item.item_name = i.name;
                        if (num(i.price)) item.price = num(i.price);
                        if (i.quantity) item.quantity = Number(i.quantity);
                        return item;
                    });
                }

                var ids = params.content_ids || [];
                if (!ids.length) return [];

                // Only a one-line event can attribute the whole value to its
                // single item; a basket total split evenly would be a lie.
                var each = ids.length === 1 ? num(params.value) : null;

                return ids.map(function (id) {
                    var item = { item_id: String(id), google_business_vertical: VERTICAL };
                    if (params.content_name) item.item_name = params.content_name;
                    if (each) item.price = each;
                    return item;
                });
            };

            // A refresh of the confirmation page re-mounts the component and
            // fires Purchase again. Google dedups on transaction_id, but only
            // once it has arrived — this stops the second send leaving at all.
            var alreadySent = function (key) {
                try {
                    if (sessionStorage.getItem(key)) return true;
                    sessionStorage.setItem(key, '1');
                } catch (e) { /* private window: fall through and send */ }
                return false;
            };

            var pageViews = 0;

            var send = function (event, params, opts, extra) {
                params = params || {};
                opts = opts || {};

                // gtag sends the first page_view itself on config. Only SPA
                // navigations after that need reporting.
                if (event === 'PageView') {
                    if (++pageViews > 1) gtag('event', 'page_view');
                    return;
                }

                var name = NAMES[event];
                if (!name) return;

                var payload = { currency: params.currency || 'BDT' };
                var value = num(params.value);
                if (value) payload.value = value;

                if (event === 'Search') {
                    payload = { search_term: params.search_string || '' };
                    if (!payload.search_term) return;
                    gtag('event', 'search', payload);
                    return;
                }

                var items = buildItems(params, extra);
                if (items.length) payload.items = items;

                if (event === 'Purchase') {
                    var orderId = String(opts.eventID || '');
                    if (orderId && alreadySent('g_purchase_' + orderId)) return;
                    if (orderId) payload.transaction_id = orderId;

                    // Two sends, deliberately. The GA4 purchase is reporting and
                    // audience building; the Ads conversion is what bidding and
                    // the conversion column actually read. Sending one event to
                    // both would either double-count in Ads or record nothing.
                    if (ANALYTICS) gtag('event', 'purchase', Object.assign({ send_to: ANALYTICS }, payload));

                    if (PURCHASE_SEND_TO) {
                        gtag('event', 'conversion', {
                            send_to: PURCHASE_SEND_TO,
                            value: payload.value,
                            currency: payload.currency,
                            transaction_id: payload.transaction_id,
                        });
                    }
                    return;
                }

                gtag('event', name, payload);
            };

            // Decorate rather than replace: the Pixel partial defines
            // window.track when it is switched on, and defines nothing when it
            // is off. Either way the storefront keeps calling one function.
            var pixelTrack = window.track;
            window.track = function (event, params, opts, extra) {
                if (pixelTrack) { try { pixelTrack(event, params, opts); } catch (e) {} }
                try { send(event, params, opts, extra); } catch (e) {}
            };
        })();
    </script>
@endif
