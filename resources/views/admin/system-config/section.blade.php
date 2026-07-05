@extends('layouts.admin')
@section('title', $section['label'].' — System Configuration')
@section('heading', 'System Configuration')

@section('content')
<div class="max-w-3xl">
    @include('admin.system-config._nav')

    <a href="{{ route('admin.system-config.index') }}" class="text-sm text-gold-700 hover:underline">← All sections</a>

    <div class="flex items-center justify-between mt-2 mb-1">
        <h3 class="font-display text-lg font-semibold">{{ $section['label'] }}</h3>
    </div>
    <p class="text-sm text-ink-700/60 mb-4">{{ $section['description'] }}</p>

    @if(!empty($section['env_managed']))
        <div class="rounded-md bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 text-sm mb-4">
            ⚠ <strong>Safe database wizard.</strong> New credentials are tested first and only applied if the connection succeeds. If anything fails, the previous configuration is automatically restored — you can't be locked out.
        </div>
    @endif

    @if($testResult)
        <div class="rounded-md px-4 py-2.5 text-sm mb-4 border {{ $testResult['ok'] ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' }}">
            {{ $testResult['message'] }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.system-config.save', $key) }}" class="card p-5 space-y-4">
        @csrf

        @foreach($fields as $item)
            @php $f = $item['field']; @endphp
            <div>
                <label class="label flex items-center gap-2">
                    {{ $f['label'] }}
                    @if(!empty($f['sensitive']))<span class="text-[10px] text-ink-700/40">🔒 encrypted</span>@endif
                    @if(!empty($f['coming_soon']))<span class="badge bg-ink-100 text-ink-700 text-[10px]">wiring soon</span>@endif
                </label>

                @if($f['type'] === 'bool')
                    <label class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="values[{{ $f['key'] }}]" value="0">
                        <input type="checkbox" name="values[{{ $f['key'] }}]" value="1" @checked(filter_var($item['value'], FILTER_VALIDATE_BOOLEAN))>
                        Enabled
                    </label>
                @elseif($f['type'] === 'select')
                    <select name="values[{{ $f['key'] }}]" class="input">
                        @foreach($f['options'] as $opt)
                            <option value="{{ $opt }}" @selected((string) $item['value'] === $opt)>{{ ucfirst($opt) }}</option>
                        @endforeach
                    </select>
                @elseif($f['type'] === 'textarea')
                    <textarea name="values[{{ $f['key'] }}]" rows="2" class="input">{{ $item['value'] }}</textarea>
                @elseif($f['type'] === 'password')
                    <input type="password" name="values[{{ $f['key'] }}]" class="input" autocomplete="off"
                           placeholder="{{ $item['has_saved'] ? '•••••••• (saved — leave blank to keep)' : 'Not set' }}">
                @else
                    <input type="{{ $f['type'] === 'email' ? 'email' : ($f['type'] === 'number' ? 'number' : 'text') }}"
                           name="values[{{ $f['key'] }}]" value="{{ $item['value'] }}" class="input">
                @endif

                @if($f['env'])<p class="text-[11px] text-ink-700/40 mt-1">Falls back to <code>{{ $f['env'] }}</code> in .env</p>@endif
            </div>
        @endforeach

        {{-- Redirect URI hint for Meta --}}
        @if($key === 'meta')
            <div class="text-xs text-ink-700/50">OAuth redirect URI (whitelist in your Meta App): <code class="break-all">{{ route('admin.meta.oauth.callback') }}</code></div>
        @endif

        <div class="pt-3 border-t border-ink-100 space-y-3">
            <div>
                <label class="label">Change notes <span class="text-ink-700/40">(optional)</span></label>
                <input name="notes" class="input" placeholder="Why are you changing this?">
            </div>
            <div>
                <label class="label">Confirm with your admin password</label>
                <input type="password" name="security_password" class="input" autocomplete="off" placeholder="Required to save">
                @error('security_password')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>

            <div class="flex flex-wrap gap-2">
                <button class="btn-primary">Save changes</button>
                @if(!empty($section['test']) || !empty($section['env_managed']))
                    <button type="submit" formaction="{{ route('admin.system-config.test', $key) }}" formnovalidate class="btn-outline">Test connection</button>
                @endif
            </div>
        </div>
    </form>
</div>
@endsection
