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

        $order = DB::transaction(function () use ($data, $insideDhaka, $subtotal, $discount, $shipping, $coupon, $pointsRedeemed, $pointsDiscount) {
            // Attach to a customer record (find-or-create by phone) even for guests.
            $customer = Customer::firstOrCreate(
                ['phone' => $data['phone']],
                ['name' => $data['name'], 'email' => $data['email'] ?? null],
            );

            // If a customer is logged in, prefer that record.
            if (auth('customer')->check()) {
                $customer = auth('customer')->user();
            }

            $order = Order::create([
                'order_number' => Order::generateNumber(),
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

            // Snapshot landed cost per product so margin reporting stays accurate
            // even if the product's cost changes later.
            $costs = Product::whereIn('id', collect($this->cart->items())->pluck('product_id')->filter())
                ->get(['id', 'cost_price', 'transport_cost'])->keyBy('id');

            foreach ($this->cart->items() as $item) {
                $product = $costs->get($item['product_id']);

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

                $this->decrementStock($item['product_id'], $item['variant_id'], $item['qty']);
            }

            $order->history()->create(['status' => 'processing', 'note' => 'Order placed by customer']);

            if ($coupon) {
                $coupon->increment('used_count');
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

    protected function decrementStock(int $productId, ?int $variantId, int $qty): void
    {
        if ($variantId) {
            \App\Models\ProductVariant::where('id', $variantId)->decrement('stock_quantity', $qty);
        }
        $product = Product::find($productId);
        if ($product && $product->manage_stock) {
            $product->decrement('stock_quantity', $qty);
            if ($product->stock_quantity <= 0) {
                $product->update(['in_stock' => false]);
            }
        }
    }
}
