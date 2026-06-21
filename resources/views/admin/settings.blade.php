@extends('layouts.admin')
@section('title', 'Settings')
@section('heading', 'Settings')

@section('content')
<div class="grid lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 card p-6">
        <h2 class="font-semibold mb-4">Store settings</h2>
        <form action="{{ route('admin.settings.update') }}" method="POST" class="space-y-4">
            @csrf
            <div class="grid sm:grid-cols-2 gap-4">
                <div><label class="label">Store name</label><input name="store_name" value="{{ $general['store_name'] }}" class="input"></div>
                <div><label class="label">Store phone</label><input name="store_phone" value="{{ $general['store_phone'] }}" class="input"></div>
            </div>
            <div><label class="label">Store email</label><input name="store_email" type="email" value="{{ $general['store_email'] }}" class="input"></div>
            <p class="text-xs text-ink-700/50">The scrolling announcement bar is managed in <a href="{{ route('admin.appearance') }}" class="text-gold-700 underline">Appearance → Announcement top bar</a>.</p>
            <div class="grid sm:grid-cols-2 gap-4">
                <div><label class="label">Shipping inside Dhaka (৳)</label><input name="shipping_inside" type="number" value="{{ $general['shipping_inside'] }}" class="input"></div>
                <div><label class="label">Shipping outside Dhaka (৳)</label><input name="shipping_outside" type="number" value="{{ $general['shipping_outside'] }}" class="input"></div>
            </div>
            <button class="btn-primary">Save settings</button>
        </form>
    </div>

    <div class="card p-6 h-fit">
        <h2 class="font-semibold mb-4">Integrations</h2>
        <ul class="space-y-3 text-sm">
            <li class="flex items-center justify-between">
                <span>Steadfast Courier</span>
                <span class="badge {{ $integrations['steadfast_configured'] ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }}">{{ $integrations['steadfast_configured'] ? 'Configured' : 'Not set' }}</span>
            </li>
            <li class="flex items-center justify-between">
                <span>KhudeBarta SMS</span>
                <span class="badge {{ $integrations['sms_enabled'] ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }}">{{ $integrations['sms_enabled'] ? 'Enabled' : 'Disabled' }}</span>
            </li>
        </ul>
        <p class="text-xs text-ink-700/50 mt-4">API keys for Steadfast &amp; SMS are configured in the server <code>.env</code> file for security.</p>
    </div>
</div>
@endsection
