@extends('layouts.admin')
@section('title', 'Contact Messages')
@section('heading', 'Contact Messages')

@section('content')
<div class="max-w-4xl space-y-4">
    <a href="{{ route('admin.pages') }}" class="text-sm text-gold-700 hover:underline">← Pages</a>

    <div class="space-y-3">
        @forelse($messages as $m)
            <div class="card p-4 {{ $m->is_read ? '' : 'border-gold-300' }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <div class="font-medium">{{ $m->name }}
                            @unless($m->is_read)<span class="badge bg-gold-100 text-gold-700 ml-1">New</span>@endunless
                        </div>
                        <div class="text-xs text-ink-700/50">
                            {{ $m->created_at->format('d M Y H:i') }}
                            @if($m->phone) · 📞 {{ $m->phone }}@endif
                            @if($m->email) · ✉️ {{ $m->email }}@endif
                        </div>
                    </div>
                    <div class="flex gap-1">
                        <form action="{{ route('admin.messages.read', $m) }}" method="POST">@csrf<button class="text-xs text-ink-700/60 hover:text-gold-700 px-2 py-1">{{ $m->is_read ? 'Mark unread' : 'Mark read' }}</button></form>
                        <form action="{{ route('admin.messages.destroy', $m) }}" method="POST" onsubmit="return confirm('Delete this message?')">@csrf @method('DELETE')<button class="text-xs text-red-600 hover:underline px-2 py-1">Delete</button></form>
                    </div>
                </div>
                @if($m->subject)<div class="text-sm font-medium mt-2">{{ $m->subject }}</div>@endif
                <p class="text-sm text-ink-700/80 mt-1 whitespace-pre-wrap">{{ $m->message }}</p>
            </div>
        @empty
            <div class="card p-10 text-center text-ink-700/50">No messages yet.</div>
        @endforelse
    </div>

    <div>{{ $messages->links() }}</div>
</div>
@endsection
