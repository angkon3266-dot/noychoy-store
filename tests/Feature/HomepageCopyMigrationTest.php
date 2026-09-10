<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The homepage copy migrations run against an empty settings table in CI, so
 * they are exercised here against the stored values they exist to rewrite.
 */
class HomepageCopyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(string $file): void
    {
        (require database_path('migrations/'.$file))->up();
        Setting::flushMemo();
    }

    public function test_a_stored_gift_message_line_is_dropped_and_the_rest_kept(): void
    {
        Setting::put('home_content', ['hero_trust' => [
            'Cash on delivery',
            'Gift message included',
            'Delivered by Steadfast',
        ]]);

        $this->runMigration('2026_09_10_100200_drop_gift_message_line_from_stored_hero_trust.php');

        $this->assertSame(['Cash on delivery', 'Delivered by Steadfast'], home_content('hero_trust'));
    }

    public function test_a_setting_without_the_line_is_left_alone(): void
    {
        Setting::put('home_content', ['hero_heading' => 'Our own heading']);

        $this->runMigration('2026_09_10_100200_drop_gift_message_line_from_stored_hero_trust.php');

        $this->assertSame(['hero_heading' => 'Our own heading'], Setting::get('home_content'));
        // No stored line, so the storefront shows the new shipped default.
        $this->assertSame(['Cash on delivery'], home_content('hero_trust'));
    }
}
