<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The brand font stylesheet has to actually be there.
 *
 * `scripts/merge-font-faces.mjs` re-fingerprints the merged stylesheet after
 * every build and writes the new name into `fonts-manifest.json`. It wrote the
 * bare basename instead of the path relative to `public/build`, so
 * `brand_font_css_url()` emitted `/build/fonts-XXXX.css` while the file sat at
 * `/build/assets/fonts-XXXX.css`. Every storefront page 404'd its own fonts and
 * fell back to system faces — for two weeks, silently, because nothing checked.
 *
 * These run against the committed build output, which is what ships (there is
 * no npm step on the server), so a future build that breaks the path again
 * fails here instead of on the live site.
 */
class BrandFontAssetTest extends TestCase
{
    // The storefront check below renders a real page, which records a visit.
    use RefreshDatabase;

    public function test_the_stylesheet_url_points_at_a_file_that_exists(): void
    {
        $url = brand_font_css_url();

        if ($url === null) {
            $this->markTestSkipped('No fonts manifest in this checkout — nothing to verify.');
        }

        $path = public_path((string) parse_url($url, PHP_URL_PATH));

        $this->assertFileExists($path, "brand_font_css_url() points at {$url}, which is not in public/");
        $this->assertStringContainsString('@font-face', (string) file_get_contents($path));
    }

    public function test_the_manifest_records_a_path_relative_to_build_not_a_bare_name(): void
    {
        $manifest = public_path('build/fonts-manifest.json');

        if (! is_file($manifest)) {
            $this->markTestSkipped('No fonts manifest in this checkout.');
        }

        $file = json_decode((string) file_get_contents($manifest), true)['style']['file'] ?? null;

        $this->assertIsString($file);
        $this->assertFileExists(
            public_path('build/'.ltrim($file, '/')),
            'fonts-manifest.json → style.file must resolve from public/build. '
            .'A bare "fonts-XXXX.css" is the regression that served no fonts at all.',
        );
    }

    public function test_the_storefront_links_the_stylesheet_it_can_actually_serve(): void
    {
        $url = brand_font_css_url();

        if ($url === null) {
            $this->markTestSkipped('No fonts manifest in this checkout.');
        }

        $this->get('/')->assertOk()->assertSee($url, false);
    }
}
