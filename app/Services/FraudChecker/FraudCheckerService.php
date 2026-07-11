<?php

namespace App\Services\FraudChecker;

use App\Models\FraudReport;
use Azmolla\FraudCheckerBdCourier\Facade\FraudCheckerBdCourier;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the azmolla/fraud-checker-bd-courier package: validates the
 * phone number, runs the (slow) multi-courier lookup and caches the aggregated
 * result as a FraudReport so the orders list can flag risky customers cheaply.
 *
 * Never throws to the caller — a missing/invalid config or a courier outage
 * returns a friendly error string instead, so the UI degrades gracefully.
 */
class FraudCheckerService
{
    public function __construct(private readonly FraudCheckerSettings $settings) {}

    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
    }

    /**
     * Normalise any stored phone form to a bare 11-digit BD mobile (01XXXXXXXXX),
     * or null if it isn't a valid Bangladeshi mobile number.
     */
    public function normalizePhone(?string $phone): ?string
    {
        $digits = bd_phone($phone);

        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
    }

    /** The cached report for a phone (if any), without running a new lookup. */
    public function cachedFor(?string $phone): ?FraudReport
    {
        $normalized = $this->normalizePhone($phone);

        return $normalized ? FraudReport::where('phone', $normalized)->first() : null;
    }

    /**
     * Run a live fraud check and cache it.
     *
     * @return array{0:?FraudReport,1:?string} [report, errorMessage]
     */
    public function check(?string $phone): array
    {
        $normalized = $this->normalizePhone($phone);
        if (! $normalized) {
            return [null, 'Not a valid Bangladeshi mobile number (expected 01XXXXXXXXX).'];
        }

        if (! $this->isConfigured()) {
            return [null, 'Courier logins are not configured yet (Settings → Fraud Checker).'];
        }

        try {
            $data = FraudCheckerBdCourier::check($normalized);
        } catch (\Throwable $e) {
            Log::warning('Fraud check failed', ['phone' => $normalized, 'error' => $e->getMessage()]);

            return [null, 'Fraud check could not complete: '.$e->getMessage()];
        }

        $agg = is_array($data) ? ($data['aggregate'] ?? []) : [];

        $report = FraudReport::updateOrCreate(['phone' => $normalized], [
            'payload' => is_array($data) ? $data : [],
            'total_deliveries' => (int) ($agg['total_deliveries'] ?? 0),
            'total_success' => (int) ($agg['total_success'] ?? 0),
            'total_cancel' => (int) ($agg['total_cancel'] ?? 0),
            'success_ratio' => isset($agg['success_ratio']) ? (float) $agg['success_ratio'] : null,
            'cancel_ratio' => isset($agg['cancel_ratio']) ? (float) $agg['cancel_ratio'] : null,
            'is_risky' => $this->isRisky($agg),
            'checked_at' => now(),
        ]);

        return [$report, null];
    }

    /**
     * Risk heuristic: a high cancellation ratio, or several cancellations paired
     * with a low success ratio, marks the customer (and their order) as risky.
     */
    public function isRisky(array $aggregate): bool
    {
        $cancelRatio = (float) ($aggregate['cancel_ratio'] ?? 0);
        $cancels = (int) ($aggregate['total_cancel'] ?? 0);
        $successRatio = isset($aggregate['success_ratio']) ? (float) $aggregate['success_ratio'] : 100.0;

        return $cancelRatio >= 30 || ($cancels >= 3 && $successRatio < 60);
    }
}
