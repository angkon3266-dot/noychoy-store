<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Meta\Services\MetaDebug;
use Illuminate\Http\Request;

/**
 * Meta Integration Debug page (temporary). Independent, logged Graph API testers
 * for OAuth, businesses, catalogs, pages, Instagram, pixels and ad accounts —
 * each shows the HTTP request, raw JSON, parsed result, execution time and any
 * errors. Every call is recorded to storage/logs/meta-debug.log and the buffer.
 *
 * Only reachable when Debug Mode is enabled (local/dev or META_DEBUG=true).
 */
class MetaDebugController extends Controller
{
    public function __construct(private readonly MetaDebug $debug) {}

    /** Debug Mode must be on and the user must have Meta access. */
    private function guard(): void
    {
        abort_unless($this->debug->enabled(), 404);
        abort_unless(auth()->user()?->canAccess('meta'), 403);
    }

    public function index()
    {
        $this->guard();

        return view('admin.meta.debug', [
            'context' => $this->debug->context(),
            'readiness' => $this->debug->readiness(),
            'recent' => $this->debug->recent(null, 40),
            'errors' => $this->debug->recent('error', 20),
        ]);
    }

    /** Run a single independent test and return its structured result as JSON. */
    public function test(Request $request, string $what)
    {
        $this->guard();

        if (! $this->debug->token() && $what !== 'graph') {
            return response()->json([
                'ok' => false,
                'notes' => ['No access token available. Connect a Meta account first (Marketing → Meta Connection or the Meta Integration dashboard).'],
                'calls' => [],
            ]);
        }

        $result = match ($what) {
            'oauth' => $this->testOAuth(),
            'businesses' => $this->testBusinesses(),
            'catalogs' => $this->testCatalogs(),
            'pages' => $this->testPages(),
            'instagram' => $this->testInstagram(),
            'pixels' => $this->testPixels(),
            'ad_accounts' => $this->testAdAccounts(),
            'discovery' => $this->testFullDiscovery(),
            'graph' => $this->testArbitrary($request),
            default => ['ok' => false, 'notes' => ['Unknown test: '.$what], 'calls' => []],
        };

        return response()->json($result + ['what' => $what]);
    }

    public function clear()
    {
        $this->guard();
        $this->debug->clear();

        return back()->with('success', 'Meta debug buffer cleared.');
    }

    // ── Individual tests ──────────────────────────────────────────────────────

    private function testOAuth(): array
    {
        // Inspect the token itself (validity, scopes, expiry) using an app token.
        $appId = config('meta.oauth.app_id');
        $appSecret = config('meta.oauth.app_secret');
        $inspector = ($appId && $appSecret) ? "{$appId}|{$appSecret}" : $this->debug->token();

        $call = $this->call('debug_token', 'GET', 'debug_token', [
            'input_token' => $this->debug->token(),
        ], $inspector);

        $data = $call['raw']['data'] ?? [];
        $granted = $data['scopes'] ?? [];
        $required = ['business_management', 'catalog_management'];

        return [
            'ok' => (bool) ($data['is_valid'] ?? false),
            'calls' => [$call],
            'parsed' => [
                'is_valid' => $data['is_valid'] ?? null,
                'app_id' => $data['app_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'type' => $data['type'] ?? null,
                'expires_at' => isset($data['expires_at']) ? ($data['expires_at'] === 0 ? 'never (long-lived)' : date('c', $data['expires_at'])) : null,
                'data_access_expires_at' => isset($data['data_access_expires_at']) ? date('c', $data['data_access_expires_at']) : null,
                'granted_scopes' => $granted,
                'missing_required_scopes' => array_values(array_diff($required, $granted)),
            ],
            'notes' => [
                'Granted scopes come from Meta debug_token. "Denied" permissions = required minus granted.',
                empty(array_diff($required, $granted))
                    ? 'business_management + catalog_management are BOTH granted.'
                    : 'MISSING required scope(s): '.implode(', ', array_diff($required, $granted)),
            ],
        ];
    }

    private function testBusinesses(): array
    {
        $call = $this->call('GET /me/businesses', 'GET', 'me/businesses', [
            'fields' => 'id,name,verification_status,created_time',
            'limit' => 50,
        ]);

        $businesses = $call['raw']['data'] ?? [];

        return [
            'ok' => $call['error'] === null,
            'calls' => [$call],
            'parsed' => array_map(fn ($b) => [
                'id' => $b['id'] ?? null,
                'name' => $b['name'] ?? null,
                'verification_status' => $b['verification_status'] ?? null,
            ], $businesses),
            'notes' => [
                count($businesses).' business(es) returned by GET /me/businesses.',
                'This is the list the OAuth callback iterates to find catalogs. If your Business Portfolio is missing here, the token was not granted access to it.',
            ],
        ];
    }

    private function testCatalogs(): array
    {
        $calls = [];
        $notes = [];
        $found = [];

        // 1) Discover businesses.
        $bizCall = $this->call('GET /me/businesses', 'GET', 'me/businesses', ['fields' => 'id,name', 'limit' => 50]);
        $calls[] = $bizCall;
        $businesses = $bizCall['raw']['data'] ?? [];
        $notes[] = count($businesses).' business(es) found.';

        // 2) For each business, try BOTH catalog edges (owned vs. accessible/client).
        foreach ($businesses as $b) {
            $bid = $b['id'] ?? null;
            $bname = $b['name'] ?? $bid;
            if (! $bid) {
                continue;
            }

            $owned = $this->call("GET /{$bid}/owned_product_catalogs ({$bname})", 'GET', "{$bid}/owned_product_catalogs", ['fields' => 'id,name,product_count,vertical', 'limit' => 100]);
            $calls[] = $owned;
            foreach (($owned['raw']['data'] ?? []) as $c) {
                $found[$c['id']] = ['name' => $c['name'] ?? null, 'business' => $bname, 'via' => 'owned_product_catalogs', 'product_count' => $c['product_count'] ?? null];
            }

            $accessible = $this->call("GET /{$bid}/product_catalogs ({$bname})", 'GET', "{$bid}/product_catalogs", ['fields' => 'id,name,product_count,vertical', 'limit' => 100]);
            $calls[] = $accessible;
            foreach (($accessible['raw']['data'] ?? []) as $c) {
                // Mark catalogs visible via product_catalogs but NOT owned_product_catalogs.
                $found[$c['id']] ??= ['name' => $c['name'] ?? null, 'business' => $bname, 'via' => 'product_catalogs (accessible/client, NOT owned)', 'product_count' => $c['product_count'] ?? null];
            }
        }

        // 3) Direct lookup of the configured/known catalog id.
        $knownId = $this->debug->catalogId();
        if ($knownId) {
            $direct = $this->call("GET /{$knownId} (configured catalog)", 'GET', $knownId, ['fields' => 'id,name,product_count,vertical']);
            $calls[] = $direct;
            if ($direct['error'] === null && ! empty($direct['raw']['id'])) {
                $notes[] = "Configured catalog {$knownId} is reachable directly (name: ".($direct['raw']['name'] ?? '?').').';
                if (! isset($found[$knownId])) {
                    $notes[] = "IMPORTANT: catalog {$knownId} resolves directly but did NOT appear under any business's owned_product_catalogs — the legacy OAuth discovery (which only reads owned_product_catalogs) would therefore report 'no catalogs found'.";
                }
            }
        }

        return [
            'ok' => ! empty($found),
            'calls' => $calls,
            'parsed' => array_map(fn ($id, $meta) => ['id' => $id] + $meta, array_keys($found), array_values($found)),
            'notes' => array_merge($notes, [
                'Inclusion rule: the legacy Commerce OAuth flow (MetaOAuthController::discoverCatalogs) ONLY queries {business}/owned_product_catalogs.',
                'If a catalog appears under product_catalogs but not owned_product_catalogs, it is shared/assigned to the business (client catalog), not owned by it — and legacy discovery misses it.',
            ]),
        ];
    }

    private function testPages(): array
    {
        $call = $this->call('GET /me/accounts', 'GET', 'me/accounts', [
            'fields' => 'id,name,category,instagram_business_account{id,username}',
            'limit' => 100,
        ]);
        $pages = $call['raw']['data'] ?? [];

        return [
            'ok' => $call['error'] === null,
            'calls' => [$call],
            'parsed' => array_map(fn ($p) => [
                'id' => $p['id'] ?? null,
                'name' => $p['name'] ?? null,
                'instagram' => $p['instagram_business_account']['username'] ?? null,
            ], $pages),
            'notes' => [count($pages).' page(s) returned. Requires pages_show_list / business_management.'],
        ];
    }

    private function testInstagram(): array
    {
        $call = $this->call('GET /me/accounts (IG)', 'GET', 'me/accounts', [
            'fields' => 'id,name,instagram_business_account{id,username,name}',
            'limit' => 100,
        ]);
        $igs = [];
        foreach (($call['raw']['data'] ?? []) as $p) {
            if (! empty($p['instagram_business_account']['id'])) {
                $igs[] = $p['instagram_business_account'] + ['via_page' => $p['name'] ?? null];
            }
        }

        return [
            'ok' => $call['error'] === null,
            'calls' => [$call],
            'parsed' => $igs,
            'notes' => [count($igs).' Instagram business account(s) linked to your pages.'],
        ];
    }

    private function testPixels(): array
    {
        $calls = [];
        $pixels = [];
        $bizCall = $this->call('GET /me/businesses', 'GET', 'me/businesses', ['fields' => 'id,name', 'limit' => 50]);
        $calls[] = $bizCall;
        foreach (($bizCall['raw']['data'] ?? []) as $b) {
            $bid = $b['id'] ?? null;
            if (! $bid) {
                continue;
            }
            $c = $this->call("GET /{$bid}/owned_pixels", 'GET', "{$bid}/owned_pixels", ['fields' => 'id,name,last_fired_time', 'limit' => 100]);
            $calls[] = $c;
            foreach (($c['raw']['data'] ?? []) as $px) {
                $pixels[] = $px + ['business' => $b['name'] ?? $bid];
            }
        }

        return [
            'ok' => ! empty($pixels),
            'calls' => $calls,
            'parsed' => $pixels,
            'notes' => [count($pixels).' pixel(s) owned across your businesses. Requires ads_management / business_management.'],
        ];
    }

    private function testAdAccounts(): array
    {
        $call = $this->call('GET /me/adaccounts', 'GET', 'me/adaccounts', [
            'fields' => 'id,account_id,name,account_status',
            'limit' => 100,
        ]);
        $accounts = $call['raw']['data'] ?? [];

        return [
            'ok' => $call['error'] === null,
            'calls' => [$call],
            'parsed' => array_map(fn ($a) => [
                'id' => $a['id'] ?? null,
                'name' => $a['name'] ?? null,
                'status' => $a['account_status'] ?? null,
            ], $accounts),
            'notes' => [count($accounts).' ad account(s). Read-only (this platform is not an Ads Manager). Requires ads_read.'],
        ];
    }

    private function testFullDiscovery(): array
    {
        // Run the whole chain; the individual notes explain each asset.
        $oauth = $this->testOAuth();
        $businesses = $this->testBusinesses();
        $catalogs = $this->testCatalogs();
        $pages = $this->testPages();
        $instagram = $this->testInstagram();
        $pixels = $this->testPixels();
        $ads = $this->testAdAccounts();

        return [
            'ok' => $catalogs['ok'],
            'calls' => array_merge(
                $oauth['calls'], $businesses['calls'], $catalogs['calls'],
                $pages['calls'], $instagram['calls'], $pixels['calls'], $ads['calls'],
            ),
            'parsed' => [
                'catalogs' => $catalogs['parsed'],
                'pages' => $pages['parsed'],
                'instagram' => $instagram['parsed'],
                'pixels' => $pixels['parsed'],
                'ad_accounts' => $ads['parsed'],
            ],
            'notes' => array_merge(
                ['Commerce discovery summary (why each asset was/was not included):'],
                $catalogs['notes'],
            ),
        ];
    }

    private function testArbitrary(Request $request): array
    {
        $path = trim((string) $request->input('path', 'me'));
        $path = ltrim($path, '/');
        $call = $this->call("GET /{$path}", 'GET', $path, ['fields' => $request->input('fields', 'id,name')]);

        return [
            'ok' => $call['error'] === null,
            'calls' => [$call],
            'parsed' => $call['raw'],
            'notes' => ['Arbitrary Graph GET for ad-hoc debugging.'],
        ];
    }

    /** Run one labelled, logged Graph call and shape it for the UI. */
    private function call(string $label, string $method, string $path, array $params = [], ?string $token = null): array
    {
        $r = $this->debug->graph($method, $path, $params, $token);

        return [
            'label' => $label,
            'request' => [
                'method' => $method,
                'endpoint' => $path,
                'url' => $r['record']['url'],
                'query' => $r['record']['query'],
            ],
            'http_status' => $r['status'],
            'duration_ms' => $r['duration_ms'],
            'raw' => $r['body'],
            'error' => $r['error'],
            'exception' => $r['exception'],
        ];
    }
}
