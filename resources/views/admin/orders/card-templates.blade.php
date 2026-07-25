@extends('layouts.admin')
@section('title', 'Thank-you card templates')
@section('heading', 'Thank-you card messages')

@section('content')
<div class="max-w-3xl">
    <p class="text-sm text-ink-700/70 mb-4">
        Messages printed on the cards you slip into parcels. Each card shows your logo and the message —
        nothing else. Pick which message is used automatically for first-time buyers and for returning customers;
        you can override it at print time, or rewrite any single customer's card on the print preview.
        <a href="{{ route('admin.orders.cards') }}" target="_blank" class="text-gold-700 underline">Preview / print</a>
    </p>

    <form action="{{ route('admin.orders.card-templates.save') }}" method="POST"
          x-data="{ rows: {{ Js::from(array_values($templates)) }},
                    defNew: {{ Js::from($defaultNew) }},
                    defRepeat: {{ Js::from($defaultRepeat) }},
                    add() { this.rows.push({ name: '', text: '' }); } }">
        @csrf

        <div class="card p-5 mb-4">
            <h2 class="font-semibold text-sm mb-3">Message library</h2>
            <template x-for="(r, i) in rows" :key="i">
                <div class="rounded-lg border border-ink-100 p-3 mb-3">
                    <div class="flex items-center gap-2 mb-2">
                        <input :name="`templates[${i}][name]`" x-model="r.name" class="input py-1.5 text-sm w-56" placeholder="Template name (e.g. Eid special)">
                        <button type="button" @click="rows.splice(i, 1)" x-show="rows.length > 1"
                                class="ml-auto text-red-500 text-lg px-2" title="Remove">&times;</button>
                    </div>
                    <textarea :name="`templates[${i}][text]`" x-model="r.text" rows="4"
                              class="input text-sm" placeholder="Thank you for your order…"></textarea>
                    <p class="text-[11px] text-ink-700/45 mt-1">
                        Personalise it: <code>&#123;name&#125;</code> prints the customer's name, <code>&#123;store&#125;</code> your store name,
                        <code>&#123;order_number&#125;</code> their order number — e.g. <em>“Dear &#123;name&#125;, thank you for your order…”</em>.
                        Keep it under ~200 characters so it stays comfortable on a small card.
                    </p>
                </div>
            </template>
            <button type="button" @click="add()" class="btn-outline py-1.5 text-xs">+ Add template</button>
        </div>

        <div class="card p-5 mb-4">
            <h2 class="font-semibold text-sm mb-1">Defaults</h2>
            <p class="text-xs text-ink-700/60 mb-3">Used automatically when you print cards, based on whether the buyer has ordered before.</p>
            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">New customer (first order)</label>
                    <select name="default_new" x-model="defNew" class="input">
                        <template x-for="r in rows.filter(r => r.name)" :key="r.name">
                            <option :value="r.name" x-text="r.name"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="label">Repeat customer</label>
                    <select name="default_repeat" x-model="defRepeat" class="input">
                        <template x-for="r in rows.filter(r => r.name)" :key="r.name">
                            <option :value="r.name" x-text="r.name"></option>
                        </template>
                    </select>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-gold-200 bg-gold-50/60 p-4 mb-4 text-sm text-ink-700/80">
            🎨 Card <strong>size, font, spacing, border and colours</strong> live in
            <a href="{{ route('admin.appearance') }}#cards" class="text-gold-700 underline font-medium">Appearance → Cards &amp; print</a>,
            with a live preview. Currently {{ $size['w'] }} × {{ $size['h'] }} mm.
        </div>

        <button class="btn-primary">Save messages</button>
    </form>
</div>
@endsection
