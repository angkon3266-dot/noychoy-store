<?php

namespace App\Support;

/**
 * Which homepage the store is serving: the React one, or a Blade template.
 *
 * This decision used to live in two places and be phrased in terms of a Blade
 * FILE — HomeController compared a resolved view name to
 * `shop.templates.home.couture`, having first fallen back to another template
 * if that file did not exist. So the React homepage was selected by the
 * existence of a Blade file it never actually renders. Deleting that file as
 * part of a dead-code sweep would have silently reverted the live homepage to
 * the old Blade one, while the middleware's copy of the same condition (which
 * had no existence check) kept telling the client to navigate there as a
 * single-page app.
 *
 * One method, keyed on the template NAME, called from both places.
 */
class HomePage
{
    /** The original React homepage design (the store's default). */
    public const REACT_TEMPLATE = 'couture';

    /**
     * Every design the React homepage renders. They share one data payload and
     * one builder (Admin → Appearance → Homepage) and differ only in how
     * Home.jsx lays the sections out ("editorial" since 2026-10-28).
     */
    public const REACT_TEMPLATES = ['couture', 'editorial'];

    public static function isReact(?string $templateKey = null): bool
    {
        return in_array($templateKey ?: theme('homepage_template'), self::REACT_TEMPLATES, true);
    }
}
