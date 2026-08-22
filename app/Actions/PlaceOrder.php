<?php

namespace App\Actions;

use App\Exceptions\CheckoutException;
use App\Jobs\SendOrderPlacedEffects;
use App\Models\AbandonedCart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Visit;
use App\Services\CartService;
use App\Services\LoyaltyService;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlaceOrder
{
    public function __construct(
        protected CartService $cart,
    ) {}

    /**
     * @param  array{name:string,phone:string,email?:string,address:string,area?:string,district?:string,is_inside_dhaka?:bool,notes?:string,is_gift?:bool,card_message?:string}  $data
     */
    public function handle(array $data): Order
    {
        // Store the phone in one canonical form: "01XXXXXXXXX" (no spaces / +880),
        // so the order, the customer record and lookups all match.
        $data['phone'] = bd_phone($data['phone']);

        $insideDhaka = (bool) ($data['is_inside_dhaka'] ?? false);

        $order = DB::transaction(function () use ($data, $insideDhaka) {
            // Re-validate every line against live data, holding row locks so two
            // simultaneous checkouts can't both take the last unit. Throws
            // CheckoutException (rolling back) if anything no longer holds.
            [$products, $variants] = $this->validateLines();

            // Enforce per-customer coupon limit now that we know the phone.
            if (($coupon = $this->cart->coupon()) && $coupon->customerLimitReached($data['phone'])) {
                $this->cart->removeCoupon();
            }

            // Totals are read AFTER validation, not before: validateLines() can
            // reprice a line or refresh a stale offer snapshot, and totals taken
            // beforehand would bake in the values it just corrected. It also
            // closes the window where the cart could change between pricing the
            // order and writing it.
            $subtotal = $this->cart->subtotal();
            $discount = $this->cart->discount();
            $shipping = $this->cart->shipping($insideDhaka);
            $coupon = $this->cart->coupon();

            // Loyalty points the customer chose to redeem (already in $discount).
            $pointsRedeemed = $this->cart->redeemablePoints();
            $pointsDiscount = $this->cart->pointsDiscount();

            // Personalized offer applied to this order (marked redeemed below).
            $customerOffer = $this->cart->customerOffer();

            // Member-pricing portion of the discount (for "saved as a member").
            $memberDiscount = $this->cart->memberSignupDiscount();

            // A logged-in customer always owns their own order. Check this FIRST:
            // running firstOrCreate() unconditionally created a junk customer row
            // (0 orders, never used) whenever a member shipped to a different
            // number — a gift — which then skewed segments and CRM counts.
            $customer = auth('customer')->user() ?? Customer::firstOrCreate(
                ['phone' => $data['phone']],
                ['name' => $data['name'], 'email' => $data['email'] ?? null],
            );

            // Backfill an email a repeat guest provides on a later order —
            // firstOrCreate only sets it on creation.
            if (blank($customer->email) && filled($data['email'] ?? null)) {
                $customer->update(['email' => $data['email']]);
            }

            // Where this buyer came from, read off their own visit history.
            $attribution = Visit::attributionFor(request()->cookie('visitor_token'));

            $order = $this->createWithUniqueNumber($attribution + [
                'customer_id' => $customer->id,
                'customer_name' => $data['name'],
                'customer_phone' => $data['phone'],
                'customer_email' => $data['email'] ?? null,
                'shipping_address' => $data['address'],
                'area' => $data['area'] ?? null,
                'district' => $data['district'] ?? null,
                'is_inside_dhaka' => $insideDhaka,
                'subtotal' => $subtotal,
                'shipping_cost' => $shipping,
                'discount' => $discount,
                'member_discount' => $memberDiscount,
                'points_redeemed' => $pointsRedeemed,
                'points_discount' => $pointsDiscount,
                'total' => max(0, $subtotal - $discount + $shipping),
                'payment_method' => 'cod',
                'payment_status' => 'unpaid',
                'status' => 'processing',
                'coupon_code' => $coupon?->code,
                'notes' => $data['notes'] ?? null,
                // Gift orders: the buyer's message lands in card_message so the
                // existing thank-you-card printer picks it up as-is.
                'is_gift' => (bool) ($data['is_gift'] ?? false),
                'card_message' => ($data['is_gift'] ?? false) ? ($data['card_message'] ?? null) : null,
                'source' => 'web',
            ]);

            // The locked products double as the landed-cost snapshot (margin
            // reporting stays accurate even if the product's cost changes later).
            foreach ($this->cart->items() as $item) {
                $product = $products->get($item['product_id']);

                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'],
                    'name' => $item['name'],
                    'sku' => $item['sku'],
                    'attributes' => $item['attributes'],
                    'price' => $item['price'],
                    'cost_price' => $product?->cost_price,
                    'transport_cost' => $product?->transport_cost,
                    'quantity' => $item['qty'],
                    'subtotal' => $item['price'] * $item['qty'],
                ]);

                $this->decrementStock($product, $variants->get($item['variant_id']), (int) $item['qty']);
            }

            $order->history()->create(['status' => 'processing', 'note' => 'Order placed by customer']);

            if ($coupon) {
                $coupon->increment('used_count');
            }

            // Count this redemption of the customer's personalized offer; stamp
            // redeemed_at when its usage cap (if any) is reached.
            if ($customerOffer && auth('customer')->check() && (int) $customerOffer->customer_id === (int) $customer->id) {
                $customerOffer->increment('redemptions');
                if (! $customerOffer->hasUsesLeft() && $customerOffer->redeemed_at === null) {
                    $customerOffer->update(['redeemed_at' => now()]);
                }
            }

            // Deduct any redeemed loyalty points (logged-in customers only).
            if ($pointsRedeemed > 0 && auth('customer')->check()) {
                app(LoyaltyService::class)->award(
                    $customer, -$pointsRedeemed, 'redeem',
                    'Redeemed on order '.$order->order_number, $order,
                );
            }

            // Update customer rollups.
            $customer->increment('total_orders');
            $customer->increment('total_spent', $order->total);
            $customer->update(['last_order_at' => now()]);

            return $order;
        });

        $order = $order->fresh('items');

        // Mark any abandoned-cart lead for this phone/session as recovered.
        AbandonedCart::where('recovered', false)
            ->where(fn ($q) => $q->where('phone', $data['phone'])->orWhere('session_id', session()->getId()))
            ->update(['recovered' => true]);

        // Confirmation SMS, invoice email and Meta CAPI Purchase are queued so a
        // slow gateway never delays the buyer's redirect. The browser context is
        // captured now — the queue worker has no request to read it from.
        SendOrderPlacedEffects::dispatch(
            $order,
            MetaTrackingService::captureClientContext(),
        );

        $this->cart->clear();

        return $order;
    }

    /**
     * Re-validate every cart line against live, row-locked data: the product is
     * still published, stock covers the quantity (pre-orders exempt), and the
     * price hasn't changed since it was added to the cart. On a price change the
     * cart line is repriced so the customer sees current numbers on the bounce.
     *
     * @return array{0:Collection, 1:Collection} [products, variants] keyed by id
     *
     * @throws CheckoutException
     */
    protected function validateLines(): array
    {
        $items = $this->cart->items();

        // lockForUpdate holds the rows until the transaction commits, so a
        // concurrent checkout waits here instead of overselling the last unit.
        $products = Product::with('category')
            ->whereIn('id', $items->pluck('product_id')->filter()->unique())
            ->lockForUpdate()->get()->keyBy('id');
        // `with('product')` matters: ProductVariant::effective_price falls back to
        // the parent price, so without it each variant line fired a lazy query
        // while this transaction was holding row locks.
        $variants = ProductVariant::with('product')
            ->whereIn('id', $items->pluck('variant_id')->filter()->unique())
            ->lockForUpdate()->get()->keyBy('id');

        $repriced = [];
        $offersReduced = [];

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            $variant = $item['variant_id'] ? $variants->get($item['variant_id']) : null;

            // Product (or chosen variant) has been removed, unpublished, or the
            // variant retired. A variant that is no longer active is as gone as
            // one that was deleted.
            if (! $product || $product->status !== 'published'
                || ($item['variant_id'] && (! $variant || ! $variant->is_active))) {
                $this->cart->remove($item['key']);

                throw new CheckoutException(
                    '"'.$item['name'].'" is no longer available and was removed from your cart.'
                );
            }

            // Stock check (pre-orders intentionally sell past zero).
            if (! $product->isPreorder()) {
                $qty = (int) $item['qty'];

                // Untracked stock: the manual "sold out" toggle is the only signal
                // there is, and it was never checked here — so an item marked sold
                // out while it sat in a cart still went through checkout.
                if (! $product->manage_stock && ! $product->in_stock) {
                    $this->cart->remove($item['key']);

                    throw new CheckoutException(
                        '"'.$item['name'].'" is sold out and was removed from your cart.'
                    );
                }
                if ($variant && (int) $variant->stock_quantity < $qty) {
                    throw new CheckoutException(
                        'Only '.max(0, (int) $variant->stock_quantity).' of "'.$item['name'].'" left in stock — please adjust the quantity.'
                    );
                }
                if (! $variant && $product->manage_stock && (int) $product->stock_quantity < $qty) {
                    throw new CheckoutException(
                        'Only '.max(0, (int) $product->stock_quantity).' of "'.$item['name'].'" left in stock — please adjust the quantity.'
                    );
                }
            }

            // Price check — the session snapshot must match the live price.
            $current = (float) ($variant?->effective_price ?? $product->price);
            if (round($current, 2) !== round((float) $item['price'], 2)) {
                $this->cart->repriceLine($item['key'], $current);
                $repriced[] = $item['name'];
            }

            // Offer check — quantity/bundle tiers are snapshotted when the item is
            // added, so a cart could keep claiming a discount the admin has since
            // cut or withdrawn. Refresh the snapshot either way; only make the
            // customer re-confirm when the offer got WORSE, since a better one
            // costs them nothing.
            $liveTiers = $product->offerTiers();
            $snapshot = $item['offers'] ?? [];

            if (CartService::offerSignature($liveTiers) !== CartService::offerSignature($snapshot)) {
                $this->cart->refreshLineOffers($item['key'], $liveTiers);

                $qty = (int) $item['qty'];
                if (round($product->offerPercentForQty($qty), 2) < round($this->cart->lineOfferPercent($item), 2)) {
                    $offersReduced[] = $item['name'];
                }
            }
        }

        if ($repriced !== []) {
            throw new CheckoutException(
                'Prices were updated for: '.implode(', ', $repriced).'. Please review your cart before ordering.'
            );
        }

        if ($offersReduced !== []) {
            throw new CheckoutException(
                'The offer on '.implode(', ', $offersReduced).' has changed. Please review your cart before ordering.'
            );
        }

        return [$products, $variants];
    }

    /**
     * Create the order, retrying on an order-number collision (two simultaneous
     * checkouts can generate the same sequential number; the unique index makes
     * the loser retry with the next one instead of 500ing).
     */
    /**
     * Create the order, stepping the number forward if it collides.
     *
     * The offset matters: generateNumber() is deterministic, so retrying it
     * unchanged produced the identical number and the identical duplicate-key
     * error every time — three attempts, three failures, a 500 for the customer.
     * Passing the attempt number moves each retry to the next candidate, which
     * is what makes it survive both a lost race and a gap in the sequence.
     */
    protected function createWithUniqueNumber(array $attributes): Order
    {
        $attempts = 8;

        for ($attempt = 0; ; $attempt++) {
            try {
                return Order::create(
                    ['order_number' => Order::generateNumber($attempt)] + $attributes
                );
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= $attempts - 1) {
                    throw $e;
                }
            }
        }
    }

    /** Decrement stock on the already-locked models (validated ≥ qty above). */
    protected function decrementStock(?Product $product, ?ProductVariant $variant, int $qty): void
    {
        if ($variant) {
            $variant->decrement('stock_quantity', $qty);
        }
        if ($product && $product->manage_stock) {
            $product->decrement('stock_quantity', $qty);
            if ($product->stock_quantity <= 0) {
                $product->update(['in_stock' => false]);
            }
        }
    }
}
