{{-- One built-in homepage section's panel. $key is its config('home.sections') key. --}}
@php $meta = config('home.sections.'.$key); @endphp
<x-admin.home-panel :token="$key" :title="$meta['label']" :hint="$meta['hint'] ?? null" :toggle="$meta['toggle']" :home="$home">
    @switch($key)

        @case('ribbon')
            <p class="text-xs text-ink-700/60">Short reassurances that scroll slowly across the page. Icon names come from the site's own set — start typing to pick one.</p>
            <div x-data="{ rows: @js(array_values($home['feature_strip'] ?? [])) }">
                <template x-for="(r, i) in rows" :key="i">
                    <div class="flex gap-2 mb-2">
                        <input :name="`feature_strip[${i}][icon]`" x-model="r.icon" list="storefront-icons" class="input w-40" placeholder="truck" maxlength="24">
                        <input :name="`feature_strip[${i}][title]`" x-model="r.title" class="input flex-1" maxlength="60" placeholder="Fast delivery across Bangladesh">
                        <button type="button" @click="rows.splice(i,1)" class="text-red-500 px-2 text-xl leading-none">&times;</button>
                    </div>
                </template>
                <button type="button" @click="rows.push({icon:'check',title:''})" class="btn-outline text-xs py-1">+ Add a point</button>
            </div>
            @break

        @case('categories')
            @include('admin.appearance.home._texts', ['fields' => [
                'categories_eyebrow' => 'Small label', 'categories_title' => 'Heading',
                'categories_link_label' => '“View all” link text', 'categories_link' => '“View all” goes to',
            ]])
            @include('admin.partials._category-picker', [
                'field' => 'category_scroller_ids',
                'label' => 'Which categories, in order',
                'help' => 'Leave empty to show your main categories automatically. Each tile uses the category’s own picture — change those under Categories.',
                'selectedIds' => $home['category_scroller_ids'] ?? [],
                'allCategories' => $allCategories,
            ])
            @break

        @case('picked')
            <p class="text-xs text-ink-700/60">Each visitor gets their own picks from what they told the gift finder, loved, or what others bought alongside. The row hides itself until there are four.</p>
            @include('admin.appearance.home._texts', ['fields' => ['picked_for_you_eyebrow' => 'Small label', 'picked_for_you_title' => 'Heading']])
            @break

        @case('occasions')
            @include('admin.appearance.home._texts', ['fields' => [
                'occasions_eyebrow' => 'Small label', 'occasions_title' => 'Heading', 'occasions_subtitle' => 'Line under the heading',
            ]])
            @php
                $occasions = collect($home['occasions'] ?? config('home.defaults.occasions', []))->values();
                // Two spare rows so a new occasion can be added without a code change.
                $occasions = $occasions->concat(array_fill(0, max(0, min(2, 12 - $occasions->count())), ['label' => '', 'tagline' => '', 'link' => '', 'image' => null]));
            @endphp
            <p class="text-xs text-ink-700/60">Portrait pictures work best (e.g. 800×1000). A tile with no picture shows as a coloured text tile. Clear a name to delete that tile; fill an empty row to add one.</p>
            <div class="space-y-3">
                @foreach($occasions as $i => $o)
                    <div class="rounded-lg border border-ink-100 p-3 grid sm:grid-cols-[auto_1fr] gap-4 items-start">
                        <x-media-field :name="'occasion_image_'.$i" :value="theme_asset($o['image'] ?? null) ?: ''" folder="occasions" />
                        <div class="grid sm:grid-cols-3 gap-2 min-w-0">
                            <input name="occasions[{{ $i }}][label]" value="{{ $o['label'] ?? '' }}" class="input" placeholder="Name (e.g. Eid Gifts)">
                            <input name="occasions[{{ $i }}][tagline]" value="{{ $o['tagline'] ?? '' }}" class="input" placeholder="Short line">
                            <input name="occasions[{{ $i }}][link]" value="{{ $o['link'] ?? '' }}" class="input" placeholder="Link (defaults to Shop)">
                            @if($menuCollections->isNotEmpty())
                                <select class="input text-xs sm:col-span-3" onchange="if(this.value){this.closest('div').querySelector('input[name$=\'[link]\']').value=this.value}">
                                    <option value="">Point this tile at a collection…</option>
                                    @foreach($menuCollections as $mc)
                                        <option value="{{ $mc['url'] }}">{{ $mc['name'] }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @break

        @case('deals')
            <p class="text-xs text-ink-700/60">One card per running offer under <a href="{{ route('admin.offers.index') }}" class="underline">Offers</a>. Hides itself when no offers are running.</p>
            @include('admin.appearance.home._texts', ['fields' => ['deals_eyebrow' => 'Small label', 'deals_title' => 'Heading', 'deals_subtitle' => 'Line under the heading']])
            <div class="max-w-xs">
                <label class="label">Ends at (optional)</label>
                <input type="datetime-local" name="home[deals_ends_at]" class="input"
                       value="{{ filled($home['deals_ends_at'] ?? null) ? \Illuminate\Support\Carbon::parse($home['deals_ends_at'])->format('Y-m-d\TH:i') : '' }}">
                <p class="text-[11px] text-ink-700/50 mt-1">Shows a countdown and hides the section when it passes. Site time zone: <strong>{{ config('app.timezone') }}</strong>.</p>
            </div>
            @break

        @case('membership')
            <p class="text-sm text-ink-700/70">Shows what joining is worth — the member discount, points and tiers. Those numbers come from the membership settings, so they always match the cart and checkout. It hides itself when membership has no discount or points.</p>
            @break

        @case('best')
            @include('admin.appearance.home._texts', ['fields' => [
                'best_selling_eyebrow' => 'Small label', 'best_selling_title' => 'Heading',
                'best_selling_link_label' => '“View all” link text', 'best_selling_link' => '“View all” goes to',
            ]])
            @include('admin.appearance.home._product-source', ['p' => 'best', 'autoLabel' => 'Automatic (best sellers by sales)'])
            @break

        @case('recent')
            <p class="text-xs text-ink-700/60">Each visitor sees the pieces they looked at. Hides itself until there are two.</p>
            @include('admin.appearance.home._texts', ['fields' => ['recently_viewed_eyebrow' => 'Small label', 'recently_viewed_title' => 'Heading']])
            @break

        @case('gift_finder')
            @include('admin.appearance.home._texts', ['fields' => [
                'gift_finder_eyebrow' => 'Small label', 'gift_finder_title' => 'Heading', 'gift_finder_text' => 'Text under the heading',
            ]])
            @php $budgets = collect($home['gift_budgets'] ?? config('home.defaults.gift_budgets', []))->values(); @endphp
            <div x-data="{ rows: @js($budgets->map(fn ($b) => ['min' => $b['min'] ?? '', 'max' => $b['max'] ?? ''])->all()) }">
                <label class="label">Budget buttons</label>
                <template x-for="(r, i) in rows" :key="i">
                    <div class="flex items-center gap-2 mb-2">
                        <input :name="`gift_budgets[${i}][min]`" x-model="r.min" type="number" min="0" step="1" class="input w-32" placeholder="From (৳)">
                        <span class="text-ink-700/40">–</span>
                        <input :name="`gift_budgets[${i}][max]`" x-model="r.max" type="number" min="0" step="1" class="input w-32" placeholder="To (৳)">
                        <button type="button" @click="rows.splice(i,1)" class="text-red-500 px-2 text-xl leading-none">&times;</button>
                    </div>
                </template>
                <button type="button" @click="rows.push({min:'',max:''})" class="btn-outline text-xs py-1">+ Add a budget</button>
                <p class="text-xs text-ink-700/50 mt-1">Leave “From” empty for “Under ৳X”, or “To” empty for “৳X+”.</p>
            </div>
            @php $promises = collect($home['gift_promises'] ?? [])->values(); @endphp
            <div x-data="{ rows: @js($promises->all()) }">
                <input type="hidden" name="gift_promises_present" value="1">
                <label class="label">Promises beside it (up to 3)</label>
                <template x-for="(r, i) in rows" :key="i">
                    <div class="flex gap-2 mb-2">
                        <input :name="`gift_promises[${i}][icon]`" x-model="r.icon" list="storefront-icons" class="input w-32" placeholder="gift" maxlength="24">
                        <input :name="`gift_promises[${i}][title]`" x-model="r.title" class="input flex-1" maxlength="60" placeholder="Gift-ready packing">
                        <input :name="`gift_promises[${i}][text]`" x-model="r.text" class="input flex-1" maxlength="80" placeholder="No price slip in the box">
                        <button type="button" @click="rows.splice(i,1)" class="text-red-500 px-2 text-xl leading-none">&times;</button>
                    </div>
                </template>
                <button type="button" x-show="rows.length < 3" @click="rows.push({icon:'gift',title:'',text:''})" class="btn-outline text-xs py-1">+ Add a promise</button>
            </div>
            @break

        @case('featured')
            @include('admin.appearance.home._texts', ['fields' => [
                'featured_eyebrow' => 'Small label', 'featured_title' => 'Heading', 'featured_subtitle' => 'Line under the heading',
                'featured_link_label' => 'Link text (top right)', 'featured_link' => 'Link goes to',
            ]])
            @include('admin.appearance.home._product-source', ['p' => 'featured', 'autoLabel' => 'Automatic (products ticked “Featured”)'])

            @php $leadMode = $home['featured_lead_mode'] ?? 'first'; @endphp
            <div x-data="{ mode: @js($leadMode) }" class="rounded-lg border border-ink-100 bg-ink-50/50 p-3 space-y-3">
                <p class="text-sm font-semibold text-ink-800">The large tile</p>
                <div class="flex flex-wrap gap-2 text-sm">
                    @foreach(['first' => 'The first product', 'product' => 'A product I choose', 'custom' => 'My own picture and words', 'none' => 'No large tile'] as $value => $label)
                        <label class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 cursor-pointer transition"
                               :class="mode === '{{ $value }}' ? 'border-gold-500 bg-white ring-1 ring-gold-400' : 'border-ink-200 bg-white hover:border-gold-300'">
                            <input type="radio" name="home[featured_lead_mode]" value="{{ $value }}" x-model="mode" class="sr-only">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                <div x-show="mode === 'product'" x-cloak>
                    <select name="home[featured_lead_product_id]" class="input">
                        <option value="">Choose the product…</option>
                        @foreach($homeProducts as $hp)
                            <option value="{{ $hp['id'] }}" @selected((string) ($home['featured_lead_product_id'] ?? '') === (string) $hp['id'])>{{ $hp['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div x-show="mode === 'custom'" x-cloak class="grid sm:grid-cols-[auto_1fr] gap-4">
                    <x-media-field name="featured_lead_image" :value="theme_asset($home['featured_lead_image'] ?? null) ?: ''" folder="branding" />
                    @include('admin.appearance.home._texts', ['fields' => [
                        'featured_lead_heading' => 'Heading on the picture', 'featured_lead_text' => 'Line under it',
                        'featured_lead_link' => 'Goes to',
                    ]])
                </div>
                <div x-show="mode !== 'none'" x-cloak>
                    @include('admin.appearance.home._texts', ['fields' => ['featured_lead_eyebrow' => 'Small label on the tile', 'featured_lead_button' => 'Button text']])
                </div>
                <p class="text-xs text-ink-700/50">A portrait picture (e.g. 1000×1250) suits the tile. With “No large tile” the products fill the full width.</p>
            </div>
            @break

        @case('gifting_steps')
            @include('admin.appearance.home._texts', ['fields' => ['gifting_steps_eyebrow' => 'Small label', 'gifting_steps_title' => 'Heading']])
            @php $steps = collect($home['gifting_steps'] ?? [])->values(); @endphp
            <div x-data="{ rows: @js($steps->all()) }">
                <input type="hidden" name="gifting_steps_present" value="1">
                <label class="label">Steps (up to 4)</label>
                <template x-for="(r, i) in rows" :key="i">
                    <div class="flex gap-2 mb-2">
                        <span class="w-6 pt-2.5 text-xs tabular-nums text-ink-700/40" x-text="String(i + 1).padStart(2, '0')"></span>
                        <input :name="`gifting_steps[${i}][title]`" x-model="r.title" class="input w-56" maxlength="60" placeholder="Pick a piece">
                        <input :name="`gifting_steps[${i}][text]`" x-model="r.text" class="input flex-1" maxlength="120" placeholder="Browse by occasion, budget or category.">
                        <button type="button" @click="rows.splice(i,1)" class="text-red-500 px-2 text-xl leading-none">&times;</button>
                    </div>
                </template>
                <button type="button" x-show="rows.length < 4" @click="rows.push({title:'',text:''})" class="btn-outline text-xs py-1">+ Add a step</button>
            </div>
            @break

        @case('reviews')
            @include('admin.appearance.home._texts', ['fields' => ['reviews_eyebrow' => 'Small label', 'reviews_title' => 'Heading', 'reviews_caption' => 'Line under the score']])
            @php $chosen = collect($home['reviews_ids'] ?? [])->map(fn ($i) => (int) $i); @endphp
            <input type="hidden" name="reviews_ids_present" value="1">
            <div x-data="{ n: {{ $chosen->count() }} }">
                <p class="text-sm font-semibold text-ink-800">Which reviews</p>
                <p class="text-xs text-ink-700/60 mb-2">Tick the ones to show (<span x-text="n"></span> ticked). With none ticked, the newest <strong>5-star</strong> reviews that have words show by themselves.</p>
                @if($recentReviews->isEmpty())
                    <p class="text-xs text-ink-700/60">No approved reviews yet.</p>
                @else
                    <div class="max-h-72 overflow-y-auto rounded-lg border border-ink-100 divide-y divide-ink-100 bg-white">
                        @foreach($recentReviews as $rv)
                            <label class="flex items-start gap-2 px-3 py-2 text-xs hover:bg-ink-50 cursor-pointer">
                                <input type="checkbox" name="reviews_ids[]" value="{{ $rv->id }}" @checked($chosen->contains($rv->id)) @change="n += $event.target.checked ? 1 : -1" class="mt-0.5">
                                <span class="min-w-0">
                                    <span class="font-medium">{{ $rv->author_name }}</span>
                                    <span class="{{ $rv->rating >= 5 ? 'text-gold-600' : 'text-ink-700/50' }}">{{ str_repeat('★', (int) $rv->rating) }}{{ str_repeat('☆', 5 - (int) $rv->rating) }}</span>
                                    <span class="text-ink-700/50">· {{ $rv->product?->name }}</span>
                                    <span class="block text-ink-700/70">{{ \Illuminate\Support\Str::limit($rv->body ?: $rv->title, 180) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>
            @break

        @case('promise')
            @include('admin.appearance.home._texts', ['fields' => ['promise_eyebrow' => 'Small label', 'promise_title' => 'Heading', 'promise_text' => 'Text']])
            <x-media-field name="promise_image" :value="theme_asset($home['promise_image'] ?? null) ?: ''" folder="branding"
                label="Picture" help="Portrait or square. Leave empty to use your newest product photo." />
            <div>
                <label class="label">Three short points under the text</label>
                <div class="grid sm:grid-cols-3 gap-3">
                    @for($b = 1; $b <= 3; $b++)
                        <div class="rounded-lg border border-ink-100 p-3 space-y-2">
                            <input name="home[badge{{ $b }}_title]" value="{{ $home['badge'.$b.'_title'] }}" class="input" placeholder="Title">
                            <input name="home[badge{{ $b }}_text]" value="{{ $home['badge'.$b.'_text'] }}" class="input" placeholder="Short line">
                        </div>
                    @endfor
                </div>
            </div>
            @break

        @case('new')
            @include('admin.appearance.home._texts', ['fields' => [
                'new_arrivals_eyebrow' => 'Small label', 'new_arrivals_title' => 'Heading',
                'new_arrivals_link_label' => '“View all” link text', 'new_arrivals_link' => '“View all” goes to',
            ]])
            @include('admin.appearance.home._product-source', ['p' => 'new', 'autoLabel' => 'Automatic (newest first)'])
            @break

    @endswitch
</x-admin.home-panel>
