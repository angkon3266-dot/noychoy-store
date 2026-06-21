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
        .label { border: 1px dashed #bbb; padding: 3mm; display: flex; flex-direction: column; font-size: 10px; overflow: hidden; }
        .label .top { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #000; padding-bottom: 1mm; }
        .label .brand { font-weight: bold; font-size: 12px; }
        .label .cod { font-weight: bold; font-size: 12px; border: 1.5px solid #000; padding: 0 4px; border-radius: 3px; }
        .label .to { margin-top: 1.5mm; line-height: 1.35; }
        .label .to .name { font-weight: bold; font-size: 11px; }
        .label .barcode { margin-top: auto; text-align: center; }
        .label .barcode svg { max-width: 100%; height: 11mm; }
        .label .meta { display: flex; justify-content: space-between; font-size: 8px; color: #444; }

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
                <div class="label">
                    <div class="top">
                        <span class="brand">{{ \App\Models\Setting::get('store_name', config('store.name')) }}</span>
                        <span class="cod">COD ৳{{ number_format((float) $order->total, 0) }}</span>
                    </div>
                    <div class="to">
                        <div class="name">{{ $order->customer_name }}</div>
                        <div>{{ $order->customer_phone }}</div>
                        <div>{{ $order->shipping_address }}{{ $order->area ? ', '.$order->area : '' }}{{ $order->district ? ', '.$order->district : '' }}</div>
                    </div>
                    <div class="meta">
                        <span>Inv: {{ $order->order_number }}</span>
                        <span>{{ $order->total_quantity }} item(s)</span>
                    </div>
                    <div class="barcode">
                        <svg class="bc" data-code="{{ $order->shipment->tracking_code ?: $order->shipment->consignment_id }}"></svg>
                        <div style="font-size:8px">{{ $order->shipment->tracking_code ?: $order->shipment->consignment_id }}</div>
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
