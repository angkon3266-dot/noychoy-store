<?php

namespace App\Services\Meta;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What Meta itself says it received from this store over the last 24 hours,
 * per event and per side: the browser Pixel against the Conversions API.
 *
 * Nothing on this site can watch the Pixel arrive. It runs in the shopper's
 * browser and reports straight to Meta, so the admin only ever saw its own
 * half: on 2026-09-27 the owner read "the Pixel is not firing" off the Test
 * panel's log, whose Pixel column cannot tick since that panel stopped firing
 * the Pixel, and off Meta's browser helper, which does not list the hidden-form
 * posts Chrome uses for this site's events — while Meta was receiving those
 * events by the hundred. These are the figures Events Manager is drawn from.
 *
 * Read from the dataset's /stats edge. Only aggregation=event honours
 * event_source (WEB_ONLY / SERVER_ONLY); every other breakdown silently
 * ignores it. Meta publishes each hour roughly an hour late, and counts each
 * side's copy on its own: an order reported by both is 1 + 1 here, and one
 * event once Meta has merged the pair on its event_id.
 */
class MetaReceivedEvents
{
    /** In funnel order. PageView and Search come from the browser alone. */
    public const EVENTS = ['PageView', 'ViewContent', 'Search', 'AddToCart', 'InitiateCheckout', 'Purchase'];

    private const CACHE_KEY = 'meta.received-events';

    /** Meta's figures move hourly; this keeps a busy admin page off the Graph API. */
    private const TTL = 600;

    public function __construct(
        private readonly MetaSettings $settings,
        private readonly MetaGraphClient $client,
    ) {}

    /**
     * @return array{ok: bool, error: ?string, events: list<array{event: string, browser: int, server: int}>,
     *               browser_total: int, server_total: int, last_browser_hour: ?string, fetched_at: string}
     */
    public function lastDay(): array
    {
        $pixelId = $this->settings->pixelId();
        if (! $pixelId) {
            return $this->unavailable('No Pixel ID is set, so there is nothing to ask Meta about.');
        }

        // The system-user token reads the dataset; a dedicated Conversions API
        // token often can too, and is all some stores have.
        $token = $this->settings->token() ?: $this->settings->capiToken();
        if (! $token) {
            return $this->unavailable('No access token is saved, so Meta cannot be asked.');
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && ($cached['pixel_id'] ?? null) === $pixelId) {
            return $cached['result'];
        }

        $since = now()->subDay()->getTimestamp();
        try {
            $browser = $this->hourly($pixelId, 'WEB_ONLY', $since, $token);
            $server = $this->hourly($pixelId, 'SERVER_ONLY', $since, $token);
        } catch (\Throwable $e) {
            // Not cached: the next page load should ask again.
            return $this->unavailable('Meta did not answer: '.$this->redact($e->getMessage(), $token));
        }

        $events = [];
        foreach (self::EVENTS as $event) {
            $events[] = [
                'event' => $event,
                'browser' => $this->sum($browser, $event),
                'server' => $this->sum($server, $event),
            ];
        }

        $browserHours = array_keys(array_filter($browser, fn (array $counts) => array_sum($counts) > 0));

        $result = [
            'ok' => true,
            'error' => null,
            'events' => $events,
            'browser_total' => array_sum(array_column($events, 'browser')),
            'server_total' => array_sum(array_column($events, 'server')),
            'last_browser_hour' => $browserHours ? Carbon::createFromTimestamp(max($browserHours))->toIso8601String() : null,
            'fetched_at' => now()->toIso8601String(),
        ];

        Cache::put(self::CACHE_KEY, ['pixel_id' => $pixelId, 'result' => $result], self::TTL);

        return $result;
    }

    /**
     * Counts per hour for one side, keyed by the hour's Unix timestamp. Meta
     * writes the hour as "2026-09-28T20:00:00+0600", which not every browser's
     * Date() will parse, so it never leaves this class as a string.
     *
     * @return array<int, array<string, int>>
     */
    private function hourly(string $pixelId, string $source, int $since, string $token): array
    {
        $body = $this->client->request('GET', "{$pixelId}/stats", [
            'aggregation' => 'event',
            'event_source' => $source,
            'start_time' => $since,
        ], $token);

        $hours = [];
        foreach ($body['data'] ?? [] as $bucket) {
            $hour = Carbon::parse((string) ($bucket['start_time'] ?? ''))->getTimestamp();
            foreach ($bucket['data'] ?? [] as $row) {
                $name = (string) ($row['value'] ?? '');
                $hours[$hour][$name] = ($hours[$hour][$name] ?? 0) + (int) ($row['count'] ?? 0);
            }
        }

        return $hours;
    }

    /** @param array<int, array<string, int>> $hours */
    private function sum(array $hours, string $event): int
    {
        return array_sum(array_map(fn (array $counts) => $counts[$event] ?? 0, $hours));
    }

    /**
     * MetaGraphClient sends GET parameters in the query string, token included,
     * and a timeout's message quotes the whole URL. This page shows the message.
     */
    private function redact(string $message, string $token): string
    {
        $message = str_replace(array_unique([$token, urlencode($token), rawurlencode($token)]), '[redacted]', $message);

        return (string) preg_replace('/(access_token|appsecret_proof)=[^&\s"]+/', '$1=[redacted]', $message);
    }

    /** @return array{ok: false, error: string, events: array{}, browser_total: 0, server_total: 0, last_browser_hour: null, fetched_at: string} */
    private function unavailable(string $why): array
    {
        return [
            'ok' => false,
            'error' => $why,
            'events' => [],
            'browser_total' => 0,
            'server_total' => 0,
            'last_browser_hour' => null,
            'fetched_at' => now()->toIso8601String(),
        ];
    }
}
