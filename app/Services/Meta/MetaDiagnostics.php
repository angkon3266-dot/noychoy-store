<?php

namespace App\Services\Meta;

use Illuminate\Support\Facades\Http;

/**
 * Live end-to-end health check for the whole Meta integration. Reuses the
 * existing Graph client, stats and tracking service — it performs real calls
 * (debug_token, catalog fetch, feed HTTP GET) and returns a green/red verdict
 * per subsystem plus an overall 0–100 health score for the dashboard.
 *
 * Every check is wrapped so one failure never aborts the rest; unknown/optional
 * checks return ok = null (grey) and are excluded from the score.
 */
class MetaDiagnostics
{
    public function __construct(
        private readonly MetaSettings $settings,
        private readonly MetaGraphClient $client,
        private readonly MetaStats $stats,
        private readonly MetaTrackingService $tracking,
    ) {}

    /**
     * @return array{checks:array<int,array>,score:int,api_version:string,ran_at:string}
     */
    public function run(): array
    {
        $s = $this->settings;
        $apiVersion = (string) config('meta.graph_version', 'v21.0');
        $checks = [];

        // Database configuration (all three catalog credentials present).
        $checks[] = $this->check('database', 'Database Configuration', $s->isConfigured(),
            $s->isConfigured() ? 'Business ID, Catalog ID & token present.' : 'Missing Business ID, Catalog ID or access token.',
            'Enter all three under Meta → Settings.');

        // Graph API reachability + token validity (single debug_token call).
        $graphOk = null; $graphDetail = 'No token to test with.';
        $tokenOk = null; $tokenDetail = 'No token configured.';
        if ($s->hasToken()) {
            try {
                $dbg = $this->client->debugToken();
                $graphOk = true;
                $graphDetail = 'Reachable ('.$apiVersion.').';

                $valid = ($dbg['is_valid'] ?? false) === true;
                $exp = (int) ($dbg['expires_at'] ?? 0);
                $expired = $exp !== 0 && $exp < time();
                $tokenOk = $valid && ! $expired;
                $tokenDetail = ! $valid ? 'Token reported invalid.'
                    : ($expired ? 'Token expired.' : ($exp === 0 ? 'Never expires (long-lived).' : 'Expires '.date('Y-m-d', $exp).'.'));
            } catch (\Throwable $e) {
                $graphOk = false; $graphDetail = $e->getMessage();
                $tokenOk = false; $tokenDetail = $e->getMessage();
            }
        }
        $checks[] = $this->check('graph', 'Graph API', $graphOk, $graphDetail, 'Check server connectivity and the access token.');
        $checks[] = $this->check('token', 'Token Validity', $tokenOk, $tokenDetail, 'Reconnect or generate a new System User token.');

        // OAuth app credentials (Connect-with-Facebook availability).
        $oauth = filled(config('meta.oauth.app_id')) && filled(config('meta.oauth.app_secret'));
        $checks[] = $this->check('oauth', 'OAuth', $oauth,
            $oauth ? 'Meta App credentials configured.' : 'App ID/Secret not set — manual-token mode only.',
            'Set the Meta App ID & Secret (System Config) to enable Connect with Facebook.');

        // Catalog access.
        $catOk = null; $catDetail = 'No token/catalog to test.';
        if ($s->isConfigured()) {
            try {
                $c = $this->client->catalog($s->catalogId());
                $catOk = true;
                $catDetail = ($c['name'] ?? 'Catalog').' · '.($c['product_count'] ?? 0).' products';
            } catch (\Throwable $e) {
                $catOk = false; $catDetail = $e->getMessage();
            }
        }
        $checks[] = $this->check('catalog', 'Catalog', $catOk, $catDetail, 'Verify the Catalog ID and that the token can access it.');

        // Pixel.
        $pixel = $this->tracking->pixelEnabled();
        $checks[] = $this->check('pixel', 'Pixel', $pixel,
            $pixel ? 'Enabled ('.$s->pixelId().').' : 'Disabled or no Pixel ID.',
            'Enter a Pixel ID and enable the Pixel under Tracking.');

        // Conversions API.
        $capi = $s->capiEnabled();
        $checks[] = $this->check('capi', 'Conversions API', $capi,
            $capi ? 'Enabled (token + pixel present).' : 'Disabled or token/pixel missing.',
            'Enable CAPI and set a token under Tracking.');

        // Feed URL reachability.
        $feedOk = null; $feedDetail = null;
        try {
            $r = Http::timeout(8)->get(route('feed.meta'));
            $feedOk = $r->successful();
            $feedDetail = 'HTTP '.$r->status();
        } catch (\Throwable $e) {
            $feedOk = false; $feedDetail = $e->getMessage();
        }
        $checks[] = $this->check('feed', 'Feed URL', $feedOk, $feedDetail, 'Ensure the storefront is reachable and the feed route works.');

        // Queue availability.
        $q = $this->stats->queue();
        $worker = $this->stats->health()['queue_worker'];
        $queueOk = ! ($q['waiting'] > 0 && $worker === false);
        $checks[] = $this->check('queue', 'Queue', $queueOk,
            $q['waiting'].' waiting · '.$q['failed'].' failed · driver '.$q['driver'],
            'Ensure the scheduled queue worker is running (routes/console.php).');

        // Webhook (optional → grey when not set).
        $wh = (bool) $s->get('webhook_verified_at');
        $checks[] = $this->check('webhook', 'Webhook', $wh ?: null,
            $wh ? 'Verified.' : 'Not verified (optional).',
            'Verify the webhook in the Meta App if you use realtime catalog updates.');

        // Overall score over the checks that produced a definite verdict.
        $scored = array_filter($checks, fn ($c) => $c['ok'] !== null);
        $passed = count(array_filter($scored, fn ($c) => $c['ok'] === true));
        $score = count($scored) ? (int) round($passed / count($scored) * 100) : 0;

        return ['checks' => $checks, 'score' => $score, 'api_version' => $apiVersion, 'ran_at' => now()->toIso8601String()];
    }

    private function check(string $key, string $label, ?bool $ok, ?string $detail, string $fix): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail, 'fix' => $ok === true ? null : $fix];
    }
}
