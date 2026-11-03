<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Store credit per mobile number (owner, 2026-11-03).
 *
 * The owner adds (or takes off) money for a number in Admin → Customers →
 * Wallets; whoever checks out on that number may spend it. Her answers when
 * this was built:
 *
 *   - Anyone who types the number can use it, signed in or not. The shop
 *     calls that number to confirm and the courier delivers to it, so credit
 *     spent by someone else reaches the number's owner, not them.
 *   - It is store credit, so it counts like a discount: it is inside the
 *     order's `discount` and lowers the total the rider collects.
 *   - It pays for products only, never the delivery charge.
 *   - No SMS when money is added (the shop sends two automatic texts, no more).
 *
 * Every movement writes a ledger row and the balance together, under a lock
 * on the wallet row, so two checkouts on one number cannot both spend it.
 */
class WalletService
{
    /** Balance on a number, 0 for one with no wallet. */
    public function balanceFor(?string $phone): float
    {
        $phone = bd_phone((string) $phone);

        if ($phone === '') {
            return 0.0;
        }

        return (float) (Wallet::where('phone', $phone)->value('balance') ?? 0);
    }

    /**
     * What the wallet on this number would pay towards products worth
     * $productsDue (after every other discount) — never the delivery charge.
     */
    public function usableFor(?string $phone, float $productsDue): float
    {
        return round(max(0, min($this->balanceFor($phone), $productsDue)), 2);
    }

    /**
     * The checkout's view of a number's wallet, or null when it holds nothing.
     * Shared by the checkout page and the lead capture that fires on the phone.
     */
    public function checkoutCard(?string $phone): ?array
    {
        $balance = $this->balanceFor($phone);

        return $balance > 0 ? [
            'phone' => bd_phone((string) $phone),
            'balance' => $balance,
            'balanceText' => money($balance),
        ] : null;
    }

    /**
     * Add (positive) or take off (negative) money by hand from the admin.
     *
     * Taking off more than the balance is refused rather than clamped: the
     * owner typed a figure, and quietly writing a different one is how a
     * ledger stops matching what she remembers doing.
     */
    public function adjust(string $phone, float $amount, ?string $note = null, ?int $userId = null): WalletTransaction
    {
        $amount = round($amount, 2);
        $phone = bd_phone($phone);

        if ($phone === '' || $amount == 0.0) {
            throw new InvalidArgumentException('A number and an amount are needed.');
        }

        return DB::transaction(function () use ($phone, $amount, $note, $userId) {
            $wallet = $this->lockedWallet($phone, create: $amount > 0);

            if (! $wallet || (float) $wallet->balance + $amount < 0) {
                throw new InvalidArgumentException(
                    $phone.' has '.money((float) ($wallet->balance ?? 0)).' in its wallet — '
                    .money(abs($amount)).' cannot be taken off.'
                );
            }

            return $this->move($wallet, $amount, $amount > 0 ? 'credit' : 'debit', $note, null, $userId);
        });
    }

    /**
     * Lock a number's wallet and say how much of it an order may take.
     * Called inside PlaceOrder's transaction, before the order exists.
     */
    public function reserve(string $phone, float $productsDue): array
    {
        $wallet = $this->lockedWallet(bd_phone($phone), create: false);
        $use = $wallet ? round(max(0, min((float) $wallet->balance, $productsDue)), 2) : 0.0;

        return [$wallet, $use];
    }

    /** Take what reserve() allowed off the wallet, against the order just written. */
    public function spend(Wallet $wallet, float $amount, Order $order): ?WalletTransaction
    {
        if ($amount <= 0) {
            return null;
        }

        return $this->move($wallet, -$amount, 'spend', 'Used on order '.$order->order_number, $order);
    }

    /**
     * Give back the credit an order spent, when it unwinds (cancelled,
     * returned, partially delivered — Order::NOT_SALES). Once per order:
     * `wallet_refunded_at` is stamped in the same transaction, so a status
     * flip-flop cannot refund twice. Like refunded points, booking the order
     * again later does not take the credit back.
     */
    public function refundForOrder(Order $order): ?WalletTransaction
    {
        return DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->first();
            $amount = round((float) ($order?->wallet_amount ?? 0), 2);

            if (! $order || $amount <= 0 || $order->wallet_refunded_at !== null) {
                return null;
            }

            $wallet = $this->lockedWallet(bd_phone($order->customer_phone), create: true);
            $tx = $this->move($wallet, $amount, 'refund', 'Order '.$order->order_number.' '.$order->status.' — credit returned', $order);
            $order->forceFill(['wallet_refunded_at' => now()])->saveQuietly();

            return $tx;
        });
    }

    /**
     * The WhatsApp message offered after credit is added (owner, 2026-11-03:
     * "message the customer via whatsapp about the offer with pre filled
     * message"). Edited on the Wallets page; this is the default.
     */
    public const WHATSAPP_DEFAULT = "Hello {name}! {store} has added {amount} credit to your wallet for {phone}. Your balance is now {balance}.\n\nJust order on {link} with this number — it comes off the price automatically at checkout.";

    public function whatsappTemplate(): string
    {
        return trim((string) \App\Models\Setting::get('whatsapp_wallet_template')) ?: self::WHATSAPP_DEFAULT;
    }

    /**
     * A wa.me link to the number with the message filled in, or null for a
     * number WhatsApp cannot take. {amount} is the credit being announced,
     * {balance} what the wallet holds now.
     */
    public function whatsappLink(string $phone, float $amount, float $balance, ?string $name = null): ?string
    {
        $text = strtr($this->whatsappTemplate(), [
            '{name}' => trim((string) $name),
            '{store}' => store_name(),
            '{amount}' => money($amount),
            '{balance}' => money($balance),
            '{phone}' => bd_phone($phone),
            '{link}' => rtrim(url('/'), '/'),
        ]);

        // "Hello {name}!" with no name on file reads "Hello!", not "Hello !".
        $text = preg_replace('/[ \t]+([,!.?])/', '$1', $text);

        return wa_link($phone, $text);
    }

    protected function lockedWallet(string $phone, bool $create): ?Wallet
    {
        if ($phone === '') {
            return null;
        }

        $wallet = Wallet::where('phone', $phone)->lockForUpdate()->first();

        if (! $wallet && $create) {
            // Created then re-read under the lock; the unique phone index
            // settles two first credits racing for the same number.
            Wallet::firstOrCreate(['phone' => $phone], ['balance' => 0]);
            $wallet = Wallet::where('phone', $phone)->lockForUpdate()->first();
        }

        return $wallet;
    }

    protected function move(Wallet $wallet, float $amount, string $type, ?string $note, ?Order $order = null, ?int $userId = null): WalletTransaction
    {
        $balance = round((float) $wallet->balance + $amount, 2);
        $wallet->update(['balance' => $balance]);

        return $wallet->transactions()->create([
            'amount' => $amount,
            'balance_after' => $balance,
            'type' => $type,
            'note' => $note,
            'order_id' => $order?->id,
            'user_id' => $userId,
        ]);
    }
}
