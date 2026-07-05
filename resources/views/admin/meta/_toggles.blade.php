{{-- Sync behaviour toggles. Expects $snapshot (safe settings array). --}}
@php
    $toggles = [
        'auto_sync' => 'Auto-sync on product changes',
        'sync_draft' => 'Sync draft products',
        'sync_out_of_stock' => 'Sync out-of-stock products',
        'sync_hidden' => 'Sync hidden products',
        'sync_images' => 'Sync images',
        'sync_variations' => 'Sync variations',
        'sync_inventory' => 'Sync inventory',
        'sync_price' => 'Sync price',
        'sync_categories' => 'Sync categories',
    ];
@endphp
<div>
    <p class="label mb-2">Sync options</p>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
        @foreach($toggles as $key => $label)
            <label class="flex items-center gap-2 text-sm rounded-lg border border-ink-100 px-3 py-2">
                <input type="checkbox" name="{{ $key }}" value="1" @checked($snapshot[$key] ?? false)>
                {{ $label }}
            </label>
        @endforeach
    </div>
</div>
