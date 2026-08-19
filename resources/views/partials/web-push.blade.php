    @if(app(\App\Services\WebPushService::class)->ready())
        <script>
            (function () {
                const VAPID = @json(app(\App\Services\WebPushService::class)->publicKey());
                const SUBSCRIBE = @json(route('push.subscribe'));
                const WATCH = @json(route('push.watch-stock'));
                const CSRF = @json(csrf_token());
                // Auto-prompt once per browser: immediately for anyone, and right
                // after a member registers (server flashes this flag).
                const AUTOPROMPT = {{ (auth('customer')->check() ? session('prompt_push') : true) ? 'true' : 'false' }};

                function urlB64ToUint8(base64) {
                    const pad = '='.repeat((4 - (base64.length % 4)) % 4);
                    const b64 = (base64 + pad).replace(/-/g, '+').replace(/_/g, '/');
                    const raw = atob(b64);
                    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
                }

                // True if an existing subscription was created with the VAPID key
                // we're currently signing with. When the key is regenerated, the
                // old subscription would fail server-side with VapidPkHashMismatch.
                function keyMatches(sub) {
                    try {
                        const want = urlB64ToUint8(VAPID);
                        const have = new Uint8Array(sub.options && sub.options.applicationServerKey ? sub.options.applicationServerKey : []);
                        if (have.length !== want.length) return false;
                        for (let i = 0; i < want.length; i++) if (have[i] !== want[i]) return false;
                        return true;
                    } catch (e) {
                        return true; // can't tell → don't churn a working sub
                    }
                }

                async function ensure() {
                    const reg = await navigator.serviceWorker.register('/sw.js');
                    await navigator.serviceWorker.ready;
                    let sub = await reg.pushManager.getSubscription();
                    // Self-heal a stale subscription left over from a previous VAPID key.
                    if (sub && !keyMatches(sub)) {
                        try { await sub.unsubscribe(); } catch (e) {}
                        sub = null;
                    }
                    if (!sub) {
                        sub = await reg.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlB64ToUint8(VAPID),
                        });
                    }
                    await fetch(SUBSCRIBE, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
                        body: JSON.stringify(sub),
                    });
                    return sub;
                }

                const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

                // iOS Safari only exposes the Push API inside a Home-Screen-installed
                // web app. Detect that case so the UI can explain the install step
                // instead of silently showing nothing.
                const isIOS = /iP(hone|ad|od)/.test(navigator.userAgent)
                    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
                const standalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true;
                const needsInstall = isIOS && !standalone && !supported;

                // Ensure permission + a live subscription; returns the subscription or null.
                window.wpEnsure = async function () {
                    if (!supported) return null;
                    if (Notification.permission === 'denied') return null;
                    if (Notification.permission === 'default') {
                        const perm = await Notification.requestPermission();
                        if (perm !== 'granted') return null;
                    }
                    return ensure();
                };

                // "Notify me when back in stock" button on out-of-stock products.
                window.stockNotify = function (productId) {
                    return {
                        busy: false, state: 'idle',
                        async notifyMe() {
                            if (this.busy) return;
                            this.busy = true;
                            try {
                                const sub = await window.wpEnsure();
                                if (!sub) { this.state = 'denied'; return; }
                                const res = await fetch(WATCH, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
                                    body: JSON.stringify({ product_id: productId, endpoint: sub.endpoint }),
                                });
                                this.state = res.ok ? 'done' : 'idle';
                            } catch (e) { this.state = 'idle'; }
                            finally { this.busy = false; }
                        },
                    };
                };

                // Alpine component for the member "Turn on" button in the bell.
                window.webPushOptIn = function () {
                    return {
                        supported,
                        needsInstall,
                        state: supported ? Notification.permission : 'unsupported',
                        busy: false,
                        init() {
                            if (supported && this.state === 'granted') ensure().catch(() => {});
                        },
                        async enable() {
                            if (!supported || this.busy) return;
                            this.busy = true;
                            try {
                                const perm = await Notification.requestPermission();
                                this.state = perm;
                                if (perm === 'granted') await ensure();
                            } catch (e) { console.warn('Push opt-in failed', e); }
                            finally { this.busy = false; }
                        },
                    };
                };

                // Immediate opt-in prompt (once per browser).
                if (supported && AUTOPROMPT && Notification.permission === 'default' && !localStorage.getItem('wp_asked')) {
                    localStorage.setItem('wp_asked', '1');
                    // A tiny delay so it doesn't fire before first paint.
                    setTimeout(() => {
                        Notification.requestPermission().then((perm) => {
                            if (perm === 'granted') ensure().catch(() => {});
                        });
                    }, 1500);
                } else if (supported && Notification.permission === 'granted') {
                    // Keep the subscription (and its customer link) fresh on every load.
                    ensure().catch(() => {});
                }
            })();
        </script>
    @endif
