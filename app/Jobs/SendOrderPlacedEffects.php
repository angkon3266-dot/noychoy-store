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

    public function __construct(public Order $order, public array $clientContext = []) {}

    public function handle(SmsService $sms, MetaTrackingService $capi): void
    {
        $order = $this->order->fresh('items') ?? $this->order;

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
            $capi->purchase($order, $order->order_number, $this->clientContext ?: null);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
