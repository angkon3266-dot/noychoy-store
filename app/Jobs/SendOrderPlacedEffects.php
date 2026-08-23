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
 * during the checkout request; the worker has no request to read it from.
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

    public function handle(SmsService $sms, MetaTrackingService $capi): void
    {
        $order = $this->order->fresh('items') ?? $this->order;

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
                $result = $capi->purchase($order, $order->order_number, $this->clientContext ?: null);

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
