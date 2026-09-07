<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Support\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A Bangla speaker should be greeted in Bangla everywhere, not only inside
 * the chat. These pin the one source of truth (cookie for everyone, the
 * member record for members), the footer toggle, and the assistant picking
 * the preference up from the script someone writes in.
 */
class LanguagePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_is_the_default_and_the_toggle_sets_a_cookie(): void
    {
        $this->get('/shop')->assertInertia(fn (Assert $page) => $page->where('chrome.lang', 'en'));

        // Garbage never becomes a language. Checked before a real switch:
        // queued cookies outlive a request inside one test, so a 'bn' set
        // earlier would still be on this response.
        $this->post(route('lang'), ['lang' => 'xx'])->assertCookieMissing(Locale::COOKIE);

        $this->post(route('lang'), ['lang' => 'bn'])->assertCookie(Locale::COOKIE, 'bn');
        $this->withCookies([Locale::COOKIE => 'bn'])->get('/shop')
            ->assertInertia(fn (Assert $page) => $page->where('chrome.lang', 'bn'));
    }

    public function test_a_member_keeps_the_preference_on_their_record(): void
    {
        $customer = Customer::create(['name' => 'Rima', 'phone' => '01711100011', 'password' => 'secret123']);
        $this->actingAs($customer, 'customer')->post(route('lang'), ['lang' => 'bn']);

        $this->assertSame('bn', $customer->fresh()->locale);
        // The record wins over a stale cookie from another device.
        $this->actingAs($customer->fresh(), 'customer')->withCookies([Locale::COOKIE => 'en'])->get('/shop')
            ->assertInertia(fn (Assert $page) => $page->where('chrome.lang', 'bn'));
    }

    public function test_writing_to_the_assistant_in_bangla_sets_the_preference(): void
    {
        config(['services.openai.assistant_enabled' => true, 'services.openai.key' => 'sk-test']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['role' => 'assistant', 'content' => 'ঢাকার ভিতরে ৳80।']]]])]);

        $this->postJson(route('assistant.chat'), ['messages' => [['role' => 'user', 'content' => 'ডেলিভারি চার্জ কত?']]])
            ->assertOk()
            ->assertCookie(Locale::COOKIE, 'bn');

        $this->assertTrue(Locale::looksBangla('ডেলিভারি'));
        $this->assertFalse(Locale::looksBangla('delivery charge koto?'));
    }
}
