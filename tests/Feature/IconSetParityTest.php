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
}
