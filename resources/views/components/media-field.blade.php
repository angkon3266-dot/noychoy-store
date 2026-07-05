@props([
    'name',
    'value' => '',
    'folder' => 'uploads',
    'label' => null,
    'help' => null,
])
{{--
    Reusable single-image field: choose from the device OR the media library.
    Backend: call resolve_media($request, '<name>', '<folder>') — it prefers an
    uploaded file (posted as `<name>`) and falls back to the picked/remote URL
    (posted as `<name>_url`).
--}}
<div x-data="mediaField(@js($value), @js($folder))" class="space-y-1">
    @if($label)<label class="label">{{ $label }}</label>@endif
    <div class="flex items-start gap-3">
        <div class="w-24 h-24 rounded-lg border border-ink-100 bg-ink-50 overflow-hidden shrink-0 grid place-items-center">
            <template x-if="preview"><img :src="preview" class="w-full h-full object-cover" alt=""></template>
            <template x-if="!preview"><span class="text-[11px] text-ink-700/40">No image</span></template>
        </div>
        <div class="min-w-0 space-y-2">
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="chooseDevice()" class="btn-outline text-xs py-1.5">Upload from device</button>
                <button type="button" @click="pickLibrary()" class="btn-outline text-xs py-1.5">Media library</button>
                <button type="button" x-show="preview" @click="clear()" class="text-xs text-red-600 hover:underline">Remove</button>
            </div>
            <p x-show="deviceName" x-text="deviceName" class="text-xs text-ink-700/60 truncate"></p>
            @if($help)<p class="text-xs text-ink-700/50">{{ $help }}</p>@endif
        </div>
    </div>
    {{-- Device upload (multipart) — resolve_media reads this first. --}}
    <input type="file" name="{{ $name }}" accept="image/*" class="hidden" x-ref="file" @change="onDevice($event)">
    {{-- Library / remote pick — imported when no file was uploaded. --}}
    <input type="hidden" name="{{ $name }}_url" :value="value">
    {{-- Set when the user explicitly removes an existing image. --}}
    <input type="hidden" name="{{ $name }}_cleared" :value="cleared ? 1 : 0">
</div>
