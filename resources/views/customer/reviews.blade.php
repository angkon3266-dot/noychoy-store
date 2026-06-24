@extends('layouts.shop')
@section('title', 'My reviews')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8 md:py-10">
    <div class="grid md:grid-cols-[220px_1fr] gap-8">
        <aside class="hidden md:block"><div class="card p-3 sticky top-20">@include('customer._nav')</div></aside>

        <div class="min-w-0 max-w-2xl">
            @include('customer._flash')
            <h1 class="font-display text-2xl font-semibold mb-6">My reviews</h1>

            @forelse($reviews as $review)
                <div class="card p-4 mb-3">
                    <div class="flex items-center justify-between gap-3">
                        @if($review->product)
                            <a href="{{ route('product.show', $review->product) }}" class="font-medium text-gold-700 hover:underline truncate">{{ $review->product->name }}</a>
                        @else
                            <span class="font-medium text-ink-700/60">Product removed</span>
                        @endif
                        @php $st = $review->status ?? 'approved'; @endphp
                        <span class="badge shrink-0 {{ $st === 'approved' ? 'bg-green-100 text-green-700' : ($st === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }} capitalize">{{ $st === 'pending' ? 'Pending approval' : $st }}</span>
                    </div>
                    <div class="text-gold-500 text-sm mt-1">{{ str_repeat('★', $review->rating) }}<span class="text-ink-200">{{ str_repeat('★', 5 - $review->rating) }}</span></div>
                    @if($review->title)<p class="font-medium text-sm mt-1">{{ $review->title }}</p>@endif
                    @if($review->body)<p class="text-sm text-ink-700/80 mt-0.5">{{ $review->body }}</p>@endif
                    <p class="text-xs text-ink-700/40 mt-1">{{ $review->created_at->format('d M Y') }}</p>
                </div>
            @empty
                <div class="card p-6 text-center text-sm text-ink-700/60">
                    You haven't written any reviews yet. Reviews you leave on products will appear here.
                </div>
            @endforelse

            {{ $reviews->links() }}
        </div>
    </div>
</div>
@endsection
