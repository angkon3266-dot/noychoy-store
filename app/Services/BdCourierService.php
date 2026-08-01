<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BDCourier courier-history lookup.
 *
 * Answers one question before a COD parcel goes out: has this phone number
 * actually accepted deliveries before, across every major Bangladeshi courier?
 * A customer who refuses half their parcels costs the shop both the delivery
 * fee and the return fee, and nothing in the shop's own data reveals that until
 * it has already happened once.
 *
 * Deliberately narrow: only POST /courier-check is used. The vendor also
 * exposes plan, connection-test and legacy endpoints — none are wired up.
 *
 * Lookups cost plan quota, so they never happen on their own. An admin presses
 * "Check courier history" and the result is cached per phone, which means
 * re-opening the same order (or another order from the same customer) is free.
 */
class BdCourierService
{
    public const CACHE_TTL_HOURS = 24;

    /** Most numbers one bulk action will look up; the admin waits on each call. */
    public const BULK_LIMIT = 25;

    /**
     * Cached results for many phones at once, for the orders list. Cache reads
     * only — rendering a page must never spend quota.
     *
     * @param  iterable<string>  $phones
     * @return array<string, array<string, mixed>> keyed by canonical phone
     */
    public function cachedMany(iterable $phones): array
    {
        $out = [];

        foreach (collect($phones)->filter()->map(fn ($p) => bd_phone((string) $p))->unique() as $phone) {
            if ($hit = $this->cached($phone)) {
                $out[$phone] = $hit;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected function config(): array
    {
        $int = Setting::get('integrations', []);

        return is_array($int) ? $int : [];
    }

    public function isConfigured(): bool
    {
        $c = $this->config();

        return ! empty($c['bdcourier_enabled']) && filled($c['bdcourier_api_key'] ?? null);
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->config()['bdcourier_base_url'] ?? 'https://api.bdcourier.com'), '/');
    }

    /** Success-rate floor for "Safe" (percent). */
    public function safeThreshold(): float
    {
        return (float) ($this->config()['bdcourier_safe_threshold'] ?? 80);
    }

    /** Success-rate floor for "Warning"; below this is "Risky" (percent). */
    public function warningThreshold(): float
    {
        return (float) ($this->config()['bdcourier_warning_threshold'] ?? 50);
    }

    protected function cacheKey(string $phone): string
    {
        return 'bdcourier.check.'.bd_phone($phone);
    }

    /**
     * A previously fetched result for this phone, without spending quota.
     * Returns null if it was never looked up (or the cache has expired).
     *
     * @return array<string, mixed>|null
     */
    public function cached(string $phone): ?array
    {
        return Cache::get($this->cacheKey($phone));
    }

    public function forget(string $phone): void
    {
        Cache::forget($this->cacheKey($phone));
    }

    /**
     * Look the phone up and cache the result. Costs one plan credit.
     *
     * @return array{ok:bool, error?:string, summary?:array, couriers?:array, reports?:array, risk?:array, checked_at?:string}
     */
    public function check(string $phone): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'BDCourier is not configured. Add the API key under Admin → Integrations.'];
        }

        try {
            $response = Http::withToken((string) $this->config()['bdcourier_api_key'])
                ->acceptJson()
                ->timeout(20)
                ->post($this->baseUrl().'/courier-check', ['phone' => bd_phone($phone)]);
        } catch (\Throwable $e) {
            Log::warning('BDCourier lookup failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'Could not reach BDCourier. Please try again.'];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ['ok' => false, 'error' => 'BDCourier rejected the API key. Check it under Admin → Integrations.'];
        }
        if ($response->status() === 429) {
            return ['ok' => false, 'error' => 'BDCourier rate limit or plan quota reached.'];
        }
        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'BDCourier returned an error ('.$response->status().').'];
        }

        $result = $this->normalise($response->json() ?? []);

        if ($result['ok']) {
            Cache::put($this->cacheKey($phone), $result, now()->addHours(self::CACHE_TTL_HOURS));
        }

        return $result;
    }

    /**
     * Look up several phones in one go (the orders-list bulk action).
     *
     * Deduplicated by canonical phone, so five orders from the same customer
     * cost one credit, and numbers already in the cache are skipped entirely
     * unless $force. Capped because each lookup is a synchronous HTTP call and
     * the admin is waiting on the response.
     *
     * @param  iterable<string>  $phones
     * @return array{checked:int, cached:int, failed:int, skipped:int, error:?string}
     */
    public function checkMany(iterable $phones, bool $force = false): array
    {
        $unique = collect($phones)
            ->filter(fn ($p) => filled($p))
            ->map(fn ($p) => bd_phone((string) $p))
            ->unique()
            ->values();

        $out = ['checked' => 0, 'cached' => 0, 'failed' => 0, 'skipped' => 0, 'error' => null];

        if (! $this->isConfigured()) {
            $out['error'] = 'BDCourier is not configured. Add the API key under Admin → Integrations.';

            return $out;
        }

        $todo = $force ? $unique : $unique->reject(fn ($p) => $this->cached($p) !== null)->values();
        $out['cached'] = $unique->count() - $todo->count();

        if ($todo->count() > self::BULK_LIMIT) {
            $out['skipped'] = $todo->count() - self::BULK_LIMIT;
            $todo = $todo->take(self::BULK_LIMIT);
        }

        foreach ($todo as $phone) {
            $result = $this->check($phone);

            if ($result['ok'] ?? false) {
                $out['checked']++;

                continue;
            }

            $out['failed']++;
            // A rejected key or an exhausted quota will fail for every remaining
            // number too — stop rather than burning through the whole selection.
            $out['error'] ??= $result['error'] ?? null;
            if (str_contains((string) $out['error'], 'API key') || str_contains((string) $out['error'], 'quota')) {
                break;
            }
        }

        return $out;
    }

    /**
     * Reshape the vendor payload into what the order page renders.
     *
     * `data` mixes the per-courier objects and a `summary` object together at
     * the same level, so the summary is pulled out and everything else is
     * treated as a courier.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalise(array $payload): array
    {
        if (($payload['status'] ?? null) !== 'success') {
            return ['ok' => false, 'error' => (string) ($payload['message'] ?? 'BDCourier returned an unexpected response.')];
        }

        $data = (array) ($payload['data'] ?? []);
        $summaryRaw = (array) ($data['summary'] ?? []);
        unset($data['summary']);

        $num = fn ($v) => (float) ($v ?? 0);

        $summary = [
            'total_parcel' => (int) $num($summaryRaw['total_parcel'] ?? 0),
            'success_parcel' => (int) $num($summaryRaw['success_parcel'] ?? 0),
            'cancelled_parcel' => (int) $num($summaryRaw['cancelled_parcel'] ?? 0),
            'success_ratio' => round($num($summaryRaw['success_ratio'] ?? 0), 2),
        ];

        // Couriers the customer has never used add noise, so drop the empties.
        $couriers = collect($data)
            ->filter(fn ($c) => is_array($c) && (int) $num($c['total_parcel'] ?? 0) > 0)
            ->map(fn ($c, $key) => [
                'key' => $key,
                'name' => (string) ($c['name'] ?? ucfirst($key)),
                'logo' => (string) ($c['logo'] ?? ''),
                'total_parcel' => (int) $num($c['total_parcel'] ?? 0),
                'success_parcel' => (int) $num($c['success_parcel'] ?? 0),
                'cancelled_parcel' => (int) $num($c['cancelled_parcel'] ?? 0),
                'success_ratio' => round($num($c['success_ratio'] ?? 0), 2),
            ])
            ->sortByDesc('total_parcel')
            ->values()
            ->all();

        return [
            'ok' => true,
            'summary' => $summary,
            'couriers' => $couriers,
            'reports' => array_values((array) ($payload['reports'] ?? [])),
            'risk' => $this->risk($summary),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Band the success rate. A customer with no courier history at all is
     * "unknown" rather than risky — a first-time buyer isn't a bad one, and
     * calling them risky would train the shop to ignore the badge.
     *
     * @param  array{total_parcel:int, success_ratio:float}  $summary
     * @return array{level:string, label:string, tone:string, note:string}
     */
    public function risk(array $summary): array
    {
        if (($summary['total_parcel'] ?? 0) <= 0) {
            return [
                'level' => 'unknown',
                'label' => 'No history',
                'tone' => 'ink',
                'note' => 'This number has no courier history — treat as a new customer.',
            ];
        }

        $ratio = (float) ($summary['success_ratio'] ?? 0);

        if ($ratio >= $this->safeThreshold()) {
            return [
                'level' => 'safe',
                'label' => 'Safe',
                'tone' => 'green',
                'note' => 'Strong delivery record — safe to ship COD.',
            ];
        }

        if ($ratio >= $this->warningThreshold()) {
            return [
                'level' => 'warning',
                'label' => 'Warning',
                'tone' => 'amber',
                'note' => 'Mixed record — consider confirming by phone before dispatch.',
            ];
        }

        return [
            'level' => 'risky',
            'label' => 'Risky',
            'tone' => 'red',
            'note' => 'Refuses most parcels — consider advance payment before shipping.',
        ];
    }
}
