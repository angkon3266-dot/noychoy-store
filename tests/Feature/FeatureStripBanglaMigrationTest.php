<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Companion to TrustBadgeBanglaMigrationTest. The migration runs against an
 * empty settings table in CI, so it is exercised here against the stored
 * English defaults it exists to rewrite.
 */
class FeatureStripBanglaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_09_10_120000_translate_stored_feature_strip_to_bangla.php';

    /** The four rows the live store saved before the wording changed. */
    private const OLD_DEFAULTS = [
        ['icon' => 'truck', 'title' => 'Fastest Shipping Countrywide'],
        ['icon' => 'check', 'title' => 'Easy Return Policy'],
        ['icon' => 'diamond', 'title' => 'Premium Quality Product'],
        ['icon' => 'chat', 'title' => 'Online Support 24/7'],
    ];

    private function runMigration(): void
    {
        (require database_path('migrations/'.self::MIGRATION))->up();
        Setting::flushMemo();
    }

    public function test_the_stored_english_defaults_become_bangla(): void
    {
        Setting::put('home_content', ['hero_heading' => 'Untouched', 'feature_strip' => self::OLD_DEFAULTS]);

        $this->runMigration();

        $this->assertSame([
            ['icon' => 'truck', 'title' => 'সারা দেশে দ্রুত ডেলিভারি'],
            ['icon' => 'check', 'title' => 'সহজ রিটার্ন পলিসি'],
            ['icon' => 'diamond', 'title' => 'প্রিমিয়াম কোয়ালিটি পণ্য'],
            ['icon' => 'chat', 'title' => '২৪/৭ অনলাইন সাপোর্ট'],
        ], home_content('feature_strip'));
    }

    public function test_it_leaves_the_rest_of_the_homepage_content_alone(): void
    {
        Setting::put('home_content', ['hero_heading' => 'Untouched', 'feature_strip' => self::OLD_DEFAULTS]);

        $this->runMigration();

        $this->assertSame('Untouched', home_content('hero_heading'));
    }

    public function test_wording_the_owner_wrote_themselves_is_kept(): void
    {
        Setting::put('home_content', ['feature_strip' => [
            ['icon' => 'truck', 'title' => 'Fastest Shipping Countrywide'],
            ['icon' => 'gift', 'title' => 'Free gift wrap on every order'],
        ]]);

        $this->runMigration();

        $this->assertSame([
            ['icon' => 'truck', 'title' => 'সারা দেশে দ্রুত ডেলিভারি'],
            ['icon' => 'gift', 'title' => 'Free gift wrap on every order'],
        ], home_content('feature_strip'));
    }

    public function test_casing_and_stray_spacing_still_match_a_retired_default(): void
    {
        Setting::put('home_content', ['feature_strip' => [
            ['icon' => 'check', 'title' => '  easy   RETURN policy '],
        ]]);

        $this->runMigration();

        $this->assertSame('সহজ রিটার্ন পলিসি', home_content('feature_strip')[0]['title']);
    }

    public function test_nothing_stored_means_nothing_to_do(): void
    {
        $this->runMigration();

        $this->assertNull(Setting::get('home_content'));
    }
}
