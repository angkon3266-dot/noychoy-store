@extends('layouts.shop')
@section('title', 'Choose a new password')

@section('content')
<div class="mx-auto max-w-md px-4 py-12">
    <div class="card p-8">
        <h1 class="font-display text-2xl font-semibold text-center">Choose a new password</h1>

        @if(session('error'))<div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm mt-4">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm mt-4">{{ $errors->first() }}</div>@endif

        <form action="{{ route('customer.password.email.update') }}" method="POST" class="mt-6 space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div><label class="label">Email</label><input type="email" name="email" value="{{ $email }}" class="input" readonly></div>
            <div><label class="label">New password</label><input type="password" name="password" class="input" required></div>
            <div><label class="label">Confirm password</label><input type="password" name="password_confirmation" class="input" required></div>
            <button class="btn-primary w-full">Update password</button>
        </form>
    </div>
</div>
@endsection
