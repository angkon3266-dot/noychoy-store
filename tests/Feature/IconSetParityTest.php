<?php

namespace Tests\Feature;

use App\Support\StorefrontIcons;
use Tests\TestCase;

/**
 * The admin icon picker is a PHP list; the icons themselves are a JS object.
 * Nothing at runtime connects them, so an icon added on one side and not the
 * other would silently render an empty <svg>. This test is that connection.
 */
class IconSetParityTest extends TestCase
{
    public function test_php_picker_list_matches_the_js_icon_set(): void
    {
        $source = file_get_contents(resource_path('js/Shared/Icons.jsx'));

        preg_match('/const paths = \{(.*?)\n\};/s', $source, $m);
        $this->assertNotEmpty($m, 'Could not find the paths object in Icons.jsx');

        preg_match_all('/^    ([a-zA-Z]+): \'/m', $m[1], $names);

        $this->assertSame(
            $names[1],
            StorefrontIcons::names(),
            'StorefrontIcons::names() has drifted from resources/js/Shared/Icons.jsx'
        );
    }

    public function test_every_suggested_icon_actually_exists(): void
    {
        foreach (StorefrontIcons::suggested() as $name) {
            $this->assertContains($name, StorefrontIcons::names(), $name.' is suggested but not in the set');
        }
    }

    public function test_the_php_renderer_draws_exactly_the_same_icons_as_the_js_one(): void
    {
        // The Blade half of the storefront (the landing pages the ads point at)
        // renders icons from PHP, the React half from JS. Two copies of 34 SVG
        // paths WILL drift; this is the only thing stopping them.
        $source = file_get_contents(resource_path('js/Shared/Icons.jsx'));

        preg_match('/const paths = \{(.*?)
\};/s', $source, $m);
        // The trailing \s* matters: this file has CRLF endings, so a bare $
        // anchor never matches and the test would silently compare nothing at
        // all - passing while proving zero.
        preg_match_all("/^    ([a-zA-Z]+): '(.*)',\s*$/m", $m[1], $pairs, PREG_SET_ORDER);

        $js = [];
        foreach ($pairs as $pair) {
            $js[$pair[1]] = $pair[2];
        }

        $this->assertSame($js, StorefrontIcons::paths(), 'the PHP and JS icon paths have drifted');
    }

    public function test_a_known_name_renders_an_icon_and_not_its_own_name(): void
    {
        $svg = StorefrontIcons::svg('truck');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString('truck', $svg);
    }

    public function test_an_emoji_saved_before_the_picker_existed_still_shows(): void
    {
        // Older stores have glyphs in these fields. Hiding them would be worse
        // than rendering them as text.
        $this->assertStringContainsString('🚚', StorefrontIcons::svg('🚚'));
    }

    public function test_an_empty_value_falls_back_to_a_real_icon(): void
    {
        $this->assertStringContainsString('<svg', StorefrontIcons::svg(null));
    }
}
