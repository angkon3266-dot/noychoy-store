<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = PurchaseOrder::with('supplier')
            ->withCount('items')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('supplier'), fn ($q, $s) => $q->where('supplier_id', $s))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.purchase-orders.index', [
            'orders' => $orders,
            'statuses' => PurchaseOrder::STATUSES,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'summary' => [
                'open' => PurchaseOrder::whereNotIn('status', ['received', 'cancelled'])->count(),
                'value_open' => (float) PurchaseOrder::whereNotIn('status', ['received', 'cancelled'])->sum('total_cost'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $order = new PurchaseOrder(['currency' => 'USD', 'status' => 'pending', 'supplier_id' => $request->query('supplier')]);

        return view('admin.purchase-orders.form', [
            'order' => $order,
            'suppliers' => Supplier::orderBy('name')->get(),
            'statuses' => PurchaseOrder::STATUSES,
            'products' => Product::orderBy('name')->get(['id', 'name', 'sku']),
            'initialItems' => $this->initialItems($order),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $po = PurchaseOrder::create($data);
        $this->syncItems($po, $request);
        $po->recalculateTotal();

        return redirect()->route('admin.purchase-orders.show', $po)->with('success', 'Purchase order created.');
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load('supplier', 'items.product');

        return view('admin.purchase-orders.show', [
            'order' => $purchaseOrder,
            'statuses' => PurchaseOrder::STATUSES,
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load('items');

        return view('admin.purchase-orders.form', [
            'order' => $purchaseOrder,
            'suppliers' => Supplier::orderBy('name')->get(),
            'statuses' => PurchaseOrder::STATUSES,
            'products' => Product::orderBy('name')->get(['id', 'name', 'sku']),
            'initialItems' => $this->initialItems($purchaseOrder),
        ]);
    }

    /** Build the line-item seed array for the Alpine form (old input → existing items → one blank row). */
    protected function initialItems(PurchaseOrder $po): array
    {
        if (old('items')) {
            return array_values(old('items'));
        }

        $items = $po->exists ? $po->items->map(fn ($i) => [
            'product_id' => $i->product_id,
            'product_name' => $i->product_name,
            'sku' => $i->sku,
            'qty' => $i->qty,
            'unit_cost' => (float) $i->unit_cost,
            'target_price' => $i->target_price !== null ? (float) $i->target_price : null,
            'received_qty' => $i->received_qty,
            'product_link' => $i->product_link,
            'image_url' => $i->image_url,
            'attribute_names' => $i->attribute_names ?: [],
            'variants' => $i->variants ?: [],
        ])->all() : [];

        return $items ?: [$this->blankItem()];
    }

    protected function blankItem(): array
    {
        return [
            'product_name' => '', 'sku' => '', 'qty' => 0, 'unit_cost' => 0, 'target_price' => null,
            'received_qty' => 0, 'product_link' => '', 'image_url' => '',
            'attribute_names' => [], 'variants' => [['attrs' => [], 'qty' => 1]],
        ];
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->update($this->validateData($request));
        $this->syncItems($purchaseOrder, $request);
        $purchaseOrder->recalculateTotal();

        return redirect()->route('admin.purchase-orders.show', $purchaseOrder)->with('success', 'Purchase order updated.');
    }

    public function updateStatus(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(PurchaseOrder::STATUSES))],
        ]);

        $update = ['status' => $data['status']];
        if ($data['status'] === 'received' && ! $purchaseOrder->arrived_at) {
            $update['arrived_at'] = now();
        }
        $purchaseOrder->update($update);

        return back()->with('success', 'Status updated to '.$purchaseOrder->statusLabel().'.');
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->delete();

        return redirect()->route('admin.purchase-orders.index')->with('success', 'Purchase order deleted.');
    }

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'status' => ['required', 'in:'.implode(',', array_keys(PurchaseOrder::STATUSES))],
            'po_number' => ['nullable', 'string', 'max:40'],
            'currency' => ['nullable', 'string', 'max:8'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            'courier_name' => ['nullable', 'string', 'max:120'],
            'courier_tracking' => ['nullable', 'string', 'max:120'],
            'courier_cost' => ['nullable', 'numeric', 'min:0'],
            'processing_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'expected_at' => ['nullable', 'date'],
            'ordered_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /** Replace the PO's line items from the submitted rows (variant-aware). */
    protected function syncItems(PurchaseOrder $po, Request $request): void
    {
        $rows = collect($request->input('items', []))
            ->map(function ($r) {
                $attrNames = array_values(array_filter(array_map('trim', (array) (json_decode((string) ($r['attribute_names_json'] ?? '[]'), true) ?: []))));
                $variants = collect(json_decode((string) ($r['variants_json'] ?? '[]'), true) ?: [])
                    ->map(fn ($v) => ['attrs' => (array) ($v['attrs'] ?? []), 'qty' => max(0, (int) ($v['qty'] ?? 0))])
                    ->filter(fn ($v) => $v['qty'] > 0)
                    ->values()->all();

                // Total qty = sum of variant qtys, or the plain qty field when no variants.
                $qty = $variants ? array_sum(array_column($variants, 'qty')) : max(0, (int) ($r['qty'] ?? 0));

                return [
                    'product_id' => $r['product_id'] ?? null,
                    'product_name' => trim((string) ($r['product_name'] ?? '')),
                    'sku' => trim((string) ($r['sku'] ?? '')) ?: null,
                    'qty' => $qty,
                    'unit_cost' => (float) ($r['unit_cost'] ?? 0),
                    'target_price' => ($r['target_price'] ?? '') !== '' ? (float) $r['target_price'] : null,
                    'received_qty' => max(0, (int) ($r['received_qty'] ?? 0)),
                    'product_link' => trim((string) ($r['product_link'] ?? '')) ?: null,
                    'image_url' => trim((string) ($r['image_url'] ?? '')) ?: null,
                    'attribute_names' => $attrNames ?: null,
                    'variants' => $variants ?: null,
                ];
            })
            ->filter(fn ($r) => $r['product_name'] !== '' && $r['qty'] > 0)
            ->values();

        $po->items()->delete();
        foreach ($rows as $row) {
            $po->items()->create($row);
        }
    }

    /** Fetch a product image (og:image) from a supplier URL for the form preview. */
    public function fetchImage(Request $request)
    {
        $url = $request->input('url');
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['ok' => false]);
        }

        try {
            $html = \Illuminate\Support\Facades\Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($url)->body();
            if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
                || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m)) {
                return response()->json(['ok' => true, 'image' => $m[1]]);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return response()->json(['ok' => false]);
    }

    /** Export a purchase order's items to an Excel-friendly CSV. */
    public function export(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load('supplier', 'items');
        $filename = $purchaseOrder->po_number.'.csv';

        return response()->streamDownload(function () use ($purchaseOrder) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['PO', $purchaseOrder->po_number, 'Supplier', $purchaseOrder->supplier->name ?? '']);
            fputcsv($out, []);
            fputcsv($out, ['Product', 'SKU', 'Variant', 'Qty', 'Unit cost ('.$purchaseOrder->currency.')', 'Line total', 'Product link']);
            foreach ($purchaseOrder->items as $it) {
                if ($it->variants) {
                    foreach ($it->variants as $v) {
                        $variant = collect($v['attrs'] ?? [])->map(fn ($val, $k) => "$k: $val")->implode(', ');
                        fputcsv($out, [$it->product_name, $it->sku, $variant, $v['qty'] ?? 0, $it->unit_cost, ($v['qty'] ?? 0) * (float) $it->unit_cost, $it->product_link]);
                    }
                } else {
                    fputcsv($out, [$it->product_name, $it->sku, '', $it->qty, $it->unit_cost, $it->lineTotal(), $it->product_link]);
                }
            }
            fputcsv($out, []);
            fputcsv($out, ['', '', '', '', 'Items', $purchaseOrder->itemsSubtotal()]);
            fputcsv($out, ['', '', '', '', 'Courier', $purchaseOrder->courier_cost]);
            fputcsv($out, ['', '', '', '', 'Total', $purchaseOrder->total_cost]);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
