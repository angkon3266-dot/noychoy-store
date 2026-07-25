<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Thank-you cards</title>
    @php $logo = theme_asset(theme('logo')) ?: theme_asset(theme('logo_mobile')); @endphp
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Georgia, 'Times New Roman', serif; color: #161618; background: #f3f4f6; }
        .toolbar { padding: 12px 16px; background: #fff; border-bottom: 1px solid #ddd; position: sticky; top: 0; z-index: 5;
                   display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-family: Arial, Helvetica, sans-serif; }
        .toolbar button { background: #9a6c2e; color: #fff; border: 0; padding: 8px 18px; border-radius: 6px; font-size: 14px; cursor: pointer; }
        .toolbar select { padding: 7px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 13px; }
        .toolbar a { color: #555; font-size: 13px; }
        .toolbar .count { color: #777; font-size: 13px; margin-left: auto; }

        /* A4 sheet holding 3 × 4 cards of exactly 60 × 60 mm. */
        .sheet { width: 210mm; margin: 10px auto; background: #fff; padding: 8mm 12mm; display: grid;
                 grid-template-columns: repeat(3, 60mm); gap: 4mm; justify-content: center; align-content: start; }
        .card { width: 60mm; height: 60mm; border: 1px dashed #bbb; padding: 6mm 5mm;
                display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
                break-inside: avoid; page-break-inside: avoid; overflow: hidden; }
        .card .logo { max-height: 11mm; max-width: 42mm; object-fit: contain; margin-bottom: 3.5mm; }
        .card .brand { font-size: 12pt; letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 3.5mm; }
        .card .msg { font-size: 9.5pt; line-height: 1.5; white-space: pre-line; }
        /* Long messages shrink rather than overflow the 60 mm card. */
        .card .msg.long { font-size: 8.5pt; line-height: 1.42; }
        .card .msg.xlong { font-size: 7.5pt; line-height: 1.35; }

        @media print {
            .toolbar { display: none; }
            body { background: #fff; }
            .sheet { width: auto; margin: 0; padding: 0; gap: 0; grid-template-columns: repeat(3, 60mm); justify-content: start; }
            .card { border: 0; }
            @page { size: A4; margin: 8mm; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">🖨 Print</button>
    <form method="GET" style="display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="ids" value="{{ request('ids') }}">
        <label style="font-size:13px;color:#555;">Message:</label>
        <select name="template" onchange="this.form.submit()">
            <option value="">Automatic (new vs repeat customer)</option>
            @foreach($templates as $t)
                <option value="{{ $t['name'] }}" @selected($forced === $t['name'])>{{ $t['name'] }}</option>
            @endforeach
        </select>
    </form>
    <a href="{{ route('admin.orders.card-templates') }}">Edit templates</a>
    <span class="count">{{ $cards->count() }} card{{ $cards->count() === 1 ? '' : 's' }} · 60 × 60 mm · 12 per A4</span>
</div>

<div class="sheet">
    @forelse($cards as $c)
        @php
            $len = mb_strlen($c['text']);
            $size = $len > 220 ? 'xlong' : ($len > 130 ? 'long' : '');
        @endphp
        <div class="card">
            @if($logo)
                <img class="logo" src="{{ $logo }}" alt="{{ store_name() }}">
            @else
                <div class="brand">{{ store_name() }}</div>
            @endif
            <div class="msg {{ $size }}">{{ $c['text'] }}</div>
        </div>
    @empty
        <p style="grid-column:1/-1;padding:20mm;text-align:center;color:#777;">No orders selected.</p>
    @endforelse
</div>
</body>
</html>
