{{--
    One custom section in the homepage builder. Rendered once per saved block
    ($xdata = "homeBlock({...})") and once inside a <template> that the
    "Add a section" button clones. Everything is bound to the homeBlock
    component (app.js), so the same markup serves both.
--}}
<div data-section-row x-data="{!! $xdata !!}" class="rounded-xl border border-dashed border-gold-300 bg-white shadow-sm">
    <input type="hidden" name="section_order[]" :value="'block:' + b.id">
    <input type="hidden" :name="`home_blocks[${b.id}]`" :value="JSON.stringify(b)">
    <div class="flex items-center gap-2 px-3 py-2.5">
        <span data-section-grip class="shrink-0 cursor-grab active:cursor-grabbing touch-none select-none px-1 text-lg leading-none text-ink-700/40 hover:text-ink-900" title="Drag to reorder">⠿</span>
        <div class="flex flex-col shrink-0">
            <button type="button" data-section-up @click="move($el.closest('[data-section-row]'), -1)" class="w-6 h-5 rounded text-[11px] text-ink-700/60 hover:bg-ink-100 hover:text-ink-900 leading-none disabled:opacity-30" title="Move up">▲</button>
            <button type="button" data-section-down @click="move($el.closest('[data-section-row]'), 1)" class="w-6 h-5 rounded text-[11px] text-ink-700/60 hover:bg-ink-100 hover:text-ink-900 leading-none disabled:opacity-30" title="Move down">▼</button>
        </div>
        <span data-section-num class="shrink-0 w-6 text-xs tabular-nums text-ink-700/40"></span>
        <button type="button" @click="open = !open; if (open) $dispatch('home-focus', 'block:' + b.id)" class="flex-1 min-w-0 text-left">
            <span class="block text-sm font-semibold text-ink-900"><span x-text="b.title || label"></span> <span class="ml-1 rounded bg-gold-100 text-gold-800 text-[10px] font-medium px-1.5 py-0.5 align-middle">Custom</span></span>
            <span class="block text-xs text-ink-700/55 truncate" x-text="label"></span>
        </button>
        <label class="shrink-0 inline-flex items-center gap-2 cursor-pointer select-none" title="Show this section on the homepage">
            <input type="checkbox" x-model="b.enabled" class="peer sr-only">
            <span class="relative w-9 h-5 rounded-full bg-ink-200 transition-colors peer-checked:bg-gold-600 after:absolute after:top-0.5 after:left-0.5 after:w-4 after:h-4 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:after:translate-x-4"></span>
            <span class="text-xs text-ink-700/60 w-7">Show</span>
        </label>
        <button type="button" @click="open = !open" class="shrink-0 grid place-items-center w-8 h-8 rounded-lg hover:bg-ink-50 text-ink-700/60" :aria-expanded="open" aria-label="Edit section">
            <svg class="w-4 h-4 transition-transform" :class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
    </div>

    <div x-show="open" x-cloak class="border-t border-ink-100 p-4 space-y-3">
        <div class="grid sm:grid-cols-[14rem_1fr] gap-3">
            <div>
                <label class="label">Kind of section</label>
                <select x-model="b.type" class="input text-sm">
                    <option value="product_carousel">Product row</option>
                    <option value="banner_carousel">Banner + products</option>
                    <option value="banner">Promo banner(s)</option>
                    <option value="cta_banner">Image banner with text</option>
                    <option value="video">Videos</option>
                    <option value="faq">Questions &amp; answers</option>
                    <option value="reviews">Customer reviews (hand-picked)</option>
                    <option value="richtext">Text / HTML</option>
                </select>
            </div>
            <div>
                <label class="label">Heading (optional)</label>
                <input x-model="b.title" class="input text-sm" placeholder="e.g. Premium earrings, just for you">
            </div>
        </div>

        {{-- Promo banners --}}
        <div x-show="b.type === 'banner'" class="space-y-2">
            <select x-model="b.layout" class="input py-1.5 w-56 text-sm">
                <option value="single">One, full width</option>
                <option value="dual">Two side by side</option>
                <option value="grid">Three in a row</option>
            </select>
            <template x-for="(im, ii) in b.images" :key="ii">
                <div class="flex flex-wrap gap-2 items-center">
                    <img x-show="im.image" :src="src(im.image)" class="w-12 h-12 object-cover rounded border border-ink-100 shrink-0" alt="">
                    <button type="button" @click="$store.mediaLib.openWith(u => im.image = u, 'sections')" class="btn-outline py-1 text-xs shrink-0">Pick picture</button>
                    <input type="file" :name="`block_image[${b.id}][${ii}]`" accept="image/*" class="text-xs w-44">
                    <input x-model="im.link" class="input py-1.5 text-sm flex-1 min-w-40" placeholder="Link when clicked">
                    <button type="button" @click="b.images.splice(ii, 1)" class="text-red-500 px-1 text-lg">&times;</button>
                </div>
            </template>
            <button type="button" @click="addImage()" class="btn-outline py-1 text-xs">+ Add a banner</button>
        </div>

        {{-- Product rows --}}
        <div x-show="b.type === 'product_carousel' || b.type === 'banner_carousel'" class="flex flex-wrap gap-3 items-end">
            <div><label class="label text-xs">Products</label>
                <select x-model="b.source" class="input py-1.5 text-sm">
                    <option value="new">Newest</option><option value="best">Best sellers</option>
                    <option value="featured">Ticked “Featured”</option><option value="category">A category</option>
                </select>
            </div>
            <div x-show="b.source === 'category'"><label class="label text-xs">Category</label>
                <select x-model="b.category_id" class="input py-1.5 text-sm">
                    <option value="">Choose…</option>
                    @foreach($allCategories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
                </select>
            </div>
            <div><label class="label text-xs">How many</label><input type="number" x-model="b.limit" min="1" max="20" class="input py-1.5 w-20 text-sm"></div>
            <div class="flex-1 min-w-48"><label class="label text-xs">“View all” goes to</label><input x-model="b.view_all_link" class="input py-1.5 text-sm" placeholder="(optional)"></div>
        </div>
        <div x-show="b.type === 'banner_carousel'" class="flex flex-wrap gap-2 items-center">
            <span class="text-xs font-medium text-ink-700">Side banner:</span>
            <img x-show="b.banner.image" :src="src(b.banner.image)" class="w-12 h-12 object-cover rounded border border-ink-100 shrink-0" alt="">
            <button type="button" @click="$store.mediaLib.openWith(u => b.banner.image = u, 'sections')" class="btn-outline py-1 text-xs shrink-0">Pick picture</button>
            <input type="file" :name="`block_banner[${b.id}]`" accept="image/*" class="text-xs w-44">
            <input x-model="b.banner.link" class="input py-1.5 text-sm flex-1 min-w-40" placeholder="Link when clicked">
        </div>

        {{-- Image banner with text --}}
        <div x-show="b.type === 'cta_banner'" class="space-y-2">
            <div class="flex flex-wrap gap-2 items-center">
                <img x-show="b.cta.image" :src="src(b.cta.image)" class="w-16 h-12 object-cover rounded border border-ink-100 shrink-0" alt="">
                <button type="button" @click="$store.mediaLib.openWith(u => b.cta.image = u, 'sections')" class="btn-outline py-1 text-xs shrink-0">Pick picture</button>
                <input type="file" :name="`block_cta[${b.id}]`" accept="image/*" class="text-xs w-44">
            </div>
            <div class="grid sm:grid-cols-2 gap-2">
                <input x-model="b.cta.eyebrow" class="input py-1.5 text-sm" placeholder="Small text">
                <input x-model="b.cta.heading" class="input py-1.5 text-sm" placeholder="Heading">
                <input x-model="b.cta.subheading" class="input py-1.5 text-sm sm:col-span-2" placeholder="Line under the heading">
                <input x-model="b.cta.button_text" class="input py-1.5 text-sm" placeholder="Button text">
                <input x-model="b.cta.button_link" class="input py-1.5 text-sm" placeholder="Button link (defaults to Shop)">
                <select x-model="b.cta.align" class="input py-1.5 text-sm"><option value="left">Text on the left</option><option value="center">Text in the centre</option><option value="right">Text on the right</option></select>
                <select x-model="b.cta.height" class="input py-1.5 text-sm"><option value="sm">Short</option><option value="md">Medium</option><option value="lg">Tall</option></select>
            </div>
        </div>

        {{-- Videos --}}
        <div x-show="b.type === 'video'" class="space-y-2">
            <template x-for="(v, vi) in b.videos" :key="vi">
                <div class="flex gap-2">
                    <input x-model="v.title" class="input py-1.5 text-sm w-44" placeholder="Title">
                    <input x-model="v.url" class="input py-1.5 text-sm flex-1" placeholder="YouTube link or MP4 path">
                    <button type="button" @click="b.videos.splice(vi, 1)" class="text-red-500 px-1 text-lg">&times;</button>
                </div>
            </template>
            <button type="button" @click="addVideo()" class="btn-outline py-1 text-xs">+ Add a video</button>
        </div>

        {{-- Questions & answers --}}
        <div x-show="b.type === 'faq'" class="space-y-2">
            <template x-for="(f, fi) in b.faqs" :key="fi">
                <div class="flex gap-2">
                    <input x-model="f.q" class="input py-1.5 text-sm w-64" placeholder="Question">
                    <input x-model="f.a" class="input py-1.5 text-sm flex-1" placeholder="Answer">
                    <button type="button" @click="b.faqs.splice(fi, 1)" class="text-red-500 px-1 text-lg">&times;</button>
                </div>
            </template>
            <button type="button" @click="addFaq()" class="btn-outline py-1 text-xs">+ Add a question</button>
        </div>

        {{-- Hand-picked reviews --}}
        <div x-show="b.type === 'reviews'" class="space-y-2">
            <p class="text-xs text-ink-700/60">Tick the reviews to show (<span x-text="b.review_ids.length"></span> ticked). None ticked hides this section.</p>
            <div class="max-h-56 overflow-y-auto rounded-lg border border-ink-100 divide-y divide-ink-100">
                @foreach($recentReviews as $rv)
                    <label class="flex items-start gap-2 px-3 py-2 text-xs hover:bg-ink-50 cursor-pointer">
                        <input type="checkbox" :value="{{ $rv->id }}" x-model.number="b.review_ids" class="mt-0.5">
                        <span class="min-w-0">
                            <span class="font-medium">{{ $rv->author_name }}</span>
                            <span class="text-gold-600">{{ str_repeat('★', (int) $rv->rating) }}</span>
                            <span class="text-ink-700/50">· {{ $rv->product?->name }}</span>
                            <span class="block text-ink-700/60 truncate">{{ \Illuminate\Support\Str::limit($rv->body ?: $rv->title, 110) }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- Text / HTML --}}
        <div x-show="b.type === 'richtext'">
            <textarea x-model="b.html" rows="5" class="input text-sm font-mono" placeholder="<h2>Your heading</h2><p>Your text…</p>"></textarea>
        </div>

        <div class="flex justify-end pt-1">
            <button type="button" @click="removeSection()" class="text-xs text-red-600 hover:underline">Delete this section</button>
        </div>
    </div>
</div>
