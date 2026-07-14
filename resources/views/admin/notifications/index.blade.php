@extends('layouts.admin')
@section('title', 'Notifications')
@section('heading', 'Member notifications')

@php($sum = $analytics->summary())

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm whitespace-pre-line">{{ session('success') }}</div>@endif
@if(session('error'))<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm">{{ session('error') }}</div>@endif

<p class="text-sm text-ink-700/70 mb-5 max-w-3xl">
    Send announcements to your <strong>{{ number_format($memberCount) }}</strong> registered members. They appear in the bell on every page
    and on each member's dashboard. Message everyone or a specific group, automate win-backs, and track what each campaign earns.
</p>

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
        <h2 class="font-semibold mb-4">Send a notification</h2>
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
                        @php($m = $analytics->forNotification($n))
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
