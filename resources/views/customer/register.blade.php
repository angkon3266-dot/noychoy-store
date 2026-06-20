@extends('layouts.shop')
@section('title', 'Register')

@section('content')
<div class="mx-auto max-w-md px-4 py-12">
    <div class="card p-8">
        <h1 class="font-display text-2xl font-semibold text-center">Create your account</h1>

        @if($errors->any())
            <div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm mt-4">
                <ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif

        <form action="{{ route('customer.register.post') }}" method="POST" class="mt-6 space-y-4">
            @csrf
            <div><label class="label">Full name</label><input name="name" value="{{ old('name') }}" class="input" required></div>
            <div><label class="label">Mobile number</label><input name="phone" value="{{ old('phone') }}" placeholder="01XXXXXXXXX" class="input" required></div>
            <div><label class="label">Email (optional)</label><input type="email" name="email" value="{{ old('email') }}" class="input"></div>
            <div><label class="label">Password</label><input type="password" name="password" class="input" required></div>
            <div><label class="label">Confirm password</label><input type="password" name="password_confirmation" class="input" required></div>
            <button class="btn-primary w-full">Register</button>
        </form>
        <p class="text-center text-sm mt-4">Already have an account? <a href="{{ route('customer.login') }}" class="text-gold-700 hover:underline">Log in</a></p>
    </div>
</div>
@endsection
