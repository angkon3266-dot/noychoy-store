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

    // Curated fonts (Google) the admin can pick, plus "custom" for uploaded files.
    'fonts' => ['Poppins', 'Inter', 'Montserrat', 'Lato', 'Raleway', 'Jost', 'Playfair Display', 'Cormorant Garamond', 'Marcellus', 'Instrument Sans'],

    // Default appearance — overridable from Admin → Appearance (stored in settings table).
    'defaults' => [
        'logo' => null,
        'favicon' => null,
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
        'homepage_template' => 'aurelia',
        'product_template' => 'showcase',

        // Announcement bar
        'announcement_enabled' => true,
        'announcement_bg' => '#161618',
        'announcement_color' => '#f5edda',
        'announcement_messages' => [
            'Free delivery on orders over ৳3000',
            'Cash on delivery available all over Bangladesh',
            'Handcrafted jewelry · Authentic quality guaranteed',
        ],
        'announcement_link' => null,
        'announcement_speed' => 6,   // seconds per message (lower = faster scroll)

        // Conversion toggles
        'whatsapp_number' => null,            // e.g. 8801XXXXXXXXX
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
        'menu_cta_label' => null,            // optional highlighted nav button label
        'menu_cta_link' => null,             // optional highlighted nav button link

        // Product page conversion helpers
        'show_delivery_estimate' => true,
        'delivery_days_min' => 2,
        'delivery_days_max' => 4,
        'show_pdp_whatsapp' => true,
        'show_frequently_bought' => true,

        // Marketing
        'meta_pixel_id' => null,
    ],

    // Homepage templates (brand-inspired presets). Each maps to a Blade view.
    'homepage_templates' => [
        'aurelia' => ['name' => 'Aurelia — Classic Elegance', 'inspiration' => 'Tiffany & Co.', 'view' => 'shop.templates.home.aurelia'],
        'lumiere' => ['name' => 'Lumière — Editorial', 'inspiration' => 'Mejuri', 'view' => 'shop.templates.home.lumiere'],
        'maison' => ['name' => 'Maison — Luxe Dark', 'inspiration' => 'Cartier / Bvlgari', 'view' => 'shop.templates.home.maison'],
        'bloom' => ['name' => 'Bloom — Playful', 'inspiration' => 'Pandora', 'view' => 'shop.templates.home.bloom'],
        'heritage' => ['name' => 'Heritage — Traditional', 'inspiration' => 'Tanishq / Kalyan', 'view' => 'shop.templates.home.heritage'],
    ],

    // Single product page templates.
    'product_templates' => [
        'showcase' => ['name' => 'Showcase — Gallery left', 'inspiration' => 'Blue Nile', 'view' => 'shop.templates.product.showcase'],
        'minimal' => ['name' => 'Minimal — Editorial', 'inspiration' => 'Mejuri', 'view' => 'shop.templates.product.minimal'],
        'luxe' => ['name' => 'Luxe — Dark immersive', 'inspiration' => 'Cartier', 'view' => 'shop.templates.product.luxe'],
        'sticky' => ['name' => 'Sticky — Conversion-focused', 'inspiration' => 'Pandora', 'view' => 'shop.templates.product.sticky'],
        'classic' => ['name' => 'Classic — Centered', 'inspiration' => 'Tanishq', 'view' => 'shop.templates.product.classic'],
    ],
];
