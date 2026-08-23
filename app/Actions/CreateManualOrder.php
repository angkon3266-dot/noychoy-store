<?php

namespace App\Actions;

use App\Exceptions\CheckoutException;
use App\Jobs\SendOrderPlacedEffects;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * An order taken by hand — over the phone, on Messenger, or on WhatsApp.
 *
 * A large share of this shop's orders arrive as a DM. Until this existed the
 * owner had to open the public storefront and check out as if she were the
 * customer, which fired the browser Pixel from her own device and attributed
 * the sale to whatever her session looked like: her own traffic reported as a
 * customer's, on every manual order.
 *
 * Deliberately NOT built on PlaceOrder. That action is a cart pipeline — it
 * reads, reprices and clears the caller's session cart — so running it from the
 * admin would put the customer's items in the owner's own cart. What is worth
 * sharing (row locks before a stock check, the unique-number retry, the cost
 * snapshot on each line) is small and reproduced here explicitly.
 */
class CreateManualOrder
{
    /**
     * @param  array{name:string,phone:string,email?:?string,address:string,area?:?string,district?:?string,is_inside_dhaka?:bool,notes?:?string,shipping_cost?:float,discount?:float,status?:string}  $data
     * @param  array<int,array{product_id:int,variant_id:?int,qty:int,price?:?float}>  $lines
     */
    public function handle(array $data, array $lines): Order
    {
        $data['phone'] = bd_phone($data['phone']);

        return DB::transaction(function () use ($data, $lines) {
            $subtotal = 0.0;
            $resolved = [];

            foreach ($lines as $line) {
                $qty = max(1, (int) ($line['qty'] ?? 1));

                // Locked for the same reason checkout locks: two people must
                // not be able to sell the same last unit.
                $product = Product::whereKey($line['product_id'] ?? null)->lockForUpdate()->first();

                if (! $product) {
                    throw new CheckoutException('One of the chosen products no longer exists.');
                }

                $variant = null;
                if (! empty($line['variant_id'])) {
                    $variant = ProductVariant::whereKey($line['variant_id'])
                        ->where('product_id', $product->id)->lockForUpdate()->first();

                    if (! $variant) {
                        throw new CheckoutException('The chosen option for "'.$product->name.'" is no longer available.');
                    }
                }

                if ($variant && (int) $variant->stock_quantity < $qty) {
                    throw new CheckoutException('Only '.max(0, (int) $variant->stock_quantity).' of "'.$product->name.'" left in stock.');
                }

                if (! $variant && $product->manage_stock && (int) $product->stock_quantity < $qty) {
                    throw new CheckoutException('Only '.max(0, (int) $product->stock_quantity).' of "'.$product->name.'" left in stock.');
                }

                // The owner can override a price — she may have agreed a figure
                // on the phone — but it is never taken from the request blindly
                // when she did not.
                $price = isset($line['price']) && $line['price'] !== null && $line['price'] !== ''
                    ? round((float) $line['price'], 2)
                    : (float) ($variant->price ?? $product->price);

                $subtotal += $price * $qty;
                $resolved[] = compact('product', 'variant', 'qty', 'price');
            }

            if (empty($resolved)) {
                throw new CheckoutException('Add at least one product to the order.');
            }

            $shipping = round((float) ($data['shipping_cost'] ?? 0), 2);
            $discount = round((float) ($data['discount'] ?? 0), 2);
            $total = max(0, round($subtotal - $discount + $shipping, 2));

            // Match the order to an existing customer by phone, or make one, so
            // a DM buyer builds the same history as a storefront buyer.
            $customer = Customer::firstOrCreate(
                ['phone' => $data['phone']],
                ['name' => $data['name'], 'email' => $data['email'] ?? null],
            );

            $order = $this->createWithUniqueNumber([
                'customer_id' => $customer->id,
                'customer_name' => $data['name'],
                'customer_phone' => $data['phone'],
                'customer_email' => $data['email'] ?? null,
                'shipping_address' => $data['address'],
                'area' => $data['area'] ?? null,
                'district' => $data['district'] ?? null,
                'is_inside_dhaka' => (bool) ($data['is_inside_dhaka'] ?? false),
                'subtotal' => $subtotal,
                'shipping_cost' => $shipping,
                'discount' => $discount,
                'total' => $total,
                'payment_method' => 'cod',
                'status' => $data['status'] ?? 'confirmed',
                'notes' => $data['notes'] ?? null,
                // So the dashboard can tell a DM sale from a storefront one.
                'source_channel' => 'admin',
            ]);

            foreach ($resolved as $r) {
                $order->items()->create([
                    'product_id' => $r['product']->id,
                    'variant_id' => $r['variant']?->id,
                    'name' => $r['product']->name,
                    'sku' => $r['variant']->sku ?? $r['product']->sku,
                    'attributes' => $r['variant']->attributes ?? null,
                    'price' => $r['price'],
                    // Snapshot, so margin reporting survives a later cost change.
                    'cost_price' => $r['product']->cost_price,
                    'transport_cost' => $r['product']->transport_cost,
                    'quantity' => $r['qty'],
                    'subtotal' => round($r['price'] * $r['qty'], 2),
                ]);

                $this->decrementStock($r['product'], $r['variant'], $r['qty']);
            }

            $order->history()->create([
                'status' => $order->status,
                'note' => 'Order taken by hand in the admin',
                'created_by' => auth()->user()?->name ?? 'Admin',
            ]);

            // The customer still gets their confirmation SMS and invoice. The
            // client context is empty on purpose: there is no browser session
            // behind this sale, and inventing one would be worse than the
            // server-side Purchase reporting the order honestly.
            SendOrderPlacedEffects::dispatch($order->fresh('items'), []);

            return $order;
        });
    }

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
