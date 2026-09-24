<?php

namespace App\Http\Controllers\Admin;

use App\Actions\TransitionOrderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\CheckOrderCourier;
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
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * How many statuses may be pinned as slicer pills.
     *
     * Five, at the owner's request (2026-09-17). The cap used to be three on
     * the belief that the pills shared a line with the search box; they never
     * did — the pill row is its own flex-wrap line above it. Five fit on one
     * line on a laptop and wrap cleanly on a phone, with Multi and Edit pills
     * held together at the right. Everything not pinned is still one pick
     * away in the dropdown below.
     */
    public const MAX_QUICK_FILTERS = 5;

    public function index(Request $request, SteadfastService $steadfast)
    {
        $trashed = $request->boolean('trashed');

        // Normalised once, up front, because the default-status rule below reads
        // it too. ?q[] hands back an array, which the LIKE binding would fatal
        // on. The explicit !== '' tests below (rather than leaning on when()'s
        // truthiness) are so a search for "0" still counts as a search — it
        // matches every order here anyway, since phone numbers are normalised
        // to a leading zero, but the rule should not quietly depend on that.
        $term = $request->query('q');
        $term = is_string($term) ? trim($term) : '';

        // The status filter takes a SET, not a single value. The slicer pills
        // can hand back several at once ("pending,processing") while the
        // dropdown still hands back one key, and both land here. Unknown keys
        // are dropped rather than passed to the query, so a stale bookmark
        // naming a status that no longer exists falls back to the default
        // below instead of rendering an empty table.
        $requested = $request->query('status');
        $requested = collect(is_array($requested) ? $requested : explode(',', (string) $requested))
            // is_string, not a cast: ?status[][]=x hands back a nested array,
            // and casting that to a string is a fatal, not a filter.
            ->map(fn ($s) => is_string($s) ? trim($s) : null)
            ->filter();
        $wantsAll = $requested->contains('all');

        $selected = $requested
            ->filter(fn ($s) => isset(Order::STATUSES[$s]))
            ->unique()
            ->values();

        // This screen is a work queue, so it opens on the orders that still
        // need packing rather than on everything ever sold. "all" is the
        // explicit escape hatch — an empty value falls back to the default,
        // and a search has to look everywhere or it finds nothing.
        if ($selected->isEmpty() && ! $wantsAll && ! $trashed && $term === '') {
            $selected = collect(['processing']);
        }

        // Shared so the pill counts are scoped by the same search the table is.
        $search = function ($q) use ($term) {
            $q->where(fn ($w) => $w->where('order_number', 'like', "%{$term}%")
                ->orWhere('customer_phone', 'like', "%{$term}%")
                ->orWhere('customer_name', 'like', "%{$term}%"));
        };

        $orders = Order::query()
            ->when($trashed, fn ($q) => $q->onlyTrashed())
            ->when($selected->isNotEmpty(), fn ($q) => $q->whereIn('status', $selected->all()))
            ->when($term !== '', $search)
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

        // Fulfilment queue: the pieces inside whatever the list is currently
        // showing, with the quantity to prepare and the product ID/serial.
        //
        // Scoped to the same filter and search as the table rather than pinned
        // to "processing": the panel sitting beside a list of booked orders had
        // been answering a question about a different set of orders, and read
        // as empty whenever the queue she was actually looking at was not the
        // processing one.
        $processingItems = OrderItem::query()
            ->whereHas('order', function ($q) use ($trashed, $selected, $term, $search) {
                $q->when($trashed, fn ($b) => $b->onlyTrashed())
                    ->when($selected->isNotEmpty(), fn ($b) => $b->whereIn('status', $selected->all()))
                    ->when($term !== '', $search)
                    // A delivered parcel is not work waiting to be done, so its
                    // pieces never belong in a "to prepare" count — most
                    // visibly on the All view, where months of finished sales
                    // would otherwise swamp the handful still to pack.
                    // `partially_delivered` is deliberately NOT excluded: part
                    // of that parcel came back and somebody still has to
                    // settle it.
                    ->where('status', '!=', 'delivered');
            })
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

        // Per-status totals for the slicer pills. Scoped to the search box and
        // the trash view, but deliberately NOT to the status filter itself —
        // a pill has to keep showing its own total while another pill is the
        // active one, or the row stops being a dashboard the moment you use it.
        $statusCounts = Order::query()
            ->when($trashed, fn ($q) => $q->onlyTrashed())
            ->when($term !== '', $search)
            // Aliased "tally", not "total": pluck() on an Eloquent builder runs
            // the model's casts over the column it reads, and Order casts its
            // own `total` to decimal:2 — which turned a count of 2 into "2.00"
            // on the pill.
            ->selectRaw('status, count(*) as tally')
            ->groupBy('status')
            ->pluck('tally', 'status');

        // The statuses pinned to the list as one-click pills. Store-wide and
        // editable from the pills themselves: the queue the owner lives in is
        // not the same set of statuses every month, and changing it should not
        // need a developer.
        $pinned = Setting::get('admin_order_quick_filters', ['pending', 'confirmed', 'processing', 'booked', 'shipped']);
        $quickFilters = collect(is_array($pinned) ? $pinned : explode(',', is_scalar($pinned) ? (string) $pinned : ''))
            ->map(fn ($s) => is_string($s) ? trim($s) : null)
            ->filter(fn ($s) => $s !== null && isset(Order::STATUSES[$s]))
            ->unique()
            ->take(self::MAX_QUICK_FILTERS)
            ->values();

        // What this page of the list adds up to. Deliberately the page, not the
        // whole filter: it is the set she can see and count against, and it
        // costs no extra query.
        $pageTotals = [
            'orders' => $orders->count(),
            'items' => (int) $orders->sum('items_count'),
            'value' => (float) $orders->sum('total'),
        ];

        // Plain-English name for whatever the list is showing, so the
        // fulfilment panel can say which orders it is counting.
        $queueLabel = match (true) {
            $selected->isEmpty() => 'All orders',
            $selected->count() === 1 => Order::STATUSES[$selected->first()],
            default => $selected->map(fn ($s) => Order::STATUSES[$s])->implode(' + '),
        };

        return view('admin.orders.index', [
            'orders' => $orders,
            'statuses' => Order::STATUSES,
            'pageTotals' => $pageTotals,
            'queueLabel' => $queueLabel,
            // What the dropdown shows. It can only express one value, so a
            // multi-pill selection reads as "all" there and the pills carry it.
            'status' => $selected->count() === 1 ? $selected->first() : 'all',
            'selectedStatuses' => $selected->all(),
            'statusCounts' => $statusCounts,
            // The sanitised term, so the search box and the pill links never
            // have to touch the raw (possibly array) query value.
            'search' => $term,
            'quickFilters' => $quickFilters->all(),
            'maxQuickFilters' => self::MAX_QUICK_FILTERS,
            'orderCounts' => $orderCounts,
            'bdCourierOn' => $bdCourier->isConfigured(),
            'bdHistory' => $bdCourier->isConfigured() ? $bdCourier->storedMany($phones) : [],
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

    /**
     * Choose which statuses sit on the orders list as one-click slicer pills.
     *
     * An empty submission is a valid answer — it means "no pills, just the
     * dropdown" — so it is stored rather than falling back to the defaults.
     */
    public function saveQuickFilters(Request $request)
    {
        $data = $request->validate([
            'statuses' => ['nullable', 'array', 'max:' . self::MAX_QUICK_FILTERS],
            'statuses.*' => ['string', Rule::in(array_keys(Order::STATUSES))],
        ]);

        Setting::put('admin_order_quick_filters', array_values(array_unique($data['statuses'] ?? [])));

        return back()->with('success', 'Quick filters updated.');
    }

    public function show(Order $order, CustomerInsight $insight, SteadfastService $steadfast)
    {
        $order->load('items.product.images', 'items.variant.image', 'history', 'shipment', 'customer');

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
                if ($live) {
                    $order->shipment->update(['status' => $live, 'response' => ['delivery_status' => $live]]);
                    $order->setRelation('shipment', $order->shipment->fresh());
                }

                // Replaced consignments first: one delivered after it was
                // replaced moves the order, and has to be known before the
                // current consignment's cancellation is weighed.
                $moved = $this->syncReplacedConsignments($order, $steadfast, function (string $cid) use ($steadfast) {
                    $raw = $steadfast->deliveryStatus($cid);

                    return $raw ? ['delivery_status' => $raw] : [];
                }, 'Courier sync');

                // A settled courier outcome moves the order with it.
                if ($live && $steadfast->applyCourierVerdict($order, $live, 'Courier sync')) {
                    $moved = true;
                }

                if ($moved) {
                    $order->refresh()->load('items.product.images', 'items.variant.image', 'history', 'shipment', 'customer');
                }
            } catch (\Throwable $e) {
                // keep last known status
            }
        }

        // Courier track record for this customer (from their shipments).
        //
        // One outcome per ORDER, read off its current consignment. An order
        // booked again (2026-09-17) has a replaced consignment too — usually
        // cancelled at Steadfast on purpose — and counting that as a separate
        // shipment would mark a customer who received her parcel as someone
        // who refused one.
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

        // BDCourier: render only what is already stored. Never call the API
        // here — lookups cost plan quota and this is a page view.
        //
        // stored(), not cached(): a result this shop paid for months ago still
        // says whether the number accepts parcels, and it is the whole point of
        // skipping a repeat buyer's automatic check that the earlier answer is
        // here. The panel labels how old it is.
        $bdCourier = app(BdCourierService::class);

        // Editing a booked order is allowed (owner's call, 2026-09-17), so the
        // page has to say when Steadfast's copy no longer matches the order,
        // and what "Book again with courier" would send in its place.
        $booked = (bool) $order->shipment?->consignment_id;
        $order->load('shipments');

        return view('admin.orders.show', [
            'order' => $order,
            'statuses' => Order::STATUSES,
            'insight' => $insight->forPhone($order->customer_phone, $order->id),
            'courier' => $courier,
            'courierDrift' => $booked ? $steadfast->driftFor($order) : [],
            'courierNow' => $booked ? $steadfast->payloadFor($order) : null,
            'replacedShipments' => $order->shipments->filter->isSuperseded()->sortByDesc('id')->values(),
            'balance' => $steadfast->balance(),
            'bdCourierOn' => $bdCourier->isConfigured(),
            'bdCourier' => filled($order->customer_phone) ? $bdCourier->stored($order->customer_phone) : null,
            'bdCourierAuto' => $bdCourier->autoCheckNewOrders(),
            // Why nothing was looked up on its own, so an empty panel says which
            // of the reasons applied rather than implying nobody has looked.
            // Null when automatic checks are off: there is then nothing to explain.
            'bdCourierSkip' => $bdCourier->autoCheckNewOrders()
                ? CheckOrderCourier::skipReason($order, $bdCourier)
                : null,
            // The order that prompts a block is where the owner is when she
            // decides (2026-09-22), so the Customer card blocks and unblocks.
            'blockedPhone' => bd_phone((string) $order->customer_phone) !== ''
                ? \App\Models\BlockedPhone::with('blockedBy:id,name')->where('phone', bd_phone((string) $order->customer_phone))->first()
                : null,
            // The amend form's "add a product" box searches (admin.orders.product-search)
            // instead of carrying the whole catalogue in the page.
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
            // Always posted, but an emptied box means none: see below.
            'shipping_cost' => ['present', 'nullable', 'numeric', 'min:0'],
            'discount' => ['present', 'nullable', 'numeric', 'min:0'],
            'adjustments' => ['nullable', 'array', 'max:20'],
            'adjustments.*.label' => ['nullable', 'string', 'max:60'],
            'adjustments.*.amount' => ['nullable', 'numeric', 'between:-1000000,1000000'],
            'reason' => ['nullable', 'string', 'max:200'],
            // Products being added to an existing order.
            'new_lines' => ['nullable', 'array', 'max:20'],
            'new_lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'new_lines.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'new_lines.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'new_lines.*.price' => ['nullable', 'numeric', 'min:0'],
        ], [], [
            // A rejected save is now shown on the page, so it has to read as
            // English rather than "The items.0.price field is required."
            'items.*.price' => 'unit price',
            'items.*.quantity' => 'quantity',
            'shipping_cost' => 'shipping',
            'adjustments.*.label' => 'adjustment label',
            'adjustments.*.amount' => 'adjustment amount',
            'new_lines.*.product_id' => 'added product',
            'new_lines.*.variant_id' => 'option',
            'new_lines.*.qty' => 'quantity',
            'new_lines.*.price' => 'unit price',
        ]);

        // Clearing the Shipping box is how "make delivery free" gets typed, and
        // the form's running total already counts a blank box as ৳0 — so it
        // showed the free total, then failed `required` on save with nothing on
        // the page to say so, and the old charge stayed (order 10084,
        // 2026-09-21). Blank means none, for the discount too.
        $data['shipping_cost'] = round((float) ($data['shipping_cost'] ?? 0), 2);
        $data['discount'] = round((float) ($data['discount'] ?? 0), 2);

        // A payload with neither kept lines nor new ones would empty the order.
        // The form always posts what it is showing, so this only fires on a
        // malformed request — but an order with no items is not an order.
        if (empty($data['items'] ?? []) && empty($data['new_lines'] ?? [])) {
            return back()->with('error', 'An order must keep at least one item.');
        }

        // A variable product without its variation is not a line anybody can
        // pack: the browser disables the button, but the request is the thing
        // that has to be true.
        foreach ($data['new_lines'] ?? [] as $line) {
            $product = \App\Models\Product::find($line['product_id']);
            if ($product && $product->variants()->where('is_active', true)->exists() && empty($line['variant_id'])) {
                return back()->with('error', $product->name.' has options — choose which one before saving.');
            }
        }

        DB::transaction(function () use ($order, $data) {
            $itemsById = $order->items->keyBy('id');
            $subtotal = 0.0;
            $changes = [];
            $wasShipping = (float) $order->shipping_cost;
            $wasDiscount = (float) $order->discount;

            // Stock is only this order's to move while it is actually holding
            // it. Once an order is cancelled or returned its units are already
            // back on the shelf (stock_restored), and adjusting again here
            // would invent inventory that does not exist.
            $holdsStock = ! $order->stock_restored
                && ! in_array($order->status, ['cancelled', 'returned'], true);

            $kept = [];

            foreach ($data['items'] ?? [] as $row) {
                $item = $itemsById->get((int) $row['id']);
                if (! $item) {
                    continue;
                }

                $kept[] = $item->id;
                $newQty = (int) $row['quantity'];
                $delta = $newQty - (int) $item->quantity;

                // Changing a quantity used to leave stock untouched, so an
                // order amended from 1 to 5 reserved four units it never took.
                if ($holdsStock && $delta !== 0) {
                    $this->moveStock($item, -$delta);
                    $changes[] = $item->name.' '.$item->quantity.' → '.$newQty;
                }

                $lineSubtotal = round((float) $row['price'] * $newQty, 2);
                $item->update([
                    'price' => $row['price'],
                    'quantity' => $newQty,
                    'subtotal' => $lineSubtotal,
                ]);
                $subtotal += $lineSubtotal;
            }

            // Anything the form no longer shows has been removed. Its units go
            // back on the shelf.
            foreach ($itemsById as $item) {
                if (in_array($item->id, $kept, true)) {
                    continue;
                }

                if ($holdsStock) {
                    $this->moveStock($item, (int) $item->quantity);
                }

                $changes[] = 'removed '.$item->name;
                $item->delete();
            }

            // And anything newly added takes its units now.
            foreach ($data['new_lines'] ?? [] as $line) {
                $product = \App\Models\Product::whereKey($line['product_id'])->lockForUpdate()->first();

                if (! $product) {
                    continue;
                }

                // A variation must belong to the product it was picked under —
                // the id arrives from the browser, so a mismatched pair would
                // otherwise take stock off the wrong shelf.
                $variant = null;
                if (! empty($line['variant_id'])) {
                    $variant = \App\Models\ProductVariant::whereKey($line['variant_id'])
                        ->where('product_id', $product->id)
                        ->lockForUpdate()->first();
                }

                $qty = (int) $line['qty'];
                $price = ($line['price'] ?? null) !== null && $line['price'] !== ''
                    ? round((float) $line['price'], 2)
                    : (float) ($variant?->effective_price ?? $product->price);

                $new = $order->items()->create([
                    'product_id' => $product->id,
                    'variant_id' => $variant?->id,
                    'name' => $product->name,
                    'sku' => $variant?->sku ?: $product->sku,
                    // Shown beside the line name on the order, printed on the
                    // packing list, and the only record of which one she bought.
                    'attributes' => $variant?->attributes ?: null,
                    'price' => $price,
                    'cost_price' => $product->cost_price,
                    'transport_cost' => $product->transport_cost,
                    'quantity' => $qty,
                    'subtotal' => round($price * $qty, 2),
                ]);

                if ($holdsStock) {
                    $this->moveStock($new, -$qty);
                }

                $changes[] = 'added '.$product->name.($variant ? ' ('.$variant->label.')' : '').' ×'.$qty;
                $subtotal += $new->subtotal;
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

            // The note only ever said the new total, so "did the free delivery
            // save?" had no answer in the history. Money first: the note keeps
            // six changes at most.
            $amounts = [];
            if (abs($wasShipping - $data['shipping_cost']) >= 0.01) {
                $amounts[] = 'shipping '.money($wasShipping).' → '.money($data['shipping_cost']);
            }
            if (abs($wasDiscount - $data['discount']) >= 0.01) {
                $amounts[] = 'discount '.money($wasDiscount).' → '.money($data['discount']);
            }
            $changes = array_merge($amounts, $changes);

            $order->history()->create([
                'status' => $order->status,
                'note' => 'Order amended — new total '.money($total)
                    .($changes ? '. '.implode(', ', array_slice($changes, 0, 6)) : '')
                    .($data['reason'] ?? null ? '. '.$data['reason'] : ''),
                'created_by' => auth()->user()?->name ?? 'Admin',
            ]);
        });

        // A value change on a booked parcel leaves the courier collecting the
        // old COD until the order is booked again — say so where it was made.
        if ($order->shipment?->consignment_id) {
            return back()->with('warning',
                'Order updated. The courier still has the old amount — use Book again with courier to send the new one.');
        }

        return back()->with('success', 'Order updated.');
    }

    /**
     * Typeahead for the amend form's "add a product" box.
     *
     * The picker used to be a <select> holding every published product, which
     * meant scrolling 110 options to find one — and it silently excluded every
     * variable product, so "she also wants the ring in size 8" had no answer
     * here at all. Variants travel with each result so the option is picked in
     * the same breath as the product.
     */
    public function productSearch(Request $request)
    {
        $q = trim((string) $request->query('q'));

        // Two characters of text, or a single digit — product #7 is a real
        // product, and requiring "07" to find it would be a riddle.
        if ($q === '' || (mb_strlen($q) < 2 && ! ctype_digit($q))) {
            return response()->json(['results' => []]);
        }

        $products = \App\Models\Product::query()
            ->where('status', 'published')
            ->with(['variants' => fn ($v) => $v->where('is_active', true)->orderBy('id'), 'primaryImage', 'images'])
            // Digits alone are almost always the owner reading a product ID off
            // a packing slip, so match the serial exactly as well as the text.
            ->where(function ($w) use ($q) {
                $w->where('name', 'like', '%'.$q.'%')
                  ->orWhere('sku', 'like', '%'.$q.'%');
                if (ctype_digit($q)) {
                    $w->orWhere('serial', (int) $q);
                }
            })
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', [$q.'%'])
            ->orderBy('name')
            ->limit(12)
            ->get();

        return response()->json([
            'results' => $products->map(fn ($p) => [
                'id' => $p->id,
                'serial' => $p->serial,
                'name' => $p->name,
                'sku' => $p->sku,
                'price' => (float) $p->price,
                'thumbnail' => $p->thumbnail,
                'stock' => $p->manage_stock ? (int) $p->stock_quantity : null,
                'has_variants' => (bool) $p->has_variants,
                'variants' => $p->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'label' => $v->label ?: ('Variant #'.$v->id),
                    'sku' => $v->sku,
                    'price' => (float) $v->effective_price,
                    'stock' => (int) $v->stock_quantity,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * The form for an order taken over the phone or on Messenger.
     */
    public function create(Request $request)
    {
        // Arriving from "Create order" on a call reminder (owner, 2026-09-17:
        // a reminder to ring a lead back, with the items she asked about). The
        // form opens with the number and those items, and saving it ticks the
        // reminder off. A reminder wins over a lead and a customer: it is the
        // call she is on, and it already carries whatever lead or customer it
        // came from. Only a plain id counts, as for ?customer= below.
        $reminderId = $request->query('reminder');
        $reminder = is_string($reminderId) && ctype_digit($reminderId)
            ? \App\Models\CallReminder::find((int) $reminderId)
            : null;

        // Arriving from "Convert to order" on a lead: the form opens with her
        // details and basket already in it, so the call is about closing the
        // sale rather than re-typing what we already captured.
        $cart = ! $reminder && ($id = (int) $request->query('from_cart'))
            ? \App\Models\AbandonedCart::find($id)
            : null;

        // Arriving from a customer's row or page (owner, 2026-09-17: "add
        // option so I can create new order from customer list"). A repeat
        // buyer ringing to order again should not have her name, number and
        // address re-typed off the customer page in the next tab — the shop
        // already holds all three.
        //
        // A lead wins when both are given: its basket is the more specific
        // thing she came to close, and the lead's own customer details ride
        // along with it. Anything that is not a plain id — "abc", ?customer[]=,
        // a customer since deleted — opens the blank form, as a bare link would.
        $customer = ($cart || $reminder) ? null : $this->customerById($request->query('customer'));

        return view('admin.orders.create', [
            'products' => \App\Models\Product::where('status', 'published')
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'price', 'has_variants', 'manage_stock', 'stock_quantity']),
            'shipInside' => (float) \App\Models\Setting::get('shipping_inside', config('store.shipping.inside_dhaka')),
            'shipOutside' => (float) \App\Models\Setting::get('shipping_outside', config('store.shipping.outside_dhaka')),
            'cart' => $cart,
            'customer' => $customer,
            'reminder' => $reminder,
            'prefill' => $reminder
                ? $this->reminderPrefill($reminder)
                : ($cart ? $this->cartPrefill($cart) : ($customer ? $this->customerPrefill($customer) : null)),
            'restored' => $this->restoredManualInput($request),
        ]);
    }

    /** A customer named by a plain numeric id, from a query string or a posted field. */
    protected function customerById(mixed $raw): ?Customer
    {
        return is_int($raw) || (is_string($raw) && ctype_digit($raw))
            ? Customer::find((int) $raw)
            : null;
    }

    /**
     * A known customer's details, shaped like a lead's for the manual order form.
     *
     * `picked` is what the form shows about who the order is for — where the
     * address came from, and whether they are blacklisted — the same card the
     * customer search on the form hands back, so a customer opened from the
     * list and one picked by name arrive identically.
     *
     * @return array{customer:array<string,mixed>,lines:array<int,mixed>,notices:array<int,string>,picked:array<string,mixed>}
     */
    protected function customerPrefill(Customer $customer): array
    {
        $card = $this->customerCards(collect([$customer]))->first();

        return [
            'customer' => \Illuminate\Support\Arr::only($card, ['name', 'phone', 'email', 'address', 'area', 'district', 'is_inside_dhaka']),
            'lines' => [],
            'notices' => [],
            'picked' => $card,
        ];
    }

    /**
     * Customers, each with the delivery details a new order for them starts from.
     *
     * The customers table holds no address, so the details come from the best
     * place that does: the default address she saved in her account (the one
     * checkout offers her), else wherever her most recent order went, else the
     * newest address she saved without marking one default. Each is a guess
     * about where THIS parcel goes, so the card says which it used and the form
     * asks for a check before saving (owner, 2026-09-17).
     *
     * The name, number and email are always the customer's own, never the
     * saved address's recipient: the order is matched to a customer by phone,
     * and a gift address in her book must not file the sale under the friend.
     *
     * Three queries however many customers — this answers a search box as she
     * types, so one query per result is not an option.
     *
     * @param  \Illuminate\Support\Collection<int, Customer>  $customers
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function customerCards(\Illuminate\Support\Collection $customers): \Illuminate\Support\Collection
    {
        $ids = $customers->pluck('id')->all();

        // The whole address book of every customer at once: a handful of rows
        // each, defaults first, newest first.
        $books = \App\Models\Address::whereIn('customer_id', $ids)
            ->orderByDesc('is_default')->orderByDesc('id')
            ->get()->groupBy('customer_id');

        // The newest order with an address, for each customer without a
        // default — found by a subselect rather than one query per customer.
        // Newest by when it was placed, not by id: imported history can carry
        // a later id than an order taken since.
        $withoutDefault = array_values(array_filter($ids, fn ($id) => ! $books->get($id)?->first()?->is_default));
        $lastOrders = $withoutDefault === [] ? collect() : Order::whereKey(
            Customer::whereKey($withoutDefault)
                ->select('id')
                ->addSelect(['last_order_id' => Order::select('id')
                    ->whereColumn('orders.customer_id', 'customers.id')
                    ->whereNotNull('shipping_address')->where('shipping_address', '!=', '')
                    ->latest()->latest('id')
                    ->limit(1)])
                ->get()->pluck('last_order_id')->filter()->all()
        )->get()->keyBy('customer_id');

        return $customers->map(function (Customer $customer) use ($books, $lastOrders) {
            $book = $books->get($customer->id, collect());
            $saved = $book->first(fn ($a) => $a->is_default);
            $lastOrder = $saved ? null : $lastOrders->get($customer->id);
            $saved ??= $lastOrder ? null : $book->first();

            $delivery = ['address' => null, 'area' => null, 'district' => null, 'is_inside_dhaka' => false];

            if ($saved) {
                $delivery = [
                    'address' => $saved->address,
                    'area' => $saved->area,
                    'district' => $saved->district,
                    'is_inside_dhaka' => (bool) $saved->is_inside_dhaka,
                ];
                $label = filled($saved->label) ? ' “'.$saved->label.'”' : '';
                $source = 'Delivery details from '.($saved->is_default ? 'their default saved address' : 'the address they saved')
                    .$label.' — check them before saving.';
            } elseif ($lastOrder) {
                $delivery = [
                    'address' => $lastOrder->shipping_address,
                    'area' => $lastOrder->area,
                    'district' => $lastOrder->district,
                    'is_inside_dhaka' => (bool) $lastOrder->is_inside_dhaka,
                ];
                $placed = store_time($lastOrder->created_at);
                $source = 'Delivery details from their last order on '
                    .$placed->format($placed->isSameYear(store_time(now())) ? 'j M' : 'j M Y')
                    .' — check them before saving.';
            } else {
                $source = 'No saved address or past order to copy delivery details from — type them in.';
            }

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'total_orders' => (int) $customer->total_orders,
                'blacklisted' => (bool) $customer->blacklisted,
            ] + $delivery + ['source' => $source];
        });
    }

    /**
     * Existing customers by name or number, for the picker on the manual order form.
     *
     * Owner, 2026-09-17: "when creating a new order, I need to be able to select
     * existing customer data from name". The customer list's New order button
     * covers starting from the list; this covers the form she already has open
     * with someone on the phone. Reachable by anyone who can take orders — staff
     * included, who cannot open the customer list itself.
     *
     * A number is matched however it was typed or pasted: "+880 1712-345678" is
     * stored as 01712345678, so the digits are normalised before they are
     * compared, and a part of a number still finds it.
     */
    public function customerSearch(Request $request)
    {
        $term = $request->query('q');
        $term = is_string($term) ? trim($term) : '';

        if (mb_strlen($term) < 2) {
            return response()->json(['customers' => []]);
        }

        // Only a term that could be a phone number is tried as one: "Nadia 017"
        // is a name, and matching every number containing 017 is noise.
        $digits = preg_match('/^[\d\s()+\-.]+$/', $term) ? bd_phone($term) : '';

        $found = Customer::query()
            ->where(function ($w) use ($term, $digits) {
                $w->where('name', 'like', '%'.$term.'%');
                if ($digits !== '') {
                    $w->orWhere('phone', 'like', '%'.$digits.'%');
                }
            })
            // The people most likely to be ringing again come first.
            ->orderByDesc('last_order_at')->orderByDesc('id')
            ->limit(8)
            ->get();

        return response()->json(['customers' => $this->customerCards($found)->values()]);
    }

    /**
     * What the form was holding when a save bounced, for the fields Alpine owns.
     *
     * The name and address boxes read old() in Blade and always came back. The
     * product lines, delivery charge, discount and zone did not: Alpine's
     * x-model writes its own state over the value attribute, so a bounced save
     * reopened on one blank line with the charge reset from the zone. Rare
     * while the only refusals were a mistyped number or a sold-out piece; a
     * coupon the server turns down (owner, 2026-09-17) makes a bounce routine,
     * and re-adding six lines because a code was for another number is not a
     * form anyone would keep using.
     *
     * The customer picked on the form rides along too, so a bounced save still
     * says whose address it is and still warns about a blacklisted buyer.
     *
     * @return array{lines:array<int,array<string,mixed>>,shipping:?float,discount:float,inside:bool,coupon:string,picked:?array<string,mixed>}|null
     */
    protected function restoredManualInput(Request $request): ?array
    {
        if (! $request->hasSession() || ! $request->session()->hasOldInput()) {
            return null;
        }

        $number = fn ($v) => is_numeric($v) ? (float) $v : null;

        $lines = collect($request->old('lines', []))
            ->filter(fn ($l) => is_array($l))
            ->map(fn ($l) => [
                'product_id' => is_numeric($l['product_id'] ?? null) ? (int) $l['product_id'] : '',
                'variant_id' => is_numeric($l['variant_id'] ?? null) ? (int) $l['variant_id'] : null,
                'qty' => max(1, (int) ($l['qty'] ?? 1)),
                'price' => $number($l['price'] ?? null) ?? '',
            ])
            ->values();

        // The option label is display only; the id is what is posted again.
        $labels = \App\Models\ProductVariant::whereIn('id', $lines->pluck('variant_id')->filter()->all())
            ->get()->mapWithKeys(fn ($v) => [$v->id => $v->label]);

        $coupon = $request->old('coupon_code');
        $picked = $this->customerById($request->old('picked_customer'));

        return [
            'lines' => $lines->map(fn ($l) => $l + ['variation' => $l['variant_id'] ? ($labels[$l['variant_id']] ?? '') : ''])->all(),
            'shipping' => $number($request->old('shipping_cost')),
            'discount' => $number($request->old('discount')) ?? 0.0,
            // An unticked box is simply absent from the old input.
            'inside' => (bool) $request->old('is_inside_dhaka'),
            'coupon' => is_string($coupon) ? $coupon : '',
            'picked' => $picked ? $this->customerCards(collect([$picked]))->first() : null,
        ];
    }

    /**
     * A lead's snapshot, re-read against the live catalogue and shaped for the
     * manual order form.
     *
     * The snapshot records what the customer saw, which may no longer be true:
     * a piece can have been unpublished, a size deactivated, the last one sold.
     * Rather than let the form fail on save, anything that cannot be carried
     * over is left off and said plainly, so she knows what to discuss before
     * she rings.
     *
     * @return array{customer:array<string,mixed>,lines:array<int,array<string,mixed>>,notices:array<int,string>}
     */
    protected function cartPrefill(\App\Models\AbandonedCart $cart): array
    {
        [$lines, $notices] = $this->snapshotLines($cart->items ?? [], 'the basket');

        if (empty($lines)) {
            $notices[] = 'Nothing in this basket can still be sold — add the products by hand.';
        }

        return [
            'customer' => [
                'name' => $cart->name,
                'phone' => $cart->phone,
                'email' => $cart->email,
                'address' => $cart->address,
                'area' => $cart->area,
                'is_inside_dhaka' => (bool) $cart->is_inside_dhaka,
            ],
            'lines' => $lines,
            'notices' => $notices,
        ];
    }

    /**
     * Snapshot rows — a lead's basket, or the items on a call reminder — as
     * order-form lines, with a plain word about each that cannot be carried
     * over as it was.
     *
     * Shared since call reminders (owner, 2026-09-17) snapshot items in the
     * shape a lead does, and "Create order" on one meets the same truths: a
     * piece unpublished, a size deactivated, the last one sold. `$holder` is
     * what the rows came from, in those words — "the basket", "the reminder".
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    protected function snapshotLines(iterable $rows, string $holder): array
    {
        $rows = collect($rows);

        $products = \App\Models\Product::with('variants')
            ->whereIn('id', $rows->pluck('product_id')->filter()->unique()->all())
            ->get()->keyBy('id');

        $lines = [];
        $notices = [];

        foreach ($rows as $row) {
            $name = $row['name'] ?? 'Item';
            $qty = max(1, (int) ($row['qty'] ?? 1));
            $product = $products->get($row['product_id'] ?? null);

            if (! $product || $product->status !== 'published') {
                $notices[] = $name.' is no longer on sale, so it is not on the form.';

                continue;
            }

            $variantId = null;
            $variant = null;

            if (! empty($row['variant_id'])) {
                $variant = $product->variants->firstWhere('id', $row['variant_id']);
                if ($variant && $variant->is_active) {
                    $variantId = $variant->id;
                } else {
                    $variant = null;
                    $notices[] = $name.': the option they chose is gone — agree another before saving.';
                }
            } elseif ($product->has_variants) {
                $notices[] = $name.' has options and '.$holder.' never recorded one — set the price by hand.';
            }

            $stock = $variant ? (int) $variant->stock_quantity : (int) $product->stock_quantity;
            if (($variant || $product->manage_stock) && $stock < $qty) {
                $notices[] = $name.': only '.max(0, $stock).' left, and '.$holder.' has '.$qty.'.';
            }

            $lines[] = [
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'qty' => $qty,
                // The price they were shown, not today's — that is the figure
                // she is ringing to honour. Editable like any other line.
                'price' => isset($row['price']) ? (float) $row['price'] : '',
                'variation' => $variant?->label,
            ];
        }

        return [$lines, $notices];
    }

    /**
     * A call reminder, shaped for the manual order form the way a lead is.
     *
     * Owner, 2026-09-17: "Create order" on a reminder opens this form with the
     * phone and the items filled in. Who it is for follows the number, as the
     * order itself will: a customer the shop knows arrives exactly as opening
     * the form from her page does — her own name, and delivery details from her
     * saved address or last order — and anyone else arrives as the name and
     * number on the reminder. A reminder made from a lead borrows the address
     * the shopper typed at checkout when nothing better is on file.
     *
     * The items are re-read against the live catalogue like a lead's basket;
     * a reminder noted without items opens on the usual blank line.
     *
     * @return array{customer:array<string,mixed>,lines:array<int,array<string,mixed>>,notices:array<int,string>,picked?:array<string,mixed>}
     */
    protected function reminderPrefill(\App\Models\CallReminder $reminder): array
    {
        $customer = $reminder->customer?->phone === $reminder->phone
            ? $reminder->customer
            : Customer::firstWhere('phone', $reminder->phone);

        $prefill = $customer ? $this->customerPrefill($customer) : [
            'customer' => [
                'name' => $reminder->name,
                'phone' => $reminder->phone,
                'email' => null,
                'address' => null,
                'area' => null,
                'district' => null,
                'is_inside_dhaka' => false,
            ],
            'lines' => [],
            'notices' => [],
        ];

        $cart = $reminder->abandonedCart;
        if ($cart && blank($prefill['customer']['address'] ?? null) && filled($cart->address)) {
            $prefill['customer'] = array_merge($prefill['customer'], [
                'email' => ($prefill['customer']['email'] ?? null) ?: $cart->email,
                'address' => $cart->address,
                'area' => $cart->area,
                'is_inside_dhaka' => (bool) $cart->is_inside_dhaka,
            ]);

            if (isset($prefill['picked'])) {
                $prefill['picked']['source'] = 'Delivery details from the checkout this reminder was made from — check them before saving.';
            }
        }

        if (! empty($reminder->items)) {
            [$lines, $notices] = $this->snapshotLines($reminder->items, 'the reminder');

            if (empty($lines)) {
                $notices[] = 'Nothing on this reminder can still be sold — add the products by hand.';
            }

            $prefill['lines'] = $lines;
            $prefill['notices'] = array_merge($prefill['notices'], $notices);
        }

        return $prefill;
    }

    /**
     * Record an order the owner took by hand.
     *
     * A large share of this shop's sales arrive as a DM. Doing it through the
     * public storefront meant the owner's own browser fired the Pixel and the
     * sale was attributed to her session — so every manual order quietly
     * corrupted the ad data she pays for.
     */
    public function storeManual(Request $request, \App\Actions\CreateManualOrder $creator)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', new \App\Rules\BdPhone],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['required', 'string', 'max:500'],
            'area' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'is_inside_dhaka' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:'.implode(',', array_keys(Order::STATUSES))],
            'abandoned_cart_id' => ['nullable', 'integer', 'exists:abandoned_carts,id'],
            'reminder_id' => ['nullable', 'integer', 'exists:call_reminders,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.price' => ['nullable', 'numeric', 'min:0'],
            // Checked, priced and spent inside CreateManualOrder's transaction,
            // under the same row lock checkout takes — a refusal comes back as
            // an error on this field, with the reason in plain words.
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ]);

        $data['is_inside_dhaka'] = $request->boolean('is_inside_dhaka');

        try {
            $order = $creator->handle($data, $data['lines']);
        } catch (\App\Exceptions\CheckoutException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // A call reminder that became this sale (owner, 2026-09-17): the call
        // is made, so it leaves Due now and the bell, saying which order it
        // turned into. After the order exists, never before — a save that
        // bounces must leave the reminder waiting.
        $reminderClosed = ($reminderId = $data['reminder_id'] ?? null)
            && \App\Models\CallReminder::find($reminderId)?->closeWithOrder($order, $request->user()?->id);
        if ($reminderClosed) {
            \App\Services\AdminAlerts::flush();
        }
        $alsoReminder = $reminderClosed ? ' The call reminder is marked done.' : '';

        // A lead she chased and closed. CreateManualOrder has already flipped
        // every cart on this phone to recovered; this records which one became
        // this order, so the lead can be opened from the sale and back again.
        if ($cartId = $data['abandoned_cart_id'] ?? null) {
            $order->forceFill(['abandoned_cart_id' => $cartId])->save();

            return redirect()->route('admin.orders.show', $order)
                ->with('success', 'Order '.$order->order_number.' created, and the lead is marked recovered.'.$alsoReminder);
        }

        return redirect()->route('admin.orders.show', $order)
            ->with('success', 'Order '.$order->order_number.' created.'.$alsoReminder);
    }

    /**
     * The coupons waiting for a phone number, for the manual order form.
     *
     * The owner gives coupons to numbers (owner, 2026-09-17: "whenever that
     * phone number is used, the customer gets the coupon applied against that
     * number"). The storefront applies them at checkout by itself; an order she
     * takes on the phone has no cart to do that, so without this the coupon she
     * promised is forgotten exactly when the customer rings to use it. The form
     * asks as soon as it has a number and offers what comes back — it never
     * applies anything here, and saving re-checks the code under a row lock.
     *
     * Only ever advisory: the form carries on as normal if this fails.
     */
    public function couponLookup(Request $request)
    {
        $phone = $request->query('phone');

        return response()->json([
            'coupons' => \App\Models\Coupon::assignedTo(is_string($phone) ? $phone : null)
                ->map(fn (\App\Models\Coupon $coupon) => [
                    'code' => $coupon->code,
                    'label' => $coupon->label,
                    'summary' => $this->couponSummary($coupon),
                    // Enough for the form to estimate the saving on the lines
                    // in front of it. It only does so for a whole-order coupon;
                    // a scoped one depends on categories and sale prices the
                    // form does not hold, so it is worked out on save.
                    'type' => $coupon->type,
                    'value' => (float) $coupon->value,
                    'applies_to' => $coupon->applies_to ?: 'all',
                    'exclude_sale_items' => (bool) $coupon->exclude_sale_items,
                    'free_shipping' => (bool) $coupon->free_shipping,
                    'min_order' => $coupon->min_order !== null ? (float) $coupon->min_order : null,
                ])
                ->values(),
        ]);
    }

    /** A coupon in a few words: "৳200 off + free delivery · on selected products · min order ৳1,000". */
    protected function couponSummary(\App\Models\Coupon $coupon): string
    {
        $value = (float) $coupon->value;

        $saving = match (true) {
            $value <= 0 => '',
            $coupon->type === 'percent' => rtrim(rtrim(number_format($value, 2), '0'), '.').'% off',
            default => money($value).' off',
        };

        if ($coupon->free_shipping) {
            $saving = $saving === '' ? 'Free delivery' : $saving.' + free delivery';
        }

        return collect([
            $saving,
            match ($coupon->applies_to) {
                'products' => 'on selected products',
                'categories' => 'on selected categories',
                default => null,
            },
            $coupon->exclude_sale_items ? 'not on sale items' : null,
            (float) $coupon->min_order > 0 ? 'min order '.money($coupon->min_order) : null,
            $coupon->min_qty ? 'min '.$coupon->min_qty.' pieces' : null,
        ])->filter()->implode(' · ');
    }

    /**
     * Set an order's payment status by hand.
     *
     * Delivery sets this automatically, but not every case is a delivery: a
     * refund, a bank transfer taken up front, or a parcel the courier says was
     * delivered and the shop knows was not.
     */
    public function updatePayment(Request $request, Order $order)
    {
        $data = $request->validate([
            'payment_status' => ['required', 'in:unpaid,paid,refunded'],
        ]);

        if ($order->payment_status === $data['payment_status']) {
            return back();
        }

        $was = $order->payment_status;
        $order->update(['payment_status' => $data['payment_status']]);

        $order->history()->create([
            'status' => $order->status,
            'note' => 'Payment marked '.$data['payment_status'].' (was '.$was.')',
            'created_by' => auth()->user()?->name ?? 'Admin',
        ]);

        return back()->with('success', 'Payment status updated.');
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
     * Allowed on a booked order too (owner's call, 2026-09-17). This used to be
     * blocked once a consignment existed, telling her to cancel it and re-book
     * — but there was no way to re-book, so the only way out of a wrong address
     * was a parcel she knew would fail. The courier still holds its own copy,
     * so the save says so plainly, the order page flags exactly what is out of
     * date, and "Book again with courier" sends the corrected details.
     */
    public function updateDetails(Request $request, Order $order)
    {
        $booked = (bool) $order->shipment?->consignment_id;

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

        if ($booked) {
            return back()->with('warning',
                'Saved. The courier still has the old details — use Book again with courier to send these.');
        }

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
     *
     * One exception since orders can be booked again (2026-09-17): a shipped or
     * delivered order keeps its status when it is re-booked, and its NEW parcel
     * still needs a label — see Order::isAwaitingLabel(), which the order
     * page's Print label button uses too.
     */
    public function labels(Request $request)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('ids'))));

        $orders = Order::with('items.product.images', 'items.variant.image', 'shipment')
            ->awaitingLabel()
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->latest()
            ->take(200)
            ->get();

        // If the admin ticked orders that aren't awaiting a label, say so rather
        // than silently printing fewer than they expected.
        $skipped = $ids ? count($ids) - $orders->count() : 0;

        return view('admin.orders.labels', compact('orders', 'skipped'));
    }

    /**
     * Create Steadfast consignments for several orders at once.
     *
     * Orders that already have a consignment are skipped, and stay skipped
     * now that an order CAN be booked again (2026-09-17): a second booking
     * creates a second parcel and can cost a second delivery charge, so it is
     * only ever done one order at a time, from the order page, behind a
     * confirmation that says exactly that. The message names the skipped
     * orders so she knows which ones to open.
     *
     * Each order is booked under the same lock as the order page's buttons,
     * and whether it is already booked is read again inside that lock: the
     * list below is loaded once, before the loop, and another tab can book
     * one of these orders while the loop is still working through the others.
     */
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
        $skipped = [];
        $failed = [];
        $busy = [];
        $notices = [];

        foreach ($orders as $order) {
            $outcome = $this->withBookingLock($order, function () use ($order, $steadfast) {
                $order->refresh();

                if ($order->shipment()->first()?->consignment_id) {
                    return 'skipped';
                }

                $shipment = $steadfast->createForOrder($order);
                if (! $shipment) {
                    return 'failed';
                }

                if (in_array($order->status, Order::PRE_BOOKING_STATUSES, true)) {
                    app(TransitionOrderStatus::class)->handle(
                        $order, 'booked', 'Consignment created at Steadfast', auth()->user()?->name ?? 'Admin',
                    );
                }

                return 'created';
            }, fn () => 'busy');

            if ($outcome === 'created') {
                $created++;

                if ($notice = $steadfast->lastNotice()) {
                    $notices[] = '#'.$order->order_number.': '.$notice;
                }
            } elseif ($outcome === 'failed') {
                $failed[] = '#'.$order->order_number.': '.rtrim((string) $steadfast->lastError(), '. ');
            } elseif ($outcome === 'busy') {
                $busy[] = '#'.$order->order_number;
            } else {
                $skipped[] = '#'.$order->order_number;
            }
        }

        $msg = "Sent {$created} order(s) to Steadfast."
            // They have just left the default (Processing) view, so say where
            // they went rather than letting them look like they vanished.
            .($created ? ' They are now "Booked with courier" — print their labels from there.' : '')
            .($skipped
                ? ' Skipped '.count($skipped).' already booked ('.implode(', ', array_slice($skipped, 0, 10))
                    .(count($skipped) > 10 ? ' and '.(count($skipped) - 10).' more' : '')
                    .') — to send one of them again, open the order and use Book again with courier.'
                : '')
            .($busy
                ? ' Skipped '.count($busy).' being booked from another page at the same moment ('.implode(', ', $busy)
                    .') — refresh and check them before sending again.'
                : '')
            .($failed
                ? ' '.count($failed).' failed — '.implode(' · ', array_slice($failed, 0, 3))
                    .(count($failed) > 3 ? ' · and '.(count($failed) - 3).' more' : '').'.'
                : '')
            .($notices ? ' '.implode(' ', $notices) : '');

        return back()->with($failed ? 'error' : ($busy || $notices ? 'warning' : 'success'), $msg);
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
            $target->customer?->recountOrders();
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

    /**
     * Move stock for a single order line. Positive puts units back on the
     * shelf, negative takes them off.
     */
    protected function moveStock(\App\Models\OrderItem $item, int $by): void
    {
        if ($by === 0) {
            return;
        }

        if ($item->variant_id && ($variant = \App\Models\ProductVariant::find($item->variant_id))) {
            $variant->increment('stock_quantity', $by);
        }

        $product = $item->product_id ? \App\Models\Product::find($item->product_id) : null;

        if ($product && $product->manage_stock) {
            $product->increment('stock_quantity', $by);
            $product->update(['in_stock' => $product->fresh()->stock_quantity > 0]);
        }
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
        // Cancelled and returned orders are not sales — see Customer::recountOrders().
        $customer?->recountOrders();
    }

    public function pushToSteadfast(Order $order, SteadfastService $steadfast)
    {
        if (! $steadfast->isConfigured()) {
            return back()->with('error', 'Steadfast API keys are not configured (Settings → check .env).');
        }

        return $this->withBookingLock($order, function () use ($order, $steadfast) {
            $current = $order->shipment()->first();

            if ($current?->consignment_id) {
                return back()->with('error',
                    'This order already has a Steadfast consignment (#'.$current->consignment_id.'). '
                    .'To send the courier a new one, use Book again with courier on the order page.');
            }

            $shipment = $steadfast->createForOrder($order->load('items'));

            // Steadfast's own words, not "check the logs": the owner has no logs,
            // and "the recipient phone must be 11 digits" tells her what to fix.
            // A timeout is not a refusal — it may have been booked — and says so.
            if (! $shipment) {
                return back()->with('error', $steadfast->lastOutcomeUnknown()
                    ? $steadfast->lastError()
                    : 'Steadfast did not accept the booking: '.$steadfast->lastError());
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

            // An existing consignment linked rather than created: worth a look.
            if ($notice = $steadfast->lastNotice()) {
                return back()->with('warning', $notice);
            }

            return back()->with('success', "Consignment created. Tracking: {$shipment->tracking_code}");
        });
    }

    /**
     * Book an order with Steadfast again, from what the order says NOW.
     *
     * The owner's call (2026-09-17): once an order had been sent to the courier
     * it could never be sent again, so a changed COD, a corrected address or a
     * replacement parcel had no way to reach Steadfast. This creates a new
     * consignment from the order's current values — under "<order number>-N",
     * because Steadfast refuses an invoice it has already seen — and marks the
     * earlier one replaced, so only the new one drives the order from here on.
     *
     * It does NOT cancel the earlier consignment at Steadfast — that stays the
     * owner's call, made in the Steadfast panel — and the confirmation and the
     * result both say so, because a forgotten one can be picked up and charged
     * as a second delivery.
     *
     * Status: an order still being prepared, or one that was cancelled or
     * returned, is booked again, which puts it back on the label queue (and,
     * from cancelled, takes its released stock back). An order already booked,
     * shipped or delivered keeps its status and gets a history note instead —
     * so the customer is never re-notified about a parcel that did not change
     * status.
     *
     * A consignment booked as prepaid (COD 0) is not replaced by one that
     * collects money without the form saying so (confirm_cod). A cancellation
     * resets a paid order to unpaid, so booking that order again would
     * otherwise quietly ask the rider to collect the full total from a
     * customer who has already paid it.
     */
    public function rebookSteadfast(Request $request, Order $order, SteadfastService $steadfast)
    {
        if (! $steadfast->isConfigured()) {
            return back()->with('error', 'Steadfast API keys are not configured (Settings → Integrations).');
        }

        // Never booked: this is simply a first booking.
        if (! $order->shipment()->first()?->consignment_id) {
            return $this->pushToSteadfast($order, $steadfast);
        }

        return $this->withBookingLock($order, function () use ($request, $order, $steadfast) {
            $previous = $order->shipment()->first();

            // The form carries the consignment it was shown. If that is no longer
            // the current one, this page is stale (a second tab, a resubmitted
            // form) and booking now would send a third parcel nobody meant to.
            $replaces = $request->input('replaces');
            if (filled($replaces) && (int) $replaces !== (int) $previous->id) {
                return back()->with('error',
                    'This order has already been booked again since the page was opened — its current consignment is #'
                    .$previous->consignment_id.'. Check the page before booking it once more.');
            }

            $codNow = (float) $steadfast->payloadFor($order->load('items'))['cod_amount'];
            if ($previous->cod_amount !== null && (float) $previous->cod_amount == 0.0 && $codNow > 0
                && ! $request->boolean('confirm_cod')) {
                return back()->with('error',
                    'Consignment #'.$previous->consignment_id.' was booked as prepaid (COD '.money(0).'), but booking again now would ask the rider to collect '
                    .money($codNow).' — the order is marked '.($order->payment_status ?: 'unpaid').'. If the customer has already paid, mark the payment paid first. '
                    .'If they really do owe '.money($codNow).', tick "Collect '.money($codNow).' on delivery" and book again.');
            }

            $from = $order->status;
            $locked = $order->isStatusLocked();
            $pointsWereRefunded = (float) $order->points_discount > 0 && (int) $order->points_redeemed === 0;

            $shipment = $steadfast->createForOrder($order, rebook: true);

            if (! $shipment) {
                return back()->with('error', $steadfast->lastOutcomeUnknown()
                    ? rtrim((string) $steadfast->lastError(), '. ').'. Consignment #'.$previous->consignment_id
                        .' is still the current one here.'
                    : 'Steadfast did not accept the new booking: '
                        .rtrim((string) $steadfast->lastError(), '. ').'. Nothing changed — consignment #'
                        .$previous->consignment_id.' is still the current one.');
            }

            $by = auth()->user()?->name ?? 'Admin';
            $note = 'Re-booked with Steadfast: consignment '.$shipment->consignment_id
                .' replaces '.$previous->consignment_id.' (COD '.money($shipment->cod_amount).')';
            $fromLabel = Order::STATUSES[$from] ?? $from;

            $reopens = in_array($from, ['cancelled', 'returned'], true) && ! $locked;

            if (in_array($from, Order::PRE_BOOKING_STATUSES, true) || $reopens) {
                // The shared action, so stock, payment and history move exactly as
                // they do for any other status change — and "booked" sends the
                // customer nothing.
                app(TransitionOrderStatus::class)->handle($order, 'booked', $note, $by);
                $statusLine = 'The order moved from '.$fromLabel.' to Booked with courier.';

                if ($reopens && $pointsWereRefunded) {
                    $statusLine .= ' The points the customer spent on it were refunded when it was '.$from
                        .', and booking again does not take them back.';
                }
            } else {
                $order->history()->create(['status' => $from, 'note' => $note, 'created_by' => $by]);
                $statusLine = 'The order stays '.$fromLabel.'.';

                if (in_array($from, ['cancelled', 'returned'], true)) {
                    $statusLine .= ' It was not moved to Booked because the courier had confirmed the earlier'
                        .' consignment as delivered — change the status by hand if this parcel replaces it.';
                }
            }

            $message = 'Booked again with Steadfast — new consignment #'.$shipment->consignment_id
                .($shipment->tracking_code ? ' (tracking '.$shipment->tracking_code.')' : '')
                .', invoice '.$shipment->invoice.', COD '.money($shipment->cod_amount).'. '.$statusLine;

            if (! str_contains(strtolower((string) $previous->status), 'cancel')) {
                $message .= ' Consignment #'.$previous->consignment_id.' was NOT cancelled at Steadfast — '
                    .'cancel it in the Steadfast panel if that parcel should not go out.';
            }

            if ($notice = $steadfast->lastNotice()) {
                $message .= ' '.$notice;
            }

            return back()->with('success', $message);
        });
    }

    /**
     * Run a Steadfast booking for one order with nobody else booking it at the
     * same moment.
     *
     * A double click, or the same form in two tabs, would otherwise create two
     * consignments — two parcels, two delivery charges. The button locks itself
     * in the browser too; this is the part that holds when the browser does not.
     *
     * $busy answers when someone else holds the lock; by default that is a
     * redirect back with an explanation, the bulk send passes its own.
     */
    protected function withBookingLock(Order $order, \Closure $book, ?\Closure $busy = null)
    {
        try {
            $lock = \Illuminate\Support\Facades\Cache::lock('steadfast-booking:'.$order->id, 60);
            $acquired = $lock->get();
        } catch (\Throwable $e) {
            // A cache store that cannot lock must not stop the shop booking parcels.
            return $book();
        }

        if (! $acquired) {
            return $busy ? $busy() : back()->with('error',
                'This order is being booked with Steadfast right now. Wait a moment, then refresh the page before trying again.');
        }

        try {
            return $book();
        } finally {
            $lock->release();
        }
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
            // Either the per-run cap or the clock stopped the run. Which one
            // does not change what to do next, so the message doesn't say.
            $parts[] = $result['skipped'].' not reached — select those and run it again';
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

        // Consignments replaced by a later booking are read too, so the order
        // page shows what became of them (was the old parcel really cancelled?).
        // Read BEFORE the current consignment's verdict is applied: one that was
        // delivered after being replaced is the parcel the customer has, and it
        // decides whether the current one's cancellation may cancel the order.
        $replacedDelivered = $this->syncReplacedConsignments(
            $order, $steadfast, fn (string $cid) => $steadfast->statusByConsignmentId($cid), 'Courier sync',
        );

        // A settled courier outcome moves the order with it.
        $moved = $steadfast->applyCourierVerdict($order, $raw, 'Courier sync');

        if ($replacedDelivered) {
            $delivered = $order->replacedConsignmentDelivered();
            $current = $order->shipment()->first();

            return back()->with('warning', 'Delivery status refreshed — consignment #'.$delivered?->consignment_id
                .', which had been replaced, was delivered, so the order is marked '.$order->fresh()->status.'.'
                .($current && ! $current->isSettled()
                    ? ' Consignment #'.$current->consignment_id.' appears unused — cancel it in the Steadfast panel.'
                    : ''));
        }

        if ($moved) {
            return back()->with('success', 'Delivery status refreshed — order marked '.$order->fresh()->status.'.');
        }

        return back()->with('success', 'Delivery status refreshed.');
    }

    /**
     * Record what the courier now says about this order's replaced
     * consignments that have not settled yet — and let a DELIVERY among them
     * move the order (SteadfastService::applyReplacedDelivery()). Their
     * cancellations and in-flight states are only recorded.
     *
     * Settled ones are skipped: their answer will not change, and one already
     * delivered before it was replaced says nothing new.
     *
     * @param  \Closure(string): array  $read  Consignment id → Steadfast's status answer.
     * @return bool True if a replaced consignment's delivery moved the order.
     */
    protected function syncReplacedConsignments(Order $order, SteadfastService $steadfast, \Closure $read, string $by): bool
    {
        $moved = false;

        $order->shipments()->whereNotNull('superseded_at')->whereNotNull('consignment_id')->get()
            ->reject(fn ($old) => $old->isSettled())
            ->each(function ($old) use ($order, $steadfast, $read, $by, &$moved) {
                try {
                    $oldStatus = $read((string) $old->consignment_id);
                } catch (\Throwable $e) {
                    return; // keep last known status
                }

                if (empty($oldStatus['delivery_status'])) {
                    return;
                }

                $old->update(['status' => $oldStatus['delivery_status'], 'response' => $oldStatus]);

                if ($steadfast->applyReplacedDelivery($order, $old, $oldStatus['delivery_status'], $by)) {
                    $moved = true;
                }
            });

        return $moved;
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
