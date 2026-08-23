<?php

namespace App\Http\Controllers\Admin;

use App\Actions\TransitionOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Services\BdCourierService;
use App\Services\CustomerInsight;
use App\Services\SmsService;
use App\Services\SteadfastService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request, SteadfastService $steadfast)
    {
        $trashed = $request->boolean('trashed');

        // This screen is a work queue, so it opens on the orders that still
        // need packing rather than on everything ever sold. "all" is the
        // explicit escape hatch — an empty value falls back to the default,
        // and a search has to look everywhere or it finds nothing.
        $status = $request->query('status')
            ?: (($trashed || filled($request->query('q'))) ? 'all' : 'processing');
        $statusFilter = $status === 'all' ? null : $status;

        $orders = Order::query()
            ->when($trashed, fn ($q) => $q->onlyTrashed())
            ->when($statusFilter, fn ($q, $s) => $q->where('status', $s))
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

        // BDCourier reputation for the phones on this page — read from cache
        // only. A page view must never spend plan quota; the bulk action does
        // the fetching.
        $bdCourier = app(BdCourierService::class);

        return view('admin.orders.index', [
            'orders' => $orders,
            'statuses' => Order::STATUSES,
            'status' => $status,
            'orderCounts' => $orderCounts,
            'bdCourierOn' => $bdCourier->isConfigured(),
            'bdHistory' => $bdCourier->isConfigured() ? $bdCourier->cachedMany($phones) : [],
            'processingItems' => $processingItems,
            'processingSerials' => $processingSerials,
            'processingImages' => $processingImages,
            'trashed' => $trashed,
            'trashCount' => Order::onlyTrashed()->count(),
            // Courier wallet, cached — if it runs dry, bookings start failing,
            // and this is the screen you book from.
            'courierBalance' => $steadfast->balance(),
        ]);
    }

    public function show(Order $order, CustomerInsight $insight, SteadfastService $steadfast)
    {
        $order->load('items', 'history', 'shipment', 'customer');

        // Best-effort live Steadfast status refresh for this order's consignment.
        //
        // Through the CACHED reader, not the raw call: this page is the one the
        // owner lives on, every action on it redirects straight back here, and
        // the raw call carries a 30-second timeout with no cache — so a slow
        // courier API made the whole admin feel broken. The "Refresh status"
        // button still forces an uncached read when she actually wants one.
        if ($order->shipment?->consignment_id && $steadfast->isConfigured()) {
            try {
                $live = $steadfast->deliveryStatus($order->shipment->consignment_id);
                $status = $live ? ['delivery_status' => $live] : [];
                if (! empty($status['delivery_status'])) {
                    $order->shipment->update(['status' => $status['delivery_status'], 'response' => $status]);
                    $order->setRelation('shipment', $order->shipment->fresh());

                    // A settled courier outcome moves the order with it.
                    if (app(TransitionOrderStatus::class)
                        ->applyCourierStatus($order, $status['delivery_status'], 'Courier sync')) {
                        $order->refresh()->load('items', 'history', 'shipment', 'customer');
                    }
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

        // BDCourier: render only what a previous click already fetched. Never
        // call the API here — lookups cost plan quota and this is a page view.
        $bdCourier = app(BdCourierService::class);

        return view('admin.orders.show', [
            'order' => $order,
            'statuses' => Order::STATUSES,
            'insight' => $insight->forPhone($order->customer_phone, $order->id),
            'courier' => $courier,
            'balance' => $steadfast->balance(),
            'bdCourierOn' => $bdCourier->isConfigured(),
            'bdCourier' => filled($order->customer_phone) ? $bdCourier->cached($order->customer_phone) : null,
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

    /**
     * Correct the customer and delivery details on an order.
     *
     * The most common call a cash-on-delivery shop gets is "wrong flat number"
     * or "use my office address" — and until this existed the only options were
     * to book a parcel you knew would fail, or delete the order. Both cost the
     * courier fee twice and put the customer's number in the failed-delivery
     * history through no fault of theirs.
     *
     * Blocked once the consignment exists: at that point the courier holds its
     * own copy, and editing here would silently put the two out of step. Cancel
     * the consignment first, then edit, then re-book.
     */
    public function updateDetails(Request $request, Order $order)
    {
        if ($order->shipment?->consignment_id) {
            return back()->with('error',
                'This order is already booked with the courier. Cancel the consignment first, '
                .'otherwise the address here and the address on the parcel would disagree.');
        }

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', new \App\Rules\BdPhone],
            'customer_email' => ['nullable', 'email', 'max:160'],
            'shipping_address' => ['required', 'string', 'max:500'],
            'area' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'is_inside_dhaka' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $before = trim($order->shipping_address.' '.$order->area);
        $data['is_inside_dhaka'] = $request->boolean('is_inside_dhaka');

        $order->update($data);

        // Written to the trail like amend() does — who changed a delivery
        // address, and when, is exactly what you want on a disputed parcel.
        $order->history()->create([
            'status' => $order->status,
            'note' => 'Delivery details corrected'
                .($before !== trim($order->shipping_address.' '.$order->area) ? ' (address changed)' : ''),
            'created_by' => auth()->user()?->name ?? 'Admin',
        ]);

        return back()->with('success', 'Delivery details updated.');
    }

    /**
     * Print-ready shipping labels (A4, 2 per row) for orders booked with the
     * courier.
     *
     * A label is only ever wanted for a parcel that still has to be stuck and
     * handed over, so this is scoped to "booked" — the status an order enters
     * the moment a Steadfast consignment is created. Anything already shipped,
     * delivered, cancelled or returned is past that point and printing it again
     * just wastes a sheet.
     */
    public function labels(Request $request)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));

        $orders = Order::with('items.product.images', 'items.variant.image', 'shipment')
            ->where('status', 'booked')
            ->whereHas('shipment', fn ($s) => $s->whereNotNull('consignment_id'))
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->latest()
            ->take(200)
            ->get();

        // If the admin ticked orders that aren't awaiting a label, say so rather
        // than silently printing fewer than they expected.
        $skipped = $ids ? count($ids) - $orders->count() : 0;

        return view('admin.orders.labels', compact('orders', 'skipped'));
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
            if (in_array($order->status, Order::PRE_BOOKING_STATUSES, true)) {
                app(TransitionOrderStatus::class)->handle(
                    $order, 'booked', 'Consignment created at Steadfast', auth()->user()?->name ?? 'Admin',
                );
            }
            $created++;
        }

        $msg = "Sent {$created} order(s) to Steadfast"
            .($skipped ? ", {$skipped} already booked" : '')
            .($failed ? ", {$failed} failed (check logs)" : '').'.'
            // They have just left the default (Processing) view, so say where
            // they went rather than letting them look like they vanished.
            .($created ? ' They are now "Booked with courier" — print their labels from there.' : '');

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
        if ($orders->contains(fn ($o) => in_array($o->status, ['shipped', 'delivered', 'partially_delivered', 'returned', 'cancelled'], true))) {
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

        // The courier confirmed delivery: the goods are gone and the COD is
        // collected, so the status is final. The dropdown is disabled in the UI,
        // but enforce it here too — a disabled <select> stops nobody.
        if ($order->load('shipment')->isStatusLocked() && $data['status'] !== $order->status) {
            return back()->with('error',
                'This order is locked: the courier has confirmed delivery. Its status can no longer be changed.');
        }

        // Stock release/re-reserve, loyalty award, history and web push all live
        // in the shared action, so the Steadfast webhook applies exactly the same
        // effects when the courier moves an order.
        app(TransitionOrderStatus::class)->handle(
            $order, $data['status'], $data['note'] ?? null, auth()->user()->name,
        );

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

        return back()->with('success', 'Order status updated.');
    }

    /** Add ($sign=+1) or remove ($sign=-1) this order's line quantities from stock. */
    protected function adjustStock(Order $order, int $sign): void
    {
        app(TransitionOrderStatus::class)->adjustStock($order, $sign);
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
    protected function recomputeCustomer(?Customer $customer): void
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

        // Booking is not shipping: the parcel is registered with Steadfast but
        // still on the shelf waiting for its label. It moves on to "shipped"
        // when the courier actually reports movement — that is also when the
        // customer gets the "on its way" push, rather than a day early.
        if (in_array($order->status, Order::PRE_BOOKING_STATUSES, true)) {
            app(TransitionOrderStatus::class)->handle(
                $order, 'booked', 'Consignment created at Steadfast', auth()->user()?->name ?? 'Admin',
            );
        }

        return back()->with('success', "Consignment created. Tracking: {$shipment->tracking_code}");
    }

    /**
     * Look this customer's phone up on BDCourier (on demand — it costs plan
     * quota, so nothing here runs on a plain page view). The result is cached
     * per phone by the service, and the order page renders it from that cache.
     */
    public function courierCheck(Order $order, BdCourierService $bdCourier)
    {
        if (blank($order->customer_phone)) {
            return back()->with('error', 'This order has no phone number to check.');
        }

        $result = $bdCourier->check($order->customer_phone);

        if (! ($result['ok'] ?? false)) {
            return back()->with('error', $result['error'] ?? 'Courier check failed.');
        }

        return back()->with('success', 'Courier history updated for '.$order->customer_phone.'.');
    }

    /**
     * Bulk BDCourier lookup for the orders selected in the list.
     *
     * Deduplicated by phone and skipping numbers already cached, so selecting a
     * page of orders from repeat customers costs very few credits.
     */
    public function bulkCourierCheck(Request $request, BdCourierService $bdCourier)
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ])['ids'];

        // Each lookup is a synchronous HTTP call; don't let a slow shared host
        // abort part-way through a selection.
        @set_time_limit(300);

        $phones = Order::whereIn('id', $ids)->pluck('customer_phone');
        $result = $bdCourier->checkMany($phones);

        if ($result['error'] && $result['checked'] === 0) {
            return back()->with('error', $result['error']);
        }

        $parts = [];
        if ($result['checked']) {
            $parts[] = $result['checked'].' number(s) checked';
        }
        if ($result['cached']) {
            $parts[] = $result['cached'].' already up to date';
        }
        if ($result['failed']) {
            $parts[] = $result['failed'].' failed';
        }
        if ($result['skipped']) {
            $parts[] = $result['skipped'].' skipped (limit '.BdCourierService::BULK_LIMIT.' per run)';
        }

        return back()->with($result['failed'] ? 'error' : 'success',
            'Courier history: '.(implode(' · ', $parts) ?: 'nothing to check').'.');
    }

    public function refreshShipment(Order $order, SteadfastService $steadfast)
    {
        if (! $order->shipment?->consignment_id) {
            return back()->with('error', 'No consignment to refresh.');
        }

        $status = $steadfast->statusByConsignmentId($order->shipment->consignment_id);
        $raw = $status['delivery_status'] ?? null;

        $order->shipment->update([
            'status' => $raw ?? $order->shipment->status,
            'response' => $status,
        ]);
        $order->setRelation('shipment', $order->shipment->fresh());

        // A settled courier outcome moves the order with it.
        if (app(TransitionOrderStatus::class)->applyCourierStatus($order, $raw, 'Courier sync')) {
            return back()->with('success', 'Delivery status refreshed — order marked '.$order->fresh()->status.'.');
        }

        return back()->with('success', 'Delivery status refreshed.');
    }

    public function sendSms(Request $request, Order $order, SmsService $sms)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:500']]);
        $ok = $sms->send($order->customer_phone, $data['message'], $order->id);

        return back()->with($ok ? 'success' : 'error', $ok ? 'SMS sent.' : 'SMS failed (check SMS settings/logs).');
    }

    // ── Thank-you cards (6×6 cm inserts printed with the parcel) ─────────────

    /** Saved message templates, always including the two built-in defaults. */
    public static function cardTemplates(): array
    {
        $saved = Setting::get('thankyou_templates', []);

        return is_array($saved) && $saved !== [] ? $saved : [
            ['name' => 'New customer', 'text' => "Dear {name},\n\nThank you for your first order with {store}. We hope this piece brings you joy — and we'd be delighted to see you again."],
            ['name' => 'Repeat customer', 'text' => "Dear {name},\n\nThank you for coming back to {store}. It means the world to us. Enjoy your new piece — you have wonderful taste."],
        ];
    }

    /** Printed card dimensions in millimetres (Appearance → Cards & print). */
    public static function cardSize(): array
    {
        return [
            'w' => max(30, min(150, (int) theme('card_w', 60))),
            'h' => max(30, min(200, (int) theme('card_h', 60))),
        ];
    }

    /** Fill {name} / {store} / {order_number} for one order. */
    public static function renderCardText(string $text, Order $order): string
    {
        return strtr($text, [
            '{name}' => trim((string) $order->customer_name),
            '{store}' => store_name(),
            '{order_number}' => (string) $order->order_number,
        ]);
    }

    /**
     * The message that will print for this order: its own override if one was
     * saved, otherwise the new- or repeat-customer default.
     */
    public static function cardMessageFor(Order $order): string
    {
        if (filled($order->card_message)) {
            return static::renderCardText((string) $order->card_message, $order);
        }

        $templates = collect(static::cardTemplates());
        $isRepeat = (int) ($order->customer?->total_orders ?? 0) > 1;
        $name = Setting::get($isRepeat ? 'thankyou_default_repeat' : 'thankyou_default_new', $isRepeat ? 'Repeat customer' : 'New customer');
        $tpl = $templates->firstWhere('name', $name) ?? $templates->first();

        return static::renderCardText((string) ($tpl['text'] ?? ''), $order);
    }

    /** Template manager: edit the message library and pick the two defaults. */
    public function cardSettings()
    {
        return view('admin.orders.card-templates', [
            'templates' => static::cardTemplates(),
            'defaultNew' => Setting::get('thankyou_default_new', 'New customer'),
            'defaultRepeat' => Setting::get('thankyou_default_repeat', 'Repeat customer'),
            'size' => static::cardSize(),
        ]);
    }

    public function saveCardSettings(Request $request)
    {
        $data = $request->validate([
            'templates' => ['required', 'array', 'min:1'],
            'templates.*.name' => ['nullable', 'string', 'max:60'],
            'templates.*.text' => ['nullable', 'string', 'max:400'],
            'default_new' => ['nullable', 'string', 'max:60'],
            'default_repeat' => ['nullable', 'string', 'max:60'],
        ]);

        // Keep only fully-filled rows so an empty "add another" row can't create
        // a blank template that then prints blank cards.
        $templates = collect($data['templates'])
            ->filter(fn ($t) => filled($t['name'] ?? null) && filled($t['text'] ?? null))
            ->map(fn ($t) => ['name' => trim($t['name']), 'text' => trim($t['text'])])
            ->values()->all();

        if ($templates === []) {
            return back()->with('error', 'Keep at least one template with a name and a message.');
        }

        Setting::put('thankyou_templates', $templates);
        Setting::put('thankyou_default_new', $data['default_new'] ?? $templates[0]['name']);
        Setting::put('thankyou_default_repeat', $data['default_repeat'] ?? $templates[0]['name']);

        return back()->with('success', 'Thank-you card messages saved.');
    }

    /**
     * Printable 6×6 cm thank-you cards for the given orders (or all recent).
     * Each card picks the new-customer or repeat-customer template based on
     * how many orders that phone has placed, unless one is forced via ?template.
     */
    public function cards(Request $request)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));

        // Cards are packing-slip inserts, so only orders being packed get one —
        // which now runs right up to the handover, since a booked order is still
        // sitting in the shop waiting for its label.
        $orders = Order::with('customer')
            ->whereIn('status', ['processing', 'booked'])
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->latest()->take(300)->get();

        // If the admin selected orders that aren't being packed, say so rather
        // than silently printing fewer cards than they expected.
        $skipped = $ids ? count($ids) - $orders->count() : 0;

        $templates = collect(static::cardTemplates());
        $forced = $request->query('template');
        $forcedTpl = filled($forced)
            ? ($templates->firstWhere('name', $forced) ?? $templates->first())
            : null;

        $cards = $orders->map(function (Order $order) use ($forcedTpl) {
            // Forcing a template from the toolbar overrides everything (it's a
            // deliberate one-off), otherwise the order's own saved message wins,
            // then the new/repeat default.
            $text = $forcedTpl
                ? static::renderCardText((string) ($forcedTpl['text'] ?? ''), $order)
                : static::cardMessageFor($order);

            return [
                'order' => $order,
                'text' => $text,
                'custom' => ! $forcedTpl && filled($order->card_message),
            ];
        });

        return view('admin.orders.cards', [
            'cards' => $cards,
            'templates' => $templates,
            'forced' => $forced,
            'size' => static::cardSize(),
            'skipped' => $skipped,
        ]);
    }

    /**
     * Save per-order card messages edited straight on the print preview.
     * An empty message clears the override so the order falls back to its
     * new/repeat default template again.
     */
    public function saveCardMessages(Request $request)
    {
        $data = $request->validate([
            'messages' => ['required', 'array'],
            'messages.*' => ['nullable', 'string', 'max:600'],
        ]);

        $saved = 0;
        foreach ($data['messages'] as $orderId => $text) {
            $order = Order::find((int) $orderId);
            if (! $order) {
                continue;
            }
            // contenteditable hands back CRLF; normalise so the print and the
            // admin textarea agree on line breaks.
            $clean = filled($text) ? trim(str_replace("\r\n", "\n", (string) $text)) : null;
            $order->forceFill(['card_message' => $clean ?: null])->save();
            $saved++;
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'saved' => $saved])
            : back()->with('success', 'Thank-you card message saved.');
    }
}
