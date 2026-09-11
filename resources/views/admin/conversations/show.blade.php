@extends('layouts.admin')
@section('title', 'Conversation')
@section('heading', 'Conversation')

@section('content')
<a href="{{ route('admin.conversations.index') }}" class="text-sm text-gold-700 hover:underline">← Back to chat history</a>

<div class="card p-4 mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
    <span>
        <span class="text-ink-700/60">Who</span>
        @if($conversation->customer)
            <a href="{{ route('admin.customers.show', $conversation->customer) }}" class="ml-1 font-medium hover:underline">{{ $conversation->customer->name }}</a>
            <span class="text-ink-700/50">{{ $conversation->customer->phone }}</span>
        @else
            <span class="ml-1 text-ink-700/60">Guest</span>
        @endif
    </span>
    <span><span class="text-ink-700/60">Started</span> <span class="ml-1">{{ $conversation->created_at->format('d M Y, g:i a') }}</span></span>
    <span><span class="text-ink-700/60">Messages</span> <span class="ml-1">{{ $conversation->message_count }}</span></span>
    @if($conversation->first_page)
        <span><span class="text-ink-700/60">Started on</span> <a href="{{ $conversation->first_page }}" class="ml-1 text-gold-700 hover:underline">{{ $conversation->first_page }}</a></span>
    @endif
    @if($conversation->had_failure)
        <span class="badge bg-amber-100 text-amber-800">The assistant failed at least once here</span>
    @endif

    <form action="{{ route('admin.conversations.destroy', $conversation) }}" method="POST" class="ml-auto"
          onsubmit="return confirm('Delete this conversation for good?')">
        @csrf @method('DELETE')
        <button class="text-xs text-red-600 hover:underline">Delete</button>
    </form>
</div>

<div class="card p-5 mt-4 space-y-4">
    @foreach($conversation->messages as $m)
        <div class="flex {{ $m->role === 'user' ? 'justify-end' : 'justify-start' }}">
            <div class="max-w-[80%]">
                <div class="rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap
                            {{ $m->role === 'user'
                                ? 'bg-gold-100 text-ink-900 rounded-br-sm'
                                : ($m->ok ? 'bg-ink-50 text-ink-800 rounded-bl-sm' : 'bg-amber-50 border border-amber-200 text-amber-900 rounded-bl-sm') }}">{{ $m->content }}</div>

                @if($m->products)
                    {{-- What the reply actually put in front of her. --}}
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        @foreach($m->products as $p)
                            <span class="badge bg-white border border-ink-100 text-ink-700">
                                {{ $p['name'] ?? 'Product' }}@isset($p['price_text']) · {{ $p['price_text'] }}@endisset
                            </span>
                        @endforeach
                    </div>
                @endif

                <div class="mt-1 text-[11px] text-ink-700/40 {{ $m->role === 'user' ? 'text-right' : '' }}">
                    {{ $m->role === 'user' ? ($conversation->customer->name ?? 'Customer') : 'Assistant' }}
                    · {{ $m->created_at?->format('d M, g:i a') }}
                    @unless($m->ok)· could not answer @endunless
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection
