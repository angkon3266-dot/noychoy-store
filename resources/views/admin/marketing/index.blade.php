@extends('layouts.admin')
@section('title', 'Marketing Center')
@section('heading', 'Marketing Center')

@section('content')
<div class="max-w-5xl">
    <p class="text-sm text-ink-700/60 mb-5">Connect your store to sales &amp; advertising channels. More integrations are on the way.</p>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($channels as $key => $channel)
            @php $active = $channel['status'] === 'active'; @endphp
            <div class="card p-5 flex flex-col {{ $active ? '' : 'opacity-80' }}">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="font-semibold">{{ $channel['name'] }}</h3>
                    @if($active)
                        <span class="badge {{ $metaStatus==='Connected' ? 'bg-green-100 text-green-700' : ($metaStatus==='Configured' ? 'bg-amber-100 text-amber-700' : 'bg-ink-100 text-ink-700') }}">{{ $metaStatus }}</span>
                    @else
                        <span class="badge bg-ink-100 text-ink-700">Coming soon</span>
                    @endif
                </div>
                <p class="text-sm text-ink-700/60 mt-2 flex-1">{{ $channel['desc'] }}</p>
                <div class="mt-4">
                    @if($active)
                        <a href="{{ route('admin.marketing.channel', $key) }}" class="btn-primary w-full text-center">Manage</a>
                    @else
                        <a href="{{ route('admin.marketing.channel', $key) }}" class="btn-outline w-full text-center">Learn more</a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection
