@php
    $cur = $order->currency ?: 'USD';
    $money = fn ($v) => $cur.' '.number_format((float) $v, 2);
    $subtotal = $order->itemsSubtotal();
    $courier = (float) $order->courier_cost;
    $pct = (float) $order->processing_pct;
    $processing = ($subtotal + $courier) * $pct / 100;
    $s = $order->supplier;
    // dompdf has no per-glyph font fallback and DejaVu has no CJK, so any typed-in
    // text containing Chinese/Japanese/Korean is set whole in Noto Sans SC.
    $cjk ??= false;
    $t = function ($value) use ($cjk) {
        $html = e((string) $value);

        return $cjk && \App\Services\PurchaseOrderPdf::hasCjk((string) $value)
            ? '<span class="cjk">'.$html.'</span>' : $html;
    };
    $font = fn ($file) => str_replace('\\', '/', resource_path('fonts/'.$file));
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $order->po_number }}</title>
<style>
    @if($cjk)
    @font-face { font-family: 'Noto Sans SC'; font-weight: normal; src: url('{{ $font('NotoSansSC-Regular.ttf') }}') format('truetype'); }
    @font-face { font-family: 'Noto Sans SC'; font-weight: bold; src: url('{{ $font('NotoSansSC-Bold.ttf') }}') format('truetype'); }
    @endif
    @page { margin: 34px 38px 54px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    .cjk, .cjk * { font-family: 'Noto Sans SC'; }
    body { font-size: 9.5px; color: #161618; line-height: 1.45; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    .muted { color: #6b6b70; }
    .gold { color: #7a5326; }
    .right { text-align: right; }
    .center { text-align: center; }
    .nowrap { white-space: nowrap; }

    .head td { padding: 0; }
    .brand { font-size: 20px; font-weight: bold; letter-spacing: 0.5px; }
    .doc-title { font-size: 22px; font-weight: bold; letter-spacing: 2px; color: #7a5326; }
    .meta td { padding: 1.5px 0 1.5px 12px; }
    .rule { border-top: 2px solid #161618; margin: 14px 0 12px; }

    .party { width: 49%; border: 1px solid #e3ddd3; padding: 9px 11px; }
    .party h3 { margin: 0 0 4px; font-size: 8px; letter-spacing: 1.2px; text-transform: uppercase; color: #7a5326; }
    .party .name { font-size: 11px; font-weight: bold; }
    .pre { white-space: pre-line; }

    .items { margin-top: 14px; }
    .items th { background: #161618; color: #fff; font-size: 8px; letter-spacing: 0.8px; text-transform: uppercase; padding: 6px 7px; text-align: left; }
    .items td { padding: 7px; border-bottom: 1px solid #e9e4dc; }
    .items tr.alt td { background: #faf8f4; }
    .thumb { width: 46px; height: 46px; border: 1px solid #e3ddd3; }
    .thumb-empty { width: 46px; height: 46px; border: 1px solid #e3ddd3; background: #f3f0ea; }
    .pname { font-weight: bold; font-size: 10px; }
    .variants td { border: 0; padding: 0.5px 10px 0.5px 0; background: none !important; font-size: 8.5px; }
    .link { font-size: 7.5px; color: #7a5326; word-break: break-all; }

    .totals { width: 46%; margin-left: 54%; margin-top: 10px; }
    .totals td { padding: 4px 7px; }
    .totals .grand td { border-top: 2px solid #161618; font-size: 12px; font-weight: bold; padding-top: 7px; }

    .block { margin-top: 16px; }
    .block h3 { margin: 0 0 4px; font-size: 8px; letter-spacing: 1.2px; text-transform: uppercase; color: #7a5326; }
    .sign { margin-top: 38px; }
    .sign td { width: 50%; padding-right: 40px; }
    .sign .line { border-top: 1px solid #161618; padding-top: 4px; }

    footer { position: fixed; bottom: -34px; left: 0; right: 0; font-size: 7.5px; color: #8a8a90; }
</style>
</head>
<body>

<footer>
    <table><tr>
        <td>{{ $store['name'] }} · Purchase order {{ $order->po_number }}</td>
        <td class="right">Generated {{ now()->timezone(config('store.timezone'))->format('d M Y, H:i') }}</td>
    </tr></table>
</footer>

{{-- Letterhead --}}
<table class="head">
    <tr>
        <td style="width: 55%">
            @if($store['logo'])
                <img src="{{ $store['logo'] }}" style="max-height: 46px; max-width: 190px;" alt="">
            @else
                <div class="brand">{!! $t($store['name']) !!}</div>
            @endif
            <div class="muted" style="margin-top: 5px;">
                @if($store['logo'])<strong style="color:#161618">{!! $t($store['name']) !!}</strong><br>@endif
                @if($store['address'])<span class="pre">{!! $t($store['address']) !!}</span><br>@endif
                @if($store['phone']){{ $store['phone'] }}@endif
                @if($store['phone'] && $store['email']) · @endif
                @if($store['email']){{ $store['email'] }}@endif
            </div>
        </td>
        <td class="right">
            <div class="doc-title">PURCHASE ORDER</div>
            <table class="meta" style="width: auto; margin-left: auto; margin-top: 6px;">
                <tr><td class="muted right">PO number</td><td class="right"><strong>{{ $order->po_number }}</strong></td></tr>
                <tr><td class="muted right">Order date</td><td class="right">{{ $order->ordered_at?->format('d M Y') ?? '—' }}</td></tr>
                @if($order->expected_at)<tr><td class="muted right">Expected by</td><td class="right">{{ $order->expected_at->format('d M Y') }}</td></tr>@endif
                <tr><td class="muted right">Status</td><td class="right">{{ $order->statusLabel() }}</td></tr>
                <tr><td class="muted right">Currency</td><td class="right">{{ $cur }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="rule"></div>

{{-- Parties --}}
<table>
    <tr>
        <td class="party">
            <h3>Supplier</h3>
            <div class="name">{!! $s ? $t($s->name) : '—' !!}</div>
            @if($s?->contact)<div>Attn: {!! $t($s->contact) !!}</div>@endif
            @if($s?->country)<div class="muted">{!! $t($s->country) !!}</div>@endif
            @if($s?->phone)<div>Tel: {{ $s->phone }}</div>@endif
            @if($s?->email)<div>{{ $s->email }}</div>@endif
            @if($s?->wechat)<div>WeChat: {!! $t($s->wechat) !!}</div>@endif
        </td>
        <td style="width: 2%"></td>
        <td class="party">
            <h3>Ship to</h3>
            @if($order->ship_to)<div class="pre">{!! $t($order->ship_to) !!}</div>@endif
            @if($order->courier_name)<div style="margin-top: 4px;"><span class="muted">Shipping via:</span> {!! $t($order->courier_name) !!}</div>@endif
            @if($order->courier_tracking)<div class="pre" style="margin-top: 4px;">{!! $t($order->courier_tracking) !!}</div>@endif
            @if(! $order->ship_to && ! $order->courier_name && ! $order->courier_tracking)<div class="muted">—</div>@endif
        </td>
    </tr>
</table>

{{-- Lines --}}
<table class="items">
    <thead>
        <tr>
            <th style="width: 18px;">#</th>
            <th style="width: 50px;">Item</th>
            <th>Description</th>
            <th class="right" style="width: 48px;">Qty</th>
            <th class="right" style="width: 78px;">Unit price</th>
            <th class="right" style="width: 86px;">Amount</th>
        </tr>
    </thead>
    <tbody>
        @forelse($order->items as $n => $it)
            <tr class="{{ $n % 2 ? 'alt' : '' }}">
                <td class="muted">{{ $n + 1 }}</td>
                <td>
                    @if(! empty($images[$it->id]))<img src="{{ $images[$it->id] }}" class="thumb" alt="">@else<div class="thumb-empty"></div>@endif
                </td>
                <td>
                    <div class="pname">{!! $t($it->product_name) !!}</div>
                    @if($it->sku)<div class="muted">SKU: {!! $t($it->sku) !!}</div>@endif
                    @if($it->variants)
                        <table class="variants" style="width: auto; margin-top: 3px;">
                            @foreach($it->variants as $v)
                                <tr>
                                    <td>{!! $t(collect($v['attrs'] ?? [])->filter(fn ($val) => filled($val))->map(fn ($val, $k) => "$k: $val")->implode(' · ') ?: 'Default') !!}</td>
                                    <td class="right nowrap"><strong>× {{ (int) ($v['qty'] ?? 0) }}</strong></td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                    @if($it->product_link)<div class="link" style="margin-top: 3px;">{{ \Illuminate\Support\Str::limit($it->product_link, 110) }}</div>@endif
                </td>
                <td class="right nowrap"><strong>{{ number_format($it->qty) }}</strong></td>
                <td class="right nowrap">{{ $money($it->unit_cost) }}</td>
                <td class="right nowrap">{{ $money($it->lineTotal()) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="center muted" style="padding: 18px;">No items on this order.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- Totals --}}
<table class="totals">
    <tr><td class="muted">Subtotal ({{ number_format($order->items->sum('qty')) }} pcs)</td><td class="right nowrap">{{ $money($subtotal) }}</td></tr>
    @if($courier > 0)<tr><td class="muted">Shipping</td><td class="right nowrap">{{ $money($courier) }}</td></tr>@endif
    @if($pct > 0)<tr><td class="muted">Processing fee ({{ rtrim(rtrim(number_format($pct, 2), '0'), '.') }}%)</td><td class="right nowrap">{{ $money($processing) }}</td></tr>@endif
    <tr class="grand"><td>Total</td><td class="right nowrap">{{ $money($order->total_cost) }}</td></tr>
</table>

@if($order->notes)
    <div class="block">
        <h3>Notes &amp; terms</h3>
        <div class="pre">{!! $t($order->notes) !!}</div>
    </div>
@endif

<table class="sign">
    <tr>
        <td><div class="line">Authorised by — {!! $t($store['name']) !!}</div></td>
        <td><div class="line">Accepted by — {!! $s ? $t($s->name) : 'Supplier' !!}</div></td>
    </tr>
</table>

</body>
</html>
