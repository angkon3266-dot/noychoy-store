<?php

/*
 * The reward ladder — "add more, save more" — shipped defaults.
 *
 * Every paid piece in the cart climbs one rung; each rung unlocks a reward
 * that STAYS unlocked as the cart grows (tier 4 means tiers 1–4 all apply).
 * The rows below are the store owner's own ladder; Admin → Offers overwrites
 * them via the `gift_ladder_tiers` setting, so edit them there, not here.
 *
 *   threshold  paid pieces in the cart that unlock the rung
 *   type       flat (৳ off) · percent (% off what is paid) · free_delivery · free_gift
 *   value      the ৳ or % — null for the two switches
 */
return [
    'tiers' => [
        ['threshold' => 1, 'type' => 'flat', 'value' => 50],
        ['threshold' => 2, 'type' => 'percent', 'value' => 2],
        ['threshold' => 3, 'type' => 'free_delivery', 'value' => null],
        ['threshold' => 4, 'type' => 'flat', 'value' => 60],
        ['threshold' => 5, 'type' => 'flat', 'value' => 65],
        ['threshold' => 6, 'type' => 'flat', 'value' => 70],
        ['threshold' => 7, 'type' => 'flat', 'value' => 70],
        ['threshold' => 8, 'type' => 'flat', 'value' => 80],
        ['threshold' => 9, 'type' => 'free_gift', 'value' => null],
        ['threshold' => 10, 'type' => 'flat', 'value' => 100],
    ],
];
