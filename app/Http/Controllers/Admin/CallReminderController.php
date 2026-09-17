<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbandonedCart;
use App\Models\CallReminder;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Rules\BdPhone;
use App\Services\AdminAlerts;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calls to make later — a lead's number, the pieces they asked about, and when.
 *
 * The owner, 2026-09-17: "create a reminder option where I can add customer
 * lead phone number along with items to call later". Her choices: a due list,
 * an alert in the notification bell when one comes due, and on every reminder
 * a Call, a WhatsApp and a "Create order" that opens the manual order form
 * with the number and the items already filled in.
 *
 * Staff reach this too (User::sectionsFor): they take the phone orders, so
 * they are the ones ringing back.
 */
class CallReminderController extends Controller
{
    /** The list's tabs. Due now is where the work is, so it is the default. */
    public const TABS = [
        'due' => 'Due now',
        'upcoming' => 'Upcoming',
        'done' => 'Done',
    ];

    public const DEFAULT_TAB = 'due';

    public function index(Request $request)
    {
        // is_string rather than a cast: ?tab[]= and ?q[]= must not 500.
        $tab = $request->query('tab');
        $tab = is_string($tab) && isset(self::TABS[$tab]) ? $tab : self::DEFAULT_TAB;
        $q = $request->query('q');
        $q = is_string($q) ? trim($q) : '';

        $reminders = CallReminder::query()
            // Due now oldest first: the call that has waited longest is the
            // one to make first. Upcoming soonest first. Done newest first.
            ->when($tab === 'due', fn ($b) => $b->due()->orderBy('due_at')->orderBy('id'))
            ->when($tab === 'upcoming', fn ($b) => $b->upcoming()->orderBy('due_at')->orderBy('id'))
            ->when($tab === 'done', fn ($b) => $b->done()->orderByDesc('done_at')->orderByDesc('id'))
            ->when($q !== '', fn ($b) => $b->matching($q))
            ->with([
                'customer:id,name,phone,locale,blacklisted',
                'order:id,order_number',
                'creator:id,name',
                'closer:id,name',
            ])
            ->paginate(25)
            ->withQueryString()
            // Named on every page link, the default included, so page 2 of
            // Due now can never be read as another tab.
            ->appends(['tab' => $tab]);

        return view('admin.reminders.index', [
            'reminders' => $reminders,
            'tab' => $tab,
            'tabs' => self::TABS,
            'q' => $q,
            'counts' => [
                'due' => CallReminder::due()->count(),
                'upcoming' => CallReminder::upcoming()->count(),
            ],
            'variantLabels' => $this->variantLabels($reminders->getCollection()),
            'eveningAhead' => CallReminder::eveningStillAhead(),
        ]);
    }

    /**
     * A new reminder, blank or started from what the shop already holds.
     *
     * ?customer=<id> — "Remind me to call" on a customer's page: her name and
     * number. ?cart=<id> — the same button on an abandoned cart: the number and
     * name the shopper typed and the basket she left, remembered against the
     * lead. A lead wins when both are given, as it does on the order form: its
     * basket is the more specific thing to call about.
     */
    public function create(Request $request)
    {
        $reminder = new CallReminder;

        // The lead's basket is lead-desk data, so it only prefills for a role
        // that can open the lead itself. The customer prefill is a name and a
        // number, which the order form's customer search already gives staff.
        $cart = $request->user()?->canAccess('abandoned')
            ? $this->byId(AbandonedCart::class, $request->query('cart'))
            : null;
        $customer = $cart ? null : $this->byId(Customer::class, $request->query('customer'));

        if ($cart) {
            $reminder->fill(['phone' => $cart->phone, 'name' => $cart->name]);
            $reminder->abandoned_cart_id = $cart->id;
            $reminder->items = $cart->items ?: null;
        } elseif ($customer) {
            $reminder->fill(['phone' => $customer->phone, 'name' => $customer->name]);
        }

        return view('admin.reminders.create', $this->formData($request, $reminder) + [
            'cart' => $cart,
        ]);
    }

    public function store(Request $request)
    {
        $reminder = new CallReminder;
        $reminder->forceFill($this->validated($request) + [
            'created_by' => $request->user()?->id,
        ])->save();

        AdminAlerts::flush();

        return redirect()->route('admin.reminders.index', ['tab' => $reminder->tab()])
            ->with('success', 'Reminder saved — call '.$this->who($reminder).' '.$this->when($reminder).'.');
    }

    public function edit(Request $request, CallReminder $reminder)
    {
        $reminder->loadMissing(['closer:id,name', 'order:id,order_number']);

        return view('admin.reminders.edit', $this->formData($request, $reminder) + [
            'cart' => null,
        ]);
    }

    public function update(Request $request, CallReminder $reminder)
    {
        $reminder->forceFill($this->validated($request, $reminder))->save();

        AdminAlerts::flush();

        return redirect()->route('admin.reminders.index', ['tab' => $reminder->tab()])
            ->with('success', $reminder->isDone()
                ? 'Reminder updated. It stays under Done.'
                : 'Reminder updated — call '.$this->who($reminder).' '.$this->when($reminder).'.');
    }

    /** Not now: +1 hour, this evening at 7, or tomorrow at 11 — in Dhaka time. */
    public function snooze(Request $request, CallReminder $reminder)
    {
        $data = $request->validate(
            ['until' => ['required', 'string', 'in:'.implode(',', array_keys(CallReminder::SNOOZES))]],
            ['until.*' => 'Choose how long to snooze the reminder for.'],
        );

        if ($reminder->isDone()) {
            return back()->with('warning', 'That call is already marked done — edit it to set a new time.');
        }

        $reminder->forceFill(['due_at' => CallReminder::snoozeTarget($data['until'])])->save();

        AdminAlerts::flush();

        return back()->with('success', 'Snoozed — call '.$this->who($reminder).' '.$this->when($reminder).'.');
    }

    /** The call is made. What came of it is optional, and kept on the Done tab. */
    public function complete(Request $request, CallReminder $reminder)
    {
        $data = $request->validate(
            ['outcome' => ['nullable', 'string', 'max:500']],
            ['outcome.max' => 'Keep what happened on the call under 500 characters.'],
        );

        if ($reminder->isDone()) {
            return back()->with('warning', 'That call was already marked done.');
        }

        $reminder->markDone($data['outcome'] ?? null, $request->user()?->id);

        AdminAlerts::flush();

        return back()->with('success', 'Marked done — '.$this->who($reminder).'.');
    }

    public function destroy(CallReminder $reminder)
    {
        $tab = $reminder->tab();
        $editPage = rtrim((string) parse_url(route('admin.reminders.edit', $reminder), PHP_URL_PATH), '/');

        $reminder->delete();

        AdminAlerts::flush();

        // back(), unless "back" is this reminder's own edit page, which would
        // now only bounce off the missing-reminder redirect.
        $cameFromEdit = rtrim((string) parse_url(url()->previous(), PHP_URL_PATH), '/') === $editPage;

        return ($cameFromEdit ? redirect()->route('admin.reminders.index', ['tab' => $tab]) : back())
            ->with('success', 'Reminder deleted.');
    }

    // ── The form ─────────────────────────────────────────────────────────────

    /**
     * Everything the create and edit forms share.
     *
     * The items, the due date and the due time are Alpine state, which writes
     * over a value attribute — so after a save bounces they come back from the
     * old input here rather than from old() in Blade, or the form would reopen
     * on the reminder as it was instead of what was just typed.
     */
    protected function formData(Request $request, CallReminder $reminder): array
    {
        $bounced = $request->hasSession() && $request->session()->hasOldInput();

        $rows = $bounced
            ? collect($request->old('items', []))->filter(fn ($r) => is_array($r) && filled($r['product_id'] ?? null))
            : collect($reminder->items ?? []);

        [$items, $notices] = $this->formRows($rows);

        $due = $reminder->due_at ? store_time($reminder->due_at) : null;
        $phone = bd_phone((string) $request->old('phone', $reminder->phone));

        return [
            'reminder' => $reminder,
            'notices' => $bounced ? [] : $notices,
            'products' => $this->catalogue($items),
            'initial' => [
                'items' => $items,
                'due_date' => (string) $request->old('due_date', $due?->format('Y-m-d') ?? ''),
                'due_time' => (string) $request->old('due_time', $due?->format('H:i') ?? ''),
                // Who the number belongs to, if anyone — the same card the order
                // form's customer search returns, so the page can keep it up to
                // date from that search as the number is changed.
                'matched' => $this->customerCard(
                    strlen($phone) === 11 ? Customer::firstWhere('phone', $phone) : null
                ),
            ],
        ];
    }

    /**
     * Snapshot rows as the form's item picker holds them, and what could not
     * be carried over.
     *
     * A lead's basket is a record of what she saw, and a product can have been
     * deleted since. A piece that is merely unpublished stays — the reminder is
     * a note of what she asked about, and the order form says plainly that it
     * cannot be sold when the time comes.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    protected function formRows(Collection $rows): array
    {
        $products = Product::with('variants:id,product_id')
            ->whereIn('id', $rows->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->all())
            ->get(['id'])->keyBy('id');

        $items = [];
        $notices = [];

        foreach ($rows as $row) {
            $name = $row['name'] ?? 'An item';
            $product = $products->get((int) ($row['product_id'] ?? 0));

            if (! $product) {
                $notices[] = $name.' is no longer in the catalogue, so it is not on the reminder.';

                continue;
            }

            $variantId = filled($row['variant_id'] ?? null) ? (int) $row['variant_id'] : null;
            if ($variantId && ! $product->variants->contains('id', $variantId)) {
                $notices[] = $name.': the option they chose no longer exists.';
                $variantId = null;
            }

            $items[] = [
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'qty' => max(1, min(99, (int) ($row['qty'] ?? 1))),
                // The price they were shown, when a lead's basket says; the
                // catalogue's is filled in on save for anything picked here.
                'price' => is_numeric($row['price'] ?? null) ? (float) $row['price'] : null,
            ];
        }

        return [$items, $notices];
    }

    /**
     * The products the picker offers: everything on sale, with its options,
     * plus whatever the reminder already names even if it has since come off
     * sale — a reminder is edited as it stands, not silently emptied.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function catalogue(array $items): Collection
    {
        $productIds = array_values(array_unique(array_column($items, 'product_id')));
        $variantIds = array_values(array_filter(array_column($items, 'variant_id')));

        return Product::query()
            ->where(fn ($w) => $w->where('status', 'published')->orWhereIn('id', $productIds))
            ->with(['variants' => fn ($v) => $v
                ->where(fn ($w) => $w->where('is_active', true)->orWhereIn('id', $variantIds))
                ->orderBy('id')])
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'price', 'status'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'price' => (float) $p->price,
                'on_sale' => $p->status === 'published',
                'variants' => $p->variants->map(fn (ProductVariant $v) => [
                    'id' => $v->id,
                    'label' => $v->label ?: 'Option #'.$v->id,
                    // Not effective_price: that reads the product back once per
                    // option, and the product is right here.
                    'price' => $v->price !== null ? (float) $v->price : (float) $p->price,
                    'active' => (bool) $v->is_active,
                ])->values(),
            ])
            ->values();
    }

    /**
     * The request, checked and shaped for the row.
     *
     * The number is stored canonically and matched to a customer by it — the
     * same rule orders follow — so the link is right however it was typed, and
     * follows the number when it is corrected. Items are snapshotted with the
     * catalogue's name and price at the time; a price carried from a lead's
     * basket is kept, because that is the figure she was shown.
     */
    protected function validated(Request $request, ?CallReminder $reminder = null): array
    {
        // A line left on "Choose…" is a row she added and did not use, not a
        // mistake worth refusing the save over.
        $rows = $request->input('items');
        if (is_array($rows)) {
            $request->merge(['items' => array_values(array_filter(
                $rows, fn ($r) => is_array($r) && filled($r['product_id'] ?? null)
            ))]);
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20', new BdPhone],
            'name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'due_time' => ['required', 'date_format:H:i'],
            'items' => ['nullable', 'array', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'abandoned_cart_id' => ['nullable', 'integer', 'exists:abandoned_carts,id'],
        ], [
            'phone.required' => 'Enter the number to call.',
            'phone.max' => 'Please enter a valid Bangladeshi mobile number (01XXXXXXXXX).',
            'name.max' => 'Keep the name under 120 characters.',
            'notes.max' => 'Keep the notes under 2,000 characters.',
            'due_date.required' => 'Choose the day to call.',
            'due_date.date_format' => 'Choose the day to call.',
            'due_time.required' => 'Choose the time to call.',
            'due_time.date_format' => 'Choose the time to call.',
            'items.array' => 'The items could not be read — add them again.',
            'items.max' => 'A reminder holds up to 20 items.',
            'items.*.product_id.*' => 'One of the items is no longer in the catalogue — remove it or choose another product.',
            'items.*.variant_id.*' => 'One of the options could not be read — choose it again.',
            'items.*.qty.*' => 'Each quantity has to be a whole number from 1 to 99.',
            'items.*.price.*' => 'One of the item prices could not be read — choose the product again.',
            'abandoned_cart_id.*' => 'The abandoned cart this reminder was started from no longer exists.',
        ]);

        $phone = bd_phone($data['phone']);

        // Typed in Dhaka time, stored in UTC. The "!" zeroes the seconds,
        // which createFromFormat would otherwise take from the clock.
        $dueAt = Carbon::createFromFormat('!Y-m-d H:i', $data['due_date'].' '.$data['due_time'], config('store.timezone', 'Asia/Dhaka'))
            ->setTimezone(config('app.timezone'));

        $name = trim((string) ($data['name'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        return [
            'phone' => $phone,
            'name' => $name !== '' ? $name : null,
            'customer_id' => Customer::where('phone', $phone)->value('id'),
            'items' => $this->snapshot($data['items'] ?? []) ?: null,
            'notes' => $notes !== '' ? $notes : null,
            'due_at' => $dueAt,
        ] + ($reminder ? [] : [
            // Where it was started from never changes after that.
            'abandoned_cart_id' => $data['abandoned_cart_id'] ?? null,
        ]);
    }

    /**
     * Posted item rows as stored: [{product_id, variant_id|null, name, qty, price}].
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function snapshot(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $products = Product::with('variants')
            ->whereIn('id', array_map(fn ($r) => (int) $r['product_id'], $rows))
            ->get()->keyBy('id');

        return array_values(array_filter(array_map(function (array $row) use ($products) {
            $product = $products->get((int) $row['product_id']);
            if (! $product) {
                return null;
            }

            // An option id that is not this product's is dropped, not trusted.
            $variant = filled($row['variant_id'] ?? null)
                ? $product->variants->firstWhere('id', (int) $row['variant_id'])
                : null;

            $catalogue = $variant && $variant->price !== null ? (float) $variant->price : (float) $product->price;

            return [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'name' => $product->name,
                'qty' => (int) $row['qty'],
                'price' => is_numeric($row['price'] ?? null) ? round((float) $row['price'], 2) : $catalogue,
            ];
        }, $rows)));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A model named by a plain numeric id in the query string. Anything else —
     * "abc", ?cart[]=, a row since deleted — opens the blank form.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @return T|null
     */
    protected function byId(string $model, mixed $raw)
    {
        return is_string($raw) && ctype_digit($raw) ? $model::find((int) $raw) : null;
    }

    /** The card the order form's customer search hands back, trimmed to what this form shows. */
    protected function customerCard(?Customer $customer): ?array
    {
        return $customer ? [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'total_orders' => (int) $customer->total_orders,
            'blacklisted' => (bool) $customer->blacklisted,
        ] : null;
    }

    /**
     * Option labels for every item on a page of reminders, in one query.
     *
     * @return array<int, string>
     */
    protected function variantLabels(Collection $reminders): array
    {
        $ids = $reminders
            ->flatMap(fn (CallReminder $r) => collect($r->items ?? [])->pluck('variant_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        return $ids === [] ? [] : ProductVariant::whereIn('id', $ids)
            ->get(['id', 'attributes'])
            ->mapWithKeys(fn (ProductVariant $v) => [$v->id => $v->label])
            ->filter()
            ->all();
    }

    protected function who(CallReminder $reminder): string
    {
        return $reminder->displayName() ?: $reminder->phone;
    }

    /** "now", "today at 4:30 PM", "on Sun 20 Sep, 11:00 AM" — for a flash message. */
    protected function when(CallReminder $reminder): string
    {
        if ($reminder->isDue()) {
            return 'now (it is under Due now)';
        }

        $local = store_time($reminder->due_at);
        $today = store_time(now());

        return match (true) {
            $local->isSameDay($today) => 'today at '.$local->format('g:i A'),
            $local->isSameDay($today->copy()->addDay()) => 'tomorrow at '.$local->format('g:i A'),
            default => 'on '.$reminder->dueExact(),
        };
    }
}
