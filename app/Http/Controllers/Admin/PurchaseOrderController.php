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
            'received_qty' => $i->received_qty,
            'product_link' => $i->product_link,
            'image_url' => $i->image_url,
            'color' => $i->color,
            'size' => $i->size,
        ])->all() : [];

        return $items ?: [['product_name' => '', 'sku' => '', 'qty' => 1, 'unit_cost' => 0, 'color' => '', 'size' => '', 'product_link' => '', 'image_url' => '', 'received_qty' => 0]];
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

    /** Replace the PO's line items from the submitted rows. */
    protected function syncItems(PurchaseOrder $po, Request $request): void
    {
        $rows = collect($request->input('items', []))
            ->map(fn ($r) => [
                'product_id' => $r['product_id'] ?? null,
                'product_name' => trim((string) ($r['product_name'] ?? '')),
                'sku' => trim((string) ($r['sku'] ?? '')) ?: null,
                'qty' => max(0, (int) ($r['qty'] ?? 0)),
                'unit_cost' => (float) ($r['unit_cost'] ?? 0),
                'received_qty' => max(0, (int) ($r['received_qty'] ?? 0)),
                'product_link' => trim((string) ($r['product_link'] ?? '')) ?: null,
                'image_url' => trim((string) ($r['image_url'] ?? '')) ?: null,
                'color' => trim((string) ($r['color'] ?? '')) ?: null,
                'size' => trim((string) ($r['size'] ?? '')) ?: null,
            ])
            ->filter(fn ($r) => $r['product_name'] !== '' && $r['qty'] > 0)
            ->values();

        $po->items()->delete();
        foreach ($rows as $row) {
            $po->items()->create($row);
        }
    }
}
