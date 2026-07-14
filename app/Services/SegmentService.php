<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerSegment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves a customer segment to a query. Dynamic segments filter by rules
 * (spend, orders, gender, location, activity, membership); manual segments use
 * the picked member list.
 */
class SegmentService
{
    public function __construct(protected RfmService $rfm) {}

    /** @return Builder<Customer> */
    public function query(CustomerSegment $segment): Builder
    {
        if ($segment->type === 'manual') {
            $ids = $segment->members()->pluck('customers.id');

            return Customer::whereIn('id', $ids);
        }

        return $this->dynamicQuery($segment->rules ?? []);
    }

    /** @return Builder<Customer> */
    public function dynamicQuery(array $r): Builder
    {
        $q = Customer::query();

        // RFM bucket (Recency/Frequency auto-tier). Applied first — the other
        // filters below further narrow within the bucket.
        if (! empty($r['rfm']) && $this->rfm->isBucket($r['rfm'])) {
            $this->rfm->applyBucket($q, $r['rfm']);
        }

        if (! empty($r['members_only'])) {
            $q->whereNotNull('password');
        }
        if (isset($r['min_spend']) && $r['min_spend'] !== '') {
            $q->where('total_spent', '>=', (float) $r['min_spend']);
        }
        if (isset($r['max_spend']) && $r['max_spend'] !== '') {
            $q->where('total_spent', '<=', (float) $r['max_spend']);
        }
        if (isset($r['min_orders']) && $r['min_orders'] !== '') {
            $q->where('total_orders', '>=', (int) $r['min_orders']);
        }
        if (! empty($r['gender'])) {
            $q->where('gender', $r['gender']);
        }
        if (! empty($r['location'])) {
            $loc = trim((string) $r['location']);
            // Match the customer's saved address OR any order's area/district.
            $q->where(function ($w) use ($loc) {
                $w->whereHas('addresses', fn ($a) => $a->where('area', 'like', "%{$loc}%")->orWhere('district', 'like', "%{$loc}%")->orWhere('city', 'like', "%{$loc}%"))
                    ->orWhereHas('orders', fn ($o) => $o->where('area', 'like', "%{$loc}%")->orWhere('district', 'like', "%{$loc}%")->orWhere('city', 'like', "%{$loc}%"));
            });
        }

        $activity = $r['activity'] ?? 'any';
        $days = max(1, (int) ($r['activity_days'] ?? 60));
        if ($activity === 'active') {
            $q->where('last_order_at', '>=', now()->subDays($days));
        } elseif ($activity === 'lapsed') {
            $q->where(fn ($w) => $w->whereNull('last_order_at')->orWhere('last_order_at', '<', now()->subDays($days)))
                ->where('total_orders', '>', 0);
        }

        return $q;
    }

    public function count(CustomerSegment $segment): int
    {
        return $this->query($segment)->count();
    }

    /** Preview count from raw dynamic rules (before saving). */
    public function previewCount(array $rules): int
    {
        return $this->dynamicQuery($rules)->count();
    }
}
