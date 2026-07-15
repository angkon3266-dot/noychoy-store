<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Central loyalty / points engine.
 *
 *   Earn   : 1000৳ spent = 100 points  (config 'earn_per_taka')
 *   Redeem : 100 points = 5৳           (config 'redeem_value', in multiples of 'redeem_step')
 *
 * Awards are idempotent when tied to a reference model (Order / Review), so the
 * same order can never grant points twice even if the status flips repeatedly.
 */
class LoyaltyService
{
    public function enabled(): bool
    {
        return (bool) Setting::get('loyalty_enabled', config('loyalty.enabled', true));
    }

    // ── Admin-configurable rates (Setting override → config default) ─────────

    /** Points earned per taka spent. */
    public function earnPerTaka(): float
    {
        return (float) Setting::get('loyalty_earn_per_taka', config('loyalty.earn_per_taka', 0.1));
    }

    /** Taka value of one point. */
    public function redeemValue(): float
    {
        return (float) Setting::get('loyalty_redeem_value', config('loyalty.redeem_value', 0.05));
    }

    public function reviewPoints(): int
    {
        return (int) Setting::get('loyalty_review_points', config('loyalty.review_points', 200));
    }

    public function signupPoints(): int
    {
        return (int) Setting::get('loyalty_signup_points', config('loyalty.signup_points', 0));
    }

    // ── Conversions ─────────────────────────────────────────────────────────

    /** Points earned for spending an amount of money. */
    public function pointsForSpend(float $amount): int
    {
        return (int) floor(max(0, $amount) * $this->earnPerTaka());
    }

    /** Taka value of a number of points. */
    public function pointsValue(int $points): float
    {
        return round(max(0, $points) * $this->redeemValue(), 2);
    }

    public function redeemStep(): int
    {
        return max(1, (int) config('loyalty.redeem_step', 100));
    }

    public function minRedeem(): int
    {
        return max(1, (int) config('loyalty.min_redeem', 100));
    }

    /**
     * Clamp a requested redemption to: customer balance, redeem step, and a cap
     * (e.g. the order subtotal in taka). Returns whole, valid points to redeem.
     */
    public function clampRedeemable(int $requested, int $balance, float $capTaka): int
    {
        $step = $this->redeemStep();
        $maxByValue = (int) (floor($capTaka / $this->pointsValue($step)) * $step); // points whose value ≤ cap
        $points = min($requested, $balance, $maxByValue);
        $points = (int) (floor($points / $step) * $step); // snap to step

        return $points >= $this->minRedeem() ? $points : 0;
    }

    // ── Ledger ──────────────────────────────────────────────────────────────

    /**
     * Award (or deduct, if negative) points. Idempotent per (customer, type, reference).
     * Returns the PointTransaction, or null if it already existed / was a no-op.
     */
    public function award(Customer $customer, int $points, string $type, ?string $description = null, ?Model $reference = null): ?PointTransaction
    {
        if ($points === 0) {
            return null;
        }

        return DB::transaction(function () use ($customer, $points, $type, $description, $reference) {
            if ($reference) {
                $exists = PointTransaction::where('customer_id', $customer->id)
                    ->where('type', $type)
                    ->where('reference_type', $reference->getMorphClass())
                    ->where('reference_id', $reference->getKey())
                    ->exists();
                if ($exists) {
                    return null;
                }
            }

            $tx = PointTransaction::create([
                'customer_id' => $customer->id,
                'points' => $points,
                'type' => $type,
                'description' => $description,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
            ]);

            $customer->increment('points', $points);
            if ($points > 0) {
                $customer->increment('points_lifetime', $points);
            }

            return $tx;
        });
    }

    public function reviewPhotoBonus(): int
    {
        return (int) Setting::get('loyalty_review_photo_bonus', config('loyalty.review_photo_bonus', 100));
    }

    // ── Membership tiers ──────────────────────────────────────────────────────

    /**
     * @return array{current:array,next:?array,lifetime:int,to_next:int,progress:int}
     */
    public function tierFor(Customer $customer): array
    {
        $tiers = collect(config('loyalty.tiers', []))->sortBy('min_points')->values();
        $lifetime = (int) $customer->points_lifetime;

        $current = $tiers->first() ?? ['key' => 'silver', 'label' => 'Member', 'min_points' => 0, 'multiplier' => 1.0, 'perk' => ''];
        foreach ($tiers as $t) {
            if ($lifetime >= $t['min_points']) {
                $current = $t;
            }
        }
        $next = $tiers->first(fn ($t) => $t['min_points'] > $lifetime);

        $span = $next ? max(1, $next['min_points'] - $current['min_points']) : 1;

        return [
            'current' => $current,
            'next' => $next,
            'lifetime' => $lifetime,
            'to_next' => $next ? max(0, $next['min_points'] - $lifetime) : 0,
            'progress' => $next ? (int) min(100, round(($lifetime - $current['min_points']) / $span * 100)) : 100,
        ];
    }

    public function tierMultiplier(Customer $customer): float
    {
        return (float) ($this->tierFor($customer)['current']['multiplier'] ?? 1.0);
    }

    /** Award the earn-on-delivery points for an order (idempotent), boosted by tier. */
    public function awardForOrder(\App\Models\Order $order): ?PointTransaction
    {
        if (! $this->enabled() || ! $order->customer) {
            return null;
        }
        $points = (int) round($this->pointsForSpend((float) $order->subtotal) * $this->tierMultiplier($order->customer));
        if ($points <= 0) {
            return null;
        }

        $tx = $this->award($order->customer, $points, 'earn_order', 'Order '.$order->order_number.' delivered', $order);
        if ($tx) {
            $order->update(['points_earned' => $points]);
        }

        return $tx;
    }

    // ── Weekly milestones ─────────────────────────────────────────────────────

    /**
     * Milestone progress for the current week.
     *
     * @return array<int, array{key:string,label:string,points:int,icon:string,done:bool}>
     */
    public function weeklyMilestones(Customer $customer): array
    {
        $weekStart = now()->startOfWeek();
        $doneTypes = PointTransaction::where('customer_id', $customer->id)
            ->where('created_at', '>=', $weekStart)
            ->pluck('type')->unique()->all();

        return collect(config('loyalty.milestones', []))->map(fn ($m) => [
            'key' => $m['key'],
            'label' => $m['label'],
            'points' => (int) $m['points'],
            'icon' => $m['icon'] ?? '✨',
            'done' => in_array($m['key'], $doneTypes, true),
        ])->all();
    }
}
