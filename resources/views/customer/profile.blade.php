@extends('layouts.shop')
@section('title', 'Profile & security')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8 md:py-10">
    <div class="grid md:grid-cols-[220px_1fr] gap-8">
        <aside class="hidden md:block"><div class="card p-3 sticky top-20">@include('customer._nav')</div></aside>

        <div class="min-w-0 max-w-xl">
            @include('customer._flash')
            <h1 class="font-display text-2xl font-semibold mb-6">Profile &amp; security</h1>

            {{-- Profile --}}
            <div class="card p-5 mb-6">
                <h2 class="font-semibold mb-4">Account information</h2>
                <form action="{{ route('account.profile.update') }}" method="POST" class="space-y-4">
                    @csrf @method('PATCH')
                    <div><label class="label">Name</label><input name="name" value="{{ old('name', $customer->name) }}" class="input" required></div>
                    <div><label class="label">Phone</label><input name="phone" value="{{ old('phone', $customer->phone) }}" class="input" required></div>
                    <div><label class="label">Email</label><input type="email" name="email" value="{{ old('email', $customer->email) }}" class="input" placeholder="you@example.com"></div>
                    <div class="flex justify-end"><button class="btn-primary">Save changes</button></div>
                </form>
            </div>

            {{-- Password --}}
            <div class="card p-5">
                <h2 class="font-semibold mb-1">{{ $customer->password ? 'Change password' : 'Set a password' }}</h2>
                @unless($customer->password)
                    <p class="text-xs text-ink-700/60 mb-4">You signed in with Google. Set a password to also log in with your phone/email.</p>
                @endunless
                <form action="{{ route('account.password.update') }}" method="POST" class="space-y-4 mt-3">
                    @csrf @method('PATCH')
                    @if($customer->password)
                        <div><label class="label">Current password</label><input type="password" name="current_password" class="input" required></div>
                    @endif
                    <div><label class="label">New password</label><input type="password" name="password" class="input" required minlength="8"></div>
                    <div><label class="label">Confirm new password</label><input type="password" name="password_confirmation" class="input" required minlength="8"></div>
                    <div class="flex justify-end"><button class="btn-primary">Update password</button></div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
