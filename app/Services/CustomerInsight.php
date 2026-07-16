<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Aggregates a customer's order & courier delivery history by phone to surface
 * repeat-customer status and a delivery-reliability ("fraud risk") signal.
 *
 * Mirrors the noychoy-dashboard Steadfast per-phone history: a high
 * cancelled/returned ratio across several orders flags a risky COD customer.
 * Built from OUR OWN orders + Steadfast-synced shipment statuses (no public
 * Steadfast fraud endpoint exists — the panel syncs delivery status per order).
 */
class CustomerInsight
{
    /**
     * @return array{
     *   total:int, delivered:int, cancelled:int, returned:int, pending:int,
     *   success_rate:?int, fail_rate:?int, risk:string, is_repeat:bool, orders:Collection
     * }
     */
    public function forPhone(string $phone, ?int $excludeOrderId = null): array
    {
        // Orders store phones canonically — exact match uses the phone index.
        $orders = Order::query()
            ->where('customer_phone', bd_phone($phone))
            ->when($excludeOrderId, fn ($q) => $q->where('id', '!=', $excludeOrderId))
            ->with('shipment')
            ->latest()
            ->get();

        $counts = ['delivered' => 0, 'cancelled' => 0, 'returned' => 0, 'pending' => 0];

        foreach ($orders as $order) {
            $counts[$this->bucket($order)]++;
        }

        $total = $orders->count();
        $resolved = $counts['delivered'] + $counts['cancelled'] + $counts['returned'];
        $failed = $counts['cancelled'] + $counts['returned'];

        $successRate = $resolved > 0 ? (int) round($counts['delivered'] / $resolved * 100) : null;
        $failRate = $resolved > 0 ? (int) round($failed / $resolved * 100) : null;

        return [
            'total' => $total,
            'delivered' => $counts['delivered'],
            'cancelled' => $counts['cancelled'],
            'returned' => $counts['returned'],
            'pending' => $counts['pending'],
            'success_rate' => $successRate,
            'fail_rate' => $failRate,
            'risk' => $this->riskLevel($resolved, $failed, $failRate),
            'is_repeat' => $total > 0,
            'orders' => $orders,
        ];
    }

    /** Classify an order into a delivery outcome bucket. */
    protected function bucket(Order $order): string
    {
        // Prefer the courier's synced delivery status when present, else order status.
        $shipmentStatus = strtolower((string) $order->shipment?->status);
        $orderStatus = strtolower((string) $order->status);

        foreach ([$shipmentStatus, $orderStatus] as $s) {
            if (str_contains($s, 'deliver') && ! str_contains($s, 'partial')) {
                return 'delivered';
            }
            if (str_contains($s, 'return')) {
                return 'returned';
            }
            if (str_contains($s, 'cancel')) {
                return 'cancelled';
            }
        }

        return 'pending';
    }

    /**
     * none → no resolved history; low → reliable; medium/high → rising COD risk.
     * Needs at least 2 resolved orders before flagging, to avoid false positives.
     */
    protected function riskLevel(int $resolved, int $failed, ?int $failRate): string
    {
        if ($resolved < 2 || $failRate === null) {
            return 'none';
        }
        if ($failRate >= 50 && $failed >= 2) {
            return 'high';
        }
        if ($failRate >= 30) {
            return 'medium';
        }

        return 'low';
    }
}
