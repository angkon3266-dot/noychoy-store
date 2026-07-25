@extends('layouts.admin')
@section('title', $page->exists ? 'Edit landing page' : 'New landing page')
@section('heading', $page->exists ? 'Edit landing page' : 'New landing page')

@section('content')
<form action="{{ $page->exists ? route('admin.landing.update', $page) : route('admin.landing.store') }}"
      method="POST" enctype="multipart/form-data" class="space-y-6"
      x-data="landingBuilder({ blocks: @js(array_values($page->blocks ?? [])), products: @js(array_map('intval', $page->product_ids ?? [])) })">
    @csrf
    @if($page->exists)@method('PUT')@endif

    <div class="grid lg:grid-cols-3 gap-6 items-start">
        {{-- Page settings --}}
        <div class="card p-6 space-y-4">
            <div>
                <label class="label">Page title *</label>
                <input name="title" value="{{ old('title', $page->title) }}" class="input" required placeholder="Eid Bridal Sale">
            </div>
            <div>
                <label class="label">URL</label>
                <div class="flex items-center gap-1 text-sm">
                    <span class="text-ink-700/50 whitespace-nowrap">/lp/</span>
                    <input name="slug" value="{{ old('slug', $page->slug) }}" class="input py-2" placeholder="eid-bridal-sale">
                </div>
                @if($page->exists)
                    <a href="{{ $page->url() }}" target="_blank" class="text-xs text-gold-700 hover:underline mt-1 inline-block">Open page ↗</a>
                @endif
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_published" value="1" @checked(old('is_published', $page->is_published))>
                <span>Published (visible to customers)</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_header" value="1" @checked(old('show_header', $page->show_header ?? true))>
                <span>Show site header</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="show_footer" value="1" @checked(old('show_footer', $page->show_footer ?? true))>
                <span>Show site footer</span>
            </label>
            <p class="text-xs text-ink-700/50">Turning both off gives a distraction-free funnel — usually converts better for paid traffic.</p>

            <div class="border-t border-ink-100 pt-4">
                <label class="label">Products sold on this page</label>
                <p class="text-xs text-ink-700/55 mb-2">Used by the "Product buy box" section.</p>
                <div x-data="{ q: '' }">
                    <input x-model="q" class="input py-2 text-sm" placeholder="Search products…">
                    <div class="max-h-48 overflow-y-auto mt-2 rounded-lg border border-ink-100 divide-y divide-ink-100">
                        @foreach($allProducts as $p)
                            <label class="flex items-center gap-2 px-2.5 py-1.5 text-sm hover:bg-gold-50 cursor-pointer"
                                   x-show="!q || {{ Js::from(mb_strtolower($p['name'])) }}.includes(q.toLowerCase())">
                                <input type="checkbox" name="product_ids[]" value="{{ $p['id'] }}" x-model.number="products">
                                @if($p['thumb'])<img src="{{ $p['thumb'] }}" class="w-7 h-7 rounded object-cover shrink-0" alt="">@endif
                                <span class="truncate">{{ $p['name'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="text-xs text-ink-700/50 mt-1"><span x-text="products.length"></span> selected</p>
                </div>
            </div>

            <div class="border-t border-ink-100 pt-4 space-y-3">
                <div><label class="label">SEO title</label><input name="meta_title" value="{{ old('meta_title', $page->meta_title) }}" class="input" placeholder="(defaults to page title)"></div>
                <div><label class="label">SEO / share description</label><textarea name="meta_description" rows="2" class="input">{{ old('meta_description', $page->meta_description) }}</textarea></div>
                <div>
                    <label class="label">Share image</label>
                    <input type="file" name="og_image_file" accept="image/*" class="input text-xs">
                    @if($page->og_image)<p class="text-xs text-ink-700/50 mt-1">Current: {{ $page->og_image }}</p>@endif
                </div>
            </div>
        </div>

        {{-- Section builder --}}
        <div class="card p-6 lg:col-span-2">
            <h2 class="font-semibold mb-1">Sections</h2>
            <p class="text-xs text-ink-700/60 mb-4">Stack the page from blocks — reorder with ↑/↓. Everything animates in as visitors scroll.</p>
            <input type="hidden" name="blocks_json" :value="JSON.stringify(blocks)">

            <template x-for="(b, bi) in blocks" :key="bi">
                <div class="rounded-lg border border-ink-200 p-3 mb-3" x-init="ensure(b)">
                    <div class="flex items-center gap-2 mb-2">
                        <select x-model="b.type" class="input py-1.5 w-52 text-sm">
                            <option value="hero_cta">Hero + CTA</option>
                            <option value="benefits">Benefits / icon grid</option>
                            <option value="buy_box">Product buy box</option>
                            <option value="countdown">Countdown timer</option>
                            <option value="reviews">Customer reviews</option>
                            <option value="faq">FAQ accordion</option>
                            <option value="sticky_cta">Sticky CTA bar</option>
                            <option value="product_carousel">Product carousel</option>
                            <option value="banner">Promo banner(s)</option>
                            <option value="cta_banner">CTA banner (image + text)</option>
                            <option value="video">Video row</option>
                            <option value="richtext">Rich text / HTML</option>
                        </select>
                        <input x-model="b.title" class="input py-1.5 flex-1 text-sm" placeholder="Section title (optional)">
                        <label class="flex items-center gap-1 text-xs"><input type="checkbox" x-model="b.enabled"> On</label>
                        <button type="button" @click="move(bi,-1)" class="px-2">↑</button>
                        <button type="button" @click="move(bi,1)" class="px-2">↓</button>
                        <button type="button" @click="remove(bi)" class="px-2 text-red-500 text-lg">&times;</button>
                    </div>

                    {{-- Hero --}}
                    <div x-show="b.type==='hero_cta'" class="space-y-2">
                        <div class="flex gap-2 items-center">
                            <input x-model="b.hero.image" class="input py-1.5 text-sm flex-1" placeholder="background image path/URL (optional)">
                            <button type="button" @click="$store.mediaLib.openWith(u => b.hero.image = u, 'sections')" class="btn-outline py-1 text-xs shrink-0">Library</button>
                            <input type="file" :name="`block_hero[${bi}]`" accept="image/*" class="text-xs w-32">
                        </div>
                        <div class="grid sm:grid-cols-2 gap-2">
                            <input x-model="b.hero.eyebrow" class="input py-1.5 text-sm" placeholder="Eyebrow (small text above)">
                            <input x-model="b.hero.heading" class="input py-1.5 text-sm" placeholder="Big headline">
                            <input x-model="b.hero.subheading" class="input py-1.5 text-sm sm:col-span-2" placeholder="Supporting line">
                            <input x-model="b.hero.cta_text" class="input py-1.5 text-sm" placeholder="Button text (e.g. Order now)">
                            <input x-model="b.hero.cta_link" class="input py-1.5 text-sm" placeholder="Button link (default: #buy)">
                            <input x-model="b.hero.cta2_text" class="input py-1.5 text-sm" placeholder="2nd button text (optional)">
                            <input x-model="b.hero.cta2_link" class="input py-1.5 text-sm" placeholder="2nd button link">
                            <input x-model="b.hero.note" class="input py-1.5 text-sm sm:col-span-2" placeholder="Small note under buttons (e.g. Cash on delivery)">
                        </div>
                        <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="b.hero.dark"> Dark overlay (light text on image)</label>
                    </div>

                    {{-- Benefits --}}
                    <div x-show="b.type==='benefits'" class="space-y-2">
                        <template x-for="(bn, ii) in b.benefits" :key="ii">
                            <div class="flex gap-2">
                                <input x-model="bn.icon" class="input py-1.5 text-sm w-16 text-center" placeholder="✨">
                                <input x-model="bn.title" class="input py-1.5 text-sm w-44" placeholder="Benefit title">
                                <input x-model="bn.text" class="input py-1.5 text-sm flex-1" placeholder="One supporting line">
                                <button type="button" @click="b.benefits.splice(ii,1)" class="text-red-500 px-1">&times;</button>
                            </div>
                        </template>
                        <button type="button" @click="b.benefits.push({icon:'✨',title:'',text:''})" class="btn-outline py-1 text-xs">+ Add benefit</button>
                    </div>

                    {{-- Buy box --}}
                    <div x-show="b.type==='buy_box'" class="text-xs text-ink-700/60">
                        Sells the products ticked on the left. <input x-model="b.cta_label" class="input py-1 text-xs w-40 ml-2 inline-block" placeholder="Add to cart">
                    </div>

                    {{-- Countdown --}}
                    <div x-show="b.type==='countdown'" class="grid sm:grid-cols-2 gap-2">
                        <input x-model="b.countdown.title" class="input py-1.5 text-sm sm:col-span-2" placeholder="Offer ends in…">
                        <div>
                            <label class="label text-xs">Ends at</label>
                            <input type="datetime-local" x-model="b.countdown.ends_at" class="input py-1.5 text-sm">
                        </div>
                        <div class="grid grid-cols-2 gap-2 items-end">
                            <input x-model="b.countdown.cta_text" class="input py-1.5 text-sm" placeholder="Button text">
                            <input x-model="b.countdown.cta_link" class="input py-1.5 text-sm" placeholder="#buy">
                        </div>
                        <p class="text-xs text-ink-700/50 sm:col-span-2">The section hides itself automatically once the time passes.</p>
                    </div>

                    {{-- Reviews --}}
                    <div x-show="b.type==='reviews'" class="space-y-2">
                        @if($recentReviews->isEmpty())
                            <p class="text-xs text-ink-700/60">No approved reviews yet — sample testimonials will show.</p>
                        @else
                            <p class="text-xs text-ink-700/60">Tick reviews to feature (<span x-text="b.review_ids.length"></span> selected); none = sample testimonials.</p>
                            <div class="max-h-44 overflow-y-auto rounded-lg border border-ink-100 divide-y divide-ink-100">
                                @foreach($recentReviews as $rv)
                                    <label class="flex items-start gap-2 px-3 py-2 text-xs hover:bg-ink-50 cursor-pointer">
                                        <input type="checkbox" :value="{{ $rv->id }}" x-model.number="b.review_ids" class="mt-0.5">
                                        <span class="min-w-0">
                                            <span class="font-medium">{{ $rv->author_name }}</span>
                                            <span class="text-gold-600">{{ str_repeat('★', (int) $rv->rating) }}</span>
                                            <span class="block text-ink-700/60 truncate">{{ \Illuminate\Support\Str::limit($rv->body ?: $rv->title, 90) }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- FAQ --}}
                    <div x-show="b.type==='faq'" class="space-y-2">
                        <template x-for="(f, ii) in b.faqs" :key="ii">
                            <div class="flex gap-2">
                                <input x-model="f.q" class="input py-1.5 text-sm w-56" placeholder="Question">
                                <input x-model="f.a" class="input py-1.5 text-sm flex-1" placeholder="Answer">
                                <button type="button" @click="b.faqs.splice(ii,1)" class="text-red-500 px-1">&times;</button>
                            </div>
                        </template>
                        <button type="button" @click="b.faqs.push({q:'',a:''})" class="btn-outline py-1 text-xs">+ Add question</button>
                    </div>

                    {{-- Sticky CTA --}}
                    <div x-show="b.type==='sticky_cta'" class="grid sm:grid-cols-3 gap-2">
                        <input x-model="b.sticky.text" class="input py-1.5 text-sm sm:col-span-1" placeholder="Bar text">
                        <input x-model="b.sticky.button" class="input py-1.5 text-sm" placeholder="Button (Order now)">
                        <input x-model="b.sticky.link" class="input py-1.5 text-sm" placeholder="#buy">
                        <p class="text-xs text-ink-700/50 sm:col-span-3">Slides up after the visitor scrolls — keeps the CTA always reachable.</p>
                    </div>

                    {{-- Product carousel --}}
                    <div x-show="b.type==='product_carousel'" class="flex flex-wrap gap-2 items-end">
                        <div><label class="label text-xs">Source</label>
                            <select x-model="b.source" class="input py-1.5 text-sm">
                                <option value="attached">This page's products</option>
                                <option value="new">Newest</option><option value="best">Best sellers</option>
                                <option value="featured">Featured</option><option value="category">Category</option>
                            </select>
                        </div>
                        <div x-show="b.source==='category'"><label class="label text-xs">Category</label>
                            <select x-model="b.category_id" class="input py-1.5 text-sm">
                                <option value="">Choose…</option>
                                @foreach($allCategories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
                            </select>
                        </div>
                        <div><label class="label text-xs">Max items</label><input type="number" x-model="b.limit" min="1" max="20" class="input py-1.5 w-20 text-sm"></div>
                    </div>

                    {{-- Banner --}}
                    <div x-show="b.type==='banner'" class="space-y-2">
                        <select x-model="b.layout" class="input py-1.5 w-40 text-sm">
                            <option value="single">Single (full width)</option>
                            <option value="dual">Two side-by-side</option>
                            <option value="grid">Grid (3)</option>
                        </select>
                        <template x-for="(im, ii) in b.images" :key="ii">
                            <div class="flex gap-2 items-center">
                                <input x-model="im.image" class="input py-1.5 text-sm flex-1" placeholder="image path or URL">
                                <button type="button" @click="$store.mediaLib.openWith(u => im.image = u, 'sections')" class="btn-outline py-1 text-xs shrink-0">Library</button>
                                <input type="file" :name="`block_image[${bi}][${ii}]`" accept="image/*" class="text-xs w-32">
                                <input x-model="im.link" class="input py-1.5 text-sm w-32" placeholder="link">
                                <button type="button" @click="b.images.splice(ii,1)" class="text-red-500 px-1">&times;</button>
                            </div>
                        </template>
                        <button type="button" @click="b.images.push({image:'',link:''})" class="btn-outline py-1 text-xs">+ Add image</button>
                    </div>

                    {{-- CTA banner --}}
                    <div x-show="b.type==='cta_banner'" class="space-y-2">
                        <div class="flex gap-2 items-center">
                            <input x-model="b.cta.image" class="input py-1.5 text-sm flex-1" placeholder="background image path/URL">
                            <button type="button" @click="$store.mediaLib.openWith(u => b.cta.image = u, 'sections')" class="btn-outline py-1 text-xs shrink-0">Library</button>
                            <input type="file" :name="`block_cta[${bi}]`" accept="image/*" class="text-xs w-32">
                        </div>
                        <div class="grid sm:grid-cols-2 gap-2">
                            <input x-model="b.cta.eyebrow" class="input py-1.5 text-sm" placeholder="Eyebrow">
                            <input x-model="b.cta.heading" class="input py-1.5 text-sm" placeholder="Heading">
                            <input x-model="b.cta.subheading" class="input py-1.5 text-sm sm:col-span-2" placeholder="Subheading">
                            <input x-model="b.cta.button_text" class="input py-1.5 text-sm" placeholder="Button text">
                            <input x-model="b.cta.button_link" class="input py-1.5 text-sm" placeholder="Button link">
                        </div>
                    </div>

                    {{-- Video --}}
                    <div x-show="b.type==='video'" class="space-y-2">
                        <template x-for="(v, vi) in b.videos" :key="vi">
                            <div class="flex gap-2">
                                <input x-model="v.title" class="input py-1.5 text-sm w-40" placeholder="Title">
                                <input x-model="v.url" class="input py-1.5 text-sm flex-1" placeholder="YouTube link or MP4 path">
                                <button type="button" @click="b.videos.splice(vi,1)" class="text-red-500 px-1">&times;</button>
                            </div>
                        </template>
                        <button type="button" @click="b.videos.push({title:'',url:''})" class="btn-outline py-1 text-xs">+ Add video</button>
                    </div>

                    {{-- Rich text --}}
                    <div x-show="b.type==='richtext'">
                        <textarea x-model="b.html" rows="4" class="input text-sm font-mono" placeholder="<h2>Custom HTML…</h2>"></textarea>
                    </div>
                </div>
            </template>

            <div class="flex items-center gap-2 mt-2">
                <select x-model="newType" class="input py-1.5 w-52 text-sm">
                    <option value="hero_cta">Hero + CTA</option>
                    <option value="benefits">Benefits / icon grid</option>
                    <option value="buy_box">Product buy box</option>
                    <option value="countdown">Countdown timer</option>
                    <option value="reviews">Customer reviews</option>
                    <option value="faq">FAQ accordion</option>
                    <option value="sticky_cta">Sticky CTA bar</option>
                    <option value="product_carousel">Product carousel</option>
                    <option value="banner">Promo banner(s)</option>
                    <option value="cta_banner">CTA banner (image + text)</option>
                    <option value="video">Video row</option>
                    <option value="richtext">Rich text / HTML</option>
                </select>
                <button type="button" @click="add()" class="btn-outline text-sm">+ Add section</button>
                <button type="button" @click="preset()" class="btn-outline text-sm" title="Insert a proven layout you can then edit">✨ Start from a proven layout</button>
            </div>
        </div>
    </div>

    <div class="flex gap-3">
        <button class="btn-primary">{{ $page->exists ? 'Save landing page' : 'Create landing page' }}</button>
        <a href="{{ route('admin.landing.index') }}" class="btn-outline">Back to list</a>
    </div>
</form>
@endsection
