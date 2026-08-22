<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\StorefrontIcons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Pins the brand-leak fixes from roadmap step 02 so they cannot regress
 * quietly — most of them are defaults, and a default is exactly the kind of
 * thing that gets edited back without anyone noticing.
 */
class BrandChromeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shipped_defaults_no_longer_carry_the_old_brand(): void
    {
        foreach (['config/theme.php', 'config/home.php', 'config/pages.php', '.env.example'] as $file) {
            $this->assertStringNotContainsStringIgnoringCase(
                'noychoy',
                file_get_contents(base_path($file)),
                $file.' still ships the old brand name'
            );
        }
    }

    public function test_footer_about_default_is_not_duplicated_in_the_middleware(): void
    {
        // The default lived in two places, so editing config/theme.php alone
        // changed nothing on an unedited install.
        $middleware = file_get_contents(app_path('Http/Middleware/HandleInertiaRequests.php'));
        $this->assertStringNotContainsString('Handpicked jewelry, delivered across Bangladesh', $middleware);

        Setting::put('theme', ['footer_about' => 'A one-line brand promise.']);
        $this->get('/shop')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('chrome.footer.about', 'A one-line brand promise.'));
    }

    public function test_header_has_a_commercial_cta_by_default(): void
    {
        $this->get('/shop')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('chrome.menu.cta.label', 'Shop gifts')
            ->where('chrome.menu.cta.url', route('shop')));
    }

    public function test_trust_badges_and_feature_strip_ship_icon_names_not_emoji(): void
    {
        foreach (config('theme.defaults.trust_badges') as $badge) {
            $this->assertContains($badge['icon'], StorefrontIcons::names(), $badge['title']);
        }
        foreach (config('home.defaults.feature_strip') as $item) {
            $this->assertContains($item['icon'], StorefrontIcons::names(), $item['title']);
        }
        foreach (config('home.defaults.gift_promises') as $item) {
            $this->assertContains($item['icon'], StorefrontIcons::names(), $item['title']);
        }
    }

    public function test_about_page_routes_and_is_editable(): void
    {
        $this->get('/about')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Legal')
            ->where('title', 'Our story'));

        Setting::put('pages', ['about' => ['title' => 'The house of Meridian', 'body' => '<p>Founded in Dhaka.</p>']]);

        $this->get('/about')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('title', 'The house of Meridian')
            ->where('body', '<p>Founded in Dhaka.</p>'));
    }

    public function test_the_footer_links_to_the_brand_story(): void
    {
        $this->get('/shop')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('chrome.urls.about', route('page.about')));
    }

    public function test_no_react_view_renders_a_settings_icon_as_raw_text(): void
    {
        // Retiring the emoji turned the trust-badge and feature-strip settings
        // into icon NAMES. A renderer still printing the value directly shows
        // the literal word "cash" / "truck" / "sparkle" instead of an icon —
        // exactly what the product page did after the first pass. <IconOrGlyph>
        // is the only correct way to render one.
        //
        // Scoped to the settings-backed lists (b = badge, f = feature); the
        // per-notification `n.icon` is a separate, still-emoji field.
        $offenders = [];

        foreach ($this->jsxFiles() as $file) {
            $source = file_get_contents($file);

            // A raw render, not the `value={…}` prop of IconOrGlyph itself.
            if (preg_match('/(?<!value=)[{]{1}[ ]*[bf][.]icon[ ]*([|]{2}[^}]*)?[}]/', $source, $m)) {
                $offenders[] = basename($file).' renders '.$m[0].' directly';
            }
        }

        $this->assertSame([], $offenders, 'Render these through <IconOrGlyph value={...} /> instead');
    }

    /** @return array<int, string> */
    protected function jsxFiles(): array
    {
        $files = [];
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js')));

        foreach ($dir as $file) {
            if ($file->isFile() && $file->getExtension() === 'jsx') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_a_real_favicon_ships_instead_of_an_empty_file(): void
    {
        $this->assertGreaterThan(1000, filesize(public_path('favicon.ico')), 'favicon.ico is empty again');
        $this->assertFileExists(public_path('favicon.svg'));
    }

    public function test_manifest_follows_the_store_palette_and_offers_installable_icons(): void
    {
        Setting::put('theme', ['primary' => '#123456', 'background' => '#fedcba']);

        $manifest = $this->get('/site.webmanifest')->assertOk()->json();

        $this->assertSame('#123456', $manifest['theme_color']);
        $this->assertSame('#fedcba', $manifest['background_color']);
        $this->assertNotEmpty($manifest['icons']);
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
    }

    public function test_homepage_repeated_copy_is_editable_rather_than_hardcoded(): void
    {
        Setting::put('theme', ['homepage_template' => 'couture']);
        Setting::put('home_content', [
            'hero_trust' => ['Only this line'],
            'gift_finder_text' => 'Only this blurb.',
        ]);

        $this->get('/')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('heroTrust', ['Only this line'])
            ->where('giftFinder.text', 'Only this blurb.'));

        $home = file_get_contents(resource_path('js/Pages/Home.jsx'));
        $this->assertStringNotContainsString("'Cash on delivery', 'Pay when it arrives'", $home);
        $this->assertStringNotContainsString("'Pay on delivery', 'Anywhere in Bangladesh, via Steadfast.'", $home);
    }
}
