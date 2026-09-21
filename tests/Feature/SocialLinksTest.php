<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\SocialLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The shop's social accounts: TikTok and YouTube join Facebook and Instagram
 * (owner, 22 Sep 2026), set under Appearance → Footer and shown as icons in
 * the footer and the phone menu of both storefronts, and in the schema.
 */
class SocialLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'socials@admin.test'],
            ['name' => 'Owner', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    protected function saveAppearance(array $fields)
    {
        return $this->actingAs($this->admin())->from(route('admin.appearance'))
            ->post(route('admin.appearance.update'), array_merge([
                'homepage_template' => theme('homepage_template') ?: 'storefront',
            ], $fields));
    }

    public function test_a_handle_or_an_address_without_https_becomes_the_profile_link(): void
    {
        $cases = [
            ['tiktok', 'https://www.tiktok.com/@noychoy', 'https://www.tiktok.com/@noychoy'],
            ['tiktok', '@noychoy', 'https://www.tiktok.com/@noychoy'],
            ['tiktok', 'noychoy', 'https://www.tiktok.com/@noychoy'],
            ['tiktok', 'tiktok.com/@noychoy', 'https://tiktok.com/@noychoy'],
            ['tiktok', 'vm.tiktok.com/ZSabc123/', 'https://vm.tiktok.com/ZSabc123/'],
            ['youtube', '@noychoy', 'https://www.youtube.com/@noychoy'],
            ['youtube', 'www.youtube.com/@noychoy', 'https://www.youtube.com/@noychoy'],
            ['youtube', 'youtube.com/channel/UC123', 'https://youtube.com/channel/UC123'],
            ['instagram', '@_noychoy_', 'https://www.instagram.com/_noychoy_'],
            ['instagram', 'noy.choy', 'https://www.instagram.com/noy.choy'],
            ['facebook', 'Noychoylove', 'https://www.facebook.com/Noychoylove'],
            ['facebook', 'm.facebook.com/Noychoylove', 'https://m.facebook.com/Noychoylove'],
            ['facebook', '  ', null],
        ];

        foreach ($cases as [$platform, $typed, $expected]) {
            $this->assertSame($expected, SocialLinks::normalise($typed, $platform), "{$platform}: {$typed}");
        }

        // Not an address at all: handed back as typed, for the check to refuse.
        $this->assertSame('javascript:alert(1)', SocialLinks::normalise('javascript:alert(1)', 'tiktok'));
        $this->assertFalse(SocialLinks::isWebLink('javascript:alert(1)'));
        $this->assertFalse(SocialLinks::isWebLink('/tiktok'));
    }

    public function test_the_owner_adds_tiktok_and_youtube_from_appearance(): void
    {
        $this->saveAppearance([
            'footer_facebook' => 'https://www.facebook.com/Noychoylove',
            'footer_instagram' => 'https://www.instagram.com/_noychoy_/',
            'footer_tiktok' => '@noychoy',
            'footer_youtube' => 'youtube.com/@noychoy',
        ])->assertSessionHasNoErrors();

        // Stored as the address, so the box shows what gets linked.
        $this->assertSame('https://www.tiktok.com/@noychoy', theme('footer_tiktok'));
        $this->assertSame('https://youtube.com/@noychoy', theme('footer_youtube'));

        $this->get('/shop')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('chrome.footer.socials', [
                ['platform' => 'facebook', 'label' => 'Facebook', 'url' => 'https://www.facebook.com/Noychoylove'],
                ['platform' => 'instagram', 'label' => 'Instagram', 'url' => 'https://www.instagram.com/_noychoy_/'],
                ['platform' => 'tiktok', 'label' => 'TikTok', 'url' => 'https://www.tiktok.com/@noychoy'],
                ['platform' => 'youtube', 'label' => 'YouTube', 'url' => 'https://youtube.com/@noychoy'],
            ]));

        // And the admin form shows the four boxes with what was saved.
        $this->actingAs($this->admin())->get(route('admin.appearance'))->assertOk()
            ->assertSee('name="footer_tiktok"', false)
            ->assertSee('value="https://www.tiktok.com/@noychoy"', false)
            ->assertSee('name="footer_youtube"', false)
            ->assertSee('YouTube channel');
    }

    public function test_an_empty_box_hides_its_icon(): void
    {
        Setting::put('theme', ['footer_tiktok' => 'https://www.tiktok.com/@noychoy', 'footer_youtube' => 'https://www.youtube.com/@noychoy']);

        $this->saveAppearance(['footer_youtube' => ''])->assertSessionHasNoErrors();

        $this->assertNull(theme('footer_youtube'));
        $this->assertSame(['tiktok'], array_column(SocialLinks::present(), 'platform'));
    }

    public function test_something_that_is_not_a_web_address_is_refused_and_never_linked(): void
    {
        Setting::put('theme', ['footer_tiktok' => 'https://www.tiktok.com/@noychoy']);

        $this->saveAppearance(['footer_tiktok' => 'javascript:alert(1)'])
            ->assertSessionHasErrors(['footer_tiktok' => 'The TikTok link must be a web address, like https://www.tiktok.com/@yourshop']);
        $this->assertSame('https://www.tiktok.com/@noychoy', theme('footer_tiktok'), 'the saved link was overwritten');

        // A bad value that is already stored is left off the page.
        Setting::put('theme', ['footer_youtube' => 'javascript:alert(1)', 'footer_tiktok' => 'https://www.tiktok.com/@noychoy']);
        $this->assertSame(['tiktok'], array_column(SocialLinks::present(), 'platform'));
    }

    public function test_the_blade_storefront_and_the_schema_carry_the_new_accounts(): void
    {
        // A Blade home template renders through layouts/shop — footer and
        // phone menu both draw the icons there.
        Setting::put('theme', [
            'homepage_template' => 'meridian',
            'footer_tiktok' => 'https://www.tiktok.com/@noychoy',
            'footer_youtube' => 'https://www.youtube.com/@noychoy',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'href="https://www.tiktok.com/@noychoy"'), 'footer + phone menu');
        $this->assertSame(2, substr_count($html, 'href="https://www.youtube.com/@noychoy"'), 'footer + phone menu');
        $this->assertStringContainsString('aria-label="TikTok"', $html);
        $this->assertStringContainsString('aria-label="YouTube"', $html);
        $this->assertStringContainsString(SocialLinks::PLATFORMS['youtube']['icon'], $html);

        // The Organization schema names every account it links.
        $this->assertStringContainsString('"sameAs":["https://www.tiktok.com/@noychoy","https://www.youtube.com/@noychoy"]', $html);
    }

    public function test_every_platform_draws_the_same_icon_on_the_react_side(): void
    {
        // The React pages draw the icon from Icons.jsx, the Blade pages from
        // SocialLinks::PLATFORMS — two copies of each path, kept honest here.
        $source = file_get_contents(resource_path('js/Shared/Icons.jsx'));

        preg_match('/export const SOCIAL_ICONS = \{(.*?)\};/s', $source, $m);
        $this->assertNotEmpty($m, 'Could not find SOCIAL_ICONS in Icons.jsx');
        preg_match_all('/(\w+): (\w+)/', $m[1], $pairs, PREG_SET_ORDER);
        $map = array_column($pairs, 2, 1);

        $this->assertSame(array_keys(SocialLinks::PLATFORMS), array_keys($map), 'SOCIAL_ICONS has drifted from SocialLinks::PLATFORMS');

        foreach ($map as $platform => $component) {
            preg_match('/export function '.$component.'\(.*?<path d="([^"]+)"/s', $source, $path);
            $this->assertSame(SocialLinks::PLATFORMS[$platform]['icon'], $path[1] ?? null, "the {$platform} icon differs between PHP and JS");
        }
    }
}
