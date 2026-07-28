<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Meta's first-party identifiers and its customer-information data formats.
 *
 * Kept apart from MetaTrackingService on purpose: this is Meta's *specification*
 * — cookie shapes, normalisation rules, what may and may not be hashed — while
 * the service is about sending events. The rules are fiddly and easy to get
 * subtly wrong, and a wrong normalisation fails silently: Meta simply reports a
 * lower match quality, never an error.
 *
 * fbp/fbc format (fb.{subdomainIndex}.{creationTimeMs}.{payload}) and the
 * "only replace _fbc when the fbclid actually differs" rule are from Meta's
 * Conversions API parameter documentation.
 *
 * Neither fbp nor fbc is ever hashed.
 */
class MetaIdentity
{
    public const FBP_COOKIE = '_fbp';

    public const FBC_COOKIE = '_fbc';

    /** Meta's own _fbc lifetime. */
    public const FBC_DAYS = 90;

    /**
     * Meta's documented value for a server-generated fbc. The real index counts
     * the dots in the cookie's domain, but Meta says to use 1 when building the
     * value server-side rather than reading a cookie the Pixel wrote.
     */
    protected const SUBDOMAIN_INDEX = 1;

    /** Where CaptureMetaClickId leaves the resolved value for this request. */
    public const FBC_ATTRIBUTE = 'meta.fbc';

    // ── First-party identifiers ──────────────────────────────────────────────

    /**
     * The Meta browser id.
     *
     * Read-only by design. The Pixel owns this cookie, and minting our own
     * would put a second, competing identifier on the same browser — the server
     * would report one fbp while the browser's events carried another. When the
     * Pixel is blocked there simply is no fbp, and that is the honest answer.
     */
    public static function fbp(?Request $request = null): ?string
    {
        $value = ($request ?? request())->cookie(self::FBP_COOKIE);

        return self::looksLikeMetaId($value) ? $value : null;
    }

    /**
     * The Meta click id for this request — the value CaptureMetaClickId worked
     * out, falling back to the cookie for requests that skipped the middleware.
     */
    public static function fbc(?Request $request = null): ?string
    {
        $request ??= request();

        if ($resolved = $request->attributes->get(self::FBC_ATTRIBUTE)) {
            return $resolved;
        }

        $cookie = $request->cookie(self::FBC_COOKIE);

        return self::looksLikeMetaId($cookie) ? $cookie : null;
    }

    /**
     * Work out the fbc for a request, and say whether it needs storing.
     *
     * Meta's rule is to keep the existing cookie unless the URL carries a
     * *different* fbclid — replacing it on every page view would reset the
     * creation timestamp and lose when the click actually happened.
     *
     * @return array{0:?string,1:bool} [value, should be persisted]
     */
    public static function resolveFbc(Request $request): array
    {
        $cookie = $request->cookie(self::FBC_COOKIE);
        $cookie = self::looksLikeMetaId($cookie) ? $cookie : null;

        $fbclid = trim((string) $request->query('fbclid'));

        if ($fbclid === '') {
            return [$cookie, false];      // no click to record; never fabricate one
        }

        if ($cookie !== null && self::fbclidOf($cookie) === $fbclid) {
            return [$cookie, false];      // same click, keep the original timestamp
        }

        return [self::buildFbc($fbclid), true];
    }

    /** fb.1.{now in ms}.{fbclid} */
    public static function buildFbc(string $fbclid, ?int $milliseconds = null): string
    {
        return implode('.', [
            'fb',
            self::SUBDOMAIN_INDEX,
            $milliseconds ?? (int) round(microtime(true) * 1000),
            $fbclid,
        ]);
    }

    /** The fbclid segment of an existing fbc value. */
    protected static function fbclidOf(string $fbc): ?string
    {
        // The fbclid itself may contain dots, so split on the first three only.
        $parts = explode('.', $fbc, 4);

        return $parts[3] ?? null;
    }

    /** Reject anything that isn't shaped like fb.{n}.{ms}.{payload}. */
    protected static function looksLikeMetaId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^fb\.\d+\.\d+\..+$/', $value) === 1;
    }

    /**
     * Stable pseudonymous identifiers for user_data.external_id.
     *
     * Two sources, both already present in this application:
     *   · the signed-in customer's primary key — stable for life;
     *   · the two-year visitor_token cookie TrackVisit already sets — stable
     *     across the whole ad → product → cart → checkout → purchase journey,
     *     which is exactly the window Meta needs to join those events up.
     *
     * Both are sent when both are known, so events from before someone signed
     * in still join to the ones after. Neither leaves the server in the clear:
     * each is an HMAC keyed on APP_KEY, so the values are stable for this store
     * and meaningless anywhere else — a raw sequential customer id would be
     * guessable and would identify the same person across unrelated advertisers.
     *
     * @return array<int,string>
     */
    public static function externalIds(?Request $request = null): array
    {
        $request ??= request();
        $ids = [];

        try {
            if ($customer = auth('customer')->user()) {
                $ids[] = self::pseudonym('customer:'.$customer->getKey());
            }
        } catch (\Throwable) {
            // No session to read — a queue worker running a job that predates
            // captureClientContext() carrying external_id. The cookie below is
            // still worth trying; an empty result is better than a failed job.
        }

        if ($token = $request->cookie('visitor_token')) {
            $ids[] = self::pseudonym('visitor:'.$token);
        }

        return array_values(array_unique($ids));
    }

    /** A per-install, non-reversible, stable id. */
    public static function pseudonym(string $subject): string
    {
        return hash_hmac('sha256', $subject, (string) config('app.key'));
    }

    // ── Customer information parameters ──────────────────────────────────────

    /**
     * Normalise one user_data field the way Meta expects before hashing.
     * Returns null when nothing usable is left.
     *
     * Meta matches on the hash, so a value normalised differently from the one
     * in their graph simply never matches — no error, just a lower score.
     */
    public static function normalize(string $key, mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $out = match ($key) {
            // Digits only, including country code, no + and no leading zeros.
            'ph' => self::phone($value),

            // Lowercase, trimmed. Meta does not want the display name form.
            'em' => mb_strtolower($value),

            // Letters only: no punctuation, no digits, no spaces.
            'fn', 'ln' => preg_replace('/[^\p{L}]/u', '', mb_strtolower($value)) ?: null,

            // Lowercase with spaces and punctuation removed.
            'ct', 'st' => preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($value)) ?: null,

            // Postcode: lowercase, no spaces or dashes.
            'zp' => preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($value)) ?: null,

            // ISO 3166-1 alpha-2, lowercase.
            'country' => preg_match('/^[A-Za-z]{2}$/', $value) ? mb_strtolower($value) : null,

            default => mb_strtolower($value),
        };

        return filled($out) ? $out : null;
    }

    /**
     * Meta wants digits only with a country code. The app stores Bangladesh
     * numbers locally as 01XXXXXXXXX (see bd_phone()), so reuse the same
     * conversion the WhatsApp links use rather than inventing a second one.
     */
    protected static function phone(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        // Local BD form → international, via the app's own canonical helper.
        if (str_starts_with($digits, '0') && function_exists('wa_phone')) {
            return wa_phone($value) ?: null;
        }

        return ltrim($digits, '0') ?: null;
    }

    /** Fields Meta requires to be SHA-256 hashed. fbp/fbc/IP/UA are not among them. */
    public static function mustHash(string $key): bool
    {
        return in_array($key, ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'db', 'ge'], true);
    }

    /** True when a value is already a SHA-256 hex digest, so it isn't hashed twice. */
    public static function isHashed(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', $value) === 1;
    }
}
