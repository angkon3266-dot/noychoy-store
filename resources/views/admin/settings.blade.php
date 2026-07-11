@extends('layouts.admin')
@section('title', 'Settings')
@section('heading', 'Settings')

@section('content')
@if(session('success'))<div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm">{{ session('success') }}</div>@endif
@if(session('error'))<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm">{{ session('error') }}</div>@endif
@if($errors->any())<div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm">{{ $errors->first() }}</div>@endif

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

{{-- Fraud Checker (courier portal logins) — powers the order fraud report --}}
<div class="card p-6 mt-6 max-w-3xl">
    <div class="flex items-center justify-between mb-1">
        <h2 class="font-semibold">Fraud Checker (Courier logins)</h2>
        <span class="badge {{ $fraudConfigured ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }}">{{ $fraudConfigured ? 'Configured' : 'Not set' }}</span>
    </div>
    <p class="text-xs text-ink-700/60 mb-4">The fraud report on each order logs into your courier <strong>merchant portals</strong> to fetch a customer's delivery/cancellation history. Enter your portal <strong>login</strong> credentials (not API keys). Passwords are stored encrypted. Set at least one courier; missing ones are skipped.</p>

    <form action="{{ route('admin.settings.fraud-checker') }}" method="POST" class="space-y-4">
        @csrf
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="label">Steadfast — user / email</label><input name="steadfast_user" value="{{ $fraudChecker['steadfast_user'] }}" class="input" autocomplete="off"></div>
            <div><label class="label">Steadfast — password</label><input name="steadfast_password" type="password" autocomplete="new-password" class="input" placeholder="{{ $fraudChecker['steadfast_has_pw'] ? '•••••••• (saved — blank to keep)' : 'Portal password' }}"></div>
            <div><label class="label">Pathao — user / email</label><input name="pathao_user" value="{{ $fraudChecker['pathao_user'] }}" class="input" autocomplete="off"></div>
            <div><label class="label">Pathao — password</label><input name="pathao_password" type="password" autocomplete="new-password" class="input" placeholder="{{ $fraudChecker['pathao_has_pw'] ? '•••••••• (saved — blank to keep)' : 'Portal password' }}"></div>
            <div><label class="label">RedX — login phone</label><input name="redx_phone" value="{{ $fraudChecker['redx_phone'] }}" class="input" autocomplete="off" placeholder="01XXXXXXXXX"></div>
            <div><label class="label">RedX — password</label><input name="redx_password" type="password" autocomplete="new-password" class="input" placeholder="{{ $fraudChecker['redx_has_pw'] ? '•••••••• (saved — blank to keep)' : 'Portal password' }}"></div>
        </div>
        <button class="btn-primary">Save fraud checker credentials</button>
    </form>
</div>

{{-- Email (SMTP) — sends order confirmations & invoices --}}
<div class="card p-6 mt-6 max-w-3xl">
    <div class="flex items-center justify-between mb-1">
        <h2 class="font-semibold">Email (SMTP)</h2>
        <span class="badge {{ $mail['enabled'] && $mail['host'] ? 'bg-green-100 text-green-700' : 'bg-ink-100 text-ink-700' }}">{{ $mail['enabled'] && $mail['host'] ? 'Active' : 'Off' }}</span>
    </div>
    <p class="text-xs text-ink-700/60 mb-4">Used to email customers their <strong>order confirmation &amp; invoice</strong> when they provide an email. Create a mailbox in cPanel (e.g. <code>orders@meridianeclat.shop</code>) and enter its SMTP details below.</p>

    <form action="{{ route('admin.settings.mail') }}" method="POST" class="space-y-4">
        @csrf
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="mail_enabled" value="1" @checked($mail['enabled'])> Enable sending email via SMTP</label>
        <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="label">SMTP host</label><input name="mail_host" value="{{ $mail['host'] }}" class="input" placeholder="mail.meridianeclat.shop"></div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="label">Port</label><input name="mail_port" type="number" value="{{ $mail['port'] }}" class="input" placeholder="465"></div>
                <div><label class="label">Encryption</label>
                    <select name="mail_encryption" class="input">
                        <option value="ssl" @selected($mail['encryption']=='ssl')>SSL (465)</option>
                        <option value="tls" @selected($mail['encryption']=='tls')>TLS (587)</option>
                        <option value="none" @selected($mail['encryption']=='none')>None</option>
                    </select>
                </div>
            </div>
            <div><label class="label">Username (full email)</label><input name="mail_username" value="{{ $mail['username'] }}" class="input" placeholder="orders@meridianeclat.shop"></div>
            <div><label class="label">Password</label><input name="mail_password" type="password" class="input" placeholder="{{ $mail['has_password'] ? '•••••••• (unchanged)' : 'mailbox password' }}"><p class="text-xs text-ink-700/40 mt-1">Leave blank to keep the saved password.</p></div>
            <div><label class="label">From address</label><input name="mail_from_address" type="email" value="{{ $mail['from_address'] }}" class="input" placeholder="orders@meridianeclat.shop"></div>
            <div><label class="label">From name</label><input name="mail_from_name" value="{{ $mail['from_name'] }}" class="input" placeholder="Meridian Éclat"></div>
        </div>
        <button class="btn-primary">Save email settings</button>
    </form>

    <form action="{{ route('admin.settings.mail.test') }}" method="POST" class="mt-4 flex flex-wrap items-end gap-2 border-t border-ink-100 pt-4">
        @csrf
        <div class="flex-1 min-w-[220px]"><label class="label">Send a test email to</label><input name="test_email" type="email" class="input" placeholder="you@gmail.com" required></div>
        <button class="btn-outline">Send test</button>
    </form>
</div>
@endsection
