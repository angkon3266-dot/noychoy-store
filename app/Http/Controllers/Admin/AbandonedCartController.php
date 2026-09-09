<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendAbandonedCartSms;
use App\Models\AbandonedCart;
use App\Models\AbandonedCartContact;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Visit;
use App\Services\CustomerInsight;
use App\Services\SmsService;
use App\Support\AbandonedCartOutreach;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The follow-up desk for checkouts that were started and never finished.
 *
 * Every one of these rows is a shopper who typed her phone number into the
 * checkout and walked away, so the screen is built around reaching her —
 * call, WhatsApp, SMS — with the cart she left rebuilt behind a signed link,
 * and a log of what was already tried so the next person does not start cold.
 */
class AbandonedCartController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->query('filter');
        $q = trim((string) $request->query('q'));

        $carts = AbandonedCart::query()
            ->when($filter === 'open', fn ($b) => $b->open())
            ->when($filter === 'contacted', fn ($b) => $b->where('contacted', true)->where('recovered', false))
            ->when($filter === 'recovered', fn ($b) => $b->where('recovered', true))
            ->when($q !== '', function ($b) use ($q) {
                // Numbers are stored canonically, so a pasted "+8801…" has to
                // be normalised before it can match anything.
                $phone = bd_phone($q);

                $b->where(function ($w) use ($q, $phone) {
                    $w->where('name', 'like', "%{$q}%");
                    if ($phone !== '') {
                        $w->orWhere('phone', 'like', "%{$phone}%");
                    }
                });
            })
            ->withCount('contacts')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.abandoned.index', [
            'carts' => $carts,
            'filter' => $filter,
            'q' => $q,
            'openCount' => AbandonedCart::open()->count(),
            // What is sitting on the table: everything nobody has recovered.
            'atRisk' => (float) AbandonedCart::where('recovered', false)->sum('subtotal'),
            'recoveredValue' => (float) AbandonedCart::where('recovered', true)
                ->where('updated_at', '>=', now()->subDays(30))->sum('subtotal'),
            'smsReady' => app(SmsService::class)->isEnabled(),
        ]);
    }

    public function show(AbandonedCart $cart)
    {
        $cart->load(['contacts.user']);

        return view('admin.abandoned.show', [
            'cart' => $cart,
            'lines' => $this->lines($cart),
            'waLink' => AbandonedCartOutreach::whatsappLink($cart),
            'telLink' => tel_link($cart->phone),
            'restoreLink' => AbandonedCartOutreach::restoreLink($cart),
            'waMessage' => AbandonedCartOutreach::whatsappMessage($cart),
            // Whether this number has bought before, and how those parcels went.
            'insight' => app(CustomerInsight::class)->forPhone($cart->phone),
            'customer' => Customer::firstWhere('phone', $cart->phone),
            // Only resolvable for carts captured after the visitor token was
            // stored on the row — older leads simply have no source.
            'attribution' => $cart->visitor_token ? Visit::attributionFor($cart->visitor_token) : [],
            'smsReady' => app(SmsService::class)->isEnabled(),
        ]);
    }

    /**
     * The cart snapshot, rehydrated against the live catalogue for pictures and
     * links — and for the one fact the snapshot cannot know: whether the piece
     * is still buyable. A line that is gone will be dropped by the restore
     * route too, so the caller should know before dialling.
     */
    protected function lines(AbandonedCart $cart): array
    {
        $rows = collect($cart->items ?? []);

        $products = Product::with(['images', 'variants'])
            ->whereIn('id', $rows->pluck('product_id')->filter()->unique()->all())
            ->get()->keyBy('id');

        return $rows->map(function ($line) use ($products) {
            $product = $products->get($line['product_id'] ?? null);
            $available = $product && $product->status === 'published';

            $variant = null;
            if ($available && ! empty($line['variant_id'])) {
                $variant = $product->variants->firstWhere('id', $line['variant_id']);
                $available = (bool) $variant?->is_active;
            } elseif ($available && $product->has_variants) {
                // The restore route makes her re-choose rather than guess.
                $available = false;
            }

            $thumb = $variant?->image?->url ?: $product?->thumbnail;
            $qty = max(1, (int) ($line['qty'] ?? 1));
            $price = (float) ($line['price'] ?? 0);

            return [
                'name' => $line['name'] ?? 'Item',
                'qty' => $qty,
                'price' => $price,
                'total' => $price * $qty,
                'variant' => $variant?->label,
                'thumb' => $thumb ? (image_variant($thumb, 450) ?: $thumb) : null,
                // Published, not merely present: the storefront 404s on a
                // draft, so linking one would hand the caller a dead page
                // while she has the customer on the line. A variable product
                // whose line lost its variant is still worth opening, which
                // is why this is not the same test as $available.
                'url' => $product && $product->status === 'published'
                    ? route('product.show', $product) : null,
                'available' => $available,
            ];
        })->all();
    }

    /** Record a call, a WhatsApp message or a note against the lead. */
    public function logContact(Request $request, AbandonedCart $cart)
    {
        $data = $request->validate([
            'channel' => ['required', 'string', 'in:'.implode(',', array_keys(AbandonedCartContact::CHANNELS))],
            'outcome' => ['nullable', 'string', 'in:'.implode(',', array_keys(AbandonedCartContact::OUTCOMES))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->record($cart, $data['channel'], $data['outcome'] ?? null, $data['note'] ?? null, $request->user()?->id);

        return back()->with('success', 'Follow-up logged.');
    }

    /** Text this one shopper now, without waiting for the half-hourly run. */
    public function sendSms(Request $request, AbandonedCart $cart)
    {
        if ($cart->recovered) {
            return back()->with('warning', 'This cart was already recovered — no message sent.');
        }

        if (! app(SmsService::class)->isEnabled()) {
            return back()->with('error', 'SMS is off or missing credentials. Turn it on under Settings → Integrations first.');
        }

        // Stamped before dispatch for the same reason the scheduled command
        // does it: a crash costs one silent miss, not a duplicate paid message.
        // The job clears the stamp itself if the gateway refuses.
        $cart->stampQuietly(['sms_reminded_at' => now()]);
        SendAbandonedCartSms::dispatch($cart);

        $this->record($cart, 'sms', null, 'Recovery SMS queued from the admin panel.', $request->user()?->id);

        return back()->with('success', 'Recovery SMS queued. It goes out as soon as the queue worker picks it up.');
    }

    public function markContacted(Request $request, AbandonedCart $cart)
    {
        $this->record($cart, 'note', null, 'Marked contacted.', $request->user()?->id);

        return back()->with('success', 'Marked as contacted.');
    }

    /** Bulk SMS / mark contacted / delete from the list screen. */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            // Not "action": a field of that name shadows form.action in the
            // browser, and the admin's ajax layer then posts to
            // "[object HTMLInputElement]" — a 404 with no obvious cause.
            'bulk_action' => ['required', 'string', 'in:sms,contacted,delete'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $carts = AbandonedCart::whereIn('id', $data['ids'])->get();

        if ($carts->isEmpty()) {
            return back()->with('warning', 'Nothing selected.');
        }

        return match ($data['bulk_action']) {
            'sms' => $this->bulkSms($carts, $request->user()?->id),
            'contacted' => $this->bulkContacted($carts, $request->user()?->id),
            default => $this->bulkDelete($carts),
        };
    }

    protected function bulkSms($carts, ?int $userId)
    {
        if (! app(SmsService::class)->isEnabled()) {
            return back()->with('error', 'SMS is off or missing credentials. Turn it on under Settings → Integrations first.');
        }

        $queued = 0;
        $recovered = 0;
        $recent = 0;

        foreach ($carts as $cart) {
            if ($cart->recovered) {
                $recovered++;

                continue;
            }
            // A double-clicked button should not buy the same message twice.
            if ($cart->sms_reminded_at && $cart->sms_reminded_at->gt(now()->subDay())) {
                $recent++;

                continue;
            }

            $cart->stampQuietly(['sms_reminded_at' => now()]);
            SendAbandonedCartSms::dispatch($cart);
            $this->record($cart, 'sms', null, 'Recovery SMS queued from a bulk action.', $userId);
            $queued++;
        }

        $skipped = array_filter([
            $recovered ? "{$recovered} already recovered" : null,
            $recent ? "{$recent} texted in the last 24 hours" : null,
        ]);

        return back()->with('success',
            "Recovery SMS queued for {$queued} lead(s)."
            .($skipped ? ' Skipped '.implode(' and ', $skipped).'.' : '')
        );
    }

    protected function bulkContacted($carts, ?int $userId)
    {
        foreach ($carts as $cart) {
            $this->record($cart, 'note', null, 'Marked contacted in a bulk action.', $userId);
        }

        return back()->with('success', $carts->count().' lead(s) marked as contacted.');
    }

    protected function bulkDelete($carts)
    {
        $ids = $carts->pluck('id');
        $count = $ids->count();
        AbandonedCart::whereIn('id', $ids)->delete();

        return $this->afterDelete($ids)->with('success', "{$count} lead(s) removed.");
    }

    /**
     * back(), unless "back" is a lead that no longer exists.
     *
     * The bulk bar lives on the list, but url()->previous() is the last page
     * the browser actually asked the server for — and leaving a lead page with
     * the browser's own Back button never asks. Deleting that lead then
     * redirected the owner straight into its 404.
     */
    protected function afterDelete($deletedIds)
    {
        $path = fn (string $url) => rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        $gone = collect($deletedIds)
            ->map(fn ($id) => $path(route('admin.abandoned.show', $id)))
            ->contains($path(url()->previous()));

        return $gone ? redirect()->route('admin.abandoned.index') : back();
    }

    public function destroy(AbandonedCart $cart)
    {
        $cart->delete();

        // Not back(): the only delete button lives on the lead's own page, and
        // back() would reload a row that no longer exists.
        return redirect()->route('admin.abandoned.index')->with('success', 'Lead removed.');
    }

    /**
     * Write one line of follow-up history and keep the old `contacted` flag in
     * step with it — the dashboard alert and the sidebar badge still read that
     * boolean, and a lead somebody has called is no longer waiting.
     */
    protected function record(AbandonedCart $cart, string $channel, ?string $outcome, ?string $note, ?int $userId): void
    {
        DB::transaction(function () use ($cart, $channel, $outcome, $note, $userId) {
            $cart->contacts()->create([
                'user_id' => $userId,
                'channel' => $channel,
                'outcome' => $outcome,
                'note' => $note,
            ]);

            // stampQuietly, not update(): the SMS runner's whole due window is
            // measured from updated_at, so touching it here would make an old
            // cart look freshly abandoned and re-enter the texting queue.
            $cart->stampQuietly(['contacted' => true, 'last_contacted_at' => now()]);
        });
    }
}
