@extends('layouts.shop')
@section('title', 'Enter code')

@section('content')
<div class="mx-auto max-w-md px-4 py-12">
    <div class="card p-8">
        <h1 class="font-display text-2xl font-semibold text-center">Enter your code</h1>
        <p class="text-center text-sm text-ink-700/60 mt-1">Check your SMS for the 6-digit code, then set a new password.</p>

        @if(session('success'))<div class="rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2 text-sm mt-4">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm mt-4">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm mt-4">{{ $errors->first() }}</div>@endif

        <form action="{{ route('customer.password.update') }}" method="POST" class="mt-6 space-y-4">
            @csrf
            <input type="hidden" name="phone" value="{{ old('phone', $phone) }}">
            <div><label class="label">6-digit code</label><input name="otp" inputmode="numeric" maxlength="6" class="input tracking-widest text-center text-lg" placeholder="••••••" required></div>
            <div><label class="label">New password</label><input type="password" name="password" class="input" required minlength="6"></div>
            <div><label class="label">Confirm new password</label><input type="password" name="password_confirmation" class="input" required></div>
            <button class="btn-primary w-full">Reset password</button>
        </form>
        <p class="text-center text-sm mt-4"><a href="{{ route('customer.password.forgot') }}" class="text-gold-700 hover:underline">Didn't get a code? Try again</a></p>
    </div>
</div>
@endsection
