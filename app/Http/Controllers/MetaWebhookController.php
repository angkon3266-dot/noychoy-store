<?php

namespace App\Http\Controllers;

use App\Services\Meta\MetaSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Meta webhook callbacks (e.g. catalog / commerce notifications).
 *
 *  GET  — verification handshake (Meta sends hub.challenge on subscription).
 *  POST — event delivery; we verify the X-Hub-Signature-256 HMAC against the
 *         App Secret and log the payload. Extend handle() to react to specific
 *         fields (e.g. product status changes pushed back from Meta).
 *
 * The route lives under /webhooks/* which is excluded from CSRF.
 */
class MetaWebhookController extends Controller
{
    public function __construct(private readonly MetaSettings $settings) {}

    /** Subscription verification handshake. */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token && hash_equals((string) config('meta.webhook_verify_token'), (string) $token)) {
            $this->settings->update(['webhook_verified_at' => now()->toIso8601String()]);

            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /** Event delivery. */
    public function handle(Request $request)
    {
        // Verify payload authenticity when we hold the app secret.
        if ($secret = config('meta.oauth.app_secret')) {
            $signature = (string) $request->header('X-Hub-Signature-256');
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

            if (! $signature || ! hash_equals($expected, $signature)) {
                return response('Invalid signature', 403);
            }
        }

        // Log a summary only — Meta payloads can include user PII (names, message
        // content, ids). Full payload isn't persisted to logs.
        Log::info('Meta webhook received', [
            'object' => $request->input('object'),
            'entries' => is_array($request->input('entry')) ? count($request->input('entry')) : 0,
        ]);

        $this->settings->update(['last_webhook_event' => [
            'at' => now()->toIso8601String(),
            'summary' => (string) ($request->input('object', 'event')).' · '.substr(json_encode($request->input('entry', [])), 0, 200),
        ]]);

        // Meta requires a fast 200 acknowledgement; heavy handling should queue.
        return response()->json(['received' => true]);
    }
}
