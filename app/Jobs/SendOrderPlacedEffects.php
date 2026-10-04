<?php

namespace App\Jobs;

use App\Mail\OrderInvoiceMail;
use App\Models\Order;
use App\Services\Meta\MetaTrackingService;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Post-checkout side effects — confirmation SMS, invoice email, Meta CAPI
 * Purchase — queued so a slow SMS gateway or Graph API call never delays the
 * buyer's redirect to the confirmation page.
 *
 * $clientContext is the browser snapshot (IP, UA, _fbc/_fbp, URL, time) taken
 * during the checkout request; the worker has no request to read it from. An
 * order with no browser behind it (CreateManualOrder) passes
 * MetaTrackingService::noBrowserContext(), or nothing at all.
 */
class SendOrderPlacedEffects implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    /**
     * If the order is gone by the time this runs, drop the job instead of
     * failing it. Every step inside handle() catches its own errors, so this
     * job can only fail while the queue restores the Order — which happens when
     * the order was deleted while the job sat in the backlog. There are no side
     * effects to send for an order that no longer exists.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Order $order, public array $clientContext = []) {}

    /**
     * Past this, the customer's "order received" SMS and email and the staff
     * alert are skipped: a confirmation that arrives hours late — after a
     * stalled queue, as on 3 Oct 2026 — confuses more than it reassures, and
     * staff have long since seen the order. The Meta Purchase event still goes
     * (Meta accepts it for seven days). Owner's choice, 4 Oct 2026.
     */
    public const MESSAGES_STALE_AFTER_MINUTES = 120;

    public function handle(SmsService $sms, MetaTrackingService $capi): void
    {
        $order = $this->order->fresh('items') ?? $this->order;

        $late = $order->created_at?->lt(now()->subMinutes(self::MESSAGES_STALE_AFTER_MINUTES));
        if ($late) {
            // Error level: production keeps nothing below it, and a late run
            // means the queue was stuck.
            Log::error('Order confirmation ran late; customer SMS/email and staff alert skipped.', [
                'order' => $order->order_number,
                'minutes_late' => (int) $order->created_at->diffInMinutes(now()),
            ]);
        } else {
            $this->sendMessages($order, $sms);
        }

        $this->sendPurchaseEvent($order, $capi);
    }

    private function sendMessages(Order $order, SmsService $sms): void
    {
        try {
            // Staff alert first — it's the one thing someone is waiting on.
            app(\App\Services\NotificationService::class)->alertAdminsNewOrder($order);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $sms->sendTemplate('order_placed', $order);
        } catch (\Throwable $e) {
            report($e);
        }

        if (filled($order->customer_email)) {
            try {
                // Signed link so the email recipient can open the gated
                // confirmation page from any device.
                $link = URL::signedRoute('order.confirmation', ['orderNumber' => $order->order_number]);
                Mail::to($order->customer_email)->send(new OrderInvoiceMail($order, $link));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function sendPurchaseEvent(Order $order, MetaTrackingService $capi): void
    {
        try {
            // Deduplicated with the browser Pixel via order_number as event_id.
            //
            // Cache::add is the application-level guard: it only succeeds the
            // first time, so a job retry (tries = 2) or a second dispatch for
            // the same order doesn't re-send. Meta would collapse the copies
            // anyway — the event_id is the order number, so it's stable across
            // retries by construction — but not sending them at all is better
            // than relying on that, and this needs no schema change.
            $key = 'meta.purchase.'.$order->order_number;
            $once = Cache::add($key, true, now()->addDay());

            if ($once) {
                // An empty context means there was no browser to capture — an
                // order typed into the admin. It used to become null here,
                // which told send() to read the live request: inside this
                // worker, a console stub that reported every such order as a
                // website visit from 127.0.0.1 using "Symfony". Now it is said
                // out loud, and Meta gets a phone_call Purchase instead.
                $context = $this->clientContext ?: MetaTrackingService::noBrowserContext(
                    $order->created_at?->getTimestamp(),
                );

                $result = $capi->purchase($order, $order->order_number, $context);

                // send() never throws — it returns ok:false. Claiming the guard
                // before knowing that would mean a single failed POST silently
                // buried this order's revenue event for a day, with nothing
                // retrying and nothing logged. Release it so a re-dispatch can
                // still get through, and say so out loud.
                if (! ($result['ok'] ?? false)) {
                    Cache::forget($key);
                    Log::warning('Meta CAPI Purchase did not land; guard released so it can be resent.', [
                        'order' => $order->order_number,
                        'status' => $result['status'] ?? null,
                        'error' => $result['error'] ?? null,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
