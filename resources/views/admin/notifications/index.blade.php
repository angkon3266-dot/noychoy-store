@extends('layouts.admin')
@section('title', 'Notifications')
@section('heading', 'Member notifications')

@php
    $sum = $analytics->summary();
@endphp

@section('content')
@if(session('error'))<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm">{{ session('error') }}</div>@endif

<p class="text-sm text-ink-700/70 mb-5 max-w-3xl">
    Send announcements to your <strong>{{ number_format($memberCount) }}</strong> registered members. They appear in the bell on every page
    and on each member's dashboard. Message everyone or a specific group, automate win-backs, and track what each campaign earns.
</p>

{{-- ── Staff alerts: push to your own phone when an order lands ───────────── --}}
<div class="mb-6">@include('admin.partials.order-alerts')</div>

{{-- ── Campaign performance summary ───────────────────────────────────────── --}}
<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
    <div class="card p-4"><p class="text-xs text-ink-700/50">Campaigns sent</p><p class="text-xl font-bold">{{ number_format($sum['campaigns']) }}</p></div>
    <div class="card p-4"><p class="text-xs text-ink-700/50">Total reach</p><p class="text-xl font-bold">{{ number_format($sum['reach']) }}</p></div>
    <div class="card p-4"><p class="text-xs text-ink-700/50">Clicks</p><p class="text-xl font-bold">{{ number_format($sum['clicks']) }}</p></div>
    <div class="card p-4"><p class="text-xs text-ink-700/50">Orders attributed</p><p class="text-xl font-bold">{{ number_format($sum['conversions']) }}</p></div>
    <div class="card p-4"><p class="text-xs text-ink-700/50">Revenue ({{ \App\Services\CampaignAnalyticsService::ATTRIBUTION_DAYS }}d)</p><p class="text-xl font-bold text-green-700">{{ money($sum['revenue']) }}</p></div>
</div>

<div class="grid lg:grid-cols-3 gap-6">
    {{-- Compose --}}
    <div class="card p-6 h-fit" x-data="{ schedule: false, icon: '🎁' }">
        <h2 class="font-semibold mb-1">Send a web push</h2>
        <p class="text-xs text-ink-700/60 mb-4">Fires a <strong>browser push notification</strong> (pops up even when your site is closed) and shows in the member bell. Send a custom message, an image, offer, or coupon any time.</p>
        <form action="{{ route('admin.notifications.store') }}" method="POST" class="space-y-3">
            @csrf
            <div class="flex gap-2">
                <div class="w-16">
                    <label class="label">Icon</label>
                    <input name="icon" x-model="icon" maxlength="4" class="input text-center text-lg" placeholder="🎁">
                </div>
                <div class="flex-1">
                    <label class="label">Title *</label>
                    <input name="title" class="input" placeholder="New collection just dropped" required>
                </div>
            </div>
            <div><label class="label">Message</label><textarea name="body" rows="3" class="input" placeholder="Tell members what's new…"></textarea></div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="label">Link (optional)</label><input name="url" class="input" placeholder="https://… or /shop"></div>
                <div><label class="label">Button label</label><input name="cta_label" class="input" placeholder="Shop now"></div>
            </div>

            {{-- Attach a real offer (coupon) --}}
            <div>
                <label class="label">Attach an offer (optional)</label>
                <input name="coupon_code" list="pushCoupons" class="input" placeholder="Pick or type a coupon code">
                <datalist id="pushCoupons">
                    @foreach($coupons as $c)<option value="{{ $c->code }}">{{ $c->code }} — {{ $c->free_shipping ? 'Free shipping' : ($c->type === 'percent' ? rtrim(rtrim(number_format($c->value,2),'0'),'.').'% off' : money($c->value).' off') }}</option>@endforeach
                </datalist>
                <p class="text-[11px] text-ink-700/50 mt-1">The code is added to the message and the push links to your shop so members can redeem it.</p>
            </div>

            {{-- Rich push: image + action buttons --}}
            <div><label class="label">Image URL (optional — shows a big picture in the push)</label><input name="image" class="input" placeholder="https://…/banner.jpg"></div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="label text-xs">Action 1 label</label><input name="actions[0][label]" class="input py-1.5 text-sm" placeholder="Shop now"></div>
                <div><label class="label text-xs">Action 1 link</label><input name="actions[0][url]" class="input py-1.5 text-sm" placeholder="/shop"></div>
                <div><label class="label text-xs">Action 2 label</label><input name="actions[1][label]" class="input py-1.5 text-sm" placeholder="View offers"></div>
                <div><label class="label text-xs">Action 2 link</label><input name="actions[1][url]" class="input py-1.5 text-sm" placeholder="/account"></div>
            </div>

            {{-- Audience --}}
            <div x-data="{ audience: 'all' }">
                <label class="label">Send to</label>
                <select name="audience" x-model="audience" class="input">
                    <option value="all">All members ({{ number_format($memberCount) }})</option>
                    <option value="segment">A specific group…</option>
                </select>
                <select name="segment_id" x-show="audience==='segment'" x-cloak :required="audience==='segment'" class="input mt-2">
                    <option value="">Choose a group…</option>
                    @foreach($segments as $seg)<option value="{{ $seg->id }}">{{ $seg->name }}</option>@endforeach
                </select>
                @if($segments->isEmpty())<p class="text-xs text-ink-700/50 mt-1">No groups yet — <a href="{{ route('admin.segments.index') }}" class="text-gold-700 underline">create one</a>.</p>@endif
            </div>

            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="send_sms" value="1"> Also send by SMS <span class="text-xs text-ink-700/50">(uses credits; immediate sends only)</span></label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="schedule"> Schedule for later</label>
            <div x-show="schedule" x-cloak><label class="label">Send at</label><input type="datetime-local" name="scheduled_at" class="input" :required="schedule"></div>
            <button class="btn-primary w-full" x-text="schedule ? 'Schedule notification' : 'Send now'">Send now</button>
        </form>
    </div>

    {{-- Right column --}}
    <div class="lg:col-span-2 space-y-6">
        {{-- Win-back automation --}}
        <div class="card p-6" x-data="{ on: {{ $settings['winback_enabled'] ? 'true' : 'false' }} }">
            <div class="flex items-center justify-between mb-1">
                <h2 class="font-semibold">Win-back automation</h2>
                <span class="badge {{ $settings['winback_enabled'] ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }} text-[10px]">{{ $settings['winback_enabled'] ? 'Active' : 'Off' }}</span>
            </div>
            <p class="text-sm text-ink-700/60 mb-3">Automatically re-engage members who stop ordering. Runs daily via the scheduler.
                <strong>{{ number_format($winbackDue) }}</strong> member(s) are due right now.</p>
            <form action="{{ route('admin.notifications.winback-settings') }}" method="POST" class="space-y-3">
                @csrf
                <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" name="winback_enabled" value="1" x-model="on"> Enable win-back automation</label>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label text-xs">Lapsed after (days)</label><input type="number" name="winback_days" value="{{ $settings['winback_days'] }}" min="7" max="365" class="input py-1.5 text-sm" required></div>
                    <div><label class="label text-xs">Don't re-send within (days)</label><input type="number" name="winback_cooldown_days" value="{{ $settings['winback_cooldown_days'] }}" min="7" max="365" class="input py-1.5 text-sm" required></div>
                    <div><label class="label text-xs">Discount offer %</label><input type="number" step="0.01" name="winback_offer_percent" value="{{ $settings['winback_offer_percent'] }}" min="0" max="90" class="input py-1.5 text-sm" placeholder="0 = no offer"></div>
                    <div><label class="label text-xs">Offer valid for (days)</label><input type="number" name="winback_offer_days" value="{{ $settings['winback_offer_days'] }}" min="1" max="90" class="input py-1.5 text-sm" required></div>
                </div>
                <div><label class="label text-xs">Notification title</label><input name="winback_title" value="{{ $settings['winback_title'] }}" maxlength="120" class="input py-1.5 text-sm" required></div>
                <div><label class="label text-xs">Message</label><textarea name="winback_body" rows="2" maxlength="400" class="input py-1.5 text-sm">{{ $settings['winback_body'] }}</textarea></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="winback_sms" value="1" @checked($settings['winback_sms'])> Also send by SMS <span class="text-xs text-ink-700/50">(uses credits)</span></label>
                <div class="flex gap-2">
                    <button class="btn-outline text-sm">Save win-back settings</button>
                </div>
            </form>
            <form action="{{ route('admin.notifications.run-winback') }}" method="POST" class="mt-2" onsubmit="return confirm('Run the win-back now for all due members?')">
                @csrf
                <button class="text-xs text-gold-700 hover:underline">▸ Run win-back now</button>
            </form>
        </div>

        {{-- Post-delivery review requests. Off by default: it spends SMS
             credit, so switching it on is the owner's call, not a default. --}}
        <div class="card p-6" x-data="{ on: {{ $settings['review_request_enabled'] ? 'true' : 'false' }} }">
            <div class="flex items-center justify-between mb-1">
                <h2 class="font-semibold">Post-delivery review requests</h2>
                <span class="badge {{ $settings['review_request_enabled'] ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }} text-[10px]">{{ $settings['review_request_enabled'] ? 'Active' : 'Off' }}</span>
            </div>
            <p class="text-sm text-ink-700/60 mb-3">Asks the buyer to rate what they bought, a few days after the courier confirms delivery. Sends by SMS, plus email when we have one. Runs daily via the scheduler.
                <strong>{{ number_format($reviewRequestDue) }}</strong> order(s) are due right now.</p>
            <p class="text-xs text-warning-800 bg-warning-50 border border-warning-200 rounded p-2 mb-3">
                The link makes each SMS about two segments, so it costs roughly double a normal one.
                Switch on with a small <em>max orders per run</em> first, and use
                <code>php artisan reviews:request --dry</code> to see exactly who would be asked.
            </p>
            <form action="{{ route('admin.notifications.review-requests') }}" method="POST" class="space-y-3">
                @csrf
                <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" name="review_request_enabled" value="1" x-model="on"> Enable post-delivery review requests</label>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label text-xs">Ask this many days after delivery</label><input type="number" name="review_request_delay_days" value="{{ $settings['review_request_delay_days'] }}" min="1" max="60" class="input py-1.5 text-sm" required></div>
                    <div><label class="label text-xs">Skip orders delivered longer ago than (days)</label><input type="number" name="review_request_max_days" value="{{ $settings['review_request_max_days'] }}" min="2" max="365" class="input py-1.5 text-sm" required></div>
                    <div class="col-span-2"><label class="label text-xs">Max orders per run</label><input type="number" name="review_request_per_run" value="{{ $settings['review_request_per_run'] }}" min="1" max="500" class="input py-1.5 text-sm" required></div>
                </div>
                <div><label class="label text-xs">Email subject <span class="text-ink-700/40">(blank = default)</span></label><input name="review_request_email_subject" value="{{ $settings['review_request_email_subject'] }}" maxlength="150" class="input py-1.5 text-sm"></div>
                <div><label class="label text-xs">Email message <span class="text-ink-700/40">(blank = default)</span></label><textarea name="review_request_email_body" rows="2" maxlength="400" class="input py-1.5 text-sm">{{ $settings['review_request_email_body'] }}</textarea></div>

                {{-- The thank-you discount that travels with the request. --}}
                <div class="border-t border-ink-100 pt-3 mt-1">
                    <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" name="review_offer_enabled" value="1" @checked($settings['review_offer_enabled'])> Include a private thank-you discount</label>
                    <p class="text-xs text-ink-700/60 mt-1">
                        Creates a one-use code for that buyer alone, locked to the phone the order was placed with,
                        and puts it in the same SMS. A forwarded code is refused at checkout. It does
                        <strong>not</strong> stack — if the offers already running save her more, those apply instead
                        and the code stays unspent for another order.
                    </p>
                    <div class="grid grid-cols-2 gap-2 mt-2">
                        <div><label class="label text-xs">Discount %</label><input type="number" step="0.5" name="review_offer_percent" value="{{ rtrim(rtrim(number_format($settings['review_offer_percent'], 2), '0'), '.') }}" min="0" max="90" class="input py-1.5 text-sm" required></div>
                        <div><label class="label text-xs">Valid for (days)</label><input type="number" name="review_offer_days" value="{{ $settings['review_offer_days'] }}" min="1" max="365" class="input py-1.5 text-sm" required></div>
                    </div>
                    <p class="text-xs text-ink-700/50 mt-1">Adds roughly 70 characters to the SMS — check the segment cost note above.</p>
                </div>

                <p class="text-xs text-ink-700/50">The SMS wording lives on the <a href="{{ route('admin.system-config.integrations') }}" class="text-gold-700 hover:underline">Integrations</a> page, under SMS templates. The <code>{offer}</code> placeholder is where the code lands.</p>
                <div class="flex gap-2">
                    <button class="btn-outline text-sm">Save review-request settings</button>
                </div>
            </form>
            <form action="{{ route('admin.notifications.run-review-requests') }}" method="POST" class="mt-2" onsubmit="return confirm('Send review requests now for all due orders?')">
                @csrf
                <button class="text-xs text-gold-700 hover:underline">▸ Run review requests now</button>
            </form>
        </div>

        {{-- Abandoned-cart SMS. The push reminder above only ever reached
             registered members, which on a cash-on-delivery store is almost
             nobody — this is the one that reaches guests. --}}
        <div class="card p-6" x-data="{ on: {{ $settings['abandoned_sms_enabled'] ? 'true' : 'false' }} }">
            <div class="flex items-center justify-between mb-1">
                <h2 class="font-semibold">Abandoned-cart SMS</h2>
                <span class="badge {{ $settings['abandoned_sms_enabled'] ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }} text-[10px]">{{ $settings['abandoned_sms_enabled'] ? 'Active' : 'Off' }}</span>
            </div>
            <p class="text-sm text-ink-700/70 mb-3">Texts anyone who typed their phone at checkout and left without ordering — member or guest. The link puts their cart back exactly as they left it. Runs every 30 minutes.
                <strong>{{ number_format($abandonedSmsDue) }}</strong> cart(s) are waiting right now.</p>
            <form action="{{ route('admin.notifications.abandoned-sms') }}" method="POST" class="space-y-3">
                @csrf
                <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" name="abandoned_sms_enabled" value="1" x-model="on"> Enable abandoned-cart SMS</label>
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label text-xs">Wait this long after they leave (minutes)</label><input type="number" name="abandoned_sms_delay_minutes" value="{{ $settings['abandoned_sms_delay_minutes'] }}" min="15" max="1440" class="input py-1.5 text-sm" required></div>
                    <div><label class="label text-xs">Give up after (hours)</label><input type="number" name="abandoned_sms_max_hours" value="{{ $settings['abandoned_sms_max_hours'] }}" min="2" max="720" class="input py-1.5 text-sm" required></div>
                    <div class="col-span-2"><label class="label text-xs">Max texts per run</label><input type="number" name="abandoned_sms_per_run" value="{{ $settings['abandoned_sms_per_run'] }}" min="1" max="300" class="input py-1.5 text-sm" required></div>
                </div>
                <p class="text-xs text-ink-700/50">The wording lives on the <a href="{{ route('admin.system-config.integrations') }}" class="text-gold-700 hover:underline">Integrations</a> page, under SMS templates.</p>
                <div class="flex gap-2">
                    <button class="btn-outline text-sm">Save abandoned-cart settings</button>
                </div>
            </form>
            <form action="{{ route('admin.notifications.run-abandoned-sms') }}" method="POST" class="mt-2" onsubmit="return confirm('Text every waiting cart now?')">
                @csrf
                <button class="text-xs text-gold-700 hover:underline">▸ Send reminders now</button>
            </form>
        </div>

        {{-- Automation + web push --}}
        <div class="card p-6">
            <h2 class="font-semibold mb-3">Product announcements</h2>
            <form action="{{ route('admin.notifications.settings') }}" method="POST" class="space-y-2">
                @csrf
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="notify_new_arrivals" value="1" @checked($settings['notify_new_arrivals'])> Announce <strong>new arrivals</strong> (batched — one notification for the day's new products)</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="notify_preorders" value="1" @checked($settings['notify_preorders'])> Announce <strong>new pre-orders</strong> instantly (early access for members)</label>

                {{-- Browser web push --}}
                <div class="border-t border-ink-100 pt-2 mt-2 space-y-2">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="webpush_enabled" value="1" @checked($settings['webpush_enabled']) {{ $settings['webpush_keys'] ? '' : 'disabled' }}> Enable <strong>browser web push</strong>
                        @if($settings['webpush_keys'])<span class="badge bg-green-100 text-green-700 text-[10px]">{{ number_format($settings['webpush_subscribers']) }} subscriber(s)</span>@else<span class="badge bg-amber-100 text-amber-700 text-[10px]">generate keys first</span>@endif
                    </label>
                    <div><label class="label text-xs">Contact for push services (email or URL)</label><input name="webpush_subject" value="{{ $settings['webpush_subject'] }}" class="input py-1.5 text-sm" placeholder="mailto:you@store.com"></div>
                    <p class="text-[11px] text-ink-700/50">Members opt in from the notification bell on the storefront. Reaches them even when your site is closed.</p>

                    {{-- Diagnostics --}}
                    @php
                        $diag = $webpushDiag;
                        $rows = [
                            'Web push enabled' => $diag['enabled'],
                            'VAPID keys generated' => $diag['keys_present'],
                            'cURL available' => $diag['curl'],
                            'OpenSSL signing' => $diag['openssl_sign'],
                            'ECDH (openssl_pkey_derive)' => $diag['openssl_pkey_derive'],
                            'hash_hkdf' => $diag['hash_hkdf'],
                            'EC key generation' => $diag['ec_keygen'],
                        ];
                    @endphp
                    <details class="rounded-lg border border-ink-100 p-2 text-xs">
                        <summary class="cursor-pointer font-medium">Diagnostics ({{ number_format($diag['subscribers']) }} subscriber(s))</summary>
                        <ul class="mt-2 space-y-1">
                            @foreach($rows as $label => $ok)
                                <li class="flex items-center gap-2">
                                    <span class="{{ $ok ? 'text-green-700' : 'text-red-600' }}">{{ $ok ? '✓' : '✗' }}</span>
                                    <span class="{{ $ok ? '' : 'text-red-600 font-medium' }}">{{ $label }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @unless($diag['ec_keygen'])
                            <p class="mt-2 text-red-600">EC key generation failed on this server — web push cannot encrypt messages. Ask your host to enable the OpenSSL EC curves for PHP.@if($diag['ec_error']) <span class="text-ink-700/60">({{ $diag['ec_error'] }})</span>@endif</p>
                        @endunless
                        <p class="mt-2 text-ink-700/50">Subject sent to push services: <code>{{ $diag['subject'] }}</code></p>
                        <p class="mt-1 text-ink-700/50">Subscribers come from the storefront bell (log in as a customer, open the site, allow notifications), not the admin.</p>
                    </details>
                </div>
                <button class="btn-outline text-sm mt-2">Save settings</button>
            </form>
            <div class="flex flex-wrap gap-3 mt-3">
                <form action="{{ route('admin.notifications.run-new-arrivals') }}" method="POST">
                    @csrf
                    <button class="text-xs text-gold-700 hover:underline">▸ Send new-arrivals now</button>
                </form>
                <form action="{{ route('admin.notifications.vapid-keys') }}" method="POST" onsubmit="return {{ $settings['webpush_keys'] ? 'confirm(\'Replacing keys will disconnect all current subscribers. Continue?\')' : 'true' }}">
                    @csrf
                    <button class="text-xs text-gold-700 hover:underline">▸ {{ $settings['webpush_keys'] ? 'Regenerate' : 'Generate' }} VAPID keys</button>
                </form>
                @if($settings['webpush_keys'])
                    <form action="{{ route('admin.notifications.test-push') }}" method="POST">
                        @csrf
                        <button class="text-xs text-gold-700 hover:underline">▸ Send test push</button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Automated transactional push templates (order updates) --}}
        <div class="card p-6">
            <h2 class="font-semibold mb-1">Automated order push</h2>
            <p class="text-sm text-ink-700/60 mb-3">Sent automatically to the customer when an order changes status (works even when your site is closed). Edit the wording — use <code class="text-xs bg-ink-100 px-1 rounded">{name}</code>, <code class="text-xs bg-ink-100 px-1 rounded">{order}</code>, <code class="text-xs bg-ink-100 px-1 rounded">{total}</code>, <code class="text-xs bg-ink-100 px-1 rounded">{tracking}</code>.</p>
            <form action="{{ route('admin.notifications.push-templates') }}" method="POST" class="space-y-4">
                @csrf
                @foreach($pushTemplates as $key => $tpl)
                    <div class="rounded-lg border border-ink-100 p-3 space-y-2">
                        <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" name="enabled_{{ $key }}" value="1" @checked($tpl['enabled'])> {{ $tpl['label'] }}</label>
                        <input name="title_{{ $key }}" value="{{ $tpl['title'] }}" maxlength="150" class="input py-1.5 text-sm" placeholder="Title">
                        <textarea name="body_{{ $key }}" rows="2" maxlength="400" class="input py-1.5 text-sm" placeholder="Message">{{ $tpl['body'] }}</textarea>
                    </div>
                @endforeach
                <button class="btn-outline text-sm">Save order templates</button>
            </form>
        </div>

        {{-- Sent / scheduled + per-campaign analytics --}}
        <div class="card overflow-x-auto">
            <table class="w-full min-w-[760px] text-sm">
                <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                    <tr>
                        <th class="px-4 py-3">Campaign</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Reach</th>
                        <th class="px-4 py-3 text-right">Clicks</th>
                        <th class="px-4 py-3 text-right">Orders</th>
                        <th class="px-4 py-3 text-right">Revenue</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @forelse($items as $n)
                        @php
                            $m = $analytics->forNotification($n);
                        @endphp
                        <tr class="hover:bg-ink-50">
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $n->iconOrDefault() }} {{ $n->title }}</div>
                                <div class="text-[11px] text-ink-700/40">{{ $n->audience === 'segment' ? ('→ '.($n->segment->name ?? 'group')) : 'All members' }} · {{ ucfirst(str_replace('_', ' ', $n->type)) }}</div>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if($n->sent_at)<span class="text-green-700">Sent {{ $n->sent_at->diffForHumans() }}</span>
                                @elseif($n->scheduled_at)<span class="text-amber-600">Scheduled {{ $n->scheduled_at->format('d M, H:i') }}</span>
                                @else<span class="text-ink-700/50">Draft</span>@endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $n->sent_at ? number_format($m['recipients']) : '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                @if($n->sent_at){{ number_format($m['clicks']) }}<div class="text-[11px] text-ink-700/40">{{ $m['ctr'] }}%</div>@else—@endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                @if($n->sent_at){{ number_format($m['conversions']) }}<div class="text-[11px] text-ink-700/40">{{ $m['conv_rate'] }}%</div>@else—@endif
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums font-medium text-green-700">{{ $n->sent_at ? money($m['revenue']) : '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <form action="{{ route('admin.notifications.destroy', $n) }}" method="POST" onsubmit="return confirm('Remove this notification?')">@csrf @method('DELETE')<button class="text-red-600 text-xs hover:underline">Remove</button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-ink-700/50">No notifications yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-xs text-ink-700/50">Orders &amp; revenue are attributed to a campaign when a recipient orders within {{ \App\Services\CampaignAnalyticsService::ATTRIBUTION_DAYS }} days of it going out. The daily batches run via the scheduler (cron).</p>
        <div>{{ $items->links() }}</div>
    </div>
</div>
@endsection
