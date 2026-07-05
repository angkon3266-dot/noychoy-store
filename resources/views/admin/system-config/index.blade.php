@extends('layouts.admin')
@section('title', 'System Configuration')
@section('heading', 'System Configuration')

@section('content')
<div class="max-w-5xl">
    @include('admin.system-config._nav')

    <div class="rounded-md bg-ink-50 border border-ink-100 px-4 py-3 text-xs text-ink-700/60 mb-5">
        Edit platform settings here instead of the <code>.env</code> file. Values are stored <strong>encrypted</strong> in the database and applied as runtime overrides.
        Every change is versioned, audited and backed up automatically. <code>APP_KEY</code> is never editable.
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        {{-- Integrations: SMS (KhudeBarta), Steadfast courier, Google OAuth --}}
        <a href="{{ route('admin.system-config.integrations') }}" class="card p-5 hover:border-gold-300 transition">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold">Integrations</h3>
                <span class="badge bg-ink-100 text-ink-700 text-[10px]">SMS · Courier · Login</span>
            </div>
            <p class="text-sm text-ink-700/60 mt-2">KhudeBarta SMS + templates, Steadfast courier, and Google login credentials.</p>
            <p class="text-xs text-gold-700 mt-3">Edit →</p>
        </a>

        @foreach($sections as $key => $section)
            <a href="{{ route('admin.system-config.edit', $key) }}" class="card p-5 hover:border-gold-300 transition">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold">{{ $section['label'] }}</h3>
                    @if(!empty($section['env_managed']))<span class="badge bg-amber-100 text-amber-700 text-[10px]">Wizard</span>
                    @elseif(!empty($section['test']))<span class="badge bg-ink-100 text-ink-700 text-[10px]">Testable</span>@endif
                </div>
                <p class="text-sm text-ink-700/60 mt-2">{{ $section['description'] }}</p>
                <p class="text-xs text-gold-700 mt-3">Edit →</p>
            </a>
        @endforeach
    </div>
</div>
@endsection
