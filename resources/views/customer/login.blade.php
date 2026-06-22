@extends('layouts.shop')
@section('title', 'Login')

@section('content')
<div class="mx-auto max-w-md px-4 py-12">
    <div class="card p-8">
        <h1 class="font-display text-2xl font-semibold text-center">Welcome back</h1>
        <p class="text-center text-sm text-ink-700/60 mt-1">Log in to your account</p>

        @if($errors->any())
            <div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm mt-4">{{ $errors->first() }}</div>
        @endif

        <div class="mt-6"><x-google-button>Continue with Google</x-google-button></div>

        <div class="flex items-center gap-3 my-5 text-xs text-ink-700/40">
            <span class="h-px flex-1 bg-ink-100"></span>OR<span class="h-px flex-1 bg-ink-100"></span>
        </div>

        <form action="{{ route('customer.login.post') }}" method="POST" class="space-y-4">
            @csrf
            <div><label class="label">Mobile number</label><input name="phone" value="{{ old('phone') }}" class="input" required></div>
            <div><label class="label">Password</label><input type="password" name="password" class="input" required></div>
            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember"> Remember me</label>
                <a href="{{ route('customer.password.forgot') }}" class="text-sm text-gold-700 hover:underline">Forgot password?</a>
            </div>
            <button class="btn-primary w-full">Log in</button>
        </form>
        <p class="text-center text-sm mt-4">No account? <a href="{{ route('customer.register') }}" class="text-gold-700 hover:underline">Register</a></p>
    </div>
</div>
@endsection
