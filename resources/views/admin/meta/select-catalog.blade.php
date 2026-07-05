@extends('layouts.admin')
@section('title', 'Select Commerce Catalog')
@section('heading', 'Choose your Commerce Catalog')

@section('content')
<div class="max-w-2xl space-y-4">
    <p class="text-sm text-ink-700/70">Your Facebook account has access to several catalogs. Pick the one to sync products into.</p>

    <div class="card divide-y divide-ink-100">
        @foreach($catalogs as $cat)
            <form action="{{ route('admin.meta.oauth.select-catalog') }}" method="POST" class="flex items-center justify-between gap-4 px-5 py-3">
                @csrf
                <input type="hidden" name="business_id" value="{{ $cat['business_id'] }}">
                <input type="hidden" name="business_name" value="{{ $cat['business_name'] }}">
                <input type="hidden" name="catalog_id" value="{{ $cat['catalog_id'] }}">
                <input type="hidden" name="catalog_name" value="{{ $cat['catalog_name'] }}">
                <div>
                    <div class="font-medium">{{ $cat['catalog_name'] ?? 'Catalog '.$cat['catalog_id'] }}</div>
                    <div class="text-xs text-ink-700/50">{{ $cat['business_name'] }} · Catalog ID {{ $cat['catalog_id'] }}</div>
                </div>
                <button class="btn-primary text-sm py-2">Use this catalog</button>
            </form>
        @endforeach
    </div>

    <a href="{{ route('admin.meta.index') }}" class="btn-outline">Cancel</a>
</div>
@endsection
