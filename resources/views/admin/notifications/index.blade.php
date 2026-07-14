@extends('layouts.admin')
@section('title', 'Notifications')
@section('heading', 'Member notifications')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif

<p class="text-sm text-ink-700/70 mb-5 max-w-3xl">
    Send announcements to your <strong>{{ number_format($memberCount) }}</strong> registered members. They appear in the bell on every page
    and on each member's dashboard. Targeted/segmented sends and browser (web) push delivery are coming next.
</p>

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

    {{-- History --}}
    <div class="lg:col-span-2 space-y-6">
        {{-- Automation + web push --}}
        <div class="card p-6">
            <h2 class="font-semibold mb-3">Automation</h2>
            <form action="{{ route('admin.notifications.settings') }}" method="POST" class="space-y-2">
                @csrf
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="notify_new_arrivals" value="1" @checked($settings['notify_new_arrivals'])> Announce <strong>new arrivals</strong> (batched — one notification for the day's new products)</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="notify_preorders" value="1" @checked($settings['notify_preorders'])> Announce <strong>new pre-orders</strong> instantly (early access for members)</label>
                <label class="flex items-center gap-2 text-sm border-t border-ink-100 pt-2 mt-2"><input type="checkbox" name="webpush_enabled" value="1" @checked($settings['webpush_enabled'])> Enable <strong>browser web push</strong> <span class="badge bg-ink-100 text-ink-700 text-[10px]">delivery in Phase 4</span></label>
                <button class="btn-outline text-sm mt-2">Save settings</button>
            </form>
            <form action="{{ route('admin.notifications.run-new-arrivals') }}" method="POST" class="mt-3">
                @csrf
                <button class="text-xs text-gold-700 hover:underline">▸ Send the new-arrivals announcement now</button>
            </form>
            <p class="text-xs text-ink-700/50 mt-2">The daily batch runs via the scheduler (cron). Web push will let notifications reach members even when your site is closed — I'll wire delivery, opt-in and rich options in the next phase.</p>
        </div>

        {{-- Sent / scheduled --}}
        <div class="card overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                    <tr><th class="px-4 py-3">Notification</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Status</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-ink-100">
                    @forelse($items as $n)
                        <tr class="hover:bg-ink-50">
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $n->iconOrDefault() }} {{ $n->title }}</div>
                                @if($n->body)<div class="text-xs text-ink-700/50 truncate max-w-[320px]">{{ $n->body }}</div>@endif
                            </td>
                            <td class="px-4 py-3 text-ink-700/60">{{ ucfirst(str_replace('_', ' ', $n->type)) }}<div class="text-[11px] text-ink-700/40">{{ $n->audience === 'segment' ? ('→ '.($n->segment->name ?? 'group')) : 'All members' }}</div></td>
                            <td class="px-4 py-3 text-xs">
                                @if($n->sent_at)<span class="text-green-700">Sent {{ $n->sent_at->diffForHumans() }}</span>
                                @elseif($n->scheduled_at)<span class="text-amber-600">Scheduled {{ $n->scheduled_at->format('d M, H:i') }}</span>
                                @else<span class="text-ink-700/50">Draft</span>@endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <form action="{{ route('admin.notifications.destroy', $n) }}" method="POST" onsubmit="return confirm('Remove this notification?')">@csrf @method('DELETE')<button class="text-red-600 text-xs hover:underline">Remove</button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-ink-700/50">No notifications yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $items->links() }}</div>
    </div>
</div>
@endsection
