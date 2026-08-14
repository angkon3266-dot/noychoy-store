<?php

return [
    // Editable homepage content — overridable from Admin → Appearance → Homepage content
    // (stored in the settings table under the 'home_content' key).
    'defaults' => [
        // Hero
        'hero_eyebrow' => null,                 // small text above heading (defaults to store name)
        'hero_heading' => 'Jewelry that tells your story',
        'hero_highlight' => 'your story',       // portion of the heading shown in the accent colour
        'hero_subtitle' => 'Handpicked pieces, delivered across Bangladesh with cash on delivery.',
        'hero_cta_text' => 'Shop the collection',
        'hero_cta_link' => null,                // defaults to the Shop page
        'hero_secondary_text' => 'Track order',
        'hero_secondary_link' => null,          // defaults to the Track page
        'hero_image' => null,                   // optional background/feature image (upload)

        // Section titles
        'categories_title' => 'Browse Our Categories',
        'featured_title' => 'Featured',
        'best_selling_title' => 'Best Selling Products',
        'new_arrivals_title' => 'New Arrival Products',

        // Storefront section toggles (Manfare-style template)
        'show_feature_strip' => true,
        'show_categories' => true,
        'show_best_selling' => true,
        'show_new_arrivals' => true,
        'show_highlights' => true,
        'show_videos' => true,

        // Feature strip (4 reassurance items). Each: icon (emoji) + title.
        'feature_strip' => [
            ['icon' => '🚚', 'title' => 'Fastest Shipping Countrywide'],
            ['icon' => '↩️', 'title' => 'Easy Return Policy'],
            ['icon' => '💎', 'title' => 'Premium Quality Product'],
            ['icon' => '🎧', 'title' => 'Online Support 24/7'],
        ],

        // Hero slider — list of { image, link, alt }. Empty = fall back to hero_image / featured.
        'hero_slides' => [],

        // Highlighted categories (large editorial tiles) — list of category IDs.
        'highlight_category_ids' => [],

        // Homepage video sections — list of { title, url }. url = YouTube link or uploaded MP4 path.
        'videos' => [],

        // "Deals of the Day" — a carousel built from the live offers under
        // Admin → Offers. deals_ends_at is an optional deadline: while it is set
        // the section shows a countdown, and it hides itself once it passes.
        'show_deals' => true,
        'deals_title' => 'Deals of the Day',
        'deals_subtitle' => null,
        'deals_ends_at' => null,

        // "Our promise" editorial brand band (Couture template)
        'show_promise' => true,
        'promise_eyebrow' => 'Our promise',
        'promise_title' => 'Crafted to be treasured',
        // Its own copy, deliberately NOT the hero subtitle: the band used to
        // fall back to it and the homepage said the same sentence twice.
        'promise_text' => 'Every piece is checked by hand before it ships, delivered anywhere in Bangladesh by Steadfast, and paid for only when it reaches your door.',
        'promise_image' => null,                // falls back to the newest product photo

        // Trust badges (bottom strip)
        'badge1_title' => 'Cash on Delivery',
        'badge1_text' => 'Pay when you receive',
        'badge2_title' => 'Nationwide Shipping',
        'badge2_text' => 'Delivered via Steadfast',
        'badge3_title' => 'Quality Assured',
        'badge3_text' => 'Handpicked pieces',
    ],
];
