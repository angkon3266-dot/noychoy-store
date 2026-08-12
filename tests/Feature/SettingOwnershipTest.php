<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Meta\MetaSettings;
use App\Services\SystemConfig\ConfigSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One setting, one owner.
 *
 * The Meta Pixel ID used to be editable in two unrelated places — Appearance →
 * Marketing and Meta Integration — backed by two different stores, with
 * meta_pixel_id() silently preferring one. This install ended up with different
 * numbers in each: the admin read one Pixel ID on screen while a completely
 * different one fired on the storefront, with nothing anywhere hinting at the
 * disagreement.
 *
 * A setting with two editors has no single answer to "what is it set to", so
 * these tests pin down who owns what.
 */
class SettingOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'a@b.test'],
            ['name' => 'Admin', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function settings(): MetaSettings
    {
        return app(MetaSettings::class);
    }

    // ── The Pixel ID has exactly one home ────────────────────────────────────

    public function test_the_pixel_id_comes_from_meta_integration(): void
    {
        $this->settings()->update(['pixel_id' => '1822284545830766']);

        $this->assertSame('1822284545830766', meta_pixel_id());
    }

    public function test_a_leftover_appearance_pixel_can_no_longer_influence_anything(): void
    {
        // The exact shape of the bug: a stale value in the old location must
        // not resurface as the live Pixel just because the real one is unset.
        Setting::put('theme', ['meta_pixel_id' => '1270277588337464']);

        $this->assertNull(meta_pixel_id());
    }

    public function test_appearance_no_longer_offers_a_pixel_field(): void
    {
        $this->actingAs($this->admin())->get('/admin/appearance')
            ->assertOk()
            ->assertDontSee('name="meta_pixel_id"', false);
    }

    public function test_saving_appearance_cannot_write_a_pixel_id(): void
    {
        $this->settings()->update(['pixel_id' => '1822284545830766']);

        $this->actingAs($this->admin())->post('/admin/appearance', [
            'homepage_template' => 'couture',
            'product_template' => 'showcase',
            'meta_pixel_id' => '9999999999',
        ]);

        $this->assertArrayNotHasKey('meta_pixel_id', Setting::get('theme', []));
        $this->assertSame('1822284545830766', meta_pixel_id());
    }

    public function test_saving_meta_settings_does_not_blank_the_pixel(): void
    {
        // The Settings form no longer carries the Pixel, so the old
        // `'pixel_id' => $data['pixel_id'] ?? null` would have wiped it on
        // every save. It must survive a save it wasn't part of.
        $this->settings()->update(['pixel_id' => '1822284545830766', 'mode' => MetaSettings::MODE_DEVELOPMENT]);

        $this->actingAs($this->admin())->post('/admin/meta/save', [
            'mode' => 'development',
            'business_id' => '123',
            'catalog_id' => '456',
        ]);

        $this->assertSame('1822284545830766', $this->settings()->pixelId());
    }

    // ── SEO settings that used to be ignored ─────────────────────────────────

    public function test_robots_txt_asks_crawlers_away_when_set_to_noindex(): void
    {
        config(['seo.robots' => 'noindex']);

        $body = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString("Disallow: /\n", $body);
        // Nothing to advertise on a site asking not to be indexed.
        $this->assertStringNotContainsString('Sitemap:', $body);
    }

    public function test_robots_txt_allows_crawling_by_default(): void
    {
        config(['seo.robots' => 'index']);

        $body = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString('Disallow: /admin', $body);
        $this->assertStringNotContainsString("Disallow: /\n", $body);
        $this->assertStringContainsString('Sitemap:', $body);
    }

    public function test_disabling_the_sitemap_actually_disables_it(): void
    {
        config(['seo.sitemap_enabled' => false]);

        $this->get('/sitemap.xml')->assertNotFound();
        $this->assertStringNotContainsString('Sitemap:', $this->get('/robots.txt')->getContent());
    }

    public function test_the_sitemap_serves_when_enabled(): void
    {
        config(['seo.sitemap_enabled' => true]);

        $this->get('/sitemap.xml')->assertOk();
    }

    public function test_system_config_no_longer_duplicates_the_seo_meta_fields(): void
    {
        // Appearance → Homepage content owns the meta title/description.
        $keys = collect(app(ConfigSchema::class)->sections()['seo']['fields'])
            ->pluck('key')->all();

        $this->assertNotContains('seo.default_title', $keys);
        $this->assertNotContains('seo.default_description', $keys);
    }
}
