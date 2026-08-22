<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\LoyaltyService;
use App\Services\NotificationService;
use App\Services\PushTemplateService;

/**
 * The single place an order changes status.
 *
 * Moving an order to a new status is never just a column write — stock has to
 * be released or re-reserved, loyalty points awarded on delivery, history
 * recorded and the customer pushed. Those effects used to live inside
 * Admin\OrderController, so the Steadfast webhook — which updates the *same*
 * orders from the courier's side — wrote `status` directly and silently skipped
 * all of them: a courier cancellation never returned its stock to inventory,
 * and a courier delivery never paid out the customer's points.
 *
 * Both callers now go through here, so the two paths cannot drift again.
 */
class TransitionOrderStatus
{
    /** Statuses that free the stock an order had reserved. Returned goods are
     *  intentionally NOT auto-restocked (they may be damaged / need inspection). */
    protected const RELEASE_STATUSES = ['cancelled'];

    /**
     * @param  string  $by  Who made the change, for the history entry.
     * @return bool True if the status actually changed.
     */
    public function handle(Order $order, string $status, ?string $note = null, string $by = 'System'): bool
    {
        $from = $order->status;

        $order->update(['status' => $status]);
        $order->history()->create([
            'status' => $status,
            'note' => $note,
            'created_by' => $by,
        ]);

        // A no-op status write still gets a history row (an admin re-saving the
        // same status is a deliberate audit event), but must not double-adjust
        // stock or re-award points.
        if ($from === $status) {
            return false;
        }

        $this->syncStock($order, $from, $status);

        if ($status === 'delivered') {
            app(LoyaltyService::class)->awardForOrder($order->fresh('customer'));
        }

        // Points are earned by a delivery that sticks. If a delivered order is
        // later returned or cancelled, take them back — otherwise a returned
        // parcel leaves the customer holding points for a sale that unwound.
        if ($from === 'delivered' && in_array($status, ['returned', 'cancelled'], true)) {
            app(LoyaltyService::class)->reverseForOrder($order->fresh('customer'));
        }

        $this->push($order->fresh(), $status);

        return true;
    }

    /**
     * Follow the courier's verdict.
     *
     * The courier knows what physically happened, so a settled outcome moves the
     * order by itself — a clean delivery to `delivered` (final, locked), a
     * cancellation or partial delivery to `cancelled` (still editable, because
     * the shop has to decide what to do about it).
     *
     * Called from all three places the courier status can arrive: the Steadfast
     * webhook, the "Refresh status" button, and the best-effort refresh when an
     * admin opens the order. Idempotent — a repeat of the same verdict is a
     * no-op.
     *
     * @return bool True if the order's status actually moved.
     */
    public function applyCourierStatus(Order $order, ?string $rawCourierStatus, string $by = 'Courier'): bool
    {
        $target = Order::statusForCourierStatus($rawCourierStatus);

        if ($target === null || $order->status === $target) {
            return false;
        }

        return $this->handle(
            $order,
            $target,
            'Courier reported: '.$rawCourierStatus,
            $by,
        );
    }

    /**
     * Release stock back to inventory when an order enters "cancelled", and
     * re-deduct it if it is moved back out. The stock_restored flag makes both
     * directions idempotent.
     */
    public function syncStock(Order $order, string $from, string $to): void
    {
        $wasReleased = in_array($from, self::RELEASE_STATUSES, true);
        $nowReleased = in_array($to, self::RELEASE_STATUSES, true);

        if ($nowReleased && ! $wasReleased && ! $order->stock_restored) {
            $this->adjustStock($order, +1);         // return to inventory
            $order->update(['stock_restored' => true]);
        } elseif (! $nowReleased && $wasReleased && $order->stock_restored) {
            $this->adjustStock($order, -1);         // re-reserve
            $order->update(['stock_restored' => false]);
        }
    }

    /** Add ($sign=+1) or remove ($sign=-1) this order's line quantities from stock. */
    public function adjustStock(Order $order, int $sign): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue; // deleted product — nothing to adjust
            }

            $delta = $sign * (int) $item->quantity;

            if ($item->variant_id) {
                ProductVariant::where('id', $item->variant_id)->increment('stock_quantity', $delta);
            }

            // A variable product's stock_quantity is the sum of its variants, so
            // the parent moves with the variant rather than instead of it.
            $product = Product::find($item->product_id);
            if ($product && $product->manage_stock) {
                $product->increment('stock_quantity', $delta);
                $product->refresh();
                $product->update(['in_stock' => $product->stock_quantity > 0]);
            }
        }
    }

    /** Web push for the status change (template-gated; free, so always attempted). */
    protected function push(Order $order, string $status): void
    {
        if (! $order->customer_id) {
            return;
        }

        $trigger = match ($status) {
            'confirmed' => 'order_confirmed',
            'shipped' => 'order_shipped',
            'delivered' => 'order_delivered',
            default => null,
        };
        if (! $trigger) {
            return;
        }

        $payload = app(PushTemplateService::class)->forOrder($trigger, $order);
        if ($payload) {
            app(NotificationService::class)->pushToCustomer((int) $order->customer_id, $payload);
        }
    }
}
