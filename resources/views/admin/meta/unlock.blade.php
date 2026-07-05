@extends('layouts.admin')
@section('title', 'Meta Integration — Security')
@section('heading', 'Meta Integration')

@section('content')
@php $setup = $needsSetup || session('meta_setup'); @endphp

<div class="max-w-md mx-auto mt-8">
    <div class="card p-6">
        <div class="flex items-center gap-3 mb-4">
            <span class="w-10 h-10 rounded-full bg-gold-100 text-gold-700 grid place-items-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
            </span>
            <div>
                <h2 class="font-semibold">{{ $setup ? 'Set a security password' : 'Enter security password' }}</h2>
                <p class="text-xs text-ink-700/60">This module is protected by a second password, separate from your login.</p>
            </div>
        </div>

        @if($errors->any())
            <div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mb-3">{{ $errors->first() }}</div>
        @endif

        @if($lockedUntil && $lockedUntil->isFuture())
            <div class="rounded bg-amber-50 text-amber-800 text-sm px-3 py-2 mb-3">
                Too many failed attempts. Access is locked until {{ $lockedUntil->format('H:i') }} ({{ $lockedUntil->diffForHumans() }}).
            </div>
        @endif

        @if($setup)
            <p class="text-sm text-ink-700/70 mb-3">No security password has been set yet. Create one now — you'll need it every time you open Meta Integration.</p>
            <form action="{{ route('admin.meta.password.update') }}" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label class="label">New security password</label>
                    <input type="password" name="new_password" class="input" required autofocus autocomplete="new-password">
                    <p class="text-xs text-ink-700/50 mt-1">At least 8 characters, with letters and numbers.</p>
                </div>
                <div>
                    <label class="label">Confirm password</label>
                    <input type="password" name="new_password_confirmation" class="input" required autocomplete="new-password">
                </div>
                <button class="btn-primary w-full">Set password &amp; continue</button>
            </form>
        @else
            <form action="{{ route('admin.meta.unlock.submit') }}" method="POST" class="space-y-3">
                @csrf
                <div>
                    <label class="label">Security password</label>
                    <input type="password" name="security_password" class="input" required autofocus autocomplete="off">
                </div>
                <button class="btn-primary w-full">Unlock</button>
            </form>
        @endif
    </div>
    <p class="text-center text-xs text-ink-700/40 mt-3">Access attempts are logged. 5 failed tries locks this module for {{ config('meta.security.lockout_minutes', 15) }} minutes.</p>
</div>
@endsection
