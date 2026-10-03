{{-- One story section in the sectionBuilder Alpine component (resources/js/app.js),
     shared by the product form and the content-templates page. --}}
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
                <div class="aspect-square rounded bg-ink-100 overflow-hidden mb-1 grid place-items-center text-center">
                    <template x-if="s.media === 'image' && s.image"><img :src="s.image" class="w-full h-full object-cover" alt=""></template>
                    <template x-if="s.media === 'auto'"><span class="px-2 text-[11px] leading-tight text-ink-700/60" x-text="autoLabel(i)"></span></template>
                    <template x-if="s.media === 'none'"><span class="px-2 text-[11px] leading-tight text-ink-700/40">Text only</span></template>
                </div>
                <label class="btn-outline text-xs py-1 w-full text-center cursor-pointer block">Upload
                    <input type="file" accept="image/*" class="hidden" @change="upload(i, $event)">
                </label>
                <button type="button" @click="pickLibrary(i)" class="btn-outline text-xs py-1 w-full text-center mt-1">Library</button>
            </div>
            <div class="flex-1 space-y-2">
                <input x-model="s.heading" placeholder="{{ $headingPlaceholder ?? 'Heading' }}" class="input py-2">
                <textarea x-model="s.body" rows="3" placeholder="Description" class="input"></textarea>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    <span class="text-xs text-ink-700/60">Beside it:</span>
                    <label class="flex items-center gap-1"><input type="radio" value="auto" x-model="s.media" :name="`story_media_${i}`"> Product's own video / photo</label>
                    <label class="flex items-center gap-1"><input type="radio" value="image" x-model="s.media" :name="`story_media_${i}`"> This image</label>
                    <label class="flex items-center gap-1"><input type="radio" value="none" x-model="s.media" :name="`story_media_${i}`"> Nothing</label>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <label class="flex items-center gap-1"><input type="radio" value="left" x-model="s.layout" :name="`story_layout_${i}`"> Picture left</label>
                    <label class="flex items-center gap-1"><input type="radio" value="right" x-model="s.layout" :name="`story_layout_${i}`"> Picture right</label>
                    <input x-model="s.image" @input="s.media = 'image'" placeholder="or paste image URL" class="input py-1 text-xs flex-1 min-w-40">
                </div>
            </div>
        </div>
    </div>
</template>
