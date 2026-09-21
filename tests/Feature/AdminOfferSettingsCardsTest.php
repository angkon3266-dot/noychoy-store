<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four settings cards above the offer list (reward ladder, register
 * offer, loyalty, birthdays) fold to a title and a status line, and start
 * folded — open, they ran several screens before the list (owner, 22 Sep
 * 2026). A card opens by itself after its own form saves or fails, so a
 * saved setting or a turned-down one is never hidden behind a fold.
 */
class AdminOfferSettingsCardsTest extends TestCase
{
    use RefreshDatabase;

    protected const CARDS = ['ladder', 'register-offer', 'loyalty', 'occasions'];

    protected function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'cards@admin.test'],
            ['name' => 'Owner', 'password' => bcrypt('secret'), 'role' => 'admin'],
        );
    }

    /** Is the card with this id rendered open? */
    protected function isOpen(string $html, string $id): bool
    {
        $this->assertMatchesRegularExpression('/<section id="'.$id.'"[^>]*x-data="\{ open: (true|false) /', $html, "no {$id} card");
        preg_match('/<section id="'.$id.'"[^>]*x-data="\{ open: (true|false) /', $html, $m);

        return $m[1] === 'true';
    }

    public function test_every_settings_card_starts_folded_with_its_status_in_the_title_row(): void
    {
        Setting::put('gift_ladder_enabled', true);
        Setting::put('gift_ladder_tiers', [
            ['threshold' => 1, 'type' => 'flat', 'value' => 30],
            ['threshold' => 2, 'type' => 'flat', 'value' => 50],
        ]);
        Setting::put('register_offer_percent', 0);

        $html = $this->actingAs($this->admin())->get(route('admin.offers.index'))->assertOk()->getContent();

        foreach (self::CARDS as $id) {
            $this->assertFalse($this->isOpen($html, $id), "{$id} should start folded");
        }

        // What a folded card still says: whether it is on, and how much.
        $ladder = substr($html, strpos($html, 'id="ladder"'), 2500);
        $this->assertStringContainsString('Live', $ladder);
        $this->assertStringContainsString('2 rungs', $ladder);
        $register = substr($html, strpos($html, 'id="register-offer"'), 1500);
        $this->assertStringContainsString('>Off<', $register);

        // The offer list itself is not folded away.
        $this->assertStringContainsString('New offer', $html);
    }

    public function test_the_card_just_saved_stays_open(): void
    {
        $html = $this->actingAs($this->admin())
            ->from(route('admin.offers.index'))
            ->followingRedirects()
            ->post(route('admin.offers.loyalty'), [
                '_card' => 'loyalty', 'enabled' => 1,
                'per_1000' => 10, 'value_per_100' => 5, 'review' => 200, 'signup' => 0, 'photo_bonus' => 100, 'referral' => 300,
            ])->assertOk()->getContent();

        $this->assertTrue($this->isOpen($html, 'loyalty'));
        $this->assertFalse($this->isOpen($html, 'ladder'));
        $this->assertFalse($this->isOpen($html, 'occasions'));
    }

    public function test_a_turned_down_save_opens_its_own_card_with_the_error_inside(): void
    {
        $html = $this->actingAs($this->admin())
            ->from(route('admin.offers.index'))
            ->followingRedirects()
            ->post(route('admin.offers.occasions'), [
                '_card' => 'occasions', 'enabled' => 1,
                'reminder_days' => '', 'offer_percent' => 10, 'offer_days' => 7,
            ])->assertOk()->getContent();

        $this->assertTrue($this->isOpen($html, 'occasions'));

        // Shown once, inside the birthday card — no longer in the New offer
        // form, which is where every settings error used to land.
        $error = 'reminder days field is required';
        $this->assertSame(1, substr_count($html, $error));
        $at = strpos($html, $error);
        $this->assertGreaterThan(strpos($html, 'id="occasions"'), $at);
        $this->assertLessThan(strpos($html, 'New offer'), $at);
    }

    public function test_a_link_can_open_a_card_by_its_address(): void
    {
        // The customers' occasions page sends "Settings →" to the birthday card.
        $this->actingAs($this->admin())->get(route('admin.customers.occasions'))
            ->assertOk()
            ->assertSee(route('admin.offers.index').'#occasions', false);

        $html = $this->actingAs($this->admin())->get(route('admin.offers.index'))->getContent();
        $this->assertStringContainsString("window.location.hash === '#occasions'", $html);
    }
}
