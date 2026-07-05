@extends('layouts.shop')
@section('title', $title)

@section('content')
@php
    $phone = \App\Models\Setting::get('store_phone', config('store.phone'));
    $email = \App\Models\Setting::get('store_email', config('store.email'));
    $address = \App\Models\Setting::get('store_address', config('store.address'));
    $wa = theme('whatsapp_number');
@endphp
<div class="mx-auto max-w-5xl px-4 py-12">
    <h1 class="font-display text-3xl font-semibold mb-2">{{ $title }}</h1>
    <p class="text-ink-700/70 mb-8 max-w-2xl">{{ $intro }}</p>

    <div class="grid md:grid-cols-2 gap-8">
        {{-- Contact details --}}
        <div class="space-y-4">
            <h2 class="font-display text-lg font-semibold">Reach us</h2>
            <ul class="space-y-3 text-sm">
                @if($phone)<li class="flex items-center gap-2">📞 <a href="tel:{{ $phone }}" class="hover:text-gold-700">{{ $phone }}</a></li>@endif
                @if($email)<li class="flex items-center gap-2">✉️ <a href="mailto:{{ $email }}" class="hover:text-gold-700">{{ $email }}</a></li>@endif
                @if($wa)<li class="flex items-center gap-2">💬 <a href="https://wa.me/{{ preg_replace('/\D/', '', $wa) }}" target="_blank" rel="noopener" class="hover:text-gold-700">WhatsApp us</a></li>@endif
                @if($address)<li class="flex items-start gap-2">📍 <span>{{ $address }}</span></li>@endif
            </ul>
        </div>

        {{-- Contact form --}}
        <div class="card p-6">
            @if(session('success'))
                <div class="rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-2.5 text-sm mb-4">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 text-red-700 px-4 py-2.5 text-sm mb-4">{{ $errors->first() }}</div>
            @endif
            <form action="{{ route('page.contact.submit') }}" method="POST" class="space-y-3">
                @csrf
                <div class="grid sm:grid-cols-2 gap-3">
                    <input name="name" value="{{ old('name') }}" placeholder="Your name *" class="input" required>
                    <input name="phone" value="{{ old('phone') }}" placeholder="Phone" class="input">
                </div>
                <input name="email" value="{{ old('email') }}" type="email" placeholder="Email (optional)" class="input">
                <input name="subject" value="{{ old('subject') }}" placeholder="Subject" class="input">
                <textarea name="message" rows="5" placeholder="How can we help? *" class="input" required>{{ old('message') }}</textarea>
                <button class="btn-primary w-full">Send message</button>
            </form>
        </div>
    </div>
</div>
@endsection
