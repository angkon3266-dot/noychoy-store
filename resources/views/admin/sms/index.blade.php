@extends('layouts.admin')
@section('title', 'SMS')
@section('heading', 'SMS')

@section('content')
@unless($enabled)
    <div class="rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm mb-6">
        SMS is currently <strong>disabled</strong>. Set <code>SMS_ENABLED=true</code> and the KhudeBarta credentials in your <code>.env</code> to start sending.
    </div>
@else
    <div class="card p-4 mb-6 text-sm">
        Gateway balance: <strong>{{ $balance['statusInfo']['availablebalance'] ?? ($balance['availablebalance'] ?? 'n/a') }}</strong>
    </div>
@endunless

<div class="grid lg:grid-cols-2 gap-6">
    <div class="card p-6">
        <h2 class="font-semibold mb-3">Send a single SMS</h2>
        <form action="{{ route('admin.sms.send') }}" method="POST" class="space-y-3">
            @csrf
            <div><label class="label">Phone (comma-separate for several)</label><input name="phone" class="input" placeholder="01XXXXXXXXX" required></div>
            <div><label class="label">Message</label><textarea name="message" rows="3" class="input" required></textarea></div>
            <button class="btn-primary">Send</button>
        </form>
    </div>

    <div class="card p-6">
        <h2 class="font-semibold mb-3">Broadcast to all customers</h2>
        <p class="text-sm text-ink-700/60 mb-3">{{ $customerCount }} eligible customers (not blacklisted).</p>
        <form action="{{ route('admin.sms.broadcast') }}" method="POST" class="space-y-3" onsubmit="return confirm('Send to all {{ $customerCount }} customers?')">
            @csrf
            <div><label class="label">Message</label><textarea name="message" rows="3" class="input" required></textarea></div>
            <button class="btn-dark">Send broadcast</button>
        </form>
    </div>
</div>

<div class="card overflow-hidden mt-6">
    <div class="px-5 py-4 border-b border-ink-100"><h2 class="font-semibold">Recent SMS log</h2></div>
    <table class="w-full text-sm">
        <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
            <tr><th class="px-4 py-3">Phone</th><th class="px-4 py-3">Message</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">When</th></tr>
        </thead>
        <tbody class="divide-y divide-ink-100">
            @forelse($logs as $log)
                <tr>
                    <td class="px-4 py-3 whitespace-nowrap">{{ \Illuminate\Support\Str::limit($log->phone, 30) }}</td>
                    <td class="px-4 py-3 text-ink-700/70">{{ \Illuminate\Support\Str::limit($log->message, 60) }}</td>
                    <td class="px-4 py-3"><span class="badge {{ $log->status=='ACCEPTD' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ $log->status }}</span></td>
                    <td class="px-4 py-3 text-ink-700/60 whitespace-nowrap">{{ $log->created_at->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-10 text-center text-ink-700/50">No SMS sent yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-6">{{ $logs->links() }}</div>
@endsection
