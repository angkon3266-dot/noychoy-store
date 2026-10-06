<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * A product taken off the shop still has links out there — Facebook ad site
 * links, old posts, shared chats. In the first week of Oct 2026 shoppers
 * followed them into an error page 150+ times. They land on the shop now
 * (owner, 6 Oct 2026), with the ad's tracking kept.
 */
class DraftProductLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function product(array $attributes = []): Product
    {
        return Product::create($attributes + [
            'name' => 'Baguette Crystal Drop Bracelet',
            'slug' => 'baguette-crystal-drop-bracelet',
            'status' => 'published',
            'price' => 1500,
        ]);
    }

    public function test_a_draft_product_link_opens_the_shop_with_the_ads_tracking(): void
    {
        $this->product(['status' => 'draft']);

        $this->get('/product/baguette-crystal-drop-bracelet?utm_source=fb-SiteLink&utm_medium=paid&fbclid=abc&color=red')
            ->assertRedirect(route('shop', ['utm_source' => 'fb-SiteLink', 'utm_medium' => 'paid', 'fbclid' => 'abc']))
            ->assertStatus(302);
    }

    public function test_an_archived_product_link_opens_the_shop(): void
    {
        $this->product()->delete();

        $this->get('/product/baguette-crystal-drop-bracelet')->assertRedirect(route('shop'));
    }

    public function test_a_published_product_still_opens_and_an_unknown_one_is_still_not_found(): void
    {
        $this->product();

        $this->get('/product/baguette-crystal-drop-bracelet')->assertOk();
        $this->get('/product/no-such-thing')->assertNotFound();
    }

    public function test_bulk_drafting_saves_each_product_so_meta_hears_about_it(): void
    {
        $a = $this->product();
        $b = $this->product(['name' => 'Ring', 'slug' => 'ring']);
        $admin = User::create(['name' => 'A', 'email' => 'a@b.test', 'password' => bcrypt('x'), 'role' => 'admin']);

        Event::fake(['eloquent.updated: '.Product::class]);

        $this->actingAs($admin)->post(route('admin.products.bulk'), ['action' => 'draft', 'ids' => [$a->id, $b->id]]);

        Event::assertDispatchedTimes('eloquent.updated: '.Product::class, 2);
        $this->assertSame(['draft', 'draft'], Product::orderBy('id')->pluck('status')->all());
    }
}
