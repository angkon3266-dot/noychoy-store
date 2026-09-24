<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\CheckCustomersCourier;
use App\Models\Customer;
use App\Rules\BdPhone;
use App\Services\BdCourierService;
use App\Services\CustomerInsight;
use App\Services\Meta\MetaQueueRunner;
use App\Services\SmsService;
use App\Support\CourierTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request, BdCourierService $bdCourier)
    {
        $sort = $request->query('sort', 'spend');
        $tier = $this->requestedTier($request);

        $customers = $this->courierTier($this->filteredCustomers($request), $tier)
            // Only the customer's own columns: the join also brings an `id`
            // and a `phone`, and the courier row's id would overwrite the
            // customer's on every hydrated model.
            ->select('customers.*')
            ->with('courierCheck')
            ->when(
                $sort === 'parcels',
                // Never-checked customers have no parcel count and sort last
                // (NULL is the smallest value in both MySQL and SQLite), with
                // spend deciding between them.
                fn ($q) => $q->orderByDesc('courier_checks.total_parcel')->orderByDesc('customers.total_spent'),
                fn ($q) => $q->orderBy(match ($sort) {
                    'orders' => 'customers.total_orders',
                    'recent' => 'customers.last_order_at',
                    'points' => 'customers.points',
                    'name' => 'customers.name',
                    default => 'customers.total_spent',
                }, $sort === 'name' ? 'asc' : 'desc'),
            )
            // A tiebreaker, so pages 2, 3… neither repeat nor skip a customer
            // among the hundreds tied at "not checked" or ৳0.
            ->orderByDesc('customers.id')
            ->paginate(30)
            ->withQueryString();

        // Top-line analytics across the whole customer base.
        $analytics = [
            'total' => Customer::count(),
            'repeat' => Customer::where('total_orders', '>', 1)->count(),
            'members' => Customer::whereNotNull('password')->count(),
            'new_month' => Customer::where('created_at', '>=', now()->startOfMonth())->count(),
            'avg_spend' => round((float) Customer::where('total_orders', '>', 0)->avg('total_spent'), 0),
            'lifetime' => (float) Customer::sum('total_spent'),
            'blacklisted' => Customer::where('blacklisted', true)->count(),
        ];

        // Courier value tiers (owner, 2026-09-17). Everything here is read
        // from lookups already stored — rendering this page never calls
        // BDCourier, because every call costs the shop a credit.
        $courier = [
            'tiers' => CourierTier::all(),
            'counts' => $this->courierTierCounts($request),
            'active' => $tier,
            'configured' => $bdCourier->isConfigured(),
            // Store-wide, not filtered: the batch button works through every
            // customer, whatever the list happens to be showing.
            'unchecked' => $this->uncheckedCustomers()->count(),
            'batchSize' => CheckCustomersCourier::SIZE,
            'batch' => $this->courierBatchView(CheckCustomersCourier::status()),
        ];

        return view('admin.customers.index', compact('customers', 'analytics', 'sort', 'courier'));
    }

    /**
     * The list's own filters — search, checkboxes, spend and order bounds —
     * with each customer's stored courier result joined alongside.
     *
     * Shared by the table and the tier slicer so the two can never disagree:
     * with "Repeat" ticked, the pills count repeat buyers only. The tier itself
     * is deliberately NOT applied here — a pill must keep showing its own total
     * while another pill is the active one, as the order list's status pills do.
     *
     * Columns are spelled out with their table: `courier_checks` also has an
     * `id`, a `phone` and a `created_at`.
     */
    protected function filteredCustomers(Request $request): Builder
    {
        return Customer::query()
            // courier_checks.phone is unique, so the join never repeats a customer.
            ->leftJoin('courier_checks', 'courier_checks.phone', '=', 'customers.phone')
            ->when($request->query('q'), function ($q, $term) {
                $q->where(fn ($w) => $w->where('customers.name', 'like', "%{$term}%")
                    ->orWhere('customers.phone', 'like', "%{$term}%")
                    ->orWhere('customers.email', 'like', "%{$term}%"));
            })
            ->when($request->boolean('repeat'), fn ($q) => $q->where('customers.total_orders', '>', 1))
            ->when($request->boolean('blacklisted'), fn ($q) => $q->where('customers.blacklisted', true))
            ->when($request->boolean('members'), fn ($q) => $q->whereNotNull('customers.password'))
            ->when($request->boolean('has_points'), fn ($q) => $q->where('customers.points', '>', 0))
            ->when($request->boolean('has_email'), fn ($q) => $q->whereNotNull('customers.email')->where('customers.email', '!=', ''))
            ->when($request->boolean('new_month'), fn ($q) => $q->where('customers.created_at', '>=', now()->startOfMonth()))
            ->when($request->filled('min_spend'), fn ($q) => $q->where('customers.total_spent', '>=', (float) $request->query('min_spend')))
            ->when($request->filled('max_spend'), fn ($q) => $q->where('customers.total_spent', '<=', (float) $request->query('max_spend')))
            ->when($request->filled('min_orders'), fn ($q) => $q->where('customers.total_orders', '>=', (int) $request->query('min_orders')))
            // Lapsed = has ordered but not in the last N days (default 30 when toggled).
            ->when($request->boolean('lapsed'), fn ($q) => $q->where('customers.total_orders', '>', 0)
                ->where('customers.last_order_at', '<', now()->subDays((int) ($request->query('lapsed_days') ?: 30))));
    }

    /** ?tier= as a known slicer key, or null for "All" (and for anything made up). */
    protected function requestedTier(Request $request): ?string
    {
        $tier = $request->query('tier');

        return is_string($tier) && ($tier === CourierTier::UNCHECKED || CourierTier::find($tier)) ? $tier : null;
    }

    /**
     * Narrow a query that already carries the courier_checks join to one tier.
     * "Not checked" means a phone that could be looked up and never was — a
     * customer with no number cannot be checked, so is not waiting to be.
     */
    protected function courierTier(Builder $query, ?string $tier): Builder
    {
        if ($tier === CourierTier::UNCHECKED) {
            return $query->whereNull('courier_checks.id')
                ->whereNotNull('customers.phone')
                ->where('customers.phone', '!=', '');
        }

        if ($band = CourierTier::find($tier)) {
            $query->where('courier_checks.total_parcel', '>=', $band['min']);

            if ($band['max'] !== null) {
                $query->where('courier_checks.total_parcel', '<', $band['max']);
            }
        }

        return $query;
    }

    /**
     * Every slicer pill's count in one grouped query.
     *
     * The CASE is built from CourierTier, highest floor first, so the brackets
     * are defined once. Its values are integers and keys from that class, never
     * request input, which is why they are written into the SQL directly.
     *
     * @return array<string, int> keyed by tier key, plus all / unchecked / no-phone
     */
    protected function courierTierCounts(Request $request): array
    {
        $tiers = CourierTier::all();
        $lowest = array_key_first($tiers);
        $whens = collect(array_reverse($tiers))
            ->reject(fn ($t) => $t['key'] === $lowest)
            ->map(fn ($t) => 'when courier_checks.total_parcel >= '.(int) $t['min']." then '".$t['key']."'")
            ->implode(' ');

        $rows = $this->filteredCustomers($request)
            ->toBase()
            ->selectRaw("case when customers.phone is null or customers.phone = '' then 'no-phone'"
                ." when courier_checks.id is null then 'unchecked' {$whens} else '{$lowest}' end as bucket, count(*) as tally")
            ->groupBy('bucket')
            ->pluck('tally', 'bucket');

        $counts = ['all' => 0, CourierTier::UNCHECKED => 0, 'no-phone' => 0] + array_fill_keys(array_keys($tiers), 0);

        foreach ($rows as $bucket => $tally) {
            $counts[$bucket] = (int) $tally;
            $counts['all'] += (int) $tally;
        }

        return $counts;
    }

    /** Customers with a phone that has never been looked up, store-wide. */
    protected function uncheckedCustomers(): Builder
    {
        return Customer::query()
            ->whereNotNull('customers.phone')
            ->where('customers.phone', '!=', '')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('courier_checks')
                ->whereColumn('courier_checks.phone', 'customers.phone'));
    }

    /**
     * Who the next "Check next 50" press looks up: never checked, highest
     * lifetime spend first, then whoever ordered most recently — the owner's
     * order of who is worth a credit first.
     *
     * Numbers that are not Bangladeshi mobiles are passed over rather than
     * sent: BDCourier cannot answer for them, and left in they would head
     * every batch forever.
     *
     * @return array<int, int> customer ids
     */
    protected function nextCustomersToCheck(int $limit): array
    {
        return $this->uncheckedCustomers()
            ->orderByDesc('customers.total_spent')
            ->orderByDesc('customers.last_order_at')
            ->orderBy('customers.id')
            ->get(['customers.id', 'customers.phone'])
            ->filter(fn (Customer $c) => (bool) preg_match(BdPhone::PATTERN, (string) $c->phone))
            ->take($limit)
            ->map(fn (Customer $c) => (int) $c->id)
            ->values()
            ->all();
    }

    /**
     * One BDCourier lookup for one customer — the list's "Check" button and
     * the customer page's Check / Refresh. One credit, on a click, never on a
     * page view; the result is stored per phone, so the order pages of the
     * same number show it too.
     */
    public function courierCheck(Customer $customer, BdCourierService $bdCourier)
    {
        if (blank($customer->phone)) {
            return back()->with('error', 'This customer has no phone number to check.');
        }

        $result = $bdCourier->check($customer->phone);

        if (! ($result['ok'] ?? false)) {
            return back()->with('error', $result['error'] ?? 'Courier check failed.');
        }

        $total = (int) ($result['summary']['total_parcel'] ?? 0);

        if ($total === 0) {
            return back()->with('success', 'Courier history for '.$customer->name.': no parcels with any courier yet.');
        }

        return back()->with('success', 'Courier history for '.$customer->name.': '.number_format($total).' parcels ('
            .CourierTier::forTotal($total)['label'].'), '
            .CourierTier::delivery($result['summary']['success_ratio'] ?? 0, $total)['label'].'.');
    }

    /**
     * "Check next 50 customers": queue BDCourier lookups for the next batch of
     * never-checked customers (owner's choice, 2026-09-17: batches on a click,
     * nothing automatic). See CheckCustomersCourier for why it is queued.
     */
    public function courierBatch(BdCourierService $bdCourier, MetaQueueRunner $runner)
    {
        if (! $bdCourier->isConfigured()) {
            return back()->with('error', 'BDCourier is not configured. Add the API key under Admin → Integrations.');
        }

        if (CheckCustomersCourier::status()['running'] ?? false) {
            return back()->with('error', 'A courier check is already running. Wait for it to finish before starting the next batch.');
        }

        $ids = $this->nextCustomersToCheck(CheckCustomersCourier::SIZE);

        if ($ids === []) {
            $left = $this->uncheckedCustomers()->count();

            return back()->with('warning', $left > 0
                ? 'Nothing left to check: the '.$left.' customer(s) not yet checked have no valid Bangladeshi mobile number.'
                : 'Every customer with a phone number has been checked already.');
        }

        // The status check above is for the message; this is the guard. Two
        // presses that both got past it still cannot both start.
        if (CheckCustomersCourier::start($ids) === null) {
            return back()->with('error', 'A courier check is already running. Wait for it to finish before starting the next batch.');
        }

        // The scheduler drains the queue within a minute; this just makes the
        // usual case start straight away. A no-op where exec() is disabled.
        $runner->kick();

        return back()->with('success', 'Checking '.count($ids).' customers in the background (up to '.count($ids)
            .' BDCourier credits). You can keep working — the progress shows above the customer list.');
    }

    /** The batch banner polls this while a batch runs. */
    public function courierBatchStatus()
    {
        return response()->json($this->courierBatchView(CheckCustomersCourier::status()) ?? ['running' => false]);
    }

    /**
     * The batch's progress record plus the sentence and colour the banner
     * shows. Built here, once, so the first paint and every poll say the same.
     *
     * @param  array<string, mixed>|null  $status
     * @return array<string, mixed>|null
     */
    protected function courierBatchView(?array $status): ?array
    {
        if ($status === null) {
            return null;
        }

        $progress = $status['done'].' of '.$status['total'].' customers';

        [$tone, $message] = match (true) {
            $status['running'] => ['info', 'Checking '.$status['total'].' customers… '.$status['done'].' done'
                .($status['failed'] ? ' · '.$status['failed'].' failed' : '')],
            $status['interrupted'] => ['warning', 'The last courier check stopped after '.$progress
                .'. Press “Check next '.CheckCustomersCourier::SIZE.' customers” to carry on.'],
            (bool) $status['stopped'] => ['error', 'Courier check stopped after '.$progress.': '.$status['error']],
            default => [$status['failed'] ? 'warning' : 'success', 'Courier check finished: '
                .$status['checked'].' of '.$status['total'].' customers checked'
                .($status['failed'] ? ' · '.$status['failed'].' failed ('.$status['error'].')' : '')
                .($status['skipped'] ? ' · '.$status['skipped'].' skipped (checked meanwhile, or no usable number)' : '')
                .'.'],
        };

        return $status + ['tone' => $tone, 'message' => $message];
    }

    /** All personalized offers across customers — the "customized offers" hub. */
    public function offersIndex(Request $request)
    {
        $status = $request->query('status', 'live');

        $offers = \App\Models\CustomerOffer::with('customer')
            ->when($status === 'live', fn ($q) => $q->live())
            ->when($status === 'redeemed', fn ($q) => $q->whereNotNull('redeemed_at'))
            ->when($status === 'expired', fn ($q) => $q->whereNotNull('expires_at')->where('expires_at', '<', now()))
            ->latest()
            ->paginate(40)->withQueryString();

        return view('admin.customers.offers', compact('offers', 'status'));
    }

    public function show(Customer $customer, CustomerInsight $insight, BdCourierService $bdCourier)
    {
        $orders = $customer->orders()->with(['shipment', 'items.product.images'])->latest()->get();

        return view('admin.customers.show', [
            'customer' => $customer,
            'orders' => $orders,
            'purchases' => $this->purchases($orders),
            'insight' => $customer->phone ? $insight->forPhone($customer->phone) : null,
            'offers' => $customer->offers()->get(),
            'pointLog' => $customer->pointTransactions()->take(20)->get(),
            'allCategories' => \App\Models\Category::orderBy('name')->get(['id', 'name']),
            'allProducts' => \App\Models\Product::orderBy('name')->get(['id', 'name']),
            // Whatever lookup is stored for the number, however old — a stale
            // parcel count still places the customer, and the card says when
            // it was taken. Never fetched here: a page view spends no credit.
            'courierCheck' => filled($customer->phone) ? $customer->courierCheck : null,
            'bdCourierOn' => $bdCourier->isConfigured(),
        ]);
    }

    /**
     * Every piece this customer has actually bought, one line per product
     * (owner, 2026-09-24) — "what has she got from us so far", answered without
     * opening each order in turn.
     *
     * Cancelled and returned orders are left out, the rule the spend and order
     * counts on this page already follow (Order::NOT_SALES): a parcel refused
     * at the door was never bought.
     *
     * Grouped by product where one is still attached and by name otherwise, so
     * a piece deleted from the catalogue since still reads as what was sold —
     * the order line keeps the name it was bought under.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Order>  $orders  with `items.product`
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function purchases($orders)
    {
        return $orders
            ->reject(fn ($order) => in_array($order->status, \App\Models\Order::NOT_SALES, true))
            // The line needs to know which order it came on, and the orders are
            // already in hand — so the inverse is set rather than queried back.
            ->flatMap(fn ($order) => $order->items->each(fn ($item) => $item->setRelation('order', $order)))
            ->groupBy(fn ($item) => $item->product_id ?: 'name:'.\Illuminate\Support\Str::lower($item->name))
            ->map(function ($lines) {
                // Newest first within the group, so "last bought" and the name
                // shown are both taken from the most recent purchase.
                $lines = $lines->sortByDesc(fn ($line) => $line->order->created_at)->values();
                $latest = $lines->first();

                return [
                    'name' => $latest->name,
                    'product' => $latest->product,
                    'quantity' => (int) $lines->sum('quantity'),
                    'spent' => (float) $lines->sum(fn ($line) => (float) $line->subtotal),
                    'orders' => $lines->pluck('order.id')->unique()->count(),
                    'last_order' => $latest->order,
                ];
            })
            ->sortByDesc(fn ($row) => $row['last_order']->created_at)
            ->values();
    }

    /** Export customers (name, phone, address, spend…) to an Excel-friendly CSV. */
    public function export(Request $request)
    {
        $rows = $this->courierTier(
            Customer::query()->leftJoin('courier_checks', 'courier_checks.phone', '=', 'customers.phone'),
            // The Export button carries the list's query string, so an export
            // taken while the "1,000+" pill is on is that tier's call list.
            $this->requestedTier($request),
        )
            ->select('customers.*')
            ->when($request->boolean('members'), fn ($q) => $q->whereNotNull('customers.password'))
            ->when($request->filled('min_spend'), fn ($q) => $q->where('customers.total_spent', '>=', (float) $request->query('min_spend')))
            ->orderByDesc('customers.total_spent')
            ->with(['defaultAddress', 'courierCheck'])
            ->get();

        $filename = \Illuminate\Support\Str::slug(store_name()).'-customers-'.now()->format('Y-m-d').'.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads Bangla/symbols correctly
            fputcsv($out, ['Name', 'Phone', 'Email', 'Address', 'Area', 'District', 'Orders', 'Total spent', 'Points', 'Last order', 'Registered',
                'Courier tier', 'Total parcels', 'Delivered %']);
            foreach ($rows as $c) {
                $a = $c->defaultAddress;
                // Blank, not "0", for a number never looked up: nought parcels
                // is an answer BDCourier gives, and this is not that.
                $check = $c->courierCheck;
                fputcsv($out, [
                    $c->name,
                    $c->phone,
                    $c->email,
                    $a?->address,
                    $a?->area,
                    $a?->district,
                    $c->total_orders,
                    number_format((float) $c->total_spent, 2, '.', ''),
                    $c->points,
                    $c->last_order_at?->format('Y-m-d'),
                    $c->created_at?->format('Y-m-d'),
                    $check ? CourierTier::forTotal((int) $check->total_parcel)['label'] : (filled($c->phone) ? 'Not checked' : null),
                    $check?->total_parcel,
                    $check ? number_format((float) $check->success_ratio, 2, '.', '') : null,
                ]);
            }
            fclose($out);
        }, $filename, $headers);
    }

    /** Assign a personalised offer to a customer (shown in their rewards panel). */
    public function storeOffer(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:400'],
            'type' => ['required', 'in:'.implode(',', array_keys(\App\Models\CustomerOffer::TYPES))],
            'value' => ['nullable', 'numeric', 'min:0'],
            'code' => ['nullable', 'string', 'max:40'],
            'applies_to' => ['required', 'in:'.implode(',', array_keys(\App\Models\CustomerOffer::SCOPES))],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'expires_at' => ['nullable', 'date'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        // Keep only the scope list that matches the chosen target.
        $data['category_ids'] = $data['applies_to'] === 'categories' ? array_values($data['category_ids'] ?? []) : null;
        $data['product_ids'] = $data['applies_to'] === 'products' ? array_values($data['product_ids'] ?? []) : null;

        $offer = $customer->offers()->create($data + ['is_active' => true]);

        // "Bonus points" offers credit immediately and stay as a record.
        if ($offer->type === 'points' && (int) $offer->value > 0) {
            app(\App\Services\LoyaltyService::class)->award($customer, (int) $offer->value, 'adjust', 'Bonus: '.$offer->title, $offer);
        }

        // Optional SMS notification to the customer.
        if ($request->boolean('send_sms') && filled($offer->message) && filled($customer->phone)) {
            try {
                app(\App\Services\SmsService::class)->send($customer->phone, $offer->message);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // In-app + web-push nudge so the member knows about their new offer.
        app(\App\Services\NotificationService::class)
            ->notifyOfferGranted([$customer->id], $offer->title, $offer->message, $offer->rewardText());

        return back()->with('success', 'Offer added for '.$customer->name.'.');
    }

    public function destroyOffer(Customer $customer, \App\Models\CustomerOffer $offer)
    {
        // Cast both sides — on some MySQL/PDO configs integer columns come back as
        // strings, and a strict === would wrongly 404 a valid delete.
        abort_unless((int) $offer->customer_id === (int) $customer->id, 404);
        $offer->delete();

        return back()->with('success', 'Offer removed.');
    }

    /** Edit an existing personalised offer's key fields. */
    public function updateOffer(Request $request, Customer $customer, \App\Models\CustomerOffer $offer)
    {
        abort_unless((int) $offer->customer_id === (int) $customer->id, 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:400'],
            'type' => ['required', 'in:'.implode(',', array_keys(\App\Models\CustomerOffer::TYPES))],
            'value' => ['nullable', 'numeric', 'min:0'],
            'code' => ['nullable', 'string', 'max:40'],
            'expires_at' => ['nullable', 'date'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $offer->update($data);

        return back()->with('success', 'Offer updated.');
    }

    /** Mark an offer active/paused (the "mark" toggle). */
    public function toggleOffer(Customer $customer, \App\Models\CustomerOffer $offer)
    {
        abort_unless((int) $offer->customer_id === (int) $customer->id, 404);
        $offer->update(['is_active' => ! $offer->is_active]);

        return back()->with('success', $offer->is_active ? 'Offer activated.' : 'Offer paused.');
    }

    /** Assign one personalised offer to many selected customers at once. */
    public function bulkOffer(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:customers,id'],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:'.implode(',', array_keys(\App\Models\CustomerOffer::TYPES))],
            'value' => ['nullable', 'numeric', 'min:0'],
            'code' => ['nullable', 'string', 'max:40'],
            'expires_at' => ['nullable', 'date'],
            'message' => ['nullable', 'string', 'max:300'],
        ]);

        $loyalty = app(\App\Services\LoyaltyService::class);
        $customers = Customer::whereIn('id', $data['ids'])->get();

        $sample = null;
        foreach ($customers as $customer) {
            $offer = $customer->offers()->create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'message' => $data['message'] ?? null,
                'type' => $data['type'],
                'value' => $data['value'] ?? 0,
                'code' => $data['code'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'is_active' => true,
            ]);
            $sample ??= $offer;
            if ($offer->type === 'points' && (int) $offer->value > 0) {
                $loyalty->award($customer, (int) $offer->value, 'adjust', 'Bonus: '.$offer->title, $offer);
            }
        }

        if ($customers->isEmpty()) {
            return back()->with('error', 'No customers matched the selection.');
        }

        $reward = $sample?->rewardText() ?? 'a special reward';
        $message = filled($data['message'] ?? null) ? $data['message'] : null;
        $msg = 'Offer applied to '.$customers->count().' customer(s).';

        // Bell + browser web push (admin-controlled, on by default in the form).
        if ($request->boolean('send_push')) {
            $notifications = app(\App\Services\NotificationService::class);
            $notifications->notifyOfferGranted($customers->pluck('id')->all(), $data['title'], $message, $reward);
            $msg .= $notifications->lastPushQueued > 0
                ? ' Web push queued to '.$notifications->lastPushQueued.' device(s).'
                : ' (No push-subscribed devices among them — they’ll still see it in the bell.)';
        }

        // Optional SMS — queued so a big list doesn't block the request.
        if ($request->boolean('send_sms')) {
            $text = $message ?: trim($data['title'].' — '.$reward
                .(filled($data['code'] ?? null) ? '. Use code '.strtoupper($data['code']) : '')
                .'. '.store_name());
            $phones = $customers->pluck('phone')->filter()->values();
            foreach ($phones->chunk(100) as $chunk) {
                \App\Jobs\SendSegmentSms::dispatch($chunk->values()->all(), $text);
            }
            $msg .= ' SMS queued to '.$phones->count().' number(s).';
        }

        return back()->with('success', $msg);
    }

    /** Manually add or subtract loyalty points. */
    public function adjustPoints(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'points' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:160'],
        ]);

        app(\App\Services\LoyaltyService::class)->award(
            $customer, (int) $data['points'], 'adjust', $data['reason'] ?: 'Manual adjustment',
        );

        return back()->with('success', 'Points adjusted.');
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160'],
            'gender' => ['nullable', 'in:male,female,other'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'blacklisted' => ['nullable', 'boolean'],
        ]);
        $data['blacklisted'] = $request->boolean('blacklisted');
        $data['gender'] = $data['gender'] ?: null;
        $customer->update($data);

        return back()->with('success', 'Customer updated.');
    }

    public function sendSms(Request $request, Customer $customer, SmsService $sms)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:500']]);
        if (! $customer->phone) {
            return back()->with('error', 'This customer has no phone number.');
        }
        $ok = $sms->send($customer->phone, $data['message']);

        return back()->with($ok ? 'success' : 'error', $ok ? 'SMS sent.' : 'SMS failed (check SMS settings/logs).');
    }

    public function importForm()
    {
        return view('admin.customers.import');
    }

    /** Bulk-import customers from a CSV. Header: name, phone, email, notes */
    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if (! $handle) {
            return back()->with('error', 'Could not read the file.');
        }

        $header = fgetcsv($handle);
        if (! $header) {
            return back()->with('error', 'The file appears to be empty.');
        }
        // Excel writes a UTF-8 BOM in front of the first header cell, which made
        // that column "\u{FEFF}name" instead of "name" — every row then looked
        // nameless and the whole import silently skipped.
        $cols = array_map(
            fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))),
            $header,
        );

        if (! in_array('name', $cols, true) || ! in_array('phone', $cols, true)) {
            fclose($handle);

            return back()->with('error', 'The file needs "name" and "phone" columns. Found: '.implode(', ', $cols).'.');
        }

        $created = 0;
        $updated = 0;
        $reasons = ['no_name' => 0, 'no_phone' => 0, 'scientific' => 0, 'bad_phone' => 0, 'duplicate_in_file' => 0];
        $badSamples = [];
        $seen = [];

        while (($line = fgetcsv($handle)) !== false) {
            // Ignore the trailing blank line Excel leaves behind.
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $row = array_combine($cols, array_pad($line, count($cols), null));
            $rawPhone = trim((string) ($row['phone'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                $reasons['no_name']++;

                continue;
            }
            if ($rawPhone === '') {
                $reasons['no_phone']++;

                continue;
            }

            // Excel turns long numbers into "8.80192E+12" and the real digits are
            // gone for good — worth calling out rather than lumping in with
            // ordinary typos, because the fix is in the spreadsheet.
            if (stripos($rawPhone, 'e+') !== false) {
                $reasons['scientific']++;
                if (count($badSamples) < 5) {
                    $badSamples[] = $name.' — '.$rawPhone;
                }

                continue;
            }

            // Canonical 01XXXXXXXXX, so 8801…, +880 1…, 1… and 01… all become
            // one customer and match the phone format orders are stored under.
            $phone = bd_phone($rawPhone);

            if (! preg_match(\App\Rules\BdPhone::PATTERN, $phone)) {
                $reasons['bad_phone']++;
                if (count($badSamples) < 5) {
                    $badSamples[] = $name.' — '.$rawPhone;
                }

                continue;
            }

            if (isset($seen[$phone])) {
                $reasons['duplicate_in_file']++;

                continue;
            }
            $seen[$phone] = true;

            $existing = Customer::where('phone', $phone)->first();
            if ($existing) {
                $existing->update(array_filter([
                    'name' => $name,
                    'email' => trim((string) ($row['email'] ?? '')) ?: null,
                    'notes' => trim((string) ($row['notes'] ?? '')) ?: $existing->notes,
                ]));
                $updated++;
            } else {
                Customer::create([
                    'name' => $name,
                    'phone' => $phone,
                    'email' => trim((string) ($row['email'] ?? '')) ?: null,
                    'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
                ]);
                $created++;
            }
        }
        fclose($handle);

        // Tell the admin exactly what was dropped and why — "skipped 1107" on
        // its own gives them nothing to act on.
        $notes = [];
        if ($reasons['scientific']) {
            $notes[] = $reasons['scientific'].' phone(s) were mangled by Excel into scientific notation (e.g. 8.80192E+12) — '
                .'format the phone column as Text in your spreadsheet and export again';
        }
        if ($reasons['bad_phone']) {
            $notes[] = $reasons['bad_phone'].' phone(s) were not valid Bangladeshi mobile numbers';
        }
        if ($reasons['no_phone']) {
            $notes[] = $reasons['no_phone'].' row(s) had no phone number';
        }
        if ($reasons['no_name']) {
            $notes[] = $reasons['no_name'].' row(s) had no name';
        }
        if ($reasons['duplicate_in_file']) {
            $notes[] = $reasons['duplicate_in_file'].' duplicate row(s) in the file were merged';
        }

        $flash = redirect()->route('admin.customers.index')
            ->with('success', "Imported {$created} new customer(s), updated {$updated}.");

        if ($notes) {
            $flash->with('import_errors', array_merge(
                $notes,
                $badSamples ? ['Examples: '.implode(' · ', $badSamples)] : [],
            ));
        }

        return $flash;
    }
}
