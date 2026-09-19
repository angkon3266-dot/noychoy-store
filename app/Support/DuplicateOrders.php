<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Setting;
use App\Services\CartService;
use Illuminate\Support\Collection;

/**
 * "Stop duplicate orders" — Admin → Settings, on unless the owner turns it off.
 *
 * The owner's ask (2026-09-19): pressing the order button twice must not make
 * two of anything. Three ways it could:
 *
 * - **Buy now** added its piece on top of whatever the cart already held, so a
 *   second press — a double tap, or Back from the checkout and Buy now again —
 *   sent two pieces to the checkout. With this on it puts the piece in once
 *   ({@see CartService::ensure()}).
 * - **Place order** pressed twice sent two requests carrying the same cart. The
 *   route holds the session for one request at a time, so the second finds the
 *   cart the first one emptied; it is shown the order the first one placed
 *   instead of "Your cart is empty".
 * - **The same pieces on the same number within WINDOW_MINUTES**, from any
 *   browser, is almost always someone unsure the first order went through.
 *   They are told it is already placed rather than sent a second parcel. The
 *   chat assistant has had the same rule since it could order
 *   (ChatOrder::recentTwin).
 */
final class DuplicateOrders
{
    public const SETTING = 'prevent_duplicate_orders';

    public const WINDOW_MINUTES = 10;

    /** An order in these states is over; ordering the same again is a new order. */
    private const CLOSED = ['cancelled', 'returned'];

    public static function enabled(): bool
    {
        return (bool) Setting::get(self::SETTING, true);
    }

    /**
     * The order this browser placed moments ago, if it did — what a second
     * press of Place order is shown once the first one has emptied the cart.
     */
    public static function justPlacedHere(): ?Order
    {
        $number = collect((array) session('placed_orders', []))->last();

        return $number ? Order::where('order_number', $number)
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->first() : null;
    }

    /**
     * An order on this number, moments ago, of exactly the pieces in the cart.
     *
     * Exactly: the same products and options in the same quantities. One piece
     * more, or a different colour, is a different order and goes through.
     */
    public static function twin(CartService $cart, string $phone): ?Order
    {
        $wanted = self::signature($cart->items()->map(fn ($line) => [
            $line['product_id'], $line['variant_id'] ?? null, $line['qty'],
        ]));

        if ($wanted === '') {
            return null;
        }

        return Order::with('items:id,order_id,product_id,variant_id,quantity')
            ->where('customer_phone', bd_phone($phone))
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->whereNotIn('status', self::CLOSED)
            ->latest('id')
            ->get()
            ->first(fn (Order $order) => self::signature($order->items->map(fn ($item) => [
                $item->product_id, $item->variant_id, $item->quantity,
            ])) === $wanted);
    }

    /**
     * "product:variant:qty" per piece, summed per product and option and
     * sorted, so the cart and a stored order compare whatever order their
     * lines are in.
     *
     * @param  Collection<int, array{0:mixed, 1:mixed, 2:mixed}>  $lines
     */
    private static function signature(Collection $lines): string
    {
        return $lines
            ->groupBy(fn ($l) => (int) $l[0].':'.(int) ($l[1] ?? 0))
            ->map(fn ($same, $key) => $key.':'.$same->sum(fn ($l) => (int) $l[2]))
            ->sort()
            ->implode(',');
    }
}
