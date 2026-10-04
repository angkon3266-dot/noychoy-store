{{-- One story section in the sectionBuilder Alpine component (resources/js/app.js),
     shared by the product form and the content-templates page. The box shows
     what the storefront will: the product's own video / photo for an "auto"
     row, or the image / video set for this row — and takes a dropped file. --}}
<template x-for="(s, i) in sections" :key="i">
    <div class="rounded-lg border border-ink-100 p-3">
        <div class="flex items-center justify-between text-xs text-ink-700/50 mb-2">
            <span>Section <span x-text="i + 1"></span></span>
            <div class="flex gap-2">
                <button type="button" @click="move(i, -1)" class="hover:text-gold-700">↑</button>
                <button type="button" @click="move(i, 1)" class="hover:text-gold-700">↓</button>
                <button type="button" @click="remove(i)" class="text-red-600 hover:underline">Remove</button>
            </div>
        </div>
        <div class="flex gap-3">
            <div class="w-28 shrink-0">
                <div class="relative aspect-square rounded bg-ink-100 overflow-hidden mb-1 grid place-items-center text-center border-2 border-dashed transition"
                     :class="over === i ? 'border-gold-500 bg-gold-50' : 'border-transparent'"
                     @dragover.prevent="over = i" @dragenter.prevent="over = i"
                     @dragleave.prevent="over = null" @drop.prevent="drop(i, $event)"
                     title="Drop an image or a video here">
                    <template x-if="preview(i)?.type === 'image'">
                        <img :src="preview(i).src" class="absolute inset-0 w-full h-full object-cover" alt="">
                    </template>
                    <template x-if="preview(i)?.type === 'video'">
                        <video :src="preview(i).src" muted playsinline preload="metadata" class="absolute inset-0 w-full h-full object-cover"></video>
                    </template>
                    <template x-if="preview(i)?.type === 'video'">
                        <span class="absolute bottom-1 left-1 rounded bg-ink-900/70 px-1 text-[10px] text-white">▶ Video</span>
                    </template>
                    <template x-if="s.media === 'auto' && preview(i)">
                        <span class="absolute top-1 left-1 rounded bg-white/85 px-1 text-[10px] text-ink-700">Product's own</span>
                    </template>
                    <template x-if="!preview(i)">
                        <span class="px-2 text-[11px] leading-tight text-ink-700/50"
                              x-text="s.media === 'none' ? 'Text only' : (s.media === 'auto' ? autoLabel(i) : 'Drop an image or video')"></span>
                    </template>
                    <div x-show="over === i" x-cloak class="absolute inset-0 grid place-items-center bg-gold-50/90 text-[11px] font-medium text-gold-800">Drop to use</div>
                    <div x-show="uploading === i" x-cloak class="absolute inset-0 grid place-items-center bg-white/85 text-[11px] text-ink-700">Uploading…</div>
                </div>
                <label class="btn-outline text-xs py-1 w-full text-center cursor-pointer block">Upload
                    <input type="file" accept="image/*,video/mp4,video/webm,video/quicktime,video/x-m4v" class="hidden" @change="upload(i, $event)">
                </label>
                <button type="button" @click="pickLibrary(i)" class="btn-outline text-xs py-1 w-full text-center mt-1">Library</button>
            </div>
            <div class="flex-1 space-y-2">
                <input x-model="s.heading" placeholder="{{ $headingPlaceholder ?? 'Heading' }}" class="input py-2">
                <textarea x-model="s.body" rows="3" placeholder="Description" class="input"></textarea>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    <span class="text-xs text-ink-700/60">Beside it:</span>
                    <label class="flex items-center gap-1"><input type="radio" value="auto" x-model="s.media" :name="`story_media_${i}`"> Product's own video / photo</label>
                    <label class="flex items-center gap-1"><input type="radio" value="image" x-model="s.media" :name="`story_media_${i}`"> This image / video</label>
                    <label class="flex items-center gap-1"><input type="radio" value="none" x-model="s.media" :name="`story_media_${i}`"> Nothing</label>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <label class="flex items-center gap-1"><input type="radio" value="left" x-model="s.layout" :name="`story_layout_${i}`"> Picture left</label>
                    <label class="flex items-center gap-1"><input type="radio" value="right" x-model="s.layout" :name="`story_layout_${i}`"> Picture right</label>
                    <input x-model="s.image" @input="s.media = 'image'" placeholder="or paste image / video URL" class="input py-1 text-xs flex-1 min-w-40">
                </div>
            </div>
        </div>
    </div>
</template>
