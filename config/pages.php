<?php

/*
|--------------------------------------------------------------------------
| Static content pages (footer legal pages)
|--------------------------------------------------------------------------
|
| Default titles + HTML bodies for the Privacy Policy, Terms & Conditions and
| Refund/Return policy pages. Admins can override these under Admin → Pages
| (stored in the `pages` setting); these serve as the fallback.
|
| These are sensible starting templates — have them reviewed for your business
| and local law before relying on them.
|
*/

return [

    /*
     * The brand story.
     *
     * This shipped as scaffolding — "Write the story behind the brand here" —
     * and was never replaced, so the one page a shopper opens to decide whether
     * the shop is real told them to write it themselves. The copy below is a
     * real default in the brand's own voice, drawn from what the rest of the
     * site already promises (the trust badges, the refund window, the pricing
     * claim) so the page cannot contradict them. It is still the owner's to
     * rewrite in Admin → Pages.
     *
     * `eyebrow`, `headline`, `lede` and `hero_image` drive the page header;
     * `body` is the long-form copy underneath it.
     */
    'about' => [
        'title' => 'Our story',
        // Null, not the brand name: this file is the shared default for every
        // store on this codebase, so the eyebrow falls back to store_name().
        'eyebrow' => null,
        'headline' => 'Jewelry that tells your story',
        'lede' => 'Hand-picked pieces, checked before they ship, and priced to be worn — not saved for a day that never comes.',
        'hero_image' => null,
        'body' => '<h2>Why we started</h2>
<p>Fine jewelry in Bangladesh tends to arrive one of two ways: a glass counter where the price is whatever the shopkeeper decides that afternoon, or an online photo that looks nothing like the parcel. We wanted a third way — a fixed, fair price on the page, a real photograph of the actual piece, and a box you would be happy to hand to someone.</p>
<h2>What we make</h2>
<p>Brilliant-cut cubic zirconia set in gold-plated and rhodium-plated settings, with sterling silver on selected pieces. Earrings, necklaces, rings, bracelets and anklets — everyday pieces and occasion pieces, made to be worn to work on Tuesday and to a wedding on Friday.</p>
<h2>We tell you exactly what it is</h2>
<p>Our stones are cubic zirconia: lab-made, cut the same way a diamond is, and brilliant in the light. They are not diamonds, and we will never imply otherwise. Plenty of shops in this category leave that comfortably vague. We would rather put the material on every product page and let the piece earn its place.</p>
<p>That is also why the prices look the way they do. You are paying for the stone, the setting and the finishing — not for a ten-times markup and a velvet room.</p>
<h2>How we work</h2>
<p>Every order is checked by hand before it leaves us. It reaches you through our courier partner anywhere in Bangladesh, and you pay when it arrives — nothing up front, nothing online. If a piece is not what you expected, you have seven days to tell us and we will make it right.</p>
<p>If you would rather talk to a person first, call or message us. Someone here will answer.</p>',
    ],

    'privacy' => [
        'title' => 'Privacy Policy',
        'body' => '<p>We respect your privacy and are committed to protecting the personal information you share with us.</p>
<h3>Information we collect</h3>
<p>When you place an order or contact us, we collect your name, phone number, delivery address, and (optionally) your email address. We use this only to process and deliver your orders and to provide customer support.</p>
<h3>How we use your information</h3>
<ul>
<li>To process, confirm and deliver your orders (including sharing your delivery details with our courier partner).</li>
<li>To contact you about your order via call, SMS or WhatsApp.</li>
<li>To improve our products and service.</li>
</ul>
<h3>Data sharing</h3>
<p>We do not sell your personal information. We share delivery details only with our courier partner to fulfil your order.</p>
<h3>Your rights</h3>
<p>You may contact us at any time to review, update or delete your personal information.</p>',
    ],

    'terms' => [
        'title' => 'Terms & Conditions',
        'body' => '<p>By placing an order on our website, you agree to the following terms.</p>
<h3>Orders &amp; pricing</h3>
<p>All prices are listed in Bangladeshi Taka (৳) and include applicable charges unless stated otherwise. We reserve the right to cancel any order due to stock or pricing errors.</p>
<h3>Payment</h3>
<p>We currently accept Cash on Delivery (COD). Please keep the exact amount ready at the time of delivery.</p>
<h3>Delivery</h3>
<p>Delivery times are estimates and may vary. Our courier partner will contact you before delivery.</p>
<h3>Product accuracy</h3>
<p>We make every effort to display products accurately. Slight variations in colour may occur due to photography and screen settings.</p>',
    ],

    'refund' => [
        'title' => 'Refund & Return Policy',
        'body' => '<p>Your satisfaction matters to us. Please read our return and refund policy below.</p>
<h3>7 days to change your mind</h3>
<p>If a piece isn\'t right, you have 7 days from delivery to return it for an exchange or a refund — no reason needed. Contact us with your order number and we will arrange the pickup or drop-off.</p>
<h3>Damaged, defective or wrong item</h3>
<p>Please contact us within 3 days of delivery with photos. We will arrange a replacement or a full refund, including delivery.</p>
<h3>Conditions</h3>
<ul>
<li>The item must be unused and in its original condition and packaging.</li>
<li>Proof of purchase (order number) is required.</li>
<li>For hygiene reasons, earrings can only be returned unworn with the seal intact, or if faulty.</li>
</ul>
<h3>Refunds</h3>
<p>Approved refunds are processed via bKash/Nagad or the original payment method within 7 working days.</p>',
    ],

    'contact' => [
        'title' => 'Contact Us',
        'intro' => 'Have a question about an order or a product? Send us a message and we\'ll get back to you as soon as possible.',
    ],
];
