<?php

return [
    // Master switch (also overridable from Admin → Settings via Setting 'loyalty_enabled').
    'enabled' => true,

    // Earning: 1000৳ spent = 100 points  →  0.1 point per taka.
    'earn_per_taka' => 0.1,

    // Redemption: 100 points = 5৳  →  one point is worth 0.05৳.
    'redeem_value' => 0.05,
    'redeem_step' => 100,   // redeem in multiples of this
    'min_redeem' => 100,    // minimum points that can be redeemed at once

    // Action rewards (all overridable from Admin → Offers → Loyalty & points).
    'review_points' => 200,   // approved review
    'share_points' => 100,    // share on social (once per week)
    'signup_points' => 0,     // welcome bonus on registration

    // Weekly milestones — reset every week (Mon–Sun). Customers see a progress bar.
    // key must match a point_transactions.type or a tracked action.
    'milestones' => [
        ['key' => 'earn_review', 'label' => 'Write a product review', 'points' => 100, 'icon' => '⭐'],
        ['key' => 'earn_share', 'label' => 'Share us on social media', 'points' => 100, 'icon' => '📣'],
        ['key' => 'earn_order', 'label' => 'Place an order this week', 'points' => 100, 'icon' => '🛍️'],
    ],

    // Default extra discount offered to guests for creating an account (editable in Admin → Offers).
    'register_discount_percent' => 3,

    // Referral: points granted to BOTH the referrer and the new customer when the
    // referred customer's first order is delivered.
    'referral_points' => 300,

    // Bonus points when an approved review includes at least one photo.
    'review_photo_bonus' => 100,

    // Membership tiers by lifetime points earned. `multiplier` boosts order-earned
    // points (e.g. Gold earns 1.25× points). Highest qualifying tier wins.
    'tiers' => [
        ['key' => 'silver', 'label' => 'Silver', 'min_points' => 0, 'multiplier' => 1.0, 'perk' => 'Earn points on every order'],
        ['key' => 'gold', 'label' => 'Gold', 'min_points' => 3000, 'multiplier' => 1.25, 'perk' => '1.25× points + early access to drops'],
        ['key' => 'platinum', 'label' => 'Platinum', 'min_points' => 10000, 'multiplier' => 1.5, 'perk' => '1.5× points + priority support & gifts'],
    ],
];
