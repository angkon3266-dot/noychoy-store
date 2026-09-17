<?php

namespace App\Services;

use App\Actions\TransitionOrderStatus;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Shipment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Steadfast Courier API client (https://portal.packzy.com/api/v1).
 * Auth via Api-Key / Secret-Key headers. Credentials are read from the
 * admin Settings panel first, falling back to config/.env.
 */
class SteadfastService
{
    /**
     * Why the last createForOrder() call failed, in words the owner can act on.
     *
     * Every failure used to flash "Steadfast rejected the request. Check the
     * logs." — and the owner has no logs, while Steadfast's own answer ("The
     * invoice has already been taken.", "The recipient phone must be 11
     * digits.") says exactly what to fix. Kept on the service so the method can
     * still return a plain ?Shipment to its existing callers.
     */
    protected ?string $lastError = null;

    /**
     * True when the last createForOrder() failure leaves it UNKNOWN whether
     * Steadfast booked the parcel — the request went out and no answer came
     * back. "Nothing was booked" would be a guess there, and a wrong one sends
     * the owner to press the button again.
     */
    protected bool $lastOutcomeUnknown = false;

    /**
     * Something the owner should know about a booking that DID succeed: that an
     * existing consignment was linked instead of a new one created, or that the
     * invoice had to move past one Steadfast already held.
     */
    protected ?string $lastNotice = null;

    /** Read an integration setting (admin panel) with config fallback. */
    protected function cfg(string $key, $default = null)
    {
        $int = Setting::get('integrations', []);
        $value = is_array($int) ? ($int[$key] ?? null) : null;

        return filled($value) ? $value : $default;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->cfg('steadfast_base_url', config('steadfast.base_url')))
            ->timeout(config('steadfast.timeout', 30))
            ->acceptJson()
            ->withHeaders([
                'Api-Key' => $this->cfg('steadfast_api_key', config('steadfast.api_key')),
                'Secret-Key' => $this->cfg('steadfast_secret_key', config('steadfast.secret_key')),
            ]);
    }

    public function isConfigured(): bool
    {
        return filled($this->cfg('steadfast_api_key', config('steadfast.api_key')))
            && filled($this->cfg('steadfast_secret_key', config('steadfast.secret_key')));
    }

    /** Raw consignment creation. */
    public function createConsignment(array $payload): array
    {
        return $this->client()->post('/create_order', $payload)->json() ?? [];
    }

    public function bulkCreate(array $orders): array
    {
        return $this->client()->post('/create_order/bulk-order', ['data' => $orders])->json() ?? [];
    }

    public function statusByConsignmentId(string $id): array
    {
        return $this->client()->get("/status_by_cid/{$id}")->json() ?? [];
    }

    public function statusByInvoice(string $invoice): array
    {
        return $this->client()->get("/status_by_invoice/{$invoice}")->json() ?? [];
    }

    public function statusByTrackingCode(string $code): array
    {
        return $this->client()->get("/status_by_trackingcode/{$code}")->json() ?? [];
    }

    /**
     * Live delivery status for a shipment, cached 10 min so page loads stay fast
     * and we don't hammer the courier API. Returns the raw Steadfast status string
     * (e.g. in_review, pending, delivered, partial_delivered, cancelled) or null.
     */
    public function deliveryStatus(?string $consignmentId): ?string
    {
        if (! $consignmentId || ! $this->isConfigured()) {
            return null;
        }

        return Cache::remember(
            "sf_status_{$consignmentId}",
            now()->addMinutes(10),
            function () use ($consignmentId) {
                try {
                    return $this->statusByConsignmentId($consignmentId)['delivery_status'] ?? null;
                } catch (\Throwable $e) {
                    Log::warning('Steadfast status lookup failed', ['cid' => $consignmentId, 'error' => $e->getMessage()]);

                    return null;
                }
            }
        );
    }

    /** Map a raw Steadfast status to [label, step(0-3), tone]. */
    public static function describeStatus(?string $raw): array
    {
        $s = strtolower((string) $raw);

        return match (true) {
            str_contains($s, 'delivered') && ! str_contains($s, 'partial') => ['Delivered', 3, 'green'],
            str_contains($s, 'partial') => ['Partially delivered', 3, 'amber'],
            str_contains($s, 'cancel') => ['Cancelled', 3, 'red'],
            str_contains($s, 'hold') => ['On hold', 2, 'amber'],
            in_array($s, ['in_review', '', 'unknown']) => ['Booked with courier', 1, 'gold'],
            $s === 'pending' => ['Out for delivery', 2, 'gold'],
            default => [ucwords(str_replace('_', ' ', $s)), 2, 'gold'],
        };
    }

    public function getBalance(): array
    {
        return $this->client()->get('/get_balance')->json() ?? [];
    }

    /**
     * Wallet balance as a number, or null if it can't be read.
     *
     * Cached for a few minutes because this is rendered on the orders list —
     * without it, every page of orders (and every filter change) would cost a
     * courier API round-trip on the critical path of the busiest admin screen.
     */
    public function balance(): ?float
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $value = Cache::remember('steadfast.balance', now()->addMinutes(5), function () {
            try {
                $b = $this->getBalance();
                $raw = $b['current_balance'] ?? ($b['balance'] ?? null);

                // Cache a sentinel rather than null, so a courier outage doesn't
                // mean a fresh API call on every single page load.
                return is_numeric($raw) ? (float) $raw : false;
            } catch (\Throwable $e) {
                Log::warning('Steadfast balance lookup failed', ['error' => $e->getMessage()]);

                return false;
            }
        });

        return $value === false ? null : (float) $value;
    }

    public function createReturnRequest(array $payload): array
    {
        return $this->client()->post('/create_return_request', $payload)->json() ?? [];
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function lastOutcomeUnknown(): bool
    {
        return $this->lastOutcomeUnknown;
    }

    public function lastNotice(): ?string
    {
        return $this->lastNotice;
    }

    /**
     * The consignment Steadfast would be sent for this order right now.
     *
     * One builder for the booking itself and for the order page's "out of date
     * with the courier" comparison, so what is compared is exactly what would
     * be sent — built from the order's CURRENT values, never a stored copy.
     */
    public function payloadFor(Order $order, ?string $invoice = null): array
    {
        $order->loadMissing('items');

        return [
            'invoice' => $invoice ?? (string) $order->order_number,
            'recipient_name' => (string) $order->customer_name,
            'recipient_phone' => $this->normalizePhone((string) $order->customer_phone),
            // Blank parts are skipped: an order with no area used to go out as
            // "House 4, , Dhaka", which is noise on a label a rider has to read.
            'recipient_address' => collect([$order->shipping_address, $order->area, $order->district])
                ->map(fn ($part) => trim((string) $part))
                ->filter(fn ($part) => $part !== '')
                ->implode(', '),
            'cod_amount' => (float) ($order->payment_status === 'paid' ? 0 : $order->total),
            'note' => $order->notes ?? '',
            'item_description' => $order->items->map(fn ($i) => "{$i->name} x{$i->quantity}")->implode(', '),
        ];
    }

    /**
     * What the courier holds for this order that no longer matches the order.
     *
     * Editing a booked order is allowed (owner's call, 2026-09-17), and
     * Steadfast keeps its own copy — so after an edit the order page has to say,
     * precisely, which parts the parcel still carries the old version of.
     *
     * Shipments booked from 2026-09-17 carry the exact payload that was sent,
     * so every field is compared. Older ones only recorded the COD, so for those
     * the COD is compared and any edit written to the order's history after
     * the booking is flagged as a change the courier has not seen.
     *
     * @return array<string, array{label: string, booked: ?string, now: ?string}>
     */
    public function driftFor(Order $order): array
    {
        $shipment = $order->shipment;

        if (! $shipment?->consignment_id) {
            return [];
        }

        // Once the courier has delivered, there is no parcel left to correct.
        // Delivery also marks a cash-on-delivery order paid, and payloadFor()
        // sends a paid order as COD 0 — so without this every delivered COD
        // order read "COD ৳1,000 → ৳0" and offered to book it again.
        if (in_array(Order::statusForCourierStatus($shipment->status), ['delivered', 'partially_delivered'], true)
            || in_array($order->status, ['delivered', 'partially_delivered'], true)) {
            return [];
        }

        $now = $this->payloadFor($order);
        $drift = [];

        if (round((float) $shipment->cod_amount, 2) !== round((float) $now['cod_amount'], 2)) {
            $drift['cod_amount'] = [
                'label' => 'COD',
                'booked' => money($shipment->cod_amount),
                'now' => money($now['cod_amount']),
            ];
        }

        $sent = is_array($shipment->request) ? $shipment->request : null;

        if ($sent) {
            $fields = [
                'recipient_name' => 'Name',
                'recipient_phone' => 'Phone',
                'recipient_address' => 'Address',
                'item_description' => 'Items',
            ];

            foreach ($fields as $key => $label) {
                $was = trim((string) ($sent[$key] ?? ''));
                $is = trim((string) ($now[$key] ?? ''));

                if ($was !== $is) {
                    $drift[$key] = ['label' => $label, 'booked' => $was, 'now' => $is];
                }
            }

            return $drift;
        }

        $editedAfter = $order->history()
            ->where('created_at', '>=', $shipment->created_at)
            ->where(fn ($q) => $q->where('note', 'like', 'Delivery details corrected%')
                ->orWhere('note', 'like', 'Order amended%'))
            ->exists();

        if ($editedAfter) {
            $drift['edited'] = [
                'label' => 'Details',
                'booked' => null,
                'now' => 'edited after this consignment was booked',
            ];
        }

        return $drift;
    }

    /**
     * Create a consignment for an order from its current values and persist a
     * Shipment record. Returns the Shipment on success, or null on failure —
     * with Steadfast's own reason in lastError().
     *
     * A first booking always goes under the plain order number. Steadfast
     * refuses an invoice it has already seen, and that refusal is the last
     * line of defence when two requests book the same order at once — a
     * numbered invoice there would be accepted as a second live parcel.
     *
     * $rebook is the one path for booking an order AGAIN (owner's call,
     * 2026-09-17): the invoice is numbered "<order number>-N" so Steadfast
     * accepts it, the exact payload is kept for the order page's comparison,
     * and every earlier consignment of the order is marked superseded by the
     * new one in the same breath — so there is never a moment with two
     * "current" bookings. Nothing is marked unless Steadfast actually accepted
     * the new one.
     *
     * When Steadfast says the invoice is already taken, it is looked up: a
     * consignment it can name is linked to the order rather than left orphaned
     * (an attempt that timed out after Steadfast booked it would otherwise
     * block the order for good). One it cannot name is moved past — but only
     * on a re-booking, where a numbered invoice is expected anyway.
     */
    public function createForOrder(Order $order, bool $rebook = false): ?Shipment
    {
        $this->lastError = null;
        $this->lastOutcomeUnknown = false;
        $this->lastNotice = null;

        if (! $this->isConfigured()) {
            $this->lastError = 'Steadfast API keys are not configured (Settings → Integrations).';
            Log::warning('Steadfast not configured; skipping consignment', ['order' => $order->order_number]);

            return null;
        }

        $invoice = $rebook ? $order->nextCourierInvoice() : (string) $order->order_number;
        $taken = [];

        // A few numbered invoices at most: each one Steadfast refuses is a
        // consignment somebody made outside this app, and more than that is
        // something for a person to look at, not a loop.
        for ($attempt = 1; ; $attempt++) {
            $payload = $this->payloadFor($order, $invoice);

            try {
                $response = $this->createConsignment($payload);
            } catch (\Throwable $e) {
                // The request may have reached Steadfast and been booked before
                // the answer was lost, so this must not say "nothing was booked".
                $this->lastOutcomeUnknown = true;
                $this->lastError = 'Steadfast did not answer in time — it may or may not have been booked; '
                    .'check the Steadfast panel (invoice '.$invoice.') before trying again.';
                Log::error('Steadfast consignment request failed', [
                    'order' => $order->order_number, 'invoice' => $invoice, 'error' => $e->getMessage(),
                ]);

                return null;
            }

            $consignment = $response['consignment'] ?? null;

            // Success per API doc: top-level status 200 + consignment.consignment_id.
            if (is_array($consignment) && ! empty($consignment['consignment_id'])) {
                $shipment = $this->recordShipment($order, [
                    'consignment_id' => $consignment['consignment_id'],
                    'tracking_code' => $consignment['tracking_code'] ?? null,
                    'invoice' => $invoice,
                    'cod_amount' => $payload['cod_amount'],
                    'status' => $consignment['status'] ?? 'in_review',
                    'response' => $response,
                    'request' => $payload,
                ]);

                if ($taken) {
                    $this->lastNotice = 'Steadfast already had invoice '.implode(', ', $taken)
                        .' (a consignment made in the Steadfast panel, or an earlier attempt that did not answer in time),'
                        .' so this one went as '.$invoice.'. If that earlier parcel should not go out, cancel it in the Steadfast panel.';
                }

                return $shipment;
            }

            if (! static::invoiceTaken($response)) {
                $this->lastError = static::errorText($response);
                Log::error('Steadfast consignment failed', ['order' => $order->order_number, 'invoice' => $invoice, 'response' => $response]);

                return null;
            }

            Log::warning('Steadfast invoice already taken', ['order' => $order->order_number, 'invoice' => $invoice]);

            $existing = $this->consignmentForInvoice($invoice);

            if ($existing) {
                $shipment = $this->recordShipment($order, [
                    'consignment_id' => $existing['consignment_id'],
                    'tracking_code' => $existing['tracking_code'],
                    'invoice' => $invoice,
                    'cod_amount' => $existing['cod_amount'] ?? $payload['cod_amount'],
                    'status' => $existing['status'] ?: 'in_review',
                    'response' => ['linked_existing_consignment' => true] + $existing['response'],
                    // Not what this consignment was booked with — nobody here
                    // knows that — so the order page compares its COD only.
                    'request' => null,
                ]);

                $this->lastNotice = 'Steadfast already had consignment #'.$existing['consignment_id'].' under invoice '.$invoice
                    .' (most likely from an earlier attempt that did not answer in time), so it was linked to this order'
                    .' instead of booking another. Check its COD and address in the Steadfast panel.';

                return $shipment;
            }

            $taken[] = $invoice;

            if (! $rebook || $attempt >= 3) {
                $this->lastError = rtrim(static::errorText($response), '. ').'. Steadfast already has a consignment under invoice '
                    .implode(', ', $taken).' and it could not be linked to this order here — find it in the Steadfast panel:'
                    .' that parcel is live unless it is cancelled there.';

                return null;
            }

            $invoice = $order->nextCourierInvoice($taken);
        }
    }

    /**
     * Save an accepted (or linked) consignment and mark every earlier one of
     * the order replaced by it, in one transaction.
     */
    protected function recordShipment(Order $order, array $attributes): Shipment
    {
        $shipment = DB::transaction(function () use ($order, $attributes) {
            $shipment = Shipment::create(['order_id' => $order->id, 'courier' => 'steadfast'] + $attributes);

            Shipment::where('order_id', $order->id)
                ->whereKeyNot($shipment->id)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now(), 'superseded_by' => $shipment->id]);

            return $shipment;
        });

        // The caller's order may have the old consignment cached as its current one.
        $order->unsetRelation('shipment');
        $order->unsetRelation('shipments');

        return $shipment;
    }

    /** Whether Steadfast refused a booking because the invoice was used before. */
    public static function invoiceTaken(array $response): bool
    {
        return (bool) preg_match('/invoice.*(taken|already|exist|duplicate)/i', static::errorText($response));
    }

    /**
     * The consignment Steadfast holds under an invoice, when it names one that
     * is not already recorded here.
     *
     * Steadfast documents this lookup as answering with a delivery status, so
     * the consignment id is read from wherever it appears — and without one
     * there is nothing that can safely be linked.
     *
     * @return array{consignment_id: string, tracking_code: ?string, status: ?string, cod_amount: ?float, response: array}|null
     */
    protected function consignmentForInvoice(string $invoice): ?array
    {
        try {
            $response = $this->statusByInvoice(rawurlencode($invoice));
        } catch (\Throwable $e) {
            Log::warning('Steadfast invoice lookup failed', ['invoice' => $invoice, 'error' => $e->getMessage()]);

            return null;
        }

        $read = fn (string $key) => data_get($response, 'consignment.'.$key)
            ?? data_get($response, 'data.'.$key)
            ?? data_get($response, $key);

        $id = $read('consignment_id');

        if (! is_scalar($id) || trim((string) $id) === '' || Shipment::where('consignment_id', (string) $id)->exists()) {
            return null;
        }

        $tracking = $read('tracking_code');
        $status = data_get($response, 'delivery_status') ?? $read('status');
        $cod = $read('cod_amount');

        return [
            'consignment_id' => (string) $id,
            'tracking_code' => is_scalar($tracking) && (string) $tracking !== '' ? (string) $tracking : null,
            'status' => is_string($status) ? $status : null,
            'cod_amount' => is_numeric($cod) ? (float) $cod : null,
            'response' => $response,
        ];
    }

    /**
     * Follow a delivery reported on a consignment that had ALREADY been
     * replaced by a newer booking.
     *
     * Replaced consignments normally never move the order — their cancellation
     * is usually the whole point of booking again. A delivery is different: if
     * nobody cancelled the old one, the rider can still take that parcel to the
     * door, and then it IS the parcel the customer has. Ignoring it left the
     * order waiting on the unused new consignment, whose eventual cancellation
     * then cancelled a delivered order.
     *
     * So the order follows it, the history names the newer consignment that now
     * looks unused, and the replaced row is stamped so that newer one's
     * cancellation is not applied later (see applyCourierVerdict()). Callers
     * only pass a consignment that was not settled before this update — one
     * already delivered before it was replaced says nothing new.
     *
     * @return bool True if the order's status moved.
     */
    public function applyReplacedDelivery(Order $order, Shipment $replaced, ?string $raw, string $by): bool
    {
        $target = Order::statusForCourierStatus($raw);

        if (! $replaced->isSuperseded()
            || ! in_array($target, ['delivered', 'partially_delivered'], true)
            || $replaced->delivered_after_superseded_at !== null) {
            return false;
        }

        $replaced->update(['delivered_after_superseded_at' => now()]);

        $unused = $order->shipments()->where('id', '>', $replaced->id)->whereNotNull('consignment_id')->get()
            ->reject(fn (Shipment $s) => $s->isSettled())
            ->map(fn (Shipment $s) => '#'.$s->consignment_id)
            ->values();

        $note = 'Courier reported: '.$raw.' on consignment #'.$replaced->consignment_id
            .', which had been replaced — that is the parcel the customer received.'
            .match ($unused->count()) {
                0 => '',
                1 => ' The newer consignment '.$unused->first().' appears unused and should be cancelled at Steadfast.',
                default => ' The newer consignments '.$unused->implode(', ').' appear unused and should be cancelled at Steadfast.',
            };

        if ($order->status === $target) {
            $order->history()->create(['status' => $order->status, 'note' => $note, 'created_by' => $by]);

            return false;
        }

        return app(TransitionOrderStatus::class)->handle($order, $target, $note, $by);
    }

    /**
     * Apply the CURRENT consignment's settled verdict to the order — except a
     * cancellation (or return) once an earlier, replaced consignment has been
     * delivered.
     *
     * The newer consignment was the unused one then; cancelling it is tidying
     * up, and letting that cancel the order would put delivered stock back on
     * the shelf, refund the points the customer spent and text them a
     * cancellation. A history note records what the courier said instead, once
     * — this also runs on every view of the order page.
     *
     * @return bool True if the order's status moved.
     */
    public function applyCourierVerdict(Order $order, ?string $raw, string $by): bool
    {
        $target = Order::statusForCourierStatus($raw);

        if (in_array($target, ['cancelled', 'returned'], true) && ($delivered = $order->replacedConsignmentDelivered())) {
            $current = $order->shipment()->first();
            $note = 'Consignment #'.($current?->consignment_id ?? '?').' '.$target.' at the courier; #'
                .$delivered->consignment_id.' was delivered — order left as it is';

            if (! $order->history()->where('note', $note)->exists()) {
                $order->history()->create(['status' => $order->status, 'note' => $note, 'created_by' => $by]);
            }

            return false;
        }

        return app(TransitionOrderStatus::class)->applyCourierStatus($order, $raw, $by);
    }

    /**
     * Steadfast's reason for refusing a booking, as one readable sentence.
     *
     * Validation failures come back as {"status": 400, "errors": {"invoice":
     * ["The invoice has already been taken."]}}; auth and server failures as
     * {"message": "..."}; an outage as nothing at all.
     */
    public static function errorText(array $response): string
    {
        $parts = [];
        $errors = $response['errors'] ?? null;

        if (is_array($errors)) {
            $parts = collect($errors)->flatten()
                ->filter(fn ($v) => is_scalar($v) && trim((string) $v) !== '')
                ->map(fn ($v) => trim((string) $v))
                ->all();
        } elseif (is_scalar($errors) && trim((string) $errors) !== '') {
            $parts = [trim((string) $errors)];
        }

        $message = $response['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            array_unshift($parts, trim($message));
        }

        $parts = array_values(array_unique($parts));

        if ($parts) {
            return Str::limit(implode(' ', $parts), 300);
        }

        if ($response === []) {
            return 'Steadfast sent back no answer — the service may be down, or the API keys may be wrong.';
        }

        return 'Steadfast did not return a consignment'
            .(isset($response['status']) && is_scalar($response['status']) ? ' (status '.$response['status'].')' : '').'.';
    }

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        // Steadfast expects an 11-digit BD number (01XXXXXXXXX).
        if (str_starts_with($digits, '880')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10 && $digits[0] === '1') {
            $digits = '0'.$digits;
        }

        return $digits;
    }
}
