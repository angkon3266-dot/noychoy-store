<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Terms & Conditions rewrite (19 Sep 2026). Production carried Shopify's
 * stock Terms of Service — "Welcome to My Store", "powered by Shopify",
 * "[INSERT BUSINESS ADDRESS]" — on a cash-on-delivery shop that has never run
 * on Shopify. The migration replaces that template and nothing the owner wrote.
 */
class TermsRewriteTest extends TestCase
{
    use RefreshDatabase;

    private const SHOPIFY = '<p><strong>OVERVIEW</strong><br>Welcome to My Store! My Store is powered by Shopify.</p><p>[INSERT BUSINESS ADDRESS]</p>';

    protected function migration(): object
    {
        return require database_path('migrations/2026_09_19_130000_rewrite_terms_for_noychoy.php');
    }

    protected function storePages(string $termsBody): void
    {
        Setting::put('pages', [
            'terms' => ['title' => 'Terms & Conditions', 'body' => $termsBody],
            'refund' => ['title' => 'Refund & Return Policy', 'body' => '<p>7 days.</p>'],
        ]);
    }

    public function test_the_shopify_template_is_replaced_and_kept(): void
    {
        Storage::fake('local');
        $this->storePages(self::SHOPIFY);

        $this->migration()->up();

        $body = page_content('terms', 'body');
        $this->assertStringNotContainsString('Shopify', $body);
        $this->assertStringNotContainsString('My Store', $body);
        $this->assertStringNotContainsString('[INSERT', $body);
        $this->assertStringContainsString('cash on delivery', $body);
        $this->assertStringContainsString("regular price crossed out", $body);
        $this->assertStringContainsString('href="/refund-policy"', $body);
        $this->assertSame('<p>7 days.</p>', page_content('refund', 'body'), 'the other pages are untouched');
        Storage::disk('local')->assertExists('backups/terms-before-2026-09-19.html');

        $this->get('/terms-and-conditions')->assertInertia(fn (Assert $page) => $page
            ->component('Legal')
            ->where('title', 'Terms & Conditions')
            ->where('body', $body));
    }

    public function test_terms_the_owner_wrote_are_left_alone(): void
    {
        Storage::fake('local');
        $own = '<p>Our own terms, written for NoyChoy.</p>';
        $this->storePages($own);

        $this->migration()->up();

        $this->assertSame($own, page_content('terms', 'body'));
        Storage::disk('local')->assertMissing('backups/terms-before-2026-09-19.html');
    }

    public function test_rolling_back_puts_the_old_text_back(): void
    {
        Storage::fake('local');
        $this->storePages(self::SHOPIFY);
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertSame(self::SHOPIFY, page_content('terms', 'body'));
    }
}
