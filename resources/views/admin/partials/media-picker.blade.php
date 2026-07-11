{{-- Global media-picker modal — one per admin page, driven by $store.mediaLib. --}}
<script>window.MEDIA = {
    picker: @json(route('admin.media.picker')),
    upload: @json(route('admin.media.upload')),
    csrf: @json(csrf_token()),
};</script>

<div x-cloak x-show="$store.mediaLib.open" @keydown.escape.window="$store.mediaLib.close()"
     class="fixed inset-0 z-[70] flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/50" @click="$store.mediaLib.close()"></div>

    <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-3xl max-h-[85vh] flex flex-col">
        <div class="flex items-center justify-between px-5 py-3 border-b border-ink-100">
            <h3 class="font-semibold">Select media</h3>
            <button type="button" @click="$store.mediaLib.close()" class="text-ink-700/50 hover:text-ink-900 text-xl leading-none">&times;</button>
        </div>

        {{-- Tabs --}}
        <div class="flex gap-1 px-5 pt-3">
            <button type="button" @click="$store.mediaLib.tab='library'"
                    :class="$store.mediaLib.tab==='library' ? 'bg-ink-900 text-white' : 'text-ink-700 hover:bg-ink-50'"
                    class="px-3 py-1.5 rounded-lg text-sm">Media library</button>
            <button type="button" @click="$store.mediaLib.tab='device'"
                    :class="$store.mediaLib.tab==='device' ? 'bg-ink-900 text-white' : 'text-ink-700 hover:bg-ink-50'"
                    class="px-3 py-1.5 rounded-lg text-sm">Upload from device</button>
        </div>

        {{-- Library tab --}}
        <div x-show="$store.mediaLib.tab==='library'" class="flex-1 min-h-0 flex flex-col p-5 pt-3">
            <div class="flex gap-2 mb-3">
                <input x-model="$store.mediaLib.q" @input.debounce.300ms="$store.mediaLib.load()"
                       placeholder="Search by product or file name…" class="input text-sm flex-1">
                <select x-model="$store.mediaLib.browseFolder" @change="$store.mediaLib.load()" class="input text-sm w-40">
                    <option value="">All folders</option>
                    <template x-for="f in $store.mediaLib.folders" :key="f"><option :value="f" x-text="f"></option></template>
                </select>
            </div>
            <div class="flex-1 overflow-y-auto">
                <p x-show="$store.mediaLib.loading" class="text-sm text-ink-700/50 py-6 text-center">Loading…</p>
                <p x-show="!$store.mediaLib.loading && $store.mediaLib.items.length===0" class="text-sm text-ink-700/50 py-6 text-center">No images found.</p>
                <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 gap-2">
                    <template x-for="m in $store.mediaLib.items" :key="m.path">
                        <button type="button" @click="$store.mediaLib.pick(m.url)"
                                :class="$store.mediaLib.isSelected(m.url) ? 'border-gold-500 ring-2 ring-gold-400' : 'border-ink-100 hover:border-gold-400 hover:ring-2 hover:ring-gold-200'"
                                class="group relative aspect-square rounded-lg overflow-hidden border">
                            <img :src="m.url" loading="lazy" class="w-full h-full object-cover" alt="">
                            {{-- Selection checkmark (multi mode) --}}
                            <span x-show="$store.mediaLib.multi && $store.mediaLib.isSelected(m.url)"
                                  class="absolute top-1 right-1 bg-gold-600 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs leading-none">&check;</span>
                            <span class="absolute inset-x-0 bottom-0 bg-black/55 text-white text-[10px] px-1 py-0.5 truncate opacity-0 group-hover:opacity-100" x-text="m.name"></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>

        {{-- Device tab --}}
        <div x-show="$store.mediaLib.tab==='device'" class="p-5 pt-4">
            <label class="block border-2 border-dashed border-ink-200 rounded-xl p-8 text-center cursor-pointer hover:border-gold-400">
                <span x-show="!$store.mediaLib.uploading" class="text-sm text-ink-700/70"
                      x-text="$store.mediaLib.multi ? 'Click to choose one or more images from your computer' : 'Click to choose an image from your computer'"></span>
                <span x-show="$store.mediaLib.uploading" class="text-sm text-gold-700">Uploading…</span>
                <input type="file" accept="image/*" class="hidden" :multiple="$store.mediaLib.multi"
                       :disabled="$store.mediaLib.uploading" @change="$store.mediaLib.uploadDevice($event)">
            </label>
            <p class="text-xs text-ink-700/50 mt-2" x-text="$store.mediaLib.multi ? 'Uploads are added to your library and to the selection below.' : 'The image is added to your library and selected automatically.'"></p>
        </div>

        {{-- Multi-select footer: confirm the whole selection at once. --}}
        <div x-show="$store.mediaLib.multi" x-cloak class="flex items-center justify-between gap-3 px-5 py-3 border-t border-ink-100">
            <span class="text-sm text-ink-700/60"><span x-text="$store.mediaLib.selected.length"></span> selected</span>
            <div class="flex gap-2">
                <button type="button" @click="$store.mediaLib.close()" class="px-3 py-1.5 rounded-lg text-sm text-ink-700 hover:bg-ink-50">Cancel</button>
                <button type="button" @click="$store.mediaLib.confirm()" :disabled="!$store.mediaLib.selected.length"
                        class="px-4 py-1.5 rounded-lg text-sm bg-gold-600 text-white disabled:opacity-40">
                    Add <span x-show="$store.mediaLib.selected.length" x-text="$store.mediaLib.selected.length"></span> selected
                </button>
            </div>
        </div>
    </div>
</div>
