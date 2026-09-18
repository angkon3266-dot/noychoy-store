{{-- Contact messages inbox. Two on a phone, each clipped to two lines, with the
     rest a tap away — they are waiting on a reply, so they never fold shut. --}}
@if($unreadMessages > 0)
<div class="card overflow-hidden border-gold-200 group" x-data="{ all: false }" :data-all="all">
    <div class="flex items-center justify-between gap-3 px-3 sm:px-5 py-2.5 sm:py-4 border-b border-ink-100 bg-gold-50/60">
        <h2 class="font-semibold text-sm sm:text-base flex items-center gap-2">📨 New messages
            <span class="min-w-[20px] h-5 px-1.5 rounded-full bg-red-600 text-white text-xs font-semibold inline-flex items-center justify-center">{{ $unreadMessages }}</span>
        </h2>
        <a href="{{ route('admin.messages') }}" class="shrink-0 text-xs sm:text-sm text-gold-700 hover:underline">All messages →</a>
    </div>
    <div class="divide-y divide-ink-100">
        @foreach($recentMessages as $m)
            <div class="{{ $loop->index >= 2 ? 'hidden md:flex group-data-[all]:flex' : 'flex' }} px-3 sm:px-5 py-2.5 sm:py-3 items-start gap-3">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium">{{ $m->name }}
                        <span class="text-xs font-normal text-ink-700/50">· {{ $m->phone ?: $m->email }} · {{ $m->created_at->diffForHumans() }}</span>
                    </p>
                    @if($m->subject)<p class="text-xs font-medium text-ink-700/70 mt-0.5">{{ $m->subject }}</p>@endif
                    <p class="text-[13px] sm:text-sm text-ink-700/70 mt-0.5 line-clamp-2 md:line-clamp-none">{{ \Illuminate\Support\Str::limit($m->message, 160) }}</p>
                </div>
                <form action="{{ route('admin.messages.read', $m) }}" method="POST" class="shrink-0">
                    @csrf
                    <button class="text-xs text-gold-700 hover:underline whitespace-nowrap">Mark read</button>
                </form>
            </div>
        @endforeach
    </div>
    @if($recentMessages->count() > 2)
        <button type="button" @click="all = !all" class="md:hidden w-full border-t border-ink-100 py-2 text-xs font-medium text-gold-700"
                x-text="all ? 'Show fewer' : 'Show all {{ $recentMessages->count() }}'">Show all {{ $recentMessages->count() }}</button>
    @endif
</div>
@endif
