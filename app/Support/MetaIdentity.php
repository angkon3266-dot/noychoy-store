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

    /** Where CaptureMetaClickId leaves a browser id it has just minted. */
    public const FBP_ATTRIBUTE = 'meta.fbp';

    /** Meta's own _fbp lifetime. */
    public const FBP_DAYS = 90;

    // ── First-party identifiers ──────────────────────────────────────────────

    /**
     * The Meta browser id: the _fbp cookie, or the one CaptureMetaClickId
     * minted for this request when the browser had none.
     *
     * This used to be read-only, for fear of a second, competing id. But the
     * Pixel adopts an _fbp it finds rather than writing its own, and Meta's
     * Parameter Builder mints it server-side for exactly this reason. Leaving
     * it to the Pixel meant a landing page never had one: the Pixel loads
     * after the page, and not at all behind an ad blocker. Events Manager
     * showed fbp on only 57% of ViewContents (2026-10-31).
     */
    public static function fbp(?Request $request = null): ?string
    {
        $request ??= request();

        if ($minted = $request->attributes->get(self::FBP_ATTRIBUTE)) {
            return $minted;
        }

        $value = $request->cookie(self::FBP_COOKIE);

        return self::looksLikeMetaId($value) ? $value : null;
    }

    /**
     * A new browser id in the Pixel's own shape: fb.1.{ms}.{random}, the same
     * subdomain index as buildFbc() and a random part in the Pixel's range.
     */
    public static function buildFbp(?int $milliseconds = null): string
    {
        return implode('.', [
            'fb',
            self::SUBDOMAIN_INDEX,
            $milliseconds ?? (int) round(microtime(true) * 1000),
            random_int(1000000000, 2147483647),
        ]);
    }

    /**
     * The domain the Pixel scopes _fbp to: the site's own domain with a
     * leading dot, so www. and the bare domain share one cookie. Null for a
     * bare IP or "localhost", where the browser would refuse a domain cookie.
     */
    public static function cookieDomain(Request $request): ?string
    {
        $host = strtolower($request->getHost());

        if (filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.')) {
            return null;
        }

        return '.'.preg_replace('/^www\./', '', $host);
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

    // ── Client IP address ────────────────────────────────────────────────────

    /**
     * Where the browser keeps the IPv6 address it looked up for itself
     * (partials/meta-pixel), or "-" once it has found it has none. Written by
     * JavaScript, so it is left out of cookie encryption.
     */
    public const IP6_COOKIE = '_nc_ip6';

    /** How long a looked-up address is trusted before the browser asks again. */
    public const IP6_MINUTES = 60;

    /**
     * The address to report as client_ip_address.
     *
     * Meta's 2026-10-11 diagnostic: "client IP addresses that do not match
     * those sent from your Meta Pixel", on 55% of ViewContents. The Pixel talks
     * to facebook.com straight from the phone, and Bangladeshi phones that have
     * IPv6 tend to use it there, while the same phone often reaches this site
     * over the carrier's shared IPv4. Meta's own advice (the Parameter Builder)
     * is to look the IPv6 address up in the browser and prefer it, so the
     * browser does that and leaves it in IP6_COOKIE.
     *
     * The request's own address wins when it is already IPv6: it is the
     * freshest reading, from this very moment. The cookie only stands in for
     * a request that arrived over IPv4.
     */
    public static function clientIp(?Request $request = null): ?string
    {
        $request ??= request();
        $ip = $request->ip();

        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $ip;
        }

        return self::browserIpv6($request) ?? $ip;
    }

    /** The public IPv6 address the browser reported for itself, if any. */
    public static function browserIpv6(?Request $request = null): ?string
    {
        return self::publicIpv6(($request ?? request())->cookie(self::IP6_COOKIE));
    }

    /**
     * Whether this browser has already run the lookup, whatever it found. A
     * browser that has not is one whose address may still be on its way
     * ({@see ip6CacheKey()}).
     */
    public static function ipv6LookupDone(?Request $request = null): bool
    {
        return filled(($request ?? request())->cookie(self::IP6_COOKIE));
    }

    /**
     * A routable IPv6 address, or null. Anything the browser hands over is
     * checked: a private, loopback or documentation address is no use to Meta,
     * and a value that is not an address at all must never reach the payload.
     */
    public static function publicIpv6(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 45) {
            return null;
        }

        $value = strtolower(trim($value));
        $ok = filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        return $ok === false ? null : $value;
    }

    /**
     * Where the address reported by the browser waits for a deferred event
     * (App\Jobs\Meta\SendDeferredBrowserEvent): keyed by the visitor, not the
     * session, so a login in between does not lose it. Null with no visitor.
     */
    public static function ip6CacheKey(?Request $request = null): ?string
    {
        $token = ($request ?? request())->cookie('visitor_token');

        return $token ? 'meta.ip6.'.self::pseudonym('visitor:'.$token) : null;
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
