@extends('layouts.admin')
@section('title', 'Reviews')
@section('heading', 'Reviews')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif

<div class="flex flex-wrap gap-2 mb-4">
    @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'hidden' => 'Hidden'] as $key => $label)
        <a href="{{ route('admin.reviews.index', ['status' => $key]) }}"
           class="px-3 py-1.5 rounded-full text-sm {{ $current === $key ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700 hover:bg-ink-200' }}">
            {{ $label }} <span class="opacity-60">({{ $counts[$key] }})</span>
        </a>
    @endforeach
    <a href="{{ route('admin.reviews.index', ['status' => 'all']) }}" class="px-3 py-1.5 rounded-full text-sm {{ $current === 'all' ? 'bg-ink-800 text-white' : 'bg-ink-100 text-ink-700 hover:bg-ink-200' }}">All</a>
</div>

<div class="space-y-3">
    @forelse($reviews as $review)
        <div class="card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-gold-500">{{ str_repeat('★', $review->rating) }}<span class="text-ink-200">{{ str_repeat('★', 5 - $review->rating) }}</span></span>
                        <span class="font-medium text-sm">{{ $review->author_name }}</span>
                        @if($review->is_verified_buyer)<span class="badge bg-green-100 text-green-700 text-[10px]">✓ Verified</span>@endif
                        <span class="badge {{ ['pending'=>'bg-amber-100 text-amber-700','approved'=>'bg-green-100 text-green-700','hidden'=>'bg-ink-100 text-ink-600'][$review->status] }} text-[10px] capitalize">{{ $review->status }}</span>
                    </div>
                    <p class="text-xs text-ink-700/50 mt-0.5">
                        on <a href="{{ route('admin.products.edit', $review->product) }}" class="text-gold-700 hover:underline">{{ $review->product->name ?? 'deleted product' }}</a>
                        · {{ $review->created_at->format('d M Y, g:i a') }}{{ $review->phone ? ' · '.$review->phone : '' }}
                    </p>
                    @if($review->title)<p class="font-medium mt-2">{{ $review->title }}</p>@endif
                    @if($review->body)<p class="text-sm text-ink-700/80 mt-1">{{ $review->body }}</p>@endif
                    @if($review->photo_urls)
                        <div class="mt-2 flex gap-2 flex-wrap">
                            @foreach($review->photo_urls as $url)<img src="{{ $url }}" class="w-16 h-16 rounded object-cover border border-ink-100" alt="">@endforeach
                        </div>
                    @endif
                </div>
                <div class="flex flex-col gap-1.5 shrink-0">
                    @if($review->status !== 'approved')
                        <form action="{{ route('admin.reviews.status', $review) }}" method="POST">@csrf @method('PATCH')<input type="hidden" name="status" value="approved"><button class="text-xs text-green-700 hover:underline">✓ Approve</button></form>
                    @endif
                    @if($review->status !== 'hidden')
                        <form action="{{ route('admin.reviews.status', $review) }}" method="POST">@csrf @method('PATCH')<input type="hidden" name="status" value="hidden"><button class="text-xs text-ink-600 hover:underline">⊘ Turn off</button></form>
                    @endif
                    <form action="{{ route('admin.reviews.destroy', $review) }}" method="POST" onsubmit="return confirm('Delete this review permanently?')">@csrf @method('DELETE')<button class="text-xs text-red-600 hover:underline">Delete</button></form>
                </div>
            </div>
        </div>
    @empty
        <div class="card p-10 text-center text-ink-700/50">No reviews here.</div>
    @endforelse
</div>

<div class="mt-6">{{ $reviews->links() }}</div>
@endsection
