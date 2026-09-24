<?php

namespace App\Actions;

use App\Exceptions\CheckoutException;
use App\Jobs\CheckOrderCourier;
use App\Jobs\SendOrderPlacedEffects;
use App\Models\AbandonedCart;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * @param  array{name:string,phone:string,email?:?string,address:string,area?:?string,district?:?string,is_inside_dhaka?:bool,notes?:?string,shipping_cost?:float,discount?:float,status?:string,coupon_code?:?string}  $data
     * @param  array<int,array{product_id:int,variant_id:?int,qty:int,price?:?float}>  $lines
     */
    public function handle(array $data, array $lines): Order
    {
        $data['phone'] = bd_phone($data['phone']);

        // The block list holds for orders typed in the admin as well —
        // otherwise a number refused at checkout could be let in by hand
        // without anyone deciding to. Unblock it first if that is meant.
        if (\App\Models\BlockedPhone::blocks($data['phone'])) {
            throw ValidationException::withMessages([
                'phone' => 'This number is on the blocked list. Unblock it under Customers → Blocked numbers to take an order on it.',
            ]);
        }

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

            // A coupon given to this number, or any code she typed (owner,
            // 2026-09-17). Judged here, after the product locks and under a
            // lock of its own, for the same reason checkout does: a single-use
            // code must not be spendable by an order on the phone and a
            // checkout on the site in the same second.
            [$coupon, $couponDiscount] = $this->resolveCoupon($data['coupon_code'] ?? null, $data['phone'], $resolved, $subtotal, $discount);

            if ($coupon?->free_shipping) {
                $shipping = 0.0;
            }

            $total = max(0, round($subtotal - $discount - $couponDiscount + $shipping, 2));

            // Match the order to an existing customer by phone, or make one, so
            // a DM buyer builds the same history as a storefront buyer.
            $customer = Customer::firstOrCreate(
                ['phone' => $data['phone']],
                ['name' => $data['name'], 'email' => $data['email'] ?? null],
            );

            // An order typed in already cancelled (a phone sale recorded after
            // it fell through) never holds stock. It used to take the pieces
            // off the shelf anyway, and nothing ever put them back: the order
            // never passed through TransitionOrderStatus. Now nothing is taken
            // and the flag says the stock is free, so moving it to a live
            // status later takes it then, like any cancelled order.
            $holdsStock = ($data['status'] ?? 'confirmed') !== 'cancelled';

            $order = $this->createWithUniqueNumber([
                'stock_restored' => ! $holdsStock,
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
                // One discount column, as a storefront order has: the coupon's
                // share is in it, and coupon_code says where that share came
                // from — which is also what the per-number limit counts.
                'discount' => round($discount + $couponDiscount, 2),
                'coupon_code' => $coupon?->code,
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

                if ($holdsStock) {
                    $this->decrementStock($r['product'], $r['variant'], $r['qty']);
                }
            }

            if ($coupon) {
                $coupon->increment('used_count');
            }

            $order->history()->create([
                'status' => $order->status,
                'note' => 'Order taken by hand in the admin'.($coupon ? $this->couponNote($coupon, $couponDiscount) : ''),
                'created_by' => auth()->user()?->name ?? 'Admin',
            ]);

            // The customer still gets their confirmation SMS and invoice. There
            // is no browser session behind this sale, and inventing one would
            // be worse than the server-side Purchase reporting the order
            // honestly — so the context says exactly that. An empty array used
            // to stand in for it, and the Purchase quietly fell back to the
            // queue worker's own request: 127.0.0.1, user agent "Symfony",
            // reported to Meta as a website visit (2026-09-17 audit).
            SendOrderPlacedEffects::dispatch($order->fresh('items'), MetaTrackingService::noBrowserContext());

            // And its BDCourier history is looked up on its own, exactly as a
            // storefront order's is: an order taken on Messenger from someone
            // the shop has never sold to is the same COD gamble, and she is
            // often typing it in while the customer is still on the line. The
            // job waits for this transaction to commit before it runs.
            CheckOrderCourier::queueFor($order);

            // A sale closed on the phone settles the lead exactly as a
            // storefront checkout does. Without this an order she took by hand
            // left the basket sitting in the abandoned list, so the follow-up
            // queue kept chasing customers who had already bought — and the
            // recovered figure only ever counted the ones who came back alone.
            //
            // Phone only: PlaceOrder can also match on the session, and there
            // is no customer session behind an order taken in the admin.
            AbandonedCart::where('recovered', false)
                ->where('phone', $data['phone'])
                ->update(['recovered' => true]);

            // The customer's rollups, exactly as PlaceOrder keeps them. Orders
            // taken by hand never counted, so a phone or Messenger regular
            // looked like a one-time buyer everywhere those figures are read:
            // the customer list's spend and repeat filters, the new-order
            // customer picker, and the win-back and occasion audiences.
            // One typed in already cancelled or returned is not a sale.
            if (! in_array($order->status, Order::NOT_SALES, true)) {
                $customer->increment('total_orders');
                $customer->increment('total_spent', $order->total);
            }
            $customer->update(['last_order_at' => now()]);

            return $order;
        });
    }

    /**
     * The coupon for this order and what it takes off, or a refusal that says why.
     *
     * Mirrors PlaceOrder's coupon block rather than calling it, for the reason
     * this class exists at all: that one reads the caller's session cart. What
     * is checked is what checkout checks, in words the owner can repeat to the
     * customer still on the line — a code that "doesn't work" with no reason is
     * a call she cannot finish.
     *
     * Refused, not quietly dropped. She is quoting a total on the phone, and an
     * order written without the discount she promised is the argument at the
     * door that ends with the parcel coming back.
     *
     * @param  array<int,array{product:Product,variant:?ProductVariant,qty:int,price:float}>  $resolved
     * @return array{0:?Coupon,1:float}
     *
     * @throws ValidationException
     */
    protected function resolveCoupon(?string $typed, string $phone, array $resolved, float $subtotal, float $discount): array
    {
        $typed = strtoupper(trim((string) $typed));

        if ($typed === '') {
            return [null, 0.0];
        }

        // A code read out over the phone arrives with a space or a dash in it
        // as often as not — the storefront forgives that too.
        $coupon = Coupon::where('code', $typed)->lockForUpdate()->first();
        if (! $coupon && ($compact = (string) preg_replace('/[^A-Z0-9]/', '', $typed)) !== '' && $compact !== $typed) {
            $coupon = Coupon::where('code', $compact)->lockForUpdate()->first();
        }

        if (! $coupon) {
            $this->refuse('There is no coupon with the code '.$typed.'.');
        }
        if (! $coupon->is_active) {
            $this->refuse('The coupon '.$coupon->code.' is switched off.');
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            $this->refuse('The coupon '.$coupon->code.' does not start until '.store_time($coupon->starts_at)->format('j M Y').'.');
        }
        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            $this->refuse('The coupon '.$coupon->code.' expired on '.store_time($coupon->expires_at)->format('j M Y').'.');
        }
        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            $this->refuse('The coupon '.$coupon->code.' has been used up — '.$coupon->used_count.' of '.$coupon->usage_limit.' uses are gone.');
        }
        if ($coupon->reservedForSomeoneElse($phone)) {
            $this->refuse('This coupon is for a different phone number.');
        }
        if ($coupon->customerLimitReached($phone)) {
            $this->refuse('This number has already used '.$coupon->code.' as many times as it allows.');
        }
        if ($coupon->min_order !== null && $subtotal < (float) $coupon->min_order) {
            $this->refuse('The coupon '.$coupon->code.' needs an order of at least '.money($coupon->min_order)
                .' — the items come to '.money($subtotal).'.');
        }

        $eligible = array_values(array_filter($resolved, fn ($r) => $this->couponCovers($coupon, $r['product'])));

        if ($eligible === []) {
            $this->refuse('None of the items on this order are covered by '.$coupon->code
                .($coupon->exclude_sale_items ? ' (it leaves out pieces already on sale).' : '.'));
        }

        $qty = array_sum(array_column($eligible, 'qty'));

        if ($coupon->min_qty !== null && $qty < $coupon->min_qty) {
            $this->refuse('The coupon '.$coupon->code.' needs at least '.$coupon->min_qty.' pieces it covers — this order has '.$qty.'.');
        }
        if ($coupon->max_qty !== null && $qty > $coupon->max_qty) {
            $this->refuse('The coupon '.$coupon->code.' covers at most '.$coupon->max_qty.' pieces — this order has '.$qty.'.');
        }

        // Priced on the lines it covers, at the price she is actually charging
        // for them. Capped twice: never more than those lines, and never so
        // much that her own discount and the coupon together pass the items.
        $base = array_sum(array_map(fn ($r) => $r['price'] * $r['qty'], $eligible));
        $amount = $coupon->type === 'percent'
            ? $base * ((float) $coupon->value / 100)
            : (float) $coupon->value;

        return [$coupon, round(max(0, min($amount, $base, $subtotal - $discount)), 2)];
    }

    /**
     * Whether a coupon reaches this product.
     *
     * Coupon::itemEligible() for a line that never went through a cart: the
     * category is the product's own category_id and "on sale" is
     * Product::is_on_sale, exactly what CartService::lineFor() snapshots onto a
     * storefront line, so a code covers the same pieces on the phone as online.
     */
    protected function couponCovers(Coupon $coupon, Product $product): bool
    {
        if ($coupon->exclude_sale_items && $product->is_on_sale) {
            return false;
        }

        return match ($coupon->applies_to) {
            'products' => in_array((int) $product->id, array_map('intval', $coupon->product_ids ?? []), true),
            'categories' => in_array((int) ($product->category_id ?? 0), array_map('intval', $coupon->category_ids ?? []), true),
            default => true, // 'all'
        };
    }

    /** " with coupon EID200 (৳200 off, free delivery)", for the order's history. */
    protected function couponNote(Coupon $coupon, float $amount): string
    {
        $parts = array_filter([
            $amount > 0 ? money($amount).' off' : null,
            $coupon->free_shipping ? 'free delivery' : null,
        ]);

        return ' with coupon '.$coupon->code.($parts ? ' ('.implode(', ', $parts).')' : '');
    }

    /** @throws ValidationException */
    protected function refuse(string $reason): never
    {
        throw ValidationException::withMessages(['coupon_code' => $reason]);
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
