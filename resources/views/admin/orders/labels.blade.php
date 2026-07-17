<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Shipping labels</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; background: #f3f4f6; }
        .toolbar { padding: 12px 16px; background: #fff; border-bottom: 1px solid #ddd; position: sticky; top: 0; z-index: 5; }
        .toolbar button { background: #9a6c2e; color: #fff; border: 0; padding: 8px 18px; border-radius: 6px; font-size: 14px; cursor: pointer; }
        .toolbar a { margin-left: 12px; color: #555; font-size: 13px; }

        /* 2 columns; labels grow to fit all items and never split across a page. */
        .sheet { width: 210mm; margin: 10px auto; background: #fff; padding: 6mm; display: grid;
                 grid-template-columns: 1fr 1fr; gap: 4mm; align-content: start; }
        .label { border: 1px dashed #999; border-radius: 4px; padding: 3mm; font-size: 9px; line-height: 1.3;
                 break-inside: avoid; page-break-inside: avoid; display: flex; flex-direction: column; min-height: 46mm; }
        .label .top { display: flex; justify-content: space-between; align-items: center; border-bottom: 1.5px solid #000; padding-bottom: 1mm; }
        .label .brand { font-weight: bold; font-size: 12px; }
        .label .cod { font-weight: bold; font-size: 12px; border: 1.5px solid #000; padding: 1px 5px; border-radius: 3px; }
        .label .cid { font-weight: bold; font-size: 13px; letter-spacing: 0.5px; margin-top: 1mm; }
        .label .to { margin-top: 1mm; }
        .label .to .name { font-weight: bold; font-size: 11px; }
        .label .items { margin-top: 1.5mm; border-top: 1px dotted #999; padding-top: 1mm; flex: 1; }
        .label .item { display: flex; align-items: center; gap: 1.5mm; padding: 0.6mm 0; border-bottom: 1px dotted #eee; }
        .label .item:last-child { border-bottom: 0; }
        .label .item img { width: 9mm; height: 9mm; object-fit: cover; border: 1px solid #ccc; border-radius: 2px; flex: 0 0 auto; background: #f3f4f6; }
        .label .item .ph { width: 9mm; height: 9mm; border: 1px solid #ddd; border-radius: 2px; flex: 0 0 auto; background: #f3f4f6; }
        .label .item .desc { flex: 1; min-width: 0; }
        .label .item .nm { font-weight: 600; }
        .label .item .sn { display: inline-block; background: #000; color: #fff; border-radius: 2px; padding: 0 3px; font-size: 8px; }
        .label .item .var { color: #555; }
        .label .item .qp { white-space: nowrap; text-align: right; font-weight: 600; }
        .label .note { margin-top: 1mm; font-style: italic; color: #333; }
        .label .barcode { margin-top: 1.5mm; text-align: center; }
        .label .barcode svg { max-width: 100%; height: 10mm; }

        @media print {
            .toolbar { display: none; }
            body { background: #fff; }
            .sheet { margin: 0; box-shadow: none; }
            @page { size: A4; margin: 6mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">🖨 Print {{ $orders->count() }} label(s)</button>
        <a href="{{ url()->previous() }}">← Back</a>
        <span style="font-size:13px;color:#888;margin-left:12px">2 labels per row · each label lists every item with photo, variation, qty &amp; price. Only orders with a Steadfast consignment are shown.</span>
    </div>

    @if($orders->isEmpty())
        <div class="sheet"><p style="grid-column:1/3;padding:20px;text-align:center;color:#888">No orders with a courier consignment to print.</p></div>
    @endif

    <div class="sheet">
        @foreach($orders as $order)
            @php $cod = (float) ($order->shipment->cod_amount ?? $order->total); @endphp
            <div class="label">
                <div class="top">
                    <span class="brand">{{ store_name() }}</span>
                    <span class="cod">COD ৳{{ number_format($cod, 0) }}</span>
                </div>
                <div class="cid">CID: {{ $order->shipment->consignment_id }}</div>
                <div class="to">
                    <div class="name">{{ $order->customer_name }} · {{ $order->customer_phone }}</div>
                    <div>{{ $order->shipping_address }}{{ $order->area ? ', '.$order->area : '' }}{{ $order->district ? ', '.$order->district : '' }}</div>
                </div>

                <div class="items">
                    @foreach($order->items as $item)
                        @php $var = collect($item->attributes ?? [])->filter()->implode(', '); @endphp
                        <div class="item">
                            @if($item->product?->thumbnail)
                                <img src="{{ $item->product->thumbnail }}" alt="">
                            @else
                                <span class="ph"></span>
                            @endif
                            <span class="desc">
                                <span class="nm">@if($item->product?->serial)<span class="sn">#{{ $item->product->serial }}</span> @endif{{ \Illuminate\Support\Str::limit($item->name, 34) }}</span>
                                @if($var)<br><span class="var">{{ $var }}</span>@endif
                            </span>
                            <span class="qp">×{{ $item->quantity }}<br>৳{{ number_format((float) $item->price, 0) }}</span>
                        </div>
                    @endforeach
                </div>

                @if($order->notes)<div class="note">📝 {{ \Illuminate\Support\Str::limit($order->notes, 90) }}</div>@endif

                <div class="barcode">
                    <svg class="bc" data-code="{{ $order->shipment->tracking_code ?: $order->shipment->consignment_id }}"></svg>
                    <div style="font-size:8px">Inv {{ $order->order_number }} · {{ $order->shipment->tracking_code ?: $order->shipment->consignment_id }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <script>
        document.querySelectorAll('svg.bc').forEach(function (el) {
            try { JsBarcode(el, el.dataset.code || ' ', { format: 'CODE128', displayValue: false, margin: 0, height: 40 }); } catch (e) {}
        });
    </script>
</body>
</html>
