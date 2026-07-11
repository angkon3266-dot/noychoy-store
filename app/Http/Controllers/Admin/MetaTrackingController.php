<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Meta\MetaDiagnostics;
use App\Services\Meta\MetaSettings;
use App\Services\Meta\MetaStats;
use App\Services\Meta\MetaTrackingService;
use Illuminate\Http\Request;

/**
 * Pixel + Conversions API "Tracking" dashboard for the Meta module. Reuses the
 * existing MetaSettings (DB), MetaTrackingService (send/validate/test) and
 * MetaStats/MetaDiagnostics — it adds no new settings store and no .env config.
 * Lives inside the same meta.gate-protected admin area as the rest of the module.
 */
class MetaTrackingController extends Controller
{
    private const RECENT_KEY = 'meta_tracking_events';

    /** Standard events the Test panel can fire. */
    private const TEST_EVENTS = ['PageView', 'ViewContent', 'Search', 'AddToCart', 'InitiateCheckout', 'Purchase'];

    public function __construct(
        private readonly MetaSettings $settings,
        private readonly MetaTrackingService $tracking,
        private readonly MetaStats $stats,
    ) {}

    public function index()
    {
        return view('admin.meta.tracking', [
            'settings' => $this->settings,
            'snapshot' => $this->settings->safeSnapshot(),
            'pixelEnabled' => $this->tracking->pixelEnabled(),
            'capiEnabled' => $this->settings->capiEnabled(),
            'advancedMatching' => $this->tracking->advancedMatching(),
            'events' => $this->tracking->enabledEventsMap(),
            'testEventCode' => $this->tracking->testEventCode(),
            'lastEventSent' => $this->settings->get('last_event_sent_at'),
            'recent' => $this->recentEvents(),
            'health' => $this->stats->health(),
            'feedUrl' => route('feed.meta'),
            'commerceManagerUrl' => $this->settings->catalogId()
                ? "https://business.facebook.com/commerce/catalogs/{$this->settings->catalogId()}/products"
                : 'https://business.facebook.com/commerce/',
        ]);
    }

    /** Persist the Tracking settings (Pixel + per-event toggles + CAPI). */
    public function save(Request $request)
    {
        $data = $request->validate([
            'pixel_id' => ['nullable', 'string', 'max:64', 'regex:/^\d*$/'],
            'test_event_code' => ['nullable', 'string', 'max:64'],
            'capi_token' => ['nullable', 'string', 'max:1000'],
        ], ['pixel_id.regex' => 'The Pixel ID must be numeric.']);

        $flags = [
            'pixel_enabled', 'advanced_matching', 'capi_enabled',
            'track_pageview', 'track_viewcontent', 'track_search',
            'track_addtocart', 'track_initiatecheckout', 'track_purchase',
        ];

        $changes = [
            'pixel_id' => $data['pixel_id'] ?? null,
            'test_event_code' => $data['test_event_code'] ?: null,
        ];
        foreach ($flags as $f) {
            $changes[$f] = $request->boolean($f);
        }
        $this->settings->update($changes);

        // Only overwrite the CAPI token when a new one is supplied.
        if (filled($data['capi_token'] ?? null)) {
            $this->settings->setCapiToken($data['capi_token']);
        }

        return back()->with('success', 'Tracking settings saved.');
    }

    /** Fire one real CAPI test event (browser Pixel fires the same id client-side). */
    public function test(Request $request, string $event)
    {
        abort_unless(in_array($event, self::TEST_EVENTS, true), 404);

        $eventId = $request->input('event_id') ?: MetaTrackingService::newEventId($event);
        $result = $this->tracking->sendTest($event, $eventId);

        // Deduplicated when the browser Pixel also fired this exact event id.
        $result['browser_sent'] = $request->boolean('browser_sent');
        $result['deduplicated'] = $result['browser_sent'] && $result['ok'];

        $this->pushRecent($result);

        return response()->json($result);
    }

    /** Live end-to-end diagnostics + overall health score (JSON). */
    public function diagnostics(MetaDiagnostics $diagnostics)
    {
        return response()->json($diagnostics->run());
    }

    /** Validate the CAPI access token via Graph debug_token (JSON). */
    public function validateToken()
    {
        return response()->json($this->tracking->validateToken());
    }

    // ── Recent-events buffer (lightweight, capped — no migration) ────────────

    /** @return array<int,array> */
    private function recentEvents(): array
    {
        $buf = Setting::get(self::RECENT_KEY, []);

        return is_array($buf) ? $buf : [];
    }

    private function pushRecent(array $r): void
    {
        $buf = $this->recentEvents();
        array_unshift($buf, [
            'event' => $r['event'] ?? '',
            'event_id' => $r['event_id'] ?? '',
            'sku' => 'prod-test',
            'ok' => $r['ok'] ?? false,
            'status' => $r['status'] ?? 0,
            'ms' => $r['ms'] ?? 0,
            'error' => $r['error'] ?? null,
            'browser_sent' => $r['browser_sent'] ?? false,
            'deduplicated' => $r['deduplicated'] ?? false,
            'at' => now()->toIso8601String(),
        ]);

        Setting::put(self::RECENT_KEY, array_slice($buf, 0, 20));
    }
}
