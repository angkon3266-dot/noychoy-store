@extends('layouts.shop')
@section('title', 'Forgot password')

@section('content')
<div class="mx-auto max-w-md px-4 py-12">
    <div class="card p-8">
        <h1 class="font-display text-2xl font-semibold text-center">Reset your password</h1>

        @if(session('success'))<div class="rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2 text-sm mt-4">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm mt-4">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm mt-4">{{ $errors->first() }}</div>@endif

        {{-- Reset by email (link) --}}
        <p class="text-sm text-ink-700/60 mt-4 mb-2">Enter your email and we'll send you a reset link.</p>
        <form action="{{ route('customer.password.email') }}" method="POST" class="space-y-3">
            @csrf
            <div><label class="label">Email address</label><input type="email" name="email" value="{{ old('email') }}" class="input" required></div>
            <button class="btn-primary w-full">Email me a reset link</button>
        </form>

        <div class="flex items-center gap-3 my-6 text-xs text-ink-700/40">
            <span class="h-px flex-1 bg-ink-100"></span>OR<span class="h-px flex-1 bg-ink-100"></span>
        </div>

        {{-- Reset by SMS OTP --}}
        <p class="text-sm text-ink-700/60 mb-2">Prefer SMS? We'll text a 6-digit code to your mobile.</p>
        <form action="{{ route('customer.password.send') }}" method="POST" class="space-y-3">
            @csrf
            <div><label class="label">Mobile number</label><input name="phone" value="{{ old('phone') }}" placeholder="01XXXXXXXXX" class="input" required></div>
            <button class="btn-outline w-full">Send code by SMS</button>
        </form>

        <p class="text-center text-sm mt-6"><a href="{{ route('customer.login') }}" class="text-gold-700 hover:underline">Back to login</a></p>
    </div>
</div>
@endsection
