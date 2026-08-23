<?php

namespace App\Jobs;

use App\Models\AbandonedCart;
use App\Models\Order;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Text a shopper who typed their phone at checkout and left without ordering.
 *
 * The existing push reminder only ever reached registered members, which on a
 * cash-on-delivery store is almost nobody — the checkout page's own comment
 * says most buyers never register. Everyone who got as far as typing their
 * number was, until now, recoverable revenue that nobody chased.
 */
class SendAbandonedCartSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public AbandonedCart $cart) {}

    public function handle(SmsService $sms): void
    {
        $cart = $this->cart->fresh() ?? $this->cart;

        // Between being picked and being sent, they may have ordered anyway.
        if ($cart->recovered || Order::where('customer_phone', $cart->phone)
            ->where('created_at', '>=', $cart->updated_at)->exists()) {
            $cart->forceFill(['recovered' => true])->saveQuietly();

            return;
        }

        // Signed, and it rebuilds the cart on the far side — a bare link to the
        // shop would land them on an empty cart and waste the message.
        $link = URL::signedRoute('cart.restore', ['cart' => $cart->id]);

        $ok = false;

        try {
            $ok = (bool) $sms->sendTemplate('abandoned_cart', $this->pseudoOrder($cart), ['{link}' => $link]);
        } catch (\Throwable $e) {
            report($e);
        }

        if (! $ok) {
            // Un-stamp so a gateway outage does not permanently exclude them.
            $cart->forceFill(['sms_reminded_at' => null])->saveQuietly();

            Log::warning('Abandoned-cart SMS was not accepted; un-stamped for retry.', [
                'cart' => $cart->id,
            ]);
        }
    }

    /**
     * SmsService::sendTemplate speaks Order. An abandoned cart is not one, so
     * give it an unsaved stand-in carrying only the placeholders the template
     * uses — cheaper and far less risky than a parallel send path.
     */
    protected function pseudoOrder(AbandonedCart $cart): Order
    {
        $order = new Order([
            'customer_name' => $cart->name ?: 'there',
            'customer_phone' => $cart->phone,
        ]);

        $order->setRelation('items', collect());
        $order->order_number = '';
        $order->total = $cart->subtotal;

        return $order;
    }
}
