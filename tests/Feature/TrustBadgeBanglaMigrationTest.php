<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Bangla trust-badge migration runs against an empty settings table in
 * CI, so it is exercised here against the stored English defaults it exists
 * to rewrite.
 */
class TrustBadgeBanglaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_09_10_100300_translate_stored_trust_badges_to_bangla.php';

    /** The five rows the live store saved before the wording changed. */
    private const OLD_DEFAULTS = [
        ['icon' => 'cash', 'title' => 'Cash on Delivery', 'text' => 'Pay when it arrives'],
        ['icon' => 'truck', 'title' => 'Fast Delivery Across Bangladesh', 'text' => ''],
        ['icon' => 'tag', 'title' => 'Fair Pricing', 'text' => 'No 10x markups'],
        ['icon' => 'shieldCheck', 'title' => 'Authentic Quality, Guaranteed', 'text' => ''],
        ['icon' => 'calendar', 'title' => '7 Days to Change Your Mind', 'text' => ''],
    ];

    private function runMigration(string $file): void
    {
        (require database_path('migrations/'.$file))->up();
        Setting::flushMemo();
    }

    public function test_the_stored_english_defaults_become_the_four_bangla_rows(): void
    {
        Setting::put('theme', ['primary' => '#123456', 'trust_badges' => self::OLD_DEFAULTS]);

        $this->runMigration(self::MIGRATION);

        $this->assertSame([
            ['icon' => 'cash', 'title' => 'ক্যাশ অন ডেলিভারি', 'text' => 'পণ্য হাতে পেয়ে টাকা দিন'],
            ['icon' => 'truck', 'title' => 'সারা বাংলাদেশে দ্রুত ডেলিভারি', 'text' => ''],
            ['icon' => 'tag', 'title' => 'ন্যায্য দাম', 'text' => 'কোনো বাড়তি মার্কআপ নেই'],
            ['icon' => 'shieldCheck', 'title' => 'অথেনটিক কোয়ালিটি গ্যারান্টি', 'text' => 'প্রতিটি পিস হাতে চেক করে পাঠানো হয়'],
        ], theme('trust_badges'));

        // The rest of the saved theme rides along untouched.
        $this->assertSame('#123456', theme('primary'));
    }

    public function test_an_owner_written_badge_is_left_alone_and_keeps_its_place(): void
    {
        $custom = ['icon' => 'gift', 'title' => 'Free gift wrap', 'text' => 'On request'];

        Setting::put('theme', ['trust_badges' => [
            self::OLD_DEFAULTS[0],
            $custom,
            self::OLD_DEFAULTS[4],   // the 7-day badge, about to go
            self::OLD_DEFAULTS[2],
        ]]);

        $this->runMigration(self::MIGRATION);

        $badges = theme('trust_badges');

        $this->assertSame([0, 1, 2], array_keys($badges));
        $this->assertSame('ক্যাশ অন ডেলিভারি', $badges[0]['title']);
        $this->assertSame($custom, $badges[1]);
        $this->assertSame('ন্যায্য দাম', $badges[2]['title']);
    }

    public function test_a_re_picked_icon_survives_and_a_missing_one_gets_the_default(): void
    {
        Setting::put('theme', ['trust_badges' => [
            ['icon' => 'diamond', 'title' => ' fair   pricing ', 'text' => 'No 10x markups'],
            ['icon' => '', 'title' => 'Cash on Delivery', 'text' => 'Pay when it arrives'],
        ]]);

        $this->runMigration(self::MIGRATION);

        $this->assertSame('diamond', theme('trust_badges')[0]['icon']);
        $this->assertSame('ন্যায্য দাম', theme('trust_badges')[0]['title']);
        $this->assertSame('cash', theme('trust_badges')[1]['icon']);
    }

    public function test_a_store_with_no_saved_theme_is_left_untouched(): void
    {
        $this->runMigration(self::MIGRATION);

        $this->assertNull(Setting::get('theme'));
        $this->assertDatabaseMissing('settings', ['key' => 'theme']);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        Setting::put('theme', ['trust_badges' => self::OLD_DEFAULTS]);

        $this->runMigration(self::MIGRATION);
        $once = theme('trust_badges');

        $this->runMigration(self::MIGRATION);

        $this->assertSame($once, theme('trust_badges'));
    }
}
