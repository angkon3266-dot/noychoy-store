<?php

return [
    // One-click 4-colour brand palettes.
    // primary = buttons/links · accent = secondary highlights · background = page tone · text = ink/headings
    'palettes' => [
        'gold' => ['label' => 'Classic Gold', 'primary' => '#9a6c2e', 'accent' => '#b6863a', 'background' => '#fbf8f1', 'text' => '#161618'],
        'rose' => ['label' => 'Rose Gold', 'primary' => '#b76e79', 'accent' => '#cf9aa3', 'background' => '#fcf6f6', 'text' => '#2b2024'],
        'emerald' => ['label' => 'Emerald', 'primary' => '#0f766e', 'accent' => '#2dd4bf', 'background' => '#f2faf8', 'text' => '#14201e'],
        'royal' => ['label' => 'Royal Blue', 'primary' => '#1d4ed8', 'accent' => '#60a5fa', 'background' => '#f4f7fe', 'text' => '#0f1729'],
        'blush' => ['label' => 'Blush Pink', 'primary' => '#db2777', 'accent' => '#f9a8d4', 'background' => '#fdf5f9', 'text' => '#2a1620'],
        'plum' => ['label' => 'Plum', 'primary' => '#7e22ce', 'accent' => '#c084fc', 'background' => '#faf6fe', 'text' => '#1f1430'],
        'noir' => ['label' => 'Noir', 'primary' => '#1f2937', 'accent' => '#9ca3af', 'background' => '#f7f7f8', 'text' => '#0a0a0a'],
    ],

    // Built-in fonts, self-hosted by the Vite build (see vite.config.js) — no
    // request to any font CDN at runtime. "custom" (an uploaded file) is the
    // only other option; there is deliberately no live Google Fonts fetch.
    'fonts' => ['Playfair Display', 'Instrument Sans'],

    // Default appearance — overridable from Admin → Appearance (stored in settings table).
    'defaults' => [
        // ── Gift orders (checkout) ───────────────────────────────────────
        'gift_enabled' => true,
        'gift_title' => 'This is a gift',
        'gift_note' => 'We pack it gift-ready with no price slip in the box, and you can add a short message on a card.',
        'gift_message_label' => 'Message for the card (optional)',
        'gift_message_placeholder' => 'Happy anniversary, Nadia — every year with you shines brighter. Love, Rafi',
        'gift_message_help' => 'Printed on our gift card and tucked inside the box.',
        'gift_message_max' => 100,

        'logo' => null,                // desktop logo
        'logo_mobile' => null,         // mobile logo (used instead of the desktop logo on phones)
        'logo_align' => 'left',        // logo placement: left | center | right
        'header_center_image' => null, // optional image shown centered in the mobile header
        'header_center_link' => null,  // optional link for the center image
        'favicon' => null,
        'logo_height_desktop' => 40,   // px — logo height on desktop
        'logo_height_mobile' => 32,    // px — logo height on mobile (left-aligned)
        'header_center_height' => 32,  // px — center image height on mobile
        'menu_icon' => null,           // mobile menu toggle icon (e.g. the "M" mark)
        'menu_icon_rotation' => 45,    // degrees the icon rotates when the menu is open
        'menu_icon_height' => 28,      // px — menu toggle icon size on mobile
        'primary' => '#9a6c2e',     // buttons/links
        'accent' => '#b6863a',      // secondary highlights
        'background' => '#fbf8f1',  // page background
        'text' => '#161618',        // ink / headings
        // Fonts: source = google | custom (uploaded file)
        'font_heading' => 'Playfair Display',
        'font_heading_src' => 'google',
        'font_heading_file' => null,
        'font_body' => 'Instrument Sans',
        'font_body_src' => 'google',
        'font_body_file' => null,
        'homepage_template' => 'storefront',
        'product_template' => 'showcase',

        // Announcement bar
        'announcement_enabled' => true,
        'announcement_bg' => '#161618',
        'announcement_color' => '#f5edda',
        'announcement_messages' => [
            'Free delivery on orders over {free_delivery}',
            'Cash on delivery available all over Bangladesh',
            'Handcrafted jewelry · Authentic quality guaranteed',
        ],
        'announcement_link' => null,
        'announcement_speed' => 6,   // seconds per message (lower = faster scroll)

        // Registered-customer offer bar (personalised greeting + offer)
        'cbar_enabled' => false,
        'cbar_text' => 'Welcome back, {name}! Here’s a little something for you 🎁',
        'cbar_code' => null,              // optional promo code (shown as copy-to-clipboard)
        'cbar_link' => null,             // optional CTA link
        'cbar_link_label' => 'Shop now',
        'cbar_bg' => '#161618',
        'cbar_color' => '#f5edda',

        // Conversion toggles
        'whatsapp_number' => null,            // e.g. 8801XXXXXXXXX
        'messenger_url' => null,              // e.g. https://m.me/yourpage
        'show_call_button' => true,           // floating "Call now" (uses store phone)
        'show_whatsapp_button' => true,
        'show_share_button' => true,
        'show_messenger_button' => false,
        'free_shipping_bar' => true,
        'show_recently_viewed' => true,
        'show_reviews' => true,
        'urgency_low_stock' => true,
        'low_stock_threshold' => 5,
        'sticky_buy_bar' => true,
        'exit_intent' => false,
        'exit_intent_code' => null,

        // Navigation menu behaviour
        'menu_desktop_trigger' => 'hover',   // hover | click
        'menu_show_search' => true,          // show the search box in the header
        // The header's one filled button. Defaulted to something the store sells:
        // an empty label used to leave the header with no call to action at all,
        // and the admin placeholder used to suggest order tracking.
        'menu_cta_label' => 'Shop gifts',
        'menu_cta_link' => null,             // falls back to the shop route

        // Footer (editable in Appearance → Footer)
        'footer_brand' => null,              // footer heading text; defaults to store name
        'footer_about' => 'Handpicked jewelry, delivered across Bangladesh. Cash on delivery available.',
        'footer_facebook' => null,
        'footer_instagram' => null,
        'footer_copyright' => null,          // defaults to "© YEAR Store. All rights reserved."

        // Product page conversion helpers
        'show_delivery_estimate' => true,
        // Nationwide / outside-Dhaka window. These keep their names: the
        // product page reads them when it has no zone to go on, and saved
        // theme rows in production already carry them.
        'delivery_days_min' => 2,
        'delivery_days_max' => 4,
        // Inside Dhaka is faster. Used once an order knows its zone.
        'delivery_days_inside_min' => 1,
        'delivery_days_inside_max' => 2,
        // Carbon day numbers the courier does not deliver on.
        // 5 = Friday, the Bangladeshi weekend day.
        'delivery_off_days' => [5],
        'show_pdp_whatsapp' => true,
        'show_frequently_bought' => true,

        // Product page: the Care and Shipping & returns accordions under the
        // Details table. Plain text; blank lines make paragraphs, "- " makes
        // bullets, "## " makes a heading — same light format as descriptions.
        'pdp_care_text' => "- Keep away from perfume, hairspray and water — put jewelry on last, take it off first.\n"
            ."- Wipe gently with the dry soft cloth after wearing to keep the plating bright.\n"
            ."- Store each piece separately in the pouch or box it arrived in, away from sunlight.\n"
            ."- Avoid wearing during exercise, swimming or sleeping.",
        'pdp_returns_text' => "- Cash on delivery, anywhere in Bangladesh — pay only when the parcel reaches your hands.\n"
            ."- 7 days to change your mind: unused, in its original packaging, and we will exchange or refund it.\n"
            ."- Damaged, defective or wrong item? Message us within 3 days of delivery with a photo and we will make it right.\n"
            ."- Refunds go out via bKash/Nagad or your original payment method within 7 working days.",

        // Printed thank-you card design (Appearance → Cards & print).
        // Sizes in mm unless noted; font scale is a % applied to the size-derived base.
        'card_font' => 'serif',
        'card_font_custom' => null,          // used when card_font = 'custom'
        'card_font_scale' => 100,
        'card_line_height' => 150,           // % of font size
        'card_letter_spacing' => 0,          // hundredths of an em
        'card_gap' => 4,                     // space between logo and message
        'card_padding' => 6,
        'card_align' => 'center',
        'card_valign' => 'center',
        'card_text_color' => '#161618',
        'card_bg' => '#ffffff',
        'card_border' => 'dashed',           // none | dashed | dotted | solid | double
        'card_border_color' => '#c9ad74',
        'card_border_width' => 1,            // in printed pixels
        'card_border_inset' => 2,            // mm gap between card edge and border
        'card_logo_height' => 18,            // % of card height
        'card_uppercase' => false,
        'card_show_logo' => true,

        // Trust strip (editable in Appearance → Trust badges). Each: icon, title, text.
        // `icon` is a name from App\Support\StorefrontIcons — these used to be
        // emoji, which read bazaar rather than boutique on a fine-jewelry site.
        'trust_badges' => [
            ['icon' => 'cash', 'title' => 'Cash on Delivery', 'text' => 'Pay when it arrives'],
            ['icon' => 'truck', 'title' => 'Fast Delivery Across Bangladesh', 'text' => ''],
            ['icon' => 'tag', 'title' => 'Fair Pricing', 'text' => 'No 10x markups'],
            ['icon' => 'shieldCheck', 'title' => 'Authentic Quality, Guaranteed', 'text' => ''],
            ['icon' => 'calendar', 'title' => '7 Days to Change Your Mind', 'text' => ''],
        ],
    ],

    // Homepage templates (brand-inspired presets). Each maps to a Blade view.
    'homepage_templates' => [
        'storefront' => ['name' => 'Storefront — Slider + carousels', 'inspiration' => 'Manfare / modern retail', 'view' => 'shop.templates.home.storefront'],
        'couture' => ['name' => 'Couture — Modern Luxury', 'inspiration' => 'Mejuri / Tiffany editorial', 'view' => 'shop.templates.home.couture'],
        'meridian' => ['name' => 'Meridian — Full storefront', 'inspiration' => 'Occasion-led gifting funnel', 'view' => 'shop.templates.home.meridian'],
        'aurelia' => ['name' => 'Aurelia — Classic Elegance', 'inspiration' => 'Tiffany & Co.', 'view' => 'shop.templates.home.aurelia'],
        'lumiere' => ['name' => 'Lumière — Editorial', 'inspiration' => 'Mejuri', 'view' => 'shop.templates.home.lumiere'],
        'maison' => ['name' => 'Maison — Luxe Dark', 'inspiration' => 'Cartier / Bvlgari', 'view' => 'shop.templates.home.maison'],
        'bloom' => ['name' => 'Bloom — Playful', 'inspiration' => 'Pandora', 'view' => 'shop.templates.home.bloom'],
        'heritage' => ['name' => 'Heritage — Traditional', 'inspiration' => 'Tanishq / Kalyan', 'view' => 'shop.templates.home.heritage'],
    ],

    // The product-page template picker is gone. There is one React product
    // page now, and CatalogController has rendered it unconditionally since the
    // migration — the setting was saved, validated and shown in two places
    // while changing precisely nothing, and the Blade templates it chose
    // between have been deleted.
];
