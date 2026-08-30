<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The brand story page.
 *
 * It shipped as scaffolding — "Write the story behind the brand here" — and the
 * `about` key was never saved, so that instruction was live on the storefront as
 * the answer to "who are you?". It also rendered through Tailwind Typography's
 * `prose` classes, which emit nothing here because the plugin is not installed,
 * so headings and paragraphs were visually identical.
 */
class StoryPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_story_page_renders_its_own_component_not_the_legal_one(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Story'));
    }

    public function test_the_legal_pages_still_render_the_legal_component(): void
    {
        foreach (['/privacy-policy', '/terms-and-conditions', '/refund-policy'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Legal'));
        }
    }

    public function test_it_no_longer_tells_the_shopper_to_write_the_page(): void
    {
        $body = (string) page_content('about', 'body');

        $this->assertStringNotContainsString('Write the story behind the brand here', $body);
        $this->assertStringNotContainsString('Say what the jewelry is and who it is for', $body);
    }

    public function test_the_promise_strip_follows_the_stores_own_trust_badges(): void
    {
        Setting::put('theme', array_merge((array) Setting::get('theme', []), [
            'trust_badges' => [
                ['icon' => 'cash', 'title' => 'Cash on Delivery', 'text' => 'Pay when it arrives'],
                ['icon' => 'calendar', 'title' => '7 Days to Change Your Mind', 'text' => ''],
                // A badge the owner half-deleted must not render as an empty tile.
                ['icon' => 'tag', 'title' => '', 'text' => 'orphaned'],
            ],
        ]));

        $this->get('/about')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Story')
            ->has('promises', 2)
            ->where('promises.0.title', 'Cash on Delivery')
            ->where('promises.1.title', '7 Days to Change Your Mind'));
    }

    public function test_clearing_the_headline_restores_the_default_rather_than_leaving_it_blank(): void
    {
        // page_content() treats an empty field as "use the default", which is
        // how every other editable field on this site behaves. The header must
        // never render headless.
        Setting::put('pages', ['about' => ['title' => 'Our story', 'headline' => '']]);

        $this->get('/about')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('headline', config('pages.about.headline')));
    }

    public function test_the_headline_falls_back_to_the_title_when_there_is_no_default_either(): void
    {
        config(['pages.about.headline' => null]);
        Setting::put('pages', ['about' => ['title' => 'About us', 'headline' => '']]);

        $this->get('/about')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('headline', 'About us'));
    }

    public function test_a_crawler_gets_the_story_as_real_html(): void
    {
        // The page is React-rendered; without the pre-hydration shell the one
        // page that answers "are these people real?" reaches Google empty.
        $res = $this->get('/about')->assertOk();

        $res->assertSee('Why we started', false);
        $res->assertSee('cubic zirconia', false);
    }

    public function test_the_owner_can_rewrite_every_part_of_the_header(): void
    {
        Setting::put('pages', ['about' => [
            'title' => 'About us',
            'eyebrow' => 'Since 2024',
            'headline' => 'Made in Dhaka',
            'lede' => 'A short line.',
            'body' => '<p>Ours.</p>',
        ]]);

        $this->get('/about')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('eyebrow', 'Since 2024')
            ->where('headline', 'Made in Dhaka')
            ->where('lede', 'A short line.')
            ->where('body', '<p>Ours.</p>'));
    }

    public function test_saving_another_page_does_not_wipe_the_story_header_photo(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);

        Setting::put('pages', ['about' => ['title' => 'Our story', 'hero_image' => 'branding/hero.webp']]);

        // The photo is posted by <x-media-field>, outside the pages[] array, so
        // a save that does not touch it must carry it over rather than blank it.
        $this->actingAs($admin)->post('/admin/pages', [
            'pages' => [
                'about' => ['title' => 'Our story', 'body' => '<p>Hi.</p>'],
                'privacy' => ['title' => 'Privacy', 'body' => '<p>P.</p>'],
            ],
        ])->assertRedirect();

        $this->assertSame('branding/hero.webp', page_content('about', 'hero_image'));
    }

    public function test_the_header_photo_can_still_be_removed_on_purpose(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);

        Setting::put('pages', ['about' => ['title' => 'Our story', 'hero_image' => 'branding/hero.webp']]);

        $this->actingAs($admin)->post('/admin/pages', [
            'pages' => ['about' => ['title' => 'Our story', 'body' => '<p>Hi.</p>']],
            'about_hero_image_cleared' => 1,
        ])->assertRedirect();

        $this->assertNull(page_content('about', 'hero_image'));
    }

    public function test_the_eyebrow_defaults_to_the_shops_own_name(): void
    {
        // config/pages.php is shared by every store on this codebase, so it must
        // not hard-code one brand's name above the headline.
        $this->assertNull(config('pages.about.eyebrow'));

        $this->get('/about')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('eyebrow', store_name()));
    }
}
