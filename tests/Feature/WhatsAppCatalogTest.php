<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\WhatsAppCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Browse our catalogue on WhatsApp" (Nov 2026): wa.me/c/<number>, shown only
 * once the owner says the Meta catalogue is connected — before that WhatsApp
 * answers "Invalid catalog link".
 */
class WhatsAppCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function theme(array $values): void
    {
        $theme = array_merge((array) Setting::get('theme', []), $values);
        Setting::put('theme', $theme);
        Setting::flushMemo();
    }

    public function test_hidden_until_the_owner_says_it_is_connected(): void
    {
        $this->theme(['whatsapp_number' => '+880 1712-345678']);
        $this->assertNull(WhatsAppCatalog::url());

        $this->get(route('page.contact'))->assertInertia(fn (Assert $page) => $page
            ->where('details.whatsappCatalog', null)
            ->where('chrome.footer.whatsappCatalog', null));
    }

    public function test_shown_with_the_number_as_digits_once_connected(): void
    {
        $this->theme(['whatsapp_number' => '+880 1712-345678', 'whatsapp_catalog' => true]);

        $this->assertSame('https://wa.me/c/8801712345678', WhatsAppCatalog::url());
        $this->get(route('page.contact'))->assertInertia(fn (Assert $page) => $page
            ->where('details.whatsappCatalog', 'https://wa.me/c/8801712345678')
            ->where('chrome.footer.whatsappCatalog', 'https://wa.me/c/8801712345678'));
    }

    public function test_the_owner_switches_it_on_in_appearance(): void
    {
        $admin = \App\Models\User::create(['name' => 'A', 'email' => 'wa@b.test', 'password' => bcrypt('x'), 'role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.appearance'))->assertOk()->assertSee('My WhatsApp catalogue is connected');

        $this->actingAs($admin)->from(route('admin.appearance'))->post(route('admin.appearance.update'), [
            'homepage_template' => theme('homepage_template') ?: 'storefront',
            'whatsapp_number' => '8801712345678',
            'whatsapp_catalog' => '1',
        ])->assertSessionHasNoErrors();

        Setting::flushMemo();
        $this->assertTrue((bool) theme('whatsapp_catalog'));
        $this->assertSame('https://wa.me/c/8801712345678', WhatsAppCatalog::url());
    }

    public function test_no_number_means_no_link_even_when_ticked(): void
    {
        $this->theme(['whatsapp_number' => null, 'whatsapp_catalog' => true]);

        $this->assertNull(WhatsAppCatalog::url());
    }
}
