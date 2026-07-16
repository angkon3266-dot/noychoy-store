<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\CustomerInsight;
use App\Services\SmsService;
use App\Services\SteadfastService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $trashed = $request->boolean('trashed');

        $orders = Order::query()
            ->when($trashed, fn ($q) => $q->onlyTrashed())
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), function ($q, $term) {
                $q->where(fn ($w) => $w->where('order_number', 'like', "%{$term}%")
                    ->orWhere('customer_phone', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%"));
            })
            ->withCount('items')
            ->with('shipment')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        // Repeat-customer map: how many TOTAL orders each phone on this page has.
        // A count > 1 means the customer has ordered before.
        $phones = $orders->pluck('customer_phone')->unique()->filter();
        $orderCounts = Order::whereIn('customer_phone', $phones)
            ->select('customer_phone', DB::raw('count(*) as c'))
            ->groupBy('customer_phone')
            ->pluck('c', 'customer_phone');

        // Fulfilment queue: products inside "processing" orders (qty to prepare + product ID/serial).
        $processingItems = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('status', 'processing'))
            ->select('product_id', 'name', DB::raw('SUM(quantity) as qty'), DB::raw('COUNT(DISTINCT order_id) as orders'))
            ->groupBy('product_id', 'name')
            ->orderByDesc('qty')
            ->get();
        $processingProducts = Product::whereIn('id', $processingItems->pluck('product_id')->filter())
            ->with('images')->get();
        $processingSerials = $processingProducts->pluck('serial', 'id');
        $processingImages = $processingProducts->mapWithKeys(fn ($p) => [$p->id => $p->thumbnail]);

        return view('admin.orders.index', [
            'orders' => $orders,
            'statuses' => Order::STATUSES,
            'orderCounts' => $orderCounts,
            'processingItems' => $processingItems,
            'processingSerials' => $processingSerials,
            'processingImages' => $processingImages,
            'trashed' => $trashed,
            'trashCount' => Order::onlyTrashed()->count(),
        ]);
    }

    public function show(Order $order, CustomerInsight $insight, SteadfastService $steadfast)
    {
        $order->load('items', 'history', 'shipment', 'customer');

        // Best-effort live Steadfast status refresh for this order's consignment.
        if ($order->shipment?->consignment_id && $steadfast->isConfigured()) {
            try {
                $status = $steadfast->statusByConsignmentId($order->shipment->consignment_id);
                if (! empty($status['delivery_status'])) {
                    $order->shipment->update(['status' => $status['delivery_status'], 'response' => $status]);
                    $order->setRelation('shipment', $order->shipment->fresh());
                }
            } catch (\Throwable $e) {
                // keep last known status
            }
        }

        // Courier track record for this customer (from their shipments).
        $courier = ['total' => 0, 'delivered' => 0, 'partial' => 0, 'cancelled' => 0, 'returned' => 0, 'pending' => 0];
        Order::where('customer_phone', $order->customer_phone)->with('shipment')->get()->each(function ($o) use (&$courier) {
            if (! $o->shipment) {
                return;
            }
            $courier['total']++;
            $s = strtolower((string) $o->shipment->status);
            if (str_contains($s, 'partial')) {
                $courier['partial']++;
            } elseif (str_contains($s, 'deliver')) {
                $courier['delivered']++;
            } elseif (str_contains($s, 'cancel')) {
                $courier['cancelled']++;
            } elseif (str_contains($s, 'return')) {
                $courier['returned']++;
            } else {
                $courier['pending']++;
            }
        });
        $settled = $courier['delivered'] + $courier['partial'] + $courier['cancelled'] + $courier['returned'];
        $courier['success_rate'] = $settled > 0 ? round(($courier['delivered'] + $courier['partial']) / $settled * 100) : null;

        // Steadfast balance (best-effort).
        $balance = null;
        if ($steadfast->isConfigured()) {
            try {
                $b = $steadfast->getBalance();
                $balance = $b['current_balance'] ?? ($b['balance'] ?? null);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return view('admin.orders.show', [
            'order' => $order,
            'statuses' => Order::STATUSES,
            'insight' => $insight->forPhone($order->customer_phone, $order->id),
            'courier' => $courier,
            'balance' => $balance,
        ]);
    }

    /**
     * Manually amend an order's amounts: per-line price/quantity, shipping, the
     * overall discount, and any number of custom adjustment lines (a positive
     * amount is an extra charge, a negative one a discount). Recomputes the
     * subtotal + total and records a history note.
     */
    public function amend(Request $request, Order $order)
    {
        $data = $request->validate([
            'items' => ['array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'shipping_cost' => ['required', 'numeric', 'min:0'],
            'discount' => ['required', 'numeric', 'min:0'],
            'adjustments' => ['nullable', 'array', 'max:20'],
            'adjustments.*.label' => ['nullable', 'string', 'max:60'],
            'adjustments.*.amount' => ['nullable', 'numeric', 'between:-1000000,1000000'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        DB::transaction(function () use ($order, $data) {
            $itemsById = $order->items->keyBy('id');
            $subtotal = 0.0;

            foreach ($data['items'] ?? [] as $row) {
                $item = $itemsById->get((int) $row['id']);
                if (! $item) {
                    continue;
                }
                $lineSubtotal = round((float) $row['price'] * (int) $row['quantity'], 2);
                $item->update([
                    'price' => $row['price'],
                    'quantity' => $row['quantity'],
                    'subtotal' => $lineSubtotal,
                ]);
                $subtotal += $lineSubtotal;
            }

            // Keep only fully-filled adjustment lines.
            $adjustments = collect($data['adjustments'] ?? [])
                ->filter(fn ($a) => filled($a['label'] ?? null) && $a['amount'] !== null && $a['amount'] !== '')
                ->map(fn ($a) => ['label' => $a['label'], 'amount' => round((float) $a['amount'], 2)])
                ->values()->all();
            $adjustmentsTotal = array_sum(array_column($adjustments, 'amount'));

            $total = max(0, round($subtotal - (float) $data['discount'] + (float) $data['shipping_cost'] + $adjustmentsTotal, 2));

            $order->update([
                'subtotal' => $subtotal,
                'shipping_cost' => $data['shipping_cost'],
                'discount' => $data['discount'],
                'adjustments' => $adjustments ?: null,
                'total' => $total,
            ]);

            $order->history()->create([
                'status' => $order->status,
                'note' => 'Order amount amended — new total '.money($total)
                    .($data['reason'] ?? null ? '. '.$data['reason'] : ''),
                'created_by' => auth()->user()?->name ?? 'Admin',
            ]);
        });

        return back()->with('success', 'Order amounts updated.');
    }

    /** Print-ready shipping labels (A4, 14 per page) for orders with a consignment. */
    public function labels(Request $request)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));

        $orders = Order::with('items.product.images', 'shipment')
            ->whereHas('shipment', fn ($s) => $s->whereNotNull('consignment_id'))
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->latest()
            ->take(200)
            ->get();

        return view('admin.orders.labels', compact('orders'));
    }

    /** Create Steadfast consignments for several orders at once (skips already-sent). */
    public function bulkSteadfast(Request $request, SteadfastService $steadfast)
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ])['ids'];

        if (! $steadfast->isConfigured()) {
            return back()->with('error', 'Steadfast API keys are not configured (Settings → Integrations).');
        }

        $orders = Order::with('items', 'shipment')->whereIn('id', $ids)->get();
        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($orders as $order) {
            if ($order->shipment && $order->shipment->consignment_id) {
                $skipped++;
                continue;
            }
            $shipment = $steadfast->createForOrder($order);
            if (! $shipment) {
                $failed++;
                continue;
            }
            if (in_array($order->status, ['pending', 'confirmed', 'processing'], true)) {
                $order->update(['status' => 'shipped']);
                $order->history()->create(['status' => 'booked', 'note' => 'Consignment created at Steadfast', 'created_by' => auth()->user()->name]);
            }
            $created++;
        }

        $msg = "Sent {$created} order(s) to Steadfast"
            .($skipped ? ", {$skipped} already booked" : '')
            .($failed ? ", {$failed} failed (check logs)" : '').'.';

        return back()->with($failed ? 'error' : 'success', $msg);
    }

    /** Merge several orders from the same customer into one (the earliest). */
    public function merge(Request $request)
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:2'],
            'ids.*' => ['integer'],
        ])['ids'];

        $orders = Order::with('items')->whereIn('id', $ids)->get();

        if ($orders->count() < 2) {
            return back()->with('error', 'Select at least two orders to merge.');
        }
        if ($orders->pluck('customer_phone')->unique()->count() > 1) {
            return back()->with('error', 'Only orders from the same customer (phone) can be merged.');
        }
        if ($orders->contains(fn ($o) => in_array($o->status, ['shipped', 'delivered', 'returned', 'cancelled'], true))) {
            return back()->with('error', 'Orders already shipped, delivered, returned or cancelled cannot be merged.');
        }

        $target = $orders->sortBy('id')->first();
        $sources = $orders->where('id', '!=', $target->id);
        $mergedNumbers = $sources->pluck('order_number')->implode(', ');

        DB::transaction(function () use ($target, $sources, $mergedNumbers) {
            foreach ($sources as $src) {
                $src->items()->update(['order_id' => $target->id]);
            }

            // Combine duplicate lines (same product + variant) into one.
            $target->load('items');
            foreach ($target->items->groupBy(fn ($i) => $i->product_id.':'.($i->variant_id ?? 0)) as $group) {
                if ($group->count() < 2) {
                    continue;
                }
                $keep = $group->first();
                $keep->update([
                    'quantity' => $group->sum('quantity'),
                    'subtotal' => $group->sum('subtotal'),
                ]);
                foreach ($group->slice(1) as $dup) {
                    $dup->delete();
                }
            }

            $target->load('items');
            $subtotal = (float) $target->items->sum('subtotal');
            $target->update([
                'subtotal' => $subtotal,
                'total' => max(0, $subtotal - (float) $target->discount + (float) $target->shipping_cost),
                'status' => 'processing',
            ]);
            $target->history()->create([
                'status' => 'processing',
                'note' => "Merged order(s) {$mergedNumbers} into this order",
                'created_by' => auth()->user()->name,
            ]);

            foreach ($sources as $src) {
                $src->history()->delete();
                $src->delete();
            }

            // Recompute the customer's rollups from what's left.
            if ($customer = $target->customer) {
                $customer->update([
                    'total_orders' => $customer->orders()->count(),
                    'total_spent' => $customer->orders()->sum('total'),
                ]);
            }
        });

        return back()->with('success', "Merged into order {$target->order_number} (now Processing).");
    }

    public function updateStatus(Request $request, Order $order, SmsService $sms)
    {
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', array_keys(Order::STATUSES))],
            'note' => ['nullable', 'string', 'max:300'],
            'notify' => ['nullable', 'boolean'],
        ]);

        $previousStatus = $order->status;
        $order->update(['status' => $data['status']]);
        $order->history()->create([
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
            'created_by' => auth()->user()->name,
        ]);

        // Return stock to inventory when an order is cancelled (and re-deduct if
        // it is later moved back to an active status). Idempotent via the
        // stock_restored flag, so repeated saves never double-count.
        $this->syncStockForStatus($order, $previousStatus, $data['status']);

        // Award loyalty points once the order is delivered (idempotent).
        if ($data['status'] === 'delivered') {
            app(\App\Services\LoyaltyService::class)->awardForOrder($order->fresh('customer'));
        }

        if ($request->boolean('notify')) {
            $template = match ($data['status']) {
                'confirmed' => 'order_confirmed',
                'shipped' => 'order_shipped',
                'delivered' => 'order_delivered',
                'cancelled' => 'order_cancelled',
                default => null,
            };
            if ($template) {
                $sms->sendTemplate($template, $order->fresh());
            }
        }

        // Fire a web push for the status change (template-gated; free, so not tied
        // to the SMS "notify" checkbox).
        if ($previousStatus !== $data['status']) {
            $this->pushOrderStatus($order->fresh(), $data['status']);
        }

        return back()->with('success', 'Order status updated.');
    }

    /**
     * Release stock back to inventory when an order enters "cancelled", and
     * re-deduct it if the order is moved back out of "cancelled". The
     * stock_restored flag makes both directions idempotent.
     */
    protected function syncStockForStatus(Order $order, string $from, string $to): void
    {
        // Statuses that free the reserved stock. Returned goods are intentionally
        // NOT auto-restocked (they may be damaged / need inspection).
        $releaseStatuses = ['cancelled'];

        $wasReleased = in_array($from, $releaseStatuses, true);
        $nowReleased = in_array($to, $releaseStatuses, true);

        if ($nowReleased && ! $wasReleased && ! $order->stock_restored) {
            $this->adjustStock($order, +1); // return to inventory
            $order->update(['stock_restored' => true]);
        } elseif (! $nowReleased && $wasReleased && $order->stock_restored) {
            $this->adjustStock($order, -1); // re-reserve (moved back to active)
            $order->update(['stock_restored' => false]);
        }
    }

    /** Add ($sign=+1) or remove ($sign=-1) this order's line quantities from stock. */
    protected function adjustStock(Order $order, int $sign): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue; // deleted product — nothing to adjust
            }

            $delta = $sign * (int) $item->quantity;

            if ($item->variant_id) {
                \App\Models\ProductVariant::where('id', $item->variant_id)->increment('stock_quantity', $delta);
            }

            $product = Product::find($item->product_id);
            if ($product && $product->manage_stock) {
                $product->increment('stock_quantity', $delta);
                $product->refresh();
                $product->update(['in_stock' => $product->stock_quantity > 0]);
            }
        }
    }

    // ── Delete / restore (soft delete) ──────────────────────────────────────

    /** Move a single order to Trash (recoverable), returning any reserved stock. */
    public function destroy(Order $order)
    {
        DB::transaction(function () use ($order) {
            $this->releaseStockOnDelete($order);
            $customer = $order->customer;
            $order->delete();
            $this->recomputeCustomer($customer);
        });

        return back()->with('success', "Order {$order->order_number} moved to Trash.");
    }

    /** Move several selected orders to Trash at once. */
    public function bulkDelete(Request $request)
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ])['ids'];

        $orders = Order::with('items', 'customer')->whereIn('id', $ids)->get();
        $customers = collect();

        DB::transaction(function () use ($orders, $customers) {
            foreach ($orders as $order) {
                $this->releaseStockOnDelete($order);
                if ($order->customer) {
                    $customers->put($order->customer_id, $order->customer);
                }
                $order->delete();
            }
        });

        $customers->each(fn ($c) => $this->recomputeCustomer($c));

        return back()->with('success', $orders->count().' order(s) moved to Trash.');
    }

    /** Restore a soft-deleted order (re-reserving stock if it is still active). */
    public function restore(Order $order)
    {
        DB::transaction(function () use ($order) {
            $order->restore();

            // If deleting released this order's stock and it's back as an active
            // order, re-reserve it. (Cancelled/returned orders keep stock freed.)
            if ($order->stock_restored && ! in_array($order->status, ['cancelled', 'returned'], true)) {
                $this->adjustStock($order, -1);
                $order->update(['stock_restored' => false]);
            }

            $this->recomputeCustomer($order->customer);
        });

        return back()->with('success', "Order {$order->order_number} restored.");
    }

    /** Permanently delete a trashed order (and its items/history). */
    public function forceDelete(Order $order)
    {
        $number = $order->order_number;
        DB::transaction(function () use ($order) {
            $order->history()->delete();
            $order->items()->delete();
            $order->forceDelete();
        });

        return back()->with('success', "Order {$number} permanently deleted.");
    }

    /**
     * Return an order's stock to inventory when deleting it — but only if the
     * stock is still reserved (not already freed by a cancel/return). Idempotent
     * via the stock_restored flag.
     */
    protected function releaseStockOnDelete(Order $order): void
    {
        if (! $order->stock_restored && ! in_array($order->status, ['cancelled', 'returned'], true)) {
            $this->adjustStock($order, +1);
            $order->update(['stock_restored' => true]);
        }
    }

    /** Recompute a customer's order/spend rollups from their remaining orders. */
    protected function recomputeCustomer(?\App\Models\Customer $customer): void
    {
        if (! $customer) {
            return;
        }

        $customer->update([
            'total_orders' => $customer->orders()->count(),
            'total_spent' => (float) $customer->orders()->sum('total'),
        ]);
    }

    public function pushToSteadfast(Order $order, SteadfastService $steadfast)
    {
        if (! $steadfast->isConfigured()) {
            return back()->with('error', 'Steadfast API keys are not configured (Settings → check .env).');
        }

        if ($order->shipment && $order->shipment->consignment_id) {
            return back()->with('error', 'This order already has a Steadfast consignment.');
        }

        $shipment = $steadfast->createForOrder($order->load('items'));

        if (! $shipment) {
            return back()->with('error', 'Steadfast rejected the request. Check the logs.');
        }

        if (in_array($order->status, ['pending', 'confirmed', 'processing'], true)) {
            $order->update(['status' => 'shipped']);
            $order->history()->create(['status' => 'booked', 'note' => 'Consignment created at Steadfast', 'created_by' => auth()->user()->name]);
            // Courier picked it up → tell the customer it's on the way.
            $this->pushOrderStatus($order->fresh('shipment'), 'shipped');
        }

        return back()->with('success', "Consignment created. Tracking: {$shipment->tracking_code}");
    }

    /** Send the editable transactional web push for an order status change. */
    protected function pushOrderStatus(Order $order, string $status): void
    {
        if (! $order->customer_id) {
            return;
        }
        $trigger = match ($status) {
            'confirmed' => 'order_confirmed',
            'shipped' => 'order_shipped',
            'delivered' => 'order_delivered',
            default => null,
        };
        if (! $trigger) {
            return;
        }
        $payload = app(\App\Services\PushTemplateService::class)->forOrder($trigger, $order);
        if ($payload) {
            app(\App\Services\NotificationService::class)->pushToCustomer((int) $order->customer_id, $payload);
        }
    }

    public function refreshShipment(Order $order, SteadfastService $steadfast)
    {
        if (! $order->shipment?->consignment_id) {
            return back()->with('error', 'No consignment to refresh.');
        }

        $status = $steadfast->statusByConsignmentId($order->shipment->consignment_id);
        $order->shipment->update([
            'status' => $status['delivery_status'] ?? $order->shipment->status,
            'response' => $status,
        ]);

        return back()->with('success', 'Delivery status refreshed.');
    }

    public function sendSms(Request $request, Order $order, SmsService $sms)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:500']]);
        $ok = $sms->send($order->customer_phone, $data['message'], $order->id);

        return back()->with($ok ? 'success' : 'error', $ok ? 'SMS sent.' : 'SMS failed (check SMS settings/logs).');
    }
}
