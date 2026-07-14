<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Simplified RFM (Recency / Frequency / Monetary) auto-segmentation.
 *
 * Every customer with at least one order falls into exactly one bucket, decided
 * by how recently they last ordered (Recency, in days) and how many orders they
 * have placed (Frequency). Monetary value (total_spent) is surfaced in the
 * distribution for context but is not used for bucketing, which keeps the
 * buckets a clean, non-overlapping partition:
 *
 *   Recency band × Frequency band → bucket
 *   ┌──────────────┬──────────────┬───────────────────┐
 *   │              │  F ≥ 4       │  F ≤ 3            │
 *   ├──────────────┼──────────────┼───────────────────┤
 *   │ R ≤ 30       │ Champions    │ New customers     │
 *   │ R 31–90      │ Loyal        │ Promising         │
 *   │ R 91–180     │ At risk (F≥2)│ Needs attention…  │
 *   │ R > 180      │ Lost (any F)                     │
 *   └──────────────┴──────────────┴───────────────────┘
 */
class RfmService
{
    /** Recency band edges (days since last order). */
    public const R_HOT = 30;
    public const R_WARM = 90;
    public const R_COOL = 180;

    /** Frequency threshold that separates the "loyal" column from the rest. */
    public const F_HIGH = 4;

    /**
     * Bucket definitions, in display order. Each entry is a self-contained,
     * mutually-exclusive rule expressed as recency (days) + frequency ranges.
     *
     * @return array<string, array{label:string, emoji:string, tone:string, blurb:string, r_min:?int, r_max:?int, f_min:?int, f_max:?int}>
     */
    public function buckets(): array
    {
        return [
            'champions' => [
                'label' => 'Champions', 'emoji' => '🏆', 'tone' => 'green',
                'blurb' => 'Bought recently, buy often. Your best customers — reward them.',
                'r_min' => null, 'r_max' => self::R_HOT, 'f_min' => self::F_HIGH, 'f_max' => null,
            ],
            'loyal' => [
                'label' => 'Loyal', 'emoji' => '💎', 'tone' => 'gold',
                'blurb' => 'Order often and came back within 3 months. Keep them close.',
                'r_min' => self::R_HOT + 1, 'r_max' => self::R_WARM, 'f_min' => self::F_HIGH, 'f_max' => null,
            ],
            'new' => [
                'label' => 'New customers', 'emoji' => '🌱', 'tone' => 'green',
                'blurb' => 'Ordered recently but only a few times. Nurture into loyalty.',
                'r_min' => null, 'r_max' => self::R_HOT, 'f_min' => 1, 'f_max' => self::F_HIGH - 1,
            ],
            'promising' => [
                'label' => 'Promising', 'emoji' => '✨', 'tone' => 'gold',
                'blurb' => 'A few orders, last within 3 months. A gentle nudge helps.',
                'r_min' => self::R_HOT + 1, 'r_max' => self::R_WARM, 'f_min' => 1, 'f_max' => self::F_HIGH - 1,
            ],
            'at_risk' => [
                'label' => 'At risk', 'emoji' => '⚠️', 'tone' => 'amber',
                'blurb' => 'Repeat buyers going quiet (3–6 months). Win them back now.',
                'r_min' => self::R_WARM + 1, 'r_max' => self::R_COOL, 'f_min' => 2, 'f_max' => null,
            ],
            'needs_attention' => [
                'label' => 'Needs attention', 'emoji' => '👀', 'tone' => 'amber',
                'blurb' => 'One-time buyers cooling off (3–6 months). Re-engage them.',
                'r_min' => self::R_WARM + 1, 'r_max' => self::R_COOL, 'f_min' => 1, 'f_max' => 1,
            ],
            'lost' => [
                'label' => 'Lost', 'emoji' => '🕸️', 'tone' => 'red',
                'blurb' => "Haven't ordered in over 6 months. A strong offer may revive them.",
                'r_min' => self::R_COOL + 1, 'r_max' => null, 'f_min' => 1, 'f_max' => null,
            ],
        ];
    }

    public function labelFor(string $key): string
    {
        return $this->buckets()[$key]['label'] ?? ucfirst($key);
    }

    public function isBucket(string $key): bool
    {
        return array_key_exists($key, $this->buckets());
    }

    /**
     * Apply a bucket's Recency + Frequency constraints to a customer query.
     * Only customers with at least one order (a real recency date) qualify.
     *
     * @param  Builder<Customer>  $q
     * @return Builder<Customer>
     */
    public function applyBucket(Builder $q, string $key): Builder
    {
        $b = $this->buckets()[$key] ?? null;
        if (! $b) {
            return $q->whereRaw('1 = 0'); // unknown bucket → empty
        }

        $q->where('total_orders', '>=', 1)->whereNotNull('last_order_at');

        // Recency: last_order_at falls inside [now - r_max, now - r_min].
        if ($b['r_max'] !== null) {
            $q->where('last_order_at', '>=', now()->subDays($b['r_max']));
        }
        if ($b['r_min'] !== null) {
            $q->where('last_order_at', '<', now()->subDays($b['r_min']));
        }
        // Frequency.
        if ($b['f_min'] !== null) {
            $q->where('total_orders', '>=', $b['f_min']);
        }
        if ($b['f_max'] !== null) {
            $q->where('total_orders', '<=', $b['f_max']);
        }

        return $q;
    }

    /** @return Builder<Customer> */
    public function bucketQuery(string $key): Builder
    {
        return $this->applyBucket(Customer::query(), $key);
    }

    /**
     * Count + monetary summary for every bucket (for the overview panel).
     *
     * @return array<int, array{key:string, label:string, emoji:string, tone:string, blurb:string, count:int, revenue:float}>
     */
    public function distribution(): array
    {
        $out = [];
        foreach ($this->buckets() as $key => $b) {
            $q = $this->bucketQuery($key);
            $out[] = [
                'key' => $key,
                'label' => $b['label'],
                'emoji' => $b['emoji'],
                'tone' => $b['tone'],
                'blurb' => $b['blurb'],
                'count' => (clone $q)->count(),
                'revenue' => (float) (clone $q)->sum('total_spent'),
            ];
        }

        return $out;
    }
}
