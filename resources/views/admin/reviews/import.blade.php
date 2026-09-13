@extends('layouts.admin')
@section('title', 'Import reviews')
@section('heading', 'Import reviews from a spreadsheet')

@section('content')
<a href="{{ route('admin.reviews.index') }}" class="text-sm text-gold-700 hover:underline">← All reviews</a>

@if(session('error'))<div class="mt-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2.5 text-sm">{{ session('error') }}</div>@endif
@if($errors->any())<div class="mt-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2.5 text-sm">{{ $errors->first() }}</div>@endif

<div class="max-w-3xl space-y-6 mt-4">
    <p class="text-sm text-ink-700/70">
        For the whole backlog at once: one sheet, <strong>any number of products</strong>, each row carrying its own
        product, customer, rating and date. In Excel or Google Sheets use <strong>File → Save As / Download → CSV</strong>
        and upload it — or simply select the cells, copy, and paste them into the box below.
    </p>

    <form action="{{ route('admin.reviews.import.store') }}" method="POST" enctype="multipart/form-data" class="card p-6 space-y-4" data-no-lock>
        @csrf
        <div>
            <label class="label">CSV file</label>
            <input type="file" name="file" accept=".csv,text/csv" class="input py-2">
        </div>

        <div class="flex items-center gap-3 text-xs text-ink-700/40">
            <span class="h-px flex-1 bg-ink-100"></span> or paste from the spreadsheet <span class="h-px flex-1 bg-ink-100"></span>
        </div>

        <div>
            <label class="label">Paste rows — header line first</label>
            <textarea name="paste" rows="6" class="input font-mono text-xs"
                      placeholder="product	name	rating	date	review
Kundan Set	Munia	5	14/07/2026	Etto shundor!!
Gold Chain Bracelet	Shayla Rahman	5	01/09/2026	Onnek shundor lagse">{{ old('paste') }}</textarea>
            <p class="text-xs text-ink-700/50 mt-1">Copying cells out of Excel pastes them tab-separated, which is read as-is.</p>
        </div>

        <div class="sm:w-64">
            <label class="label">Show the imported reviews as</label>
            <select name="status" class="input">
                @foreach($statuses as $key => $label)
                    <option value="{{ $key }}" @selected(old('status', 'approved') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <button class="btn-primary">Import reviews</button>
    </form>

    <div class="card p-6">
        <h2 class="font-semibold mb-2">Columns</h2>
        <p class="text-sm text-ink-700/70 mb-3">
            First row must be the header. Only <code>product</code> and <code>name</code> are required —
            a blank rating counts as 5, and a blank date means today.
        </p>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-ink-50 text-left"><tr>
                    <th class="px-2 py-1.5">product*</th><th class="px-2 py-1.5">name*</th><th class="px-2 py-1.5">rating</th>
                    <th class="px-2 py-1.5">date</th><th class="px-2 py-1.5">title</th><th class="px-2 py-1.5">review</th>
                    <th class="px-2 py-1.5">phone</th><th class="px-2 py-1.5">verified</th>
                </tr></thead>
                <tbody><tr class="text-ink-700/70">
                    <td class="px-2 py-1.5">Kundan Set</td><td class="px-2 py-1.5">Munia</td><td class="px-2 py-1.5">5</td>
                    <td class="px-2 py-1.5">14/07/2026</td><td class="px-2 py-1.5">Onek shundor!</td>
                    <td class="px-2 py-1.5">Etao ekdom perfect</td><td class="px-2 py-1.5">01712345678</td><td class="px-2 py-1.5">yes</td>
                </tr></tbody>
            </table>
        </div>
        <ul class="text-xs text-ink-700/60 mt-3 space-y-1 list-disc list-inside">
            <li><strong>product</strong> — the product name exactly as it appears in Products, or its ID, slug or SKU.
                A row naming a product that does not exist is reported back to you, not guessed at.</li>
            <li><strong>date</strong> — <code>2026-07-14</code> or <code>14/07/2026</code>. Slash dates are read
                <strong>day first</strong>, and every date is a Dhaka date.</li>
            <li><strong>verified</strong> — <code>yes</code> puts the ✓ badge on it. A phone that really bought the
                piece is marked verified anyway.</li>
            <li>Column headings may also be spelled <code>customer</code>, <code>stars</code>, <code>comment</code>,
                <code>feedback</code>, <code>review_date</code> — whichever your sheet already uses.</li>
            <li>Importing the same sheet twice does not double anything: the same customer's same words on the same
                piece are left alone.</li>
        </ul>
        <button type="button" onclick="downloadReviewTemplate()" class="btn-outline mt-3">Download template CSV</button>
    </div>

    @if($products->isNotEmpty())
        <div class="card p-6">
            <h2 class="font-semibold mb-2">Your product names</h2>
            <p class="text-sm text-ink-700/70 mb-3">Copy from here into the sheet's <code>product</code> column so every row lands.</p>
            <div class="max-h-64 overflow-y-auto rounded-md border border-ink-100">
                <table class="w-full text-xs">
                    <thead class="bg-ink-50 text-left sticky top-0"><tr><th class="px-2 py-1.5">ID</th><th class="px-2 py-1.5">Name</th><th class="px-2 py-1.5">SKU</th></tr></thead>
                    <tbody>
                        @foreach($products as $p)
                            <tr class="border-t border-ink-50 text-ink-700/70">
                                <td class="px-2 py-1.5">{{ $p->id }}</td><td class="px-2 py-1.5">{{ $p->name }}</td><td class="px-2 py-1.5">{{ $p->sku }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

<script>
function downloadReviewTemplate() {
    // The product name is quoted: plenty of pieces have a comma in the name.
    const first = @js($products->first()->name ?? 'Kundan Set');
    const second = @js($products->skip(1)->first()->name ?? $products->first()->name ?? 'Gold Chain Bracelet');
    const csv = 'product,name,rating,date,title,review,phone,verified\n' +
                '"' + first + '",Munia,5,14/07/2026,Onek shundor!,"Etao ekdom perfect, thanks!",01712345678,yes\n' +
                '"' + second + '",Shayla Rahman,5,01/09/2026,,Onnek shundor lagse pochondo hoise khub,,\n';
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'review-import-template.csv';
    a.click();
}
</script>
@endsection
