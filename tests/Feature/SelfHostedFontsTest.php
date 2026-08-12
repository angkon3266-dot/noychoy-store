<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The storefront used to fetch admin-picked fonts live from
 * fonts.googleapis.com — a page load that depended on a server this install
 * does not control. Brand fonts are now either one of the two families
 * self-hosted by the Vite build (see vite.config.js) or a file the admin
 * uploads, so a storefront page never has to reach anywhere but its own host
 * to render its own text.
 */
class SelfHostedFontsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_homepage_never_references_a_google_fonts_host(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('fonts.gstatic.com', $html);
    }

    public function test_the_font_picker_only_offers_self_hosted_families(): void
    {
        $this->assertSame(['Playfair Display', 'Instrument Sans'], config('theme.fonts'));
    }
}
