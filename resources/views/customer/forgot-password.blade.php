@extends('layouts.shop')
@section('title', 'Forgot password')

@section('content')
<div class="mx-auto max-w-md px-4 py-12">
    <div class="card p-8">
        <h1 class="font-display text-2xl font-semibold text-center">Reset your password</h1>
        <p class="text-center text-sm text-ink-700/60 mt-1">We'll text a 6-digit code to your mobile number.</p>

        @if(session('error'))<div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm mt-4">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm mt-4">{{ $errors->first() }}</div>@endif

        <form action="{{ route('customer.password.send') }}" method="POST" class="mt-6 space-y-4">
            @csrf
            <div><label class="label">Mobile number</label><input name="phone" value="{{ old('phone') }}" placeholder="01XXXXXXXXX" class="input" required></div>
            <button class="btn-primary w-full">Send code</button>
        </form>
        <p class="text-center text-sm mt-4"><a href="{{ route('customer.login') }}" class="text-gold-700 hover:underline">Back to login</a></p>
    </div>
</div>
@endsection
