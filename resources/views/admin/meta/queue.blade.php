@extends('layouts.admin')
@section('title', 'Meta Queue Monitor')
@section('heading', 'Marketing')

@section('content')
<div class="max-w-4xl space-y-6" x-data="metaQueue()">
    @include('admin.meta._nav')

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="card p-4"><div class="text-xs text-ink-700/50">Waiting</div><div class="text-2xl font-semibold mt-1" x-text="q.waiting"></div></div>
        <div class="card p-4"><div class="text-xs text-ink-700/50">Running</div><div class="text-2xl font-semibold mt-1 text-gold-700" x-text="q.running"></div></div>
        <div class="card p-4"><div class="text-xs text-ink-700/50">Completed today</div><div class="text-2xl font-semibold mt-1 text-green-600" x-text="q.completed_today"></div></div>
        <div class="card p-4"><div class="text-xs text-ink-700/50">Failed</div><div class="text-2xl font-semibold mt-1 text-red-600" x-text="q.failed"></div></div>
    </div>

    <div class="card p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="text-sm">
                <p>Queue: <span class="font-medium">{{ $queue['queue_name'] }}</span> <span class="text-ink-700/40">({{ $queue['driver'] }} driver)</span></p>
                <p class="mt-1">State:
                    <span class="badge" :class="q.paused ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700'" x-text="q.paused ? 'Paused' : 'Active'"></span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <template x-if="!q.paused">
                    <form action="{{ route('admin.meta.queue.pause') }}" method="POST">@csrf<button class="btn-outline">Pause queue</button></form>
                </template>
                <template x-if="q.paused">
                    <form action="{{ route('admin.meta.queue.resume') }}" method="POST">@csrf<button class="btn-primary">Resume queue</button></form>
                </template>
                <form action="{{ route('admin.meta.queue.retry') }}" method="POST">@csrf<button class="btn-outline">Retry failed</button></form>
            </div>
        </div>
        <p class="text-xs text-ink-700/50 mt-3">
            Pausing holds new syncs on the queue without failing them — the worker keeps running and jobs resume when you press <em>Resume</em>.
            Live counts refresh every few seconds.
        </p>
    </div>

    <div class="rounded-md bg-ink-50 border border-ink-100 px-4 py-3 text-xs text-ink-700/60">
        <strong>Worker required.</strong> The database queue needs a running worker to process jobs
        (<code>php artisan queue:work</code>, or a cron running <code>queue:work --stop-when-empty</code> on shared hosting).
        If "Waiting" climbs but "Completed" stays flat, the worker isn't running.
    </div>
</div>

<script>
function metaQueue() {
    return {
        q: @json($queue),
        init() { this.poll(); },
        async poll() {
            try {
                const res = await fetch('{{ route('admin.meta.queue.status') }}', { headers: { 'Accept': 'application/json' } });
                this.q = await res.json();
            } catch (e) {}
            setTimeout(() => this.poll(), 4000);
        }
    };
}
</script>
@endsection
