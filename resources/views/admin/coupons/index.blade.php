@extends('layouts.admin')
@section('title', 'Coupons')
@section('heading', 'Coupons')

@section('content')
@php $c = $editing; @endphp
<div class="grid lg:grid-cols-3 gap-6">
    <div class="card p-6 h-fit lg:sticky lg:top-20"
         x-data="{ scope: '{{ old('applies_to', $c->applies_to ?? 'all') }}', type: '{{ old('type', $c->type ?? 'fixed') }}',
                   auto: {{ old('auto_apply', $c->auto_apply ?? false) ? 'true' : 'false' }},
                   audience: '{{ old('audience', $c->audience ?? 'all') }}' }">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-semibold">{{ $editing ? 'Edit coupon' : 'New coupon' }}</h2>
            @if($editing)<a href="{{ route('admin.coupons.index') }}" class="text-xs text-ink-700/60 hover:underline">+ New instead</a>@endif
        </div>
        @if($errors->any())<div class="rounded bg-red-50 text-red-700 text-sm px-3 py-2 mb-3"><ul class="list-disc list-inside">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

        <form action="{{ $editing ? route('admin.coupons.update', $editing) : route('admin.coupons.store') }}" method="POST" class="space-y-3">
            @csrf
            @if($editing)@method('PUT')@endif

            <div><label class="label">Code *</label><input name="code" value="{{ old('code', $c->code ?? '') }}" class="input uppercase" required></div>

            <div class="grid grid-cols-2 gap-3">
                <div><label class="label">Type</label>
                    <select name="type" x-model="type" class="input">
                        <option value="fixed">Fixed ৳</option>
                        <option value="percent">Percent %</option>
                    </select>
                </div>
                <div><label class="label">Value *</label><input name="value" type="number" step="0.01" value="{{ old('value', $c->value ?? '') }}" class="input" required></div>
            </div>

            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="free_shipping" value="1" @checked(old('free_shipping', $c->free_shipping ?? false))> Also grant free shipping</label>

            {{-- Scope --}}
            <div>
                <label class="label">Applies to</label>
                <select name="applies_to" x-model="scope" class="input">
                    <option value="all">Entire cart</option>
                    <option value="categories">Specific categories</option>
                    <option value="products">Specific products</option>
                </select>
            </div>
            <div x-show="scope === 'categories'" x-cloak>
                <label class="label">Categories</label>
                <select name="category_ids[]" multiple size="5" class="input">
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" @selected(in_array($cat->id, (array) old('category_ids', $c->category_ids ?? [])))>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div x-show="scope === 'products'" x-cloak>
                <label class="label">Products</label>
                <select name="product_ids[]" multiple size="5" class="input">
                    @foreach($products as $p)
                        <option value="{{ $p->id }}" @selected(in_array($p->id, (array) old('product_ids', $c->product_ids ?? [])))>{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="exclude_sale_items" value="1" @checked(old('exclude_sale_items', $c->exclude_sale_items ?? false))> Exclude items already on sale</label>

            {{-- Conditions --}}
            <div class="grid grid-cols-2 gap-3">
                <div><label class="label">Min order ৳</label><input name="min_order" type="number" step="0.01" value="{{ old('min_order', $c->min_order ?? '') }}" class="input"></div>
                <div><label class="label">Min qty</label><input name="min_qty" type="number" min="1" value="{{ old('min_qty', $c->min_qty ?? '') }}" class="input"></div>
                <div><label class="label">Max qty</label><input name="max_qty" type="number" min="1" value="{{ old('max_qty', $c->max_qty ?? '') }}" class="input"></div>
                <div><label class="label">Usage limit (total)</label><input name="usage_limit" type="number" min="1" value="{{ old('usage_limit', $c->usage_limit ?? '') }}" class="input"></div>
                <div><label class="label">Limit per customer</label><input name="per_customer_limit" type="number" min="1" value="{{ old('per_customer_limit', $c->per_customer_limit ?? '') }}" class="input"></div>
                <div><label class="label">Expires at</label><input name="expires_at" type="date" value="{{ old('expires_at', $c?->expires_at?->format('Y-m-d')) }}" class="input"></div>
            </div>

            {{-- Applies itself, and to whom. A coupon left as "typed only"
                 behaves exactly as it always has. --}}
            <div class="rounded-lg border border-ink-100 p-3 space-y-3">
                <label class="flex items-center gap-2 text-sm font-medium">
                    <input type="checkbox" name="auto_apply" value="1" x-model="auto"
                           @checked(old('auto_apply', $c->auto_apply ?? false))>
                    Apply automatically — no code to type
                </label>

                <div x-show="auto" x-cloak class="space-y-3">
                    <div>
                        <label class="label">Who gets it</label>
                        <select name="audience" x-model="audience" class="input">
                            @foreach(\App\Models\Coupon::AUDIENCES as $key => $label)
                                <option value="{{ $key }}" @selected(old('audience', $c->audience ?? 'all') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div x-show="audience === 'rule'" x-cloak class="space-y-2 pl-1 border-l-2 border-ink-100">
                        <label class="flex items-center gap-2 text-sm pl-2">
                            <input type="checkbox" name="audience_rules[first_order_only]" value="1"
                                   @checked(old('audience_rules.first_order_only', data_get($c?->audience_rules, 'first_order_only')))>
                            Only on someone's first order
                        </label>
                        <label class="flex items-center gap-2 text-sm pl-2">
                            <input type="checkbox" name="audience_rules[members_only]" value="1"
                                   @checked(old('audience_rules.members_only', data_get($c?->audience_rules, 'members_only')))>
                            Only customers with an account
                        </label>
                        <div class="grid grid-cols-3 gap-2 pl-2">
                            <div><label class="label text-xs">Min past orders</label><input name="audience_rules[min_orders]" type="number" min="1" value="{{ old('audience_rules.min_orders', data_get($c?->audience_rules, 'min_orders')) }}" class="input"></div>
                            <div><label class="label text-xs">Min spent ৳</label><input name="audience_rules[min_spend]" type="number" min="0" step="1" value="{{ old('audience_rules.min_spend', data_get($c?->audience_rules, 'min_spend')) }}" class="input"></div>
                            <div><label class="label text-xs">Quiet for days</label><input name="audience_rules[lapsed_days]" type="number" min="1" value="{{ old('audience_rules.lapsed_days', data_get($c?->audience_rules, 'lapsed_days')) }}" class="input"></div>
                        </div>
                        <p class="text-[11px] text-ink-700/50 pl-2">Matched on the phone entered at checkout, so it reaches guests too. Leave a box empty to ignore it.</p>
                    </div>

                    <p x-show="audience === 'phones'" x-cloak class="text-[11px] text-ink-700/50">
                        @if($editing) Add the numbers in the list below. @else Save the coupon first, then add the numbers. @endif
                    </p>
                    <p x-show="audience === 'all'" x-cloak class="text-[11px] text-amber-700">
                        Every order gets this. Set a total usage limit or an expiry unless you mean it to run forever.
                    </p>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $c->is_active ?? true))> Active</label>
            <button class="btn-primary w-full">{{ $editing ? 'Save changes' : 'Create coupon' }}</button>
        </form>
    </div>

    <div class="lg:col-span-2 card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-ink-50 text-left text-xs uppercase tracking-wide text-ink-700/60">
                <tr><th class="px-4 py-3">Code</th><th class="px-4 py-3">Discount</th><th class="px-4 py-3">Scope</th><th class="px-4 py-3">Used</th><th class="px-4 py-3">Active</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-ink-100">
                @forelse($coupons as $cp)
                    <tr class="{{ $editing && $editing->id === $cp->id ? 'bg-gold-50' : '' }}">
                        <td class="px-4 py-3 font-medium">{{ $cp->code }}@if($cp->free_shipping)<span class="ml-1 badge bg-blue-100 text-blue-700 text-[10px]">+ship</span>@endif @if($cp->auto_apply)<span class="ml-1 badge bg-gold-100 text-gold-800 text-[10px]" title="{{ \App\Models\Coupon::AUDIENCES[$cp->audience] ?? 'Every order' }}">auto</span>@endif</td>
                        <td class="px-4 py-3">{{ $cp->type=='percent' ? rtrim(rtrim(number_format($cp->value,2),'0'),'.').'%' : money($cp->value) }}@if($cp->min_order)<span class="text-xs text-ink-700/50"> (min {{ money($cp->min_order) }})</span>@endif</td>
                        <td class="px-4 py-3 text-xs text-ink-700/70">
                            @switch($cp->applies_to)
                                @case('categories') {{ count($cp->category_ids ?? []) }} categor{{ count($cp->category_ids ?? [])==1?'y':'ies' }} @break
                                @case('products') {{ count($cp->product_ids ?? []) }} product(s) @break
                                @default Entire cart
                            @endswitch
                        </td>
                        <td class="px-4 py-3">{{ $cp->used_count }}{{ $cp->usage_limit ? '/'.$cp->usage_limit : '' }}</td>
                        <td class="px-4 py-3">{!! $cp->is_active ? '<span class="badge bg-green-100 text-green-700">Yes</span>' : '<span class="badge bg-ink-100 text-ink-700">No</span>' !!}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.coupons.index', ['edit' => $cp->id]) }}" class="text-gold-700 hover:underline mr-3">Edit</a>
                            <form action="{{ route('admin.coupons.destroy', $cp) }}" method="POST" class="inline" onsubmit="return confirm('Delete coupon?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Delete</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-ink-700/50">No coupons yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-6">{{ $coupons->links() }}</div>

{{-- Who an auto-applying coupon is waiting for. Only while editing one: the
     list belongs to a coupon that exists. --}}
@if($editing && $editing->audience === 'phones')
    <div class="card p-6 mt-6">
        <h2 class="font-semibold mb-1">Who gets {{ $editing->code }}</h2>
        <p class="text-xs text-ink-700/55 mb-4">
            Matched on the phone number entered at checkout — the customer types nothing.
            Numbers that have never ordered are kept: the coupon waits for them.
        </p>

        <div class="grid md:grid-cols-3 gap-4">
            <form action="{{ route('admin.coupons.recipients.add', $editing) }}" method="POST" class="md:col-span-2 space-y-2">
                @csrf
                <input type="hidden" name="source" value="paste">
                <label class="label">Paste phone numbers</label>
                <textarea name="phones" rows="5" class="input font-mono text-xs"
                          placeholder="01712345678&#10;Nadia 01812345678&#10;01912345678, 01612345678"></textarea>
                <p class="text-[11px] text-ink-700/50">One per line or comma-separated. A name beside the number is kept for your reference.</p>
                <button class="btn-primary">Add these numbers</button>
            </form>

            <div class="space-y-3">
                <form action="{{ route('admin.coupons.recipients.add', $editing) }}" method="POST" class="space-y-2">
                    @csrf
                    <input type="hidden" name="source" value="segment">
                    <label class="label">…or a saved group</label>
                    <select name="segment_id" class="input">
                        @forelse(\App\Models\CustomerSegment::orderBy('name')->get() as $seg)
                            <option value="{{ $seg->id }}">{{ $seg->name }}</option>
                        @empty
                            <option value="">No groups yet</option>
                        @endforelse
                    </select>
                    <button class="btn-outline w-full text-sm">Add the whole group</button>
                </form>

                <form action="{{ route('admin.coupons.recipients.add', $editing) }}" method="POST"
                      onsubmit="return confirm('Add every past buyer to this coupon?')">
                    @csrf
                    <input type="hidden" name="source" value="buyers">
                    <button class="btn-outline w-full text-sm">Add everyone who has bought before</button>
                </form>
            </div>
        </div>

        @php $recipients = $editing->recipients()->latest('id')->paginate(50, ['*'], 'recipients'); @endphp
        <div class="mt-5">
            <p class="text-sm font-medium mb-2">{{ number_format($recipients->total()) }} on the list</p>
            @if($recipients->total())
                <div class="flex flex-wrap gap-2">
                    @foreach($recipients as $r)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-ink-50 border border-ink-100 px-2.5 py-1 text-xs">
                            {{ $r->name ? $r->name.' · ' : '' }}{{ $r->phone }}
                            <form action="{{ route('admin.coupons.recipients.remove', [$editing, $r]) }}" method="POST" class="inline">
                                @csrf @method('DELETE')
                                <button class="text-red-600 hover:text-red-700" title="Remove" aria-label="Remove {{ $r->phone }}">&times;</button>
                            </form>
                        </span>
                    @endforeach
                </div>
                <div class="mt-3">{{ $recipients->appends(['edit' => $editing->id])->links() }}</div>
            @else
                <p class="text-sm text-ink-700/50">Nobody yet — this coupon will not apply to anyone until you add a number.</p>
            @endif
        </div>
    </div>
@endif
@endsection
