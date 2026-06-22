<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CustomerInsight;
use App\Services\SmsService;
use App\Services\SteadfastService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()
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

        return view('admin.orders.index', [
            'orders' => $orders,
            'statuses' => Order::STATUSES,
            'orderCounts' => $orderCounts,
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

        $order->update(['status' => $data['status']]);
        $order->history()->create([
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
            'created_by' => auth()->user()->name,
        ]);

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
        }

        return back()->with('success', "Consignment created. Tracking: {$shipment->tracking_code}");
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
