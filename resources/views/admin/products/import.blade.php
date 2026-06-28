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
            <div>
                <label class="label">CSV file *</label>
                <input type="file" name="file" accept=".csv,text/csv" class="input" required>
            </div>
            <div>
                <label class="label">Images / videos ZIP <span class="text-ink-700/40 font-normal">(optional)</span></label>
                <input type="file" name="media" accept=".zip" class="input">
                <p class="text-xs text-ink-700/50 mt-1">
                    Zip the product image folders from your computer and upload here. In the CSV’s
                    <code>images</code> column, name the folder (or filename) inside the zip — every image/video in it is attached.
                    Max upload on this server: <strong>{{ upload_limit_mb() }} MB</strong>.
                </p>
            </div>
            <button class="btn-primary">Import products</button>
        </form>
    </div>

    <div class="card p-6">
        <h2 class="font-semibold mb-2">Column format</h2>
        <p class="text-sm text-ink-700/70 mb-3">First row must be the header. Only <code>name</code> is required; others are optional.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-ink-50 text-left"><tr>
                    <th class="px-2 py-1.5">name*</th><th class="px-2 py-1.5">price</th><th class="px-2 py-1.5">sku</th><th class="px-2 py-1.5">category</th><th class="px-2 py-1.5">stock</th><th class="px-2 py-1.5">status</th><th class="px-2 py-1.5">meta_description</th><th class="px-2 py-1.5">images</th>
                </tr></thead>
                <tbody><tr class="text-ink-700/70">
                    <td class="px-2 py-1.5">Gold Ring</td><td class="px-2 py-1.5">2500</td><td class="px-2 py-1.5">GR-01</td><td class="px-2 py-1.5">Rings</td><td class="px-2 py-1.5">10</td><td class="px-2 py-1.5">published</td><td class="px-2 py-1.5">Elegant 22k gold ring…</td><td class="px-2 py-1.5">gold-ring</td>
                </tr></tbody>
            </table>
        </div>
        <p class="text-xs text-ink-700/50 mt-3">Also supported: <code>short_description</code>, <code>description</code>, <code>tags</code>. The <code>images</code> column (also accepts <code>image</code>/<code>product_image</code>) names a folder or file inside the uploaded ZIP — use commas for several. Unknown categories are created automatically. (These import as <strong>simple</strong> products — add variations after import.)</p>
        <button type="button" onclick="downloadTemplate()" class="btn-outline mt-3">Download template CSV</button>
    </div>

    <div class="card p-6">
        <h2 class="font-semibold mb-2">How to import images from your computer</h2>
        <ol class="text-sm text-ink-700/70 list-decimal list-inside space-y-1">
            <li>On your PC, put each product’s photos/videos in its own folder, e.g. <code>gold-ring/</code>, <code>pearl-set/</code>.</li>
            <li>Select those folders and compress them into a single <code>.zip</code> file.</li>
            <li>In the CSV <code>images</code> column, write the folder name for each row (e.g. <code>gold-ring</code>).</li>
            <li>Upload the CSV <em>and</em> the ZIP together above. Every image/video in the named folder is attached, the first becoming the primary photo.</li>
        </ol>
    </div>
</div>

<script>
function downloadTemplate() {
    const csv = 'name,price,sku,category,stock,status,short_description,description,meta_description,tags,images\n' +
                'Gold Ring,2500,GR-01,Rings,10,published,Elegant gold ring,Full description here,Shop our elegant 22k gold ring,"eid, gold",gold-ring\n';
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'product-import-template.csv';
    a.click();
}
</script>
@endsection
