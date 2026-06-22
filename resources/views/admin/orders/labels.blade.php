<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Shipping labels</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; background: #f3f4f6; }
        .toolbar { padding: 12px 16px; background: #fff; border-bottom: 1px solid #ddd; position: sticky; top: 0; }
        .toolbar button { background: #9a6c2e; color: #fff; border: 0; padding: 8px 18px; border-radius: 6px; font-size: 14px; cursor: pointer; }
        .toolbar a { margin-left: 12px; color: #555; font-size: 13px; }

        /* 2 columns x 7 rows = 14 labels per A4 page */
        .sheet { width: 210mm; margin: 10px auto; background: #fff; padding: 5mm; display: grid;
                 grid-template-columns: 1fr 1fr; grid-auto-rows: 39mm; gap: 2mm; }
        .label { border: 1px dashed #bbb; padding: 2.5mm; display: flex; flex-direction: column; font-size: 9px; line-height: 1.25; overflow: hidden; }
        .label .top { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #000; padding-bottom: 0.8mm; }
        .label .brand { font-weight: bold; font-size: 11px; }
        .label .cod { font-weight: bold; font-size: 11px; border: 1.5px solid #000; padding: 0 4px; border-radius: 3px; }
        .label .cid { font-weight: bold; font-size: 12px; letter-spacing: 0.5px; margin-top: 0.6mm; }
        .label .to { margin-top: 0.8mm; }
        .label .to .name { font-weight: bold; font-size: 10px; }
        .label .items { margin-top: 0.8mm; border-top: 1px dotted #999; padding-top: 0.6mm; }
        .label .items .row { display: flex; align-items: center; gap: 1.5mm; }
        .label .items img { width: 7mm; height: 7mm; object-fit: cover; border: 1px solid #ccc; border-radius: 2px; flex: 0 0 auto; }
        .label .items .desc { flex: 1; min-width: 0; }
        .label .items .var { color: #555; }
        .label .note { margin-top: 0.6mm; font-style: italic; color: #333; }
        .label .barcode { margin-top: auto; text-align: center; }
        .label .barcode svg { max-width: 100%; height: 9mm; }

        @media print {
            .toolbar { display: none; }
            body { background: #fff; }
            .sheet { margin: 0; box-shadow: none; page-break-after: always; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">🖨 Print {{ $orders->count() }} label(s)</button>
        <a href="{{ url()->previous() }}">← Back</a>
        <span style="font-size:13px;color:#888;margin-left:12px">14 labels per A4 page · only orders with a Steadfast consignment are shown.</span>
    </div>

    @if($orders->isEmpty())
        <div class="sheet"><p style="grid-column:1/3;padding:20px;text-align:center;color:#888">No orders with a courier consignment to print.</p></div>
    @endif

    @foreach($orders->chunk(14) as $page)
        <div class="sheet">
            @foreach($page as $order)
                @php $cod = (float) ($order->shipment->cod_amount ?? $order->total); @endphp
                <div class="label">
                    <div class="top">
                        <span class="brand">{{ \App\Models\Setting::get('store_name', config('store.name')) }}</span>
                        <span class="cod">COD ৳{{ number_format($cod, 0) }}</span>
                    </div>
                    <div class="cid">CID: {{ $order->shipment->consignment_id }}</div>
                    <div class="to">
                        <div class="name">{{ $order->customer_name }} · {{ $order->customer_phone }}</div>
                        <div>{{ $order->shipping_address }}{{ $order->area ? ', '.$order->area : '' }}{{ $order->district ? ', '.$order->district : '' }}</div>
                    </div>
                    <div class="items">
                        @foreach($order->items->take(2) as $i => $item)
                            @php $var = collect($item->attributes ?? [])->filter()->implode(', '); @endphp
                            <div class="row">
                                @if($i === 0 && $item->product?->thumbnail)
                                    <img src="{{ $item->product->thumbnail }}" alt="">
                                @endif
                                <span class="desc">
                                    {{ \Illuminate\Support\Str::limit($item->name, 28) }}
                                    @if($var)<span class="var">({{ $var }})</span>@endif
                                    × {{ $item->quantity }} · ৳{{ number_format((float) $item->price, 0) }}
                                </span>
                            </div>
                        @endforeach
                        @if($order->items->count() > 2)<div class="var">+{{ $order->items->count() - 2 }} more item(s)</div>@endif
                    </div>
                    @if($order->notes)<div class="note">📝 {{ \Illuminate\Support\Str::limit($order->notes, 70) }}</div>@endif
                    <div class="barcode">
                        <svg class="bc" data-code="{{ $order->shipment->tracking_code ?: $order->shipment->consignment_id }}"></svg>
                        <div style="font-size:8px">Inv {{ $order->order_number }} · {{ $order->shipment->tracking_code ?: $order->shipment->consignment_id }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach

    <script>
        document.querySelectorAll('svg.bc').forEach(function (el) {
            try { JsBarcode(el, el.dataset.code || ' ', { format: 'CODE128', displayValue: false, margin: 0, height: 40 }); } catch (e) {}
        });
    </script>
</body>
</html>
