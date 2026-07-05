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
<h3>Returns</h3>
<p>If you receive a damaged, defective or wrong item, please contact us within 3 days of delivery with photos. We will arrange a replacement or refund.</p>
<h3>Conditions</h3>
<ul>
<li>The item must be unused and in its original condition and packaging.</li>
<li>Proof of purchase (order number) is required.</li>
</ul>
<h3>Refunds</h3>
<p>Approved refunds are processed via bKash/Nagad or the original payment method within 7 working days.</p>
<h3>Non-returnable items</h3>
<p>For hygiene reasons, earrings and certain personalised items may not be eligible for return unless faulty.</p>',
    ],

    'contact' => [
        'title' => 'Contact Us',
        'intro' => 'Have a question about an order or a product? Send us a message and we\'ll get back to you as soon as possible.',
    ],
];
