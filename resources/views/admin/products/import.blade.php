@extends('layouts.admin')
@section('title', 'Import products')
@section('heading', 'Import products from CSV')

@section('content')
<div class="max-w-2xl space-y-6">
    @if(session('error'))<div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2.5 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-2.5 text-sm">{{ $errors->first() }}</div>@endif

    <div class="card p-6">
        <h2 class="font-semibold mb-2">Upload a CSV file</h2>
        <p class="text-sm text-ink-700/70 mb-4">In Excel/Google Sheets, use <strong>File → Save As / Download → CSV</strong>, then upload it here.</p>
        <form action="{{ route('admin.products.import.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4" data-no-lock>
            @csrf
            <input type="file" name="file" accept=".csv,text/csv" class="input" required>
            <button class="btn-primary">Import products</button>
        </form>
    </div>

    <div class="card p-6">
        <h2 class="font-semibold mb-2">Column format</h2>
        <p class="text-sm text-ink-700/70 mb-3">First row must be the header. Only <code>name</code> is required; others are optional.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-ink-50 text-left"><tr>
                    <th class="px-2 py-1.5">name*</th><th class="px-2 py-1.5">price</th><th class="px-2 py-1.5">sku</th><th class="px-2 py-1.5">category</th><th class="px-2 py-1.5">stock</th><th class="px-2 py-1.5">status</th><th class="px-2 py-1.5">tags</th>
                </tr></thead>
                <tbody><tr class="text-ink-700/70">
                    <td class="px-2 py-1.5">Gold Ring</td><td class="px-2 py-1.5">2500</td><td class="px-2 py-1.5">GR-01</td><td class="px-2 py-1.5">Rings</td><td class="px-2 py-1.5">10</td><td class="px-2 py-1.5">published</td><td class="px-2 py-1.5">eid, gold</td>
                </tr></tbody>
            </table>
        </div>
        <p class="text-xs text-ink-700/50 mt-3">Also supported: <code>short_description</code>, <code>description</code>. Unknown categories are created automatically. (These import as <strong>simple</strong> products — add variations after import.)</p>
        <button type="button" onclick="downloadTemplate()" class="btn-outline mt-3">Download template CSV</button>
    </div>
</div>

<script>
function downloadTemplate() {
    const csv = 'name,price,sku,category,stock,status,short_description,description,tags\n' +
                'Gold Ring,2500,GR-01,Rings,10,published,Elegant gold ring,Full description here,"eid, gold"\n';
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'product-import-template.csv';
    a.click();
}
</script>
@endsection
