{{--
    Staff device opt-in for new-order push alerts. Self-contained: registers the
    same /sw.js the storefront uses, but posts to the admin endpoint so the
    subscription is stored with audience = 'admin' and never receives marketing.
--}}
@php
    $vapid = \App\Models\Setting::get('webpush_public_key');
    $pushReady = (bool) \App\Models\Setting::get('webpush_enabled', false) && filled($vapid);
    $devices = \App\Models\PushSubscription::admins()->latest('last_used_at')->get();
@endphp

<div class="card p-5" x-data="adminOrderAlerts({{ Js::from($vapid) }}, {{ $pushReady ? 'true' : 'false' }})" x-cloak>
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 class="font-semibold">🛍 New order alerts on this device</h2>
            <p class="text-xs text-ink-700/60 mt-1 max-w-xl">
                Get a push notification the moment an order is placed — even when the admin panel is closed.
                Turn it on once per device you want alerted.
            </p>
        </div>
        <template x-if="state === 'granted' && subscribed">
            <span class="badge bg-green-100 text-green-700 whitespace-nowrap">On for this device</span>
        </template>
    </div>

    @unless($pushReady)
        <p class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2.5">
            Web push is off or has no VAPID keys yet. Enable it and generate keys in
            <a href="{{ route('admin.notifications.index') }}" class="underline">Notifications</a> first.
        </p>
    @else
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <button type="button" @click="enable()" x-show="!subscribed" :disabled="busy"
                    class="btn-primary py-1.5 text-sm">
                <span x-text="busy ? 'Turning on…' : 'Turn on alerts here'"></span>
            </button>
            <button type="button" @click="disable()" x-show="subscribed" :disabled="busy"
                    class="btn-outline py-1.5 text-sm">Turn off on this device</button>

            <form action="{{ route('admin.push.test') }}" method="POST" class="inline">
                @csrf
                <button class="btn-outline py-1.5 text-sm">Send test alert</button>
            </form>

            <form action="{{ route('admin.push.toggle') }}" method="POST" class="inline ml-auto">
                @csrf
                <label class="flex items-center gap-2 text-xs text-ink-700/70">
                    <input type="checkbox" name="on" value="1" onchange="submitForm(this.form)"
                           @checked(\App\Models\Setting::get('admin_order_alerts', true)) class="rounded">
                    Alerts enabled store-wide
                </label>
            </form>
        </div>

        <p x-show="state === 'denied'" class="mt-2 text-xs text-red-600">
            Notifications are blocked for this site in your browser settings — allow them, then try again.
        </p>
        <p x-show="needsInstall" class="mt-2 text-xs text-ink-700/70 bg-gold-50 border border-gold-200 rounded-lg p-2.5">
            <strong>On iPhone/iPad:</strong> Apple only delivers push to installed apps. Tap
            <strong>Share → Add to Home Screen</strong>, open the admin from that icon, then come back here and turn alerts on.
        </p>
        <p x-show="!supported && !needsInstall" class="mt-2 text-xs text-ink-700/60">
            This browser doesn’t support web push. Try Chrome on Android/desktop, or install this admin to your iPhone Home Screen.
        </p>
    @endunless

    @if($devices->isNotEmpty())
        <div class="mt-4 pt-3 border-t border-ink-100">
            <p class="text-xs text-ink-700/60 mb-2">{{ $devices->count() }} device(s) receiving alerts:</p>
            <ul class="text-xs text-ink-700/70 space-y-1">
                @foreach($devices as $d)
                    <li class="flex justify-between gap-3">
                        <span>{{ $d->label ?: 'Device' }}{{ $d->user?->name ? ' · '.$d->user->name : '' }}</span>
                        <span class="text-ink-700/45">{{ $d->last_used_at?->diffForHumans() ?? 'never used' }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

@once
    @push('scripts')
    <script>
        window.adminOrderAlerts = function (vapid, ready) {
            const SUBSCRIBE = @json(route('admin.push.subscribe'));
            const UNSUBSCRIBE = @json(route('admin.push.unsubscribe'));
            const CSRF = document.querySelector('meta[name=csrf-token]').content;

            function b64ToUint8(base64) {
                const pad = '='.repeat((4 - (base64.length % 4)) % 4);
                const raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
                return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
            }

            const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
            const isIOS = /iP(hone|ad|od)/.test(navigator.userAgent)
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            const standalone = window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;

            return {
                supported,
                needsInstall: isIOS && !standalone && !supported,
                state: supported ? Notification.permission : 'unsupported',
                subscribed: false,
                busy: false,

                async init() {
                    if (!supported || !ready) return;
                    try {
                        const reg = await navigator.serviceWorker.getRegistration('/');
                        const sub = reg && await reg.pushManager.getSubscription();
                        // Only claim "on" once the server has this endpoint as a
                        // staff device — a shopper subscription doesn't count.
                        this.subscribed = !!sub && this.state === 'granted' && !!localStorage.getItem('adminPush');
                    } catch (e) {}
                },

                async enable() {
                    if (!supported || this.busy) return;
                    this.busy = true;
                    try {
                        const perm = await Notification.requestPermission();
                        this.state = perm;
                        if (perm !== 'granted') return;

                        const reg = await navigator.serviceWorker.register('/sw.js');
                        await navigator.serviceWorker.ready;
                        let sub = await reg.pushManager.getSubscription();
                        if (!sub) {
                            sub = await reg.pushManager.subscribe({
                                userVisibleOnly: true,
                                applicationServerKey: b64ToUint8(vapid),
                            });
                        }
                        const res = await fetch(SUBSCRIBE, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
                            body: JSON.stringify(sub),
                        });
                        if (res.ok) {
                            localStorage.setItem('adminPush', '1');
                            this.subscribed = true;
                        }
                    } catch (e) {
                        console.error(e);
                    } finally {
                        this.busy = false;
                    }
                },

                async disable() {
                    if (this.busy) return;
                    this.busy = true;
                    try {
                        const reg = await navigator.serviceWorker.getRegistration('/');
                        const sub = reg && await reg.pushManager.getSubscription();
                        if (sub) {
                            await fetch(UNSUBSCRIBE, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, Accept: 'application/json' },
                                body: JSON.stringify({ endpoint: sub.endpoint }),
                            });
                        }
                        localStorage.removeItem('adminPush');
                        this.subscribed = false;
                    } catch (e) {
                    } finally {
                        this.busy = false;
                    }
                },
            };
        };
    </script>
    @endpush
@endonce
