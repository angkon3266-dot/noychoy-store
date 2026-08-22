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

        // Feature strip (4 reassurance items). Each: icon + title.
        // `icon` is a name from App\Support\StorefrontIcons; an emoji saved
        // before the picker existed still renders, it just looks like an emoji.
        'feature_strip' => [
            ['icon' => 'truck', 'title' => 'Fastest Shipping Countrywide'],
            ['icon' => 'check', 'title' => 'Easy Return Policy'],
            ['icon' => 'diamond', 'title' => 'Premium Quality Product'],
            ['icon' => 'chat', 'title' => 'Online Support 24/7'],
        ],

        // Short reassurance line under the hero headline. These used to be
        // hardcoded in Home.jsx, which is how "cash on delivery" ended up on
        // the homepage six times with no way to edit any of them.
        'hero_trust' => [
            'Cash on delivery',
            'Gift message included',
        ],

        // Gift finder blurb (the band under the hero CTA).
        'gift_finder_text' => 'Pick a budget and we will show you everything that fits it — every piece arrives gift-ready, with a card message if you want one.',

        // The three gift promises beside the finder. icon = StorefrontIcons name.
        'gift_promises' => [
            ['icon' => 'gift', 'title' => 'Gift-ready packing', 'text' => 'No price slip in the box'],
            ['icon' => 'mail', 'title' => 'Personal card', 'text' => 'Add a message at checkout'],
            ['icon' => 'cash', 'title' => 'Cash on delivery', 'text' => 'Pay when it arrives'],
        ],

        // "How gifting works" — the four-step band.
        'gifting_steps' => [
            ['title' => 'Pick a piece', 'text' => 'Browse by occasion, budget or category.'],
            ['title' => 'Tick "This is a gift"', 'text' => 'At checkout — add a card message if you like.'],
            ['title' => 'We pack it gift-ready', 'text' => 'Hand-checked, no price slip in the box.'],
            ['title' => 'Pay on delivery', 'text' => 'Anywhere in Bangladesh, via Steadfast.'],
        ],

        // Hero slider — list of { image, link, alt }. Empty = fall back to hero_image / featured.
        'hero_slides' => [],

        // Highlighted categories (large editorial tiles) — list of category IDs.
        'highlight_category_ids' => [],

        // Homepage video sections — list of { title, url }. url = YouTube link or uploaded MP4 path.
        'videos' => [],

        // ── "Shop by occasion" (Meridian template) ──────────────────────────
        // Bangladesh-first, and gift-led on purpose. Eid-ul-Fitr and Pohela
        // Boishakh are the two peaks of the local calendar — industry figures
        // put roughly three quarters of fashion-market sales across those two
        // alone — and the rest are the year-round reasons someone buys jewelry
        // for another person. Each tile needs an image before it renders, and
        // the section hides entirely until at least one has one, so a fresh
        // install never shows a row of empty boxes.
        'show_occasions' => true,
        'occasions_title' => 'Shop by occasion',
        'occasions_subtitle' => 'Who is it for, and what are they celebrating?',
        'occasions' => [
            ['label' => 'Eid Gifts', 'tagline' => 'The year’s biggest gifting season', 'link' => null, 'image' => null],
            ['label' => 'Pohela Boishakh', 'tagline' => 'Boishakhi red, white and gold', 'link' => null, 'image' => null],
            ['label' => 'Anniversary', 'tagline' => 'Mark the years together', 'link' => null, 'image' => null],
            ['label' => 'Birthday Gift', 'tagline' => 'Something she opens twice', 'link' => null, 'image' => null],
            ['label' => 'Date Night', 'tagline' => 'Delicate pieces for low light', 'link' => null, 'image' => null],
            ['label' => 'Gift for Her', 'tagline' => 'No occasion needed', 'link' => null, 'image' => null],
            ['label' => 'Holud & Wedding', 'tagline' => 'Bridal sets and statement pieces', 'link' => null, 'image' => null],
            ['label' => 'Everyday', 'tagline' => 'Light enough to forget you are wearing it', 'link' => null, 'image' => null],
        ],

        // "Shopping for someone?" — budget bands that link straight into the
        // shop's existing price filter. Amounts only; the labels are formatted
        // with the store's own currency so this travels to other installs.
        'show_gift_finder' => true,
        'gift_finder_title' => 'Shopping for someone?',
        'gift_budgets' => [
            ['min' => null, 'max' => 1000],
            ['min' => 1000, 'max' => 2500],
            ['min' => 2500, 'max' => null],
        ],

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
