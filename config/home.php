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
        'categories_title' => 'Shop by category',
        'featured_title' => 'Featured',
        'new_arrivals_title' => 'New arrivals',

        // Trust badges (bottom strip)
        'badge1_title' => 'Cash on Delivery',
        'badge1_text' => 'Pay when you receive',
        'badge2_title' => 'Nationwide Shipping',
        'badge2_text' => 'Delivered via Steadfast',
        'badge3_title' => 'Quality Assured',
        'badge3_text' => 'Handpicked pieces',
    ],
];
