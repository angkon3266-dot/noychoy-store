<?php

/*
|--------------------------------------------------------------------------
| SEO
|--------------------------------------------------------------------------
|
| Two of these keys (robots, sitemap_enabled) are also editable in Admin →
| System Config → SEO and are applied over the top of this file at boot by
| ConfigApplier. The rest are deployment-level facts about who the shop is
| and where it sells, which the structured-data builder reads.
|
| The country settings are not decoration. This is a Bangladeshi shop selling
| in BDT with cash on delivery, and every ranking signal we can honestly give
| Google about that — language, locale, currency, area served, shipping rates,
| return policy — is one less thing it has to guess.
|
*/

return [

    // 'index' | 'noindex'. Admin-overridable.
    'robots' => env('SEO_ROBOTS', 'index'),

    'sitemap_enabled' => env('SEO_SITEMAP_ENABLED', true),

    // ── Market targeting ────────────────────────────────────────────────────
    // en-BD, not en-GB: same English, but it tells Google which country's
    // results this belongs in. The shop ships nowhere else, so say so.
    'html_lang' => env('SEO_HTML_LANG', 'en-BD'),
    'og_locale' => env('SEO_OG_LOCALE', 'en_BD'),
    'country' => env('SEO_COUNTRY', 'BD'),
    'geo_region' => env('SEO_GEO_REGION', 'BD-C'),   // Dhaka division
    'geo_placename' => env('SEO_GEO_PLACENAME', 'Dhaka, Bangladesh'),

    // Appended to product / category titles that have no admin-written
    // meta title. Bangladeshi shoppers overwhelmingly search "<thing> price
    // in bangladesh" / "<thing> price in bd", so the qualifier belongs in the
    // title rather than being left to Google to infer.
    'title_qualifier' => env('SEO_TITLE_QUALIFIER', 'Price in Bangladesh'),

    // ── Who the shop is (Organization / OnlineStore schema) ─────────────────
    'organization' => [
        // Street address is optional — a shop with no walk-in counter should
        // leave it null rather than invent one. Locality/country still help.
        'street' => env('SEO_ADDR_STREET'),
        'locality' => env('SEO_ADDR_LOCALITY', 'Dhaka'),
        'region' => env('SEO_ADDR_REGION', 'Dhaka'),
        'postal_code' => env('SEO_ADDR_POSTCODE'),
        'founding_date' => env('SEO_FOUNDING_DATE'),
        // Extra profiles beyond the Facebook/Instagram links already set in
        // Appearance → Footer. Comma-separated URLs.
        'same_as' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SEO_SAME_AS', ''))
        ))),
    ],

    // ── Return window, in days, for hasMerchantReturnPolicy ─────────────────
    // Matches the Refund Policy page as shipped ("contact us within 3 days of
    // delivery"). Keep the two in step — a schema figure that contradicts the
    // written policy is worse than no schema at all, and Google cross-checks.
    'return_days' => (int) env('SEO_RETURN_DAYS', 3),

    // Who pays return postage, as a schema.org ReturnFeesEnumeration member:
    // 'FreeReturn' | 'ReturnFeesCustomerResponsibility' | 'ReturnShippingFees'.
    // Left null deliberately: the shipped policy only accepts returns for
    // damaged/wrong items, and guessing here would put a claim on the shop's
    // behalf that its own policy page does not make. Set it once the owner
    // has decided, and the property starts being emitted.
    'return_fees' => env('SEO_RETURN_FEES'),
];
