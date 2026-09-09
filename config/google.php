<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google tag — Analytics 4, Google Ads conversions, dynamic remarketing
    |--------------------------------------------------------------------------
    |
    | Every value here is only the fallback for a fresh install. The live ones
    | live in the settings table so the store owner can change them in
    | Admin → System Config → Google without a deploy — the same arrangement
    | the Meta credentials use. App\Services\Google\GoogleTagService is the one
    | place that resolves settings-then-config; nothing else should read these
    | keys directly.
    |
    */

    // GA4 Measurement ID, "G-XXXXXXXXXX". Analytics only — it does not by
    // itself report conversions to Google Ads.
    'analytics_id' => env('GOOGLE_ANALYTICS_ID'),

    // Google Ads conversion ID, "AW-123456789". This is the one that matters
    // for advertising: without it Ads sees clicks and never sees sales.
    'ads_id' => env('GOOGLE_ADS_ID'),

    // The conversion label for the "Purchase" conversion action, which Google
    // Ads shows next to the ID when you create it. The purchase event is sent
    // to "AW-123456789/AbC-D_efG" — the ID alone records nothing.
    'ads_purchase_label' => env('GOOGLE_ADS_PURCHASE_LABEL'),

    // Enhanced conversions: send a hashed email and phone with the purchase so
    // Google can match a sale to an ad click when cookies could not. Hashing is
    // done here, server-side — plain identifiers never reach the page source.
    'enhanced_conversions' => filter_var(env('GOOGLE_ENHANCED_CONVERSIONS', true), FILTER_VALIDATE_BOOL),

    // The token from Search Console / Merchant Center "HTML tag" verification —
    // the value only, not the whole <meta> element.
    'site_verification' => env('GOOGLE_SITE_VERIFICATION'),

    // Dynamic remarketing needs each item tagged with the vertical whose feed
    // it came from. A jewellery shop is "retail"; the value only changes if
    // this codebase is ever deployed for a hotel or a car dealer.
    'business_vertical' => env('GOOGLE_BUSINESS_VERTICAL', 'retail'),
];
