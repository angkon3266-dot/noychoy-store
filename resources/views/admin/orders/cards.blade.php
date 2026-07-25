<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Thank-you cards</title>
    @php
        $logo = theme_asset(theme('logo')) ?: theme_asset(theme('logo_mobile'));
        $w = $size['w']; $h = $size['h'];
        // A4 minus the 8 mm print margin (210×297 → 194×281 usable), then as
        // many whole cards as fit across and down.
        $gap = 4;
        $cols = max(1, (int) floor((194 + $gap) / ($w + $gap)));
        $rows = max(1, (int) floor((281 + $gap) / ($h + $gap)));
        $perSheet = $cols * $rows;
        // Type scales with the card so a 40 mm and a 100 mm card both read well.
        $base = round(min($w, $h) * 0.16, 1);
    @endphp
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Georgia, 'Times New Roman', serif; color: #161618; background: #f3f4f6; }
        .toolbar { padding: 12px 16px; background: #fff; border-bottom: 1px solid #ddd; position: sticky; top: 0; z-index: 5;
                   display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-family: Arial, Helvetica, sans-serif; }
        .toolbar button { background: #9a6c2e; color: #fff; border: 0; padding: 8px 18px; border-radius: 6px; font-size: 14px; cursor: pointer; }
        .toolbar button.ghost { background: #eee; color: #333; }
        .toolbar button[disabled] { opacity: .5; cursor: default; }
        .toolbar select { padding: 7px 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 13px; }
        .toolbar a { color: #555; font-size: 13px; }
        .toolbar .count { color: #777; font-size: 13px; margin-left: auto; }
        .hint { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #777; text-align: center; margin: 8px; }

        /* A4 sheet holding as many cards of the configured size as fit. */
        .sheet { width: 210mm; margin: 10px auto; background: #fff; padding: 8mm; display: grid;
                 grid-template-columns: repeat({{ $cols }}, {{ $w }}mm); gap: {{ $gap }}mm; justify-content: center; align-content: start; }
        .card { width: {{ $w }}mm; height: {{ $h }}mm; border: 1px dashed #bbb; padding: 6mm 5mm;
                display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;
                break-inside: avoid; page-break-inside: avoid; overflow: hidden; position: relative; }
        .card .logo { max-height: {{ round($h * 0.18, 1) }}mm; max-width: {{ round($w * 0.7, 1) }}mm; object-fit: contain; margin-bottom: 3.5mm; }
        .card .brand { font-size: {{ $base + 2.5 }}pt; letter-spacing: 0.06em; text-transform: uppercase; margin-bottom: 3.5mm; }
        .card .msg { font-size: {{ $base }}pt; line-height: 1.5; white-space: pre-line; width: 100%; outline: none; }
        /* Long messages shrink rather than overflow the card. */
        .card .msg.long { font-size: {{ round($base * 0.9, 1) }}pt; line-height: 1.42; }
        .card .msg.xlong { font-size: {{ round($base * 0.79, 1) }}pt; line-height: 1.35; }
        .card .who { position: absolute; top: 1.5mm; left: 0; right: 0; font-family: Arial, Helvetica, sans-serif;
                     font-size: 7pt; color: #b9b9b9; }
        .card .msg:focus { background: #fffbe9; }
        .card.edited .who::after { content: ' · edited'; color: #9a6c2e; }

        @media print {
            .toolbar, .hint, .who { display: none; }
            body { background: #fff; }
            .sheet { width: auto; margin: 0; padding: 0; gap: 0; grid-template-columns: repeat({{ $cols }}, {{ $w }}mm); justify-content: start; }
            .card { border: 0; }
            .card .msg:focus { background: none; }
            @page { size: A4; margin: 8mm; }
        }
    </style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">🖨 Print</button>
    <button class="ghost" id="save" disabled>Save messages</button>
    <form method="GET" style="display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="ids" value="{{ request('ids') }}">
        <label style="font-size:13px;color:#555;">Message:</label>
        <select name="template" onchange="this.form.submit()">
            <option value="">Automatic (per-order / new vs repeat)</option>
            @foreach($templates as $t)
                <option value="{{ $t['name'] }}" @selected($forced === $t['name'])>{{ $t['name'] }}</option>
            @endforeach
        </select>
    </form>
    <a href="{{ route('admin.orders.card-templates') }}">Templates &amp; size</a>
    <span class="count">{{ $cards->count() }} card{{ $cards->count() === 1 ? '' : 's' }} · {{ $w }} × {{ $h }} mm · {{ $perSheet }} per A4</span>
</div>

<p class="hint">Click any message to edit it for that customer, then “Save messages”. Clear it to fall back to the default template.</p>

<div class="sheet">
    @forelse($cards as $c)
        @php
            $len = mb_strlen($c['text']);
            $limitLong = (int) ($w * $h / 26);      // roughly how much text the card fits
            $cls = $len > $limitLong * 1.6 ? 'xlong' : ($len > $limitLong ? 'long' : '');
        @endphp
        <div class="card {{ $c['custom'] ? 'edited' : '' }}" data-order="{{ $c['order']->id }}">
            <span class="who">{{ $c['order']->order_number }} · {{ \Illuminate\Support\Str::limit($c['order']->customer_name, 18) }}</span>
            @if($logo)
                <img class="logo" src="{{ $logo }}" alt="{{ store_name() }}">
            @else
                <div class="brand">{{ store_name() }}</div>
            @endif
            <div class="msg {{ $cls }}" contenteditable="true" spellcheck="false">{{ $c['text'] }}</div>
        </div>
    @empty
        <p style="grid-column:1/-1;padding:20mm;text-align:center;color:#777;">No orders selected.</p>
    @endforelse
</div>

<script>
(function () {
    var saveBtn = document.getElementById('save');
    var dirty = {};

    function refresh() {
        var n = Object.keys(dirty).length;
        saveBtn.disabled = n === 0;
        saveBtn.textContent = n === 0 ? 'Save messages' : 'Save ' + n + ' message(s)';
    }

    document.querySelectorAll('.card .msg').forEach(function (el) {
        var original = el.innerText;
        el.addEventListener('input', function () {
            var id = el.closest('.card').dataset.order;
            if (el.innerText === original) { delete dirty[id]; } else { dirty[id] = el.innerText; }
            refresh();
        });
        // Paste as plain text so pasted styling can't leak into the print.
        el.addEventListener('paste', function (e) {
            e.preventDefault();
            document.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text'));
        });
    });

    saveBtn.addEventListener('click', function () {
        var body = new FormData();
        var sent = Object.keys(dirty);
        sent.forEach(function (id) { body.append('messages[' + id + ']', dirty[id]); });
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';

        fetch(@json(route('admin.orders.cards.messages')), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
            body: body,
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (j.ok) {
                sent.forEach(function (id) {
                    var card = document.querySelector('.card[data-order="' + id + '"]');
                    if (card) { card.classList.toggle('edited', card.querySelector('.msg').innerText.trim() !== ''); }
                });
                dirty = {};
                saveBtn.textContent = '✓ Saved';
            } else {
                saveBtn.textContent = 'Save failed';
                saveBtn.disabled = false;
            }
        }).catch(function () {
            saveBtn.textContent = 'Save failed';
            saveBtn.disabled = false;
        });
    });
})();
</script>
</body>
</html>
