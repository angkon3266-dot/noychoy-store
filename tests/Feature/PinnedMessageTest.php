<?php

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Setting;
use App\Models\User;
use App\Support\PinnedMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Appearance → Pinned message (owner, 2026-09-19): "any message I can pin
 * sitewide at the top that will float — like stock clearance sale, flat 30%
 * discount on all products".
 */
class PinnedMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function pin(array $values = []): void
    {
        Setting::put('theme', array_merge((array) Setting::get('theme', []), [
            'pinned_enabled' => true,
            'pinned_text' => 'Stock clearance sale — flat 30% off on all products',
        ], $values));
    }

    public function test_nothing_is_pinned_until_the_owner_writes_one(): void
    {
        $this->assertNull(PinnedMessage::current());

        $this->pin(['pinned_enabled' => false]);
        $this->assertNull(PinnedMessage::current(), 'written but switched off');

        $this->pin(['pinned_text' => '   ']);
        $this->assertNull(PinnedMessage::current(), 'switched on with nothing to say');
    }

    public function test_a_pinned_message_reaches_every_react_page(): void
    {
        $this->pin(['pinned_link' => '/shop', 'pinned_link_label' => 'Shop now']);

        $this->get('/shop')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('chrome.pinned.text', 'Stock clearance sale — flat 30% off on all products')
            ->where('chrome.pinned.link', '/shop')
            ->where('chrome.pinned.linkLabel', 'Shop now')
            ->where('chrome.pinned.bg', PinnedMessage::DEFAULT_BG)
            ->where('chrome.pinned.dismissible', false));
    }

    public function test_it_comes_down_by_itself_at_the_time_set(): void
    {
        // Bangladesh time, as the owner typed it into the field.
        $this->pin(['pinned_until' => now('Asia/Dhaka')->addHour()->format('Y-m-d\TH:i')]);
        $live = PinnedMessage::current();
        $this->assertNotNull($live);
        // Sent with its offset, so the browser can hide it on a cached page too.
        $this->assertMatchesRegularExpression('/\+06:00$/', $live['until']);

        $this->pin(['pinned_until' => now('Asia/Dhaka')->subMinute()->format('Y-m-d\TH:i')]);
        $this->assertNull(PinnedMessage::current());
    }

    public function test_only_a_real_colour_reaches_the_style_attribute(): void
    {
        $this->pin(['pinned_bg' => 'red;background:url(x)', 'pinned_color' => '#0a0']);

        $this->assertSame(PinnedMessage::DEFAULT_BG, PinnedMessage::current()['bg']);
        $this->assertSame('#0a0', PinnedMessage::current()['color']);
    }

    public function test_a_new_message_is_shown_again_to_someone_who_closed_the_last(): void
    {
        $this->pin(['pinned_dismissible' => true]);
        $first = PinnedMessage::current()['id'];

        $this->pin(['pinned_text' => 'Eid sale ends Sunday']);

        $this->assertNotSame($first, PinnedMessage::current()['id']);
    }

    public function test_the_blade_pages_carry_it_inside_the_sticky_header(): void
    {
        $this->pin();
        $page = LandingPage::create(['title' => 'Eid', 'slug' => 'eid', 'is_published' => true, 'show_header' => true, 'show_footer' => true, 'blocks' => []]);

        $html = $this->get('/lp/'.$page->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-pinned-message', $html);
        $this->assertStringContainsString('Stock clearance sale — flat 30% off on all products', $html);
        $header = strpos($html, '<header class="sticky top-0');
        $this->assertNotFalse($header);
        $this->assertGreaterThan($header, strpos($html, 'data-pinned-message'), 'inside the sticky header, so it floats');
    }

    // ── Appearance ───────────────────────────────────────────────────────────

    protected function admin(): User
    {
        return User::firstOrCreate(['email' => 'owner@pinned.test'], ['name' => 'Owner', 'password' => bcrypt('x'), 'role' => 'admin']);
    }

    /** The whole Appearance form posts at once; only the fields under test vary. */
    protected function saveAppearance(array $fields)
    {
        return $this->actingAs($this->admin())->from(route('admin.appearance'))
            ->post(route('admin.appearance.update'), array_merge([
                'homepage_template' => theme('homepage_template') ?: 'storefront',
            ], $fields));
    }

    public function test_the_owner_pins_a_message_from_appearance(): void
    {
        $this->saveAppearance([
            'pinned_enabled' => '1',
            'pinned_text' => 'Flat 30% off everything',
            'pinned_link' => '/shop',
            'pinned_link_label' => 'Shop the sale',
            'pinned_bg' => '#111111',
            'pinned_color' => '#ffcc00',
            'pinned_until' => '2099-01-01T20:00',
            'pinned_dismissible' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $pinned = PinnedMessage::current();
        $this->assertSame('Flat 30% off everything', $pinned['text']);
        $this->assertSame('/shop', $pinned['link']);
        $this->assertSame('#111111', $pinned['bg']);
        $this->assertSame('#ffcc00', $pinned['color']);
        $this->assertTrue($pinned['dismissible']);
        $this->assertStringStartsWith('2099-01-01T20:00:00', $pinned['until']);

        // And switching it off takes it down.
        $this->saveAppearance(['pinned_text' => 'Flat 30% off everything'])->assertSessionHasNoErrors();
        $this->assertNull(PinnedMessage::current());
    }

    public function test_a_link_that_is_not_a_page_or_a_web_address_is_refused(): void
    {
        foreach (['javascript:alert(1)', '//evil.example', 'shop'] as $link) {
            $this->saveAppearance(['pinned_enabled' => '1', 'pinned_text' => 'Sale', 'pinned_link' => $link])
                ->assertSessionHasErrors('pinned_link');
        }
    }

    public function test_switching_it_on_needs_the_words(): void
    {
        $this->saveAppearance(['pinned_enabled' => '1', 'pinned_text' => ''])
            ->assertSessionHasErrors('pinned_text');
    }
}
