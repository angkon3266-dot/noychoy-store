<?php

namespace App\Services\Google;

/**
 * Resolves everything the Google tag needs, in one place.
 *
 * Three different things get called "the Google tag" and only one of them makes
 * Google Ads work:
 *
 *   G-XXXXXXXXXX   GA4        traffic reporting
 *   AW-123456789   Google Ads conversions, bidding, remarketing
 *   AW-…/AbC-D_ef  the conversion action a purchase is actually recorded against
 *
 * An ID in the wrong format is the failure mode that costs money, because
 * nothing errors — gtag accepts it, the page looks fine, and the conversion
 * column stays at zero. So every ID is shape-checked before it is emitted, and
 * a malformed one is dropped rather than shipped.
 *
 * Values come from Admin → System Config → Google, which ConfigApplier pushes
 * onto config() at boot; the env entries in config/google.php are only a fresh
 * install's fallback.
 */
class GoogleTagService
{
    /** GA4 measurement IDs: "G-" then at least four alphanumerics. */
    public const ANALYTICS_PATTERN = '/^G-[A-Z0-9]{4,}$/i';

    /** Google Ads conversion IDs: "AW-" then nine or more digits. */
    public const ADS_PATTERN = '/^AW-[0-9]{9,}$/';

    /** GA4 Measurement ID, or null when unset or malformed. */
    public function analyticsId(): ?string
    {
        return $this->validated('google.analytics_id', self::ANALYTICS_PATTERN);
    }

    /** Google Ads conversion ID, or null when unset or malformed. */
    public function adsId(): ?string
    {
        return $this->validated('google.ads_id', self::ADS_PATTERN);
    }

    /** The purchase conversion action's label. Free-form; Google generates it. */
    public function purchaseLabel(): ?string
    {
        return $this->value('google.ads_purchase_label');
    }

    /** The Search Console / Merchant Center verification token. */
    public function siteVerification(): ?string
    {
        return $this->value('google.site_verification');
    }

    public function enhancedConversions(): bool
    {
        return filter_var(config('google.enhanced_conversions', true), FILTER_VALIDATE_BOOL);
    }

    public function businessVertical(): string
    {
        return (string) config('google.business_vertical', 'retail');
    }

    /**
     * Every ID the page should gtag('config', …). Both can be live at once —
     * one tag load serves GA4 and Ads together.
     *
     * @return array<int, string>
     */
    public function tagIds(): array
    {
        return array_values(array_filter([$this->analyticsId(), $this->adsId()]));
    }

    /** Whether there is anything worth putting on the page at all. */
    public function enabled(): bool
    {
        return $this->tagIds() !== [];
    }

    /**
     * The send_to for a purchase: "AW-123456789/AbC-D_efG".
     *
     * Null when either half is missing. Sending a purchase to a bare AW- ID
     * records nothing and reports no error, so half a configuration is treated
     * as none.
     */
    public function purchaseSendTo(): ?string
    {
        $id = $this->adsId();
        $label = $this->purchaseLabel();

        return $id && $label ? $id.'/'.$label : null;
    }

    /**
     * Hashed identifiers for enhanced conversions.
     *
     * Google accepts plain values and hashes them in the browser, but that puts
     * a customer's email in the page source. Hashing here costs nothing and
     * means the page only ever carries digests.
     *
     * Keys and normalisation follow Google's spec: lower-cased trimmed email,
     * phone in E.164. A Bangladeshi number is stored locally as 01XXXXXXXXX, so
     * it becomes +8801XXXXXXXXX on the way out.
     *
     * @return array<string, string>
     */
    public function userData(?object $customer): array
    {
        if (! $customer || ! $this->enhancedConversions()) {
            return [];
        }

        $out = [];

        if ($email = trim(mb_strtolower((string) ($customer->email ?? '')))) {
            $out['sha256_email_address'] = hash('sha256', $email);
        }

        if ($local = bd_phone($customer->phone ?? null)) {
            // bd_phone gives 01XXXXXXXXX; E.164 wants +880 and no leading zero.
            $e164 = '+880'.ltrim($local, '0');
            $out['sha256_phone_number'] = hash('sha256', $e164);
        }

        return $out;
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * One config read, blank treated as absent.
     *
     * Reading config() rather than the settings table is deliberate and load
     * bearing: what Admin → System Config saves lands in system_configs, and
     * ConfigApplier pushes it onto config() at boot. Setting::get() reads a
     * different table entirely and would never see any of these values.
     */
    private function value(string $configKey): ?string
    {
        $value = trim((string) config($configKey));

        return $value === '' ? null : $value;
    }

    /** A resolved value, but only if it matches the shape Google expects. */
    private function validated(string $configKey, string $pattern): ?string
    {
        $value = $this->value($configKey);

        return $value !== null && preg_match($pattern, $value) ? $value : null;
    }
}
