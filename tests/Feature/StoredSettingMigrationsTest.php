<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\StorefrontIcons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Both migrations run against an empty settings table in CI, so they are
 * exercised here against realistic stored values instead — the live store's
 * emoji and its literal free-delivery banner are the whole reason they exist.
 */
class StoredSettingMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(string $file): void
    {
        (require database_path('migrations/'.$file))->up();
        Setting::flushMemo();
    }

    public function test_stored_emoji_icons_become_icon_names(): void
    {
        Setting::put('theme', ['trust_badges' => [
            ['icon' => '💵', 'title' => 'Cash on delivery'],
            ['icon' => '🚚', 'title' => 'Fast nationwide'],
            ['icon' => '✨', 'title' => 'Quality assured'],
        ]]);
        Setting::put('home_content', ['feature_strip' => [
            ['icon' => '🚚', 'title' => 'Shipping'],
            ['icon' => '↩️', 'title' => 'Returns'],
            ['icon' => '💎', 'title' => 'Quality'],
            ['icon' => '🎧', 'title' => 'Support'],
        ]]);

        $this->runMigration('2026_08_22_140000_map_stored_emoji_icons_to_icon_names.php');

        foreach (array_merge(theme('trust_badges'), home_content('feature_strip')) as $row) {
            $this->assertContains($row['icon'], StorefrontIcons::names(), $row['title'].' was not remapped');
        }
    }

    public function test_an_unrecognised_glyph_is_left_alone_rather_than_guessed(): void
    {
        Setting::put('theme', ['trust_badges' => [['icon' => '🦄', 'title' => 'Unusual']]]);

        $this->runMigration('2026_08_22_140000_map_stored_emoji_icons_to_icon_names.php');

        $this->assertSame('🦄', theme('trust_badges')[0]['icon']);
    }

    public function test_a_stored_free_delivery_banner_becomes_self_resolving(): void
    {
        Setting::put('theme', ['announcement_messages' => [
            'Free delivery on orders over ৳3000',
            'Cash on delivery available all over Bangladesh',
        ]]);

        $this->runMigration('2026_08_22_140100_make_stored_free_delivery_banner_self_resolving.php');

        $this->assertSame([
            'Free delivery on orders over {free_delivery}',
            'Cash on delivery available all over Bangladesh',
        ], theme('announcement_messages'));

        // And it now tracks the setting rather than the typed number.
        Setting::put('free_shipping_threshold', 5000);
        $this->assertSame('Free delivery on orders over '.money(5000), announcement_messages()[0]);
    }

    public function test_a_message_with_two_amounts_is_left_alone(): void
    {
        $original = 'Free delivery over ৳3000, or ৳500 off over ৳10000';
        Setting::put('theme', ['announcement_messages' => [$original]]);

        $this->runMigration('2026_08_22_140100_make_stored_free_delivery_banner_self_resolving.php');

        $this->assertSame($original, theme('announcement_messages')[0]);
    }

    public function test_the_migration_does_not_decide_whether_free_delivery_is_on(): void
    {
        config(['store.shipping.free_threshold' => null]);
        Setting::put('free_shipping_threshold', null);
        Setting::put('theme', ['announcement_messages' => ['Free delivery on orders over ৳3000']]);

        $this->runMigration('2026_08_22_140100_make_stored_free_delivery_banner_self_resolving.php');

        // The promise is still off — the banner just stops advertising it
        // instead of the migration switching it on behind the owner's back.
        $this->assertNull(free_shipping_threshold());
        $this->assertSame([], announcement_messages());
    }
}
