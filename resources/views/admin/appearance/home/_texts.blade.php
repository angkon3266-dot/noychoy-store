{{-- A grid of the section's words. $fields = [home_content key => label]. --}}
<div class="grid sm:grid-cols-2 gap-3">
    @foreach($fields as $key => $label)
        @php $wide = \Illuminate\Support\Str::contains($key, ['subtitle', '_text', 'heading']) ; @endphp
        <div class="{{ $wide ? 'sm:col-span-2' : '' }}">
            <label class="label" for="home-{{ $key }}">{{ $label }}</label>
            @if(\Illuminate\Support\Str::endsWith($key, '_text'))
                <textarea id="home-{{ $key }}" name="home[{{ $key }}]" rows="2" class="input" placeholder="{{ config('home.defaults.'.$key) }}">{{ $home[$key] ?? '' }}</textarea>
            @else
                <input id="home-{{ $key }}" name="home[{{ $key }}]" value="{{ $home[$key] ?? '' }}" class="input" placeholder="{{ config('home.defaults.'.$key) }}">
            @endif
        </div>
    @endforeach
</div>
