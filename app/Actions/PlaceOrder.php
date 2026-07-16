<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use App\Services\MetaConversionsApi;
use App\Services\SmsService;
use Illuminate\Support\Facades\DB;

class PlaceOrder
{
    public function __construct(
        protected CartService $cart,
        protected SmsService $sms,
        protected MetaConversionsApi $capi,
    ) {}

    /**
     * @param  array{name:string,phone:string,email?:string,address:string,area?:string,district?:string,is_inside_dhaka?:bool,notes?:string}  $data
     */
    public function handle(array $data): Order
    {
        // Store the phone in one canonical form: "01XXXXXXXXX" (no spaces / +880),
        // so the order, the customer record and lookups all match.
        $data['phone'] = bd_phone($data['phone']);

        $insideDhaka = (bool) ($data['is_inside_dhaka'] ?? false);

        // Enforce per-customer coupon limit now that we know the phone.
        if (($coupon = $this->cart->coupon()) && $coupon->customerLimitReached($data['phone'])) {
            $this->cart->removeCoupon();
        }

        $subtotal = $this->cart->subtotal();
        $discount = $this->cart->discount();
        $shipping = $this->cart->shipping($insideDhaka);
        $coupon = $this->cart->coupon();

        // Loyalty points the customer chose to redeem (already reflected in $discount).
        $pointsRedeemed = $this->cart->redeemablePoints();
        $pointsDiscount = $this->cart->pointsDiscount();

        // Personalized offer applied to this order (marked redeemed after placing).
        $customerOffer = $this->cart->customerOffer();

        // Member-pricing portion of the discount (for "saved as a member" totals).
        $memberDiscount = $this->cart->memberSignupDiscount();

        $order = DB::transaction(function () use ($data, $insideDhaka, $subtotal, $discount, $shipping, $coupon, $pointsRedeemed, $pointsDiscount, $customerOffer, $memberDiscount) {
            // Re-validate every line against live data, holding row locks so two
            // simultaneous checkouts can't both take the last unit. Throws
            // CheckoutException (rolling back) if anything no longer holds.
            [$products, $variants] = $this->validateLines();

            // Attach to a customer record (find-or-create by phone) even for guests.
            $customer = Customer::firstOrCreate(
                ['phone' => $data['phone']],
                ['name' => $data['name'], 'email' => $data['email'] ?? null],
            );

            // If a customer is logged in, prefer that record.
            if (auth('customer')->check()) {
                $customer = auth('customer')->user();
            }

            $order = $this->createWithUniqueNumber([
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
                app(\App\Services\LoyaltyService::class)->award(
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
        \App\Models\AbandonedCart::where('recovered', false)
            ->where(fn ($q) => $q->where('phone', $data['phone'])->orWhere('session_id', session()->getId()))
            ->update(['recovered' => true]);

        // Fire-and-forget SMS confirmation (logged either way).
        $this->sms->sendTemplate('order_placed', $order);

        // Email the invoice if the customer left an email address.
        if (filled($order->customer_email)) {
            try {
                \Illuminate\Support\Facades\Mail::to($order->customer_email)
                    ->send(new \App\Mail\OrderInvoiceMail($order));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Server-side Purchase (deduplicated with the browser Pixel via order_number).
        $this->capi->purchase($order, $order->order_number);

        $this->cart->clear();

        return $order;
    }

    /**
     * Re-validate every cart line against live, row-locked data: the product is
     * still published, stock covers the quantity (pre-orders exempt), and the
     * price hasn't changed since it was added to the cart. On a price change the
     * cart line is repriced so the customer sees current numbers on the bounce.
     *
     * @return array{0:\Illuminate\Support\Collection, 1:\Illuminate\Support\Collection} [products, variants] keyed by id
     *
     * @throws \App\Exceptions\CheckoutException
     */
    protected function validateLines(): array
    {
        $items = $this->cart->items();

        // lockForUpdate holds the rows until the transaction commits, so a
        // concurrent checkout waits here instead of overselling the last unit.
        $products = Product::with('category')
            ->whereIn('id', $items->pluck('product_id')->filter()->unique())
            ->lockForUpdate()->get()->keyBy('id');
        $variants = \App\Models\ProductVariant::whereIn('id', $items->pluck('variant_id')->filter()->unique())
            ->lockForUpdate()->get()->keyBy('id');

        $repriced = [];

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            $variant = $item['variant_id'] ? $variants->get($item['variant_id']) : null;

            // Product (or chosen variant) has been removed / unpublished.
            if (! $product || $product->status !== 'published' || ($item['variant_id'] && ! $variant)) {
                $this->cart->remove($item['key']);

                throw new \App\Exceptions\CheckoutException(
                    '"'.$item['name'].'" is no longer available and was removed from your cart.'
                );
            }

            // Stock check (pre-orders intentionally sell past zero).
            if (! $product->isPreorder()) {
                $qty = (int) $item['qty'];
                if ($variant && (int) $variant->stock_quantity < $qty) {
                    throw new \App\Exceptions\CheckoutException(
                        'Only '.max(0, (int) $variant->stock_quantity).' of "'.$item['name'].'" left in stock — please adjust the quantity.'
                    );
                }
                if (! $variant && $product->manage_stock && (int) $product->stock_quantity < $qty) {
                    throw new \App\Exceptions\CheckoutException(
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
        }

        if ($repriced !== []) {
            throw new \App\Exceptions\CheckoutException(
                'Prices were updated for: '.implode(', ', $repriced).'. Please review your cart before ordering.'
            );
        }

        return [$products, $variants];
    }

    /**
     * Create the order, retrying on an order-number collision (two simultaneous
     * checkouts can generate the same sequential number; the unique index makes
     * the loser retry with the next one instead of 500ing).
     */
    protected function createWithUniqueNumber(array $attributes): Order
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return Order::create(['order_number' => Order::generateNumber()] + $attributes);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    /** Decrement stock on the already-locked models (validated ≥ qty above). */
    protected function decrementStock(?Product $product, ?\App\Models\ProductVariant $variant, int $qty): void
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
