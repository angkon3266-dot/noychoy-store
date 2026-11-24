<?php

namespace Tests\Feature\Mcp;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Review;
use App\Models\Setting;
use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;

/** Everything beyond the catalogue: menu, orders, customers, marketing, reviews, reports. */
class McpStoreToolsTest extends McpTestCase
{
    protected function order(array $extra = []): Order
    {
        $order = Order::create(array_merge([
            'order_number' => '20001', 'customer_name' => 'Rahim', 'customer_phone' => '01712345678',
            'shipping_address' => 'House 1, Road 2', 'city' => 'Dhaka', 'subtotal' => 2500, 'shipping_cost' => 60,
            'discount' => 0, 'member_discount' => 0, 'total' => 2560, 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'status' => 'processing', 'source' => 'web',
        ], $extra));

        return $order;
    }

    // ── Categories & collections ────────────────────────────────────────────

    public function test_categories_list_create_and_update(): void
    {
        $this->ok('create_category', ['name' => 'Rings', 'meta_title' => 'Rings for her']);
        $rings = Category::where('name', 'Rings')->firstOrFail();
        $this->assertTrue((bool) $rings->is_active);

        $this->ok('create_category', ['name' => 'Wedding Rings', 'parent' => 'Rings']);
        $this->assertSame($rings->id, Category::where('name', 'Wedding Rings')->value('parent_id'));

        $updated = $this->ok('update_category', ['category' => $rings->slug, 'description' => 'All rings', 'is_active' => false]);
        $this->assertSame('All rings', $updated['category']['description']);
        $rings->refresh();
        $this->assertFalse((bool) $rings->is_active);
        $this->assertSame('Rings for her', $rings->meta_title, 'fields not passed are kept');

        $names = array_column($this->ok('list_categories')['categories'], 'name');
        $this->assertContains('Wedding Rings', $names);

        $this->assertStringContainsString('name', strtolower($this->fails('create_category', [])));
    }

    public function test_collections_smart_and_manual(): void
    {
        $a = $this->product('Pearl Drops');
        $b = $this->product('Gold Hoops');

        $smart = $this->ok('create_collection', ['name' => 'Under 2000', 'rules' => [['field' => 'price', 'operator' => 'lte', 'value' => '2000']]]);
        $this->assertSame('smart', $smart['collection']['type']);
        $this->assertSame('price', $smart['collection']['rules'][0]['field']);

        $manual = $this->ok('create_collection', ['name' => 'Picks', 'type' => 'manual', 'products' => [$b->slug, $a->sku]]);
        $this->assertSame([$b->id, $a->id], $manual['collection']['pinned_product_ids']);

        $this->ok('update_collection', ['collection' => 'Picks', 'description' => 'Owner picks']);
        $picks = Collection::where('name', 'Picks')->first();
        $this->assertSame('Owner picks', $picks->description);
        $this->assertSame(2, $picks->products()->count(), 'pins survive an update that does not mention them');

        $this->assertCount(2, $this->ok('list_collections')['collections']);
        $this->assertStringContainsString('search_products', $this->fails('create_collection', ['name' => 'X', 'type' => 'manual', 'products' => ['nope']]));
    }

    // ── Menu ────────────────────────────────────────────────────────────────

    public function test_menu_read_and_replace_with_confirmation(): void
    {
        $menu = $this->ok('get_menu');
        $this->assertArrayHasKey('items', $menu);
        $this->assertArrayHasKey('link_targets', $menu);

        $items = [['label' => 'Rings', 'type' => 'link', 'url' => '/category/rings'], ['label' => 'Sale', 'type' => 'link', 'url' => '/shop', 'badge' => 'Hot']];

        $this->assertStringContainsString('confirm=true', $this->fails('update_menu', ['items' => $items]));

        $res = $this->ok('update_menu', ['items' => $items, 'confirm' => true]);
        $this->assertSame(['Rings', 'Sale'], array_column(Setting::get('menu'), 'label'));
        $this->assertSame('Hot', Setting::get('menu')[1]['badge']);
        $this->assertArrayHasKey('previous_items', $res);

        $this->assertStringContainsString('get_menu', $this->fails('update_menu', ['items' => [], 'confirm' => true]));
    }

    // ── Orders ──────────────────────────────────────────────────────────────

    public function test_orders_list_search_and_get(): void
    {
        $o = $this->order();
        $o->items()->create(['name' => 'Pearl Drops', 'price' => 2500, 'quantity' => 1, 'subtotal' => 2500]);
        $this->order(['order_number' => '20002', 'customer_phone' => '01811111111', 'customer_name' => 'Karim', 'status' => 'pending']);

        $this->assertCount(2, $this->ok('list_orders')['orders']);
        $this->assertSame(['20002'], array_column($this->ok('list_orders', ['status' => 'pending'])['orders'], 'order_number'));
        $this->assertSame(['20001'], array_column($this->ok('list_orders', ['search' => '+8801712345678'])['orders'], 'order_number'));

        $full = $this->ok('get_order', ['order' => '20001']);
        $this->assertSame('Pearl Drops', $full['line_items'][0]['name']);
        $this->assertEquals(2560, $full['money']['total']);
        $this->assertStringContainsString('list_orders', $this->fails('get_order', ['order' => '99999']));
    }

    public function test_order_status_change_needs_confirm_never_texts_and_respects_the_lock(): void
    {
        Http::fake();
        $o = $this->order();

        $this->assertStringContainsString('confirm=true', $this->fails('update_order_status', ['order' => '20001', 'status' => 'confirmed']));
        $this->assertSame('processing', $o->fresh()->status);

        $res = $this->ok('update_order_status', ['order' => '20001', 'status' => 'cancelled', 'note' => 'Customer asked', 'confirm' => true]);
        $this->assertSame('cancelled', $res['to']);
        $this->assertFalse($res['customer_notified']);
        $this->assertSame('Customer asked', $o->history()->latest('id')->value('note'));
        $this->assertSame($this->user->name, $o->history()->latest('id')->value('created_by'));

        $this->fails('update_order_status', ['order' => '20001', 'status' => 'nonsense', 'confirm' => true]);

        Http::assertNothingSent();
        $this->assertSame(0, SmsLog::count());
    }

    // ── Customers & SMS ─────────────────────────────────────────────────────

    public function test_find_and_get_customers_by_phone_in_any_format(): void
    {
        $c = Customer::create(['name' => 'Rima Sultana', 'phone' => '01711195772', 'total_orders' => 3, 'total_spent' => 5400]);
        $this->order(['customer_id' => $c->id]);

        $this->assertSame([$c->id], array_column($this->ok('find_customers', ['query' => '+880 1711-195772'])['customers'], 'id'));
        $this->assertSame([$c->id], array_column($this->ok('find_customers', ['query' => 'Rima'])['customers'], 'id'));

        $full = $this->ok('get_customer', ['customer' => '8801711195772']);
        $this->assertSame('Rima Sultana', $full['name']);
        $this->assertCount(1, $full['recent_orders']);
    }

    public function test_sms_needs_confirm_and_goes_through_the_sms_service(): void
    {
        Setting::put('integrations', [
            'sms_enabled' => true, 'sms_base_url' => 'http://sms.test', 'sms_api_key' => 'k', 'sms_secret_key' => 's', 'sms_caller_id' => 'NC',
        ]);
        Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD'], 200)]);
        Customer::create(['name' => 'Rima', 'phone' => '01711195772']);

        $this->assertStringContainsString('confirm=true', $this->fails('send_customer_sms', ['customer' => '01711195772', 'message' => 'Hi']));
        Http::assertNothingSent();

        $res = $this->ok('send_customer_sms', ['customer' => '01711195772', 'message' => 'আপনার অর্ডার তৈরি', 'confirm' => true]);
        $this->assertTrue($res['sent']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sms.test') && $req['toUser'] === '8801711195772');
    }

    public function test_sms_reports_a_switched_off_gateway_instead_of_pretending(): void
    {
        Http::fake();
        Customer::create(['name' => 'Rima', 'phone' => '01711195772']);

        $this->assertStringContainsString('switched off', $this->fails('send_customer_sms', ['customer' => '01711195772', 'message' => 'Hi', 'confirm' => true]));
        Http::assertNothingSent();
    }

    // ── Coupons & offers ────────────────────────────────────────────────────

    public function test_coupons_create_through_the_admin_rules_and_list(): void
    {
        $this->product();

        $res = $this->ok('create_coupon', ['code' => 'eid20', 'type' => 'percent', 'value' => 20, 'applies_to' => 'categories', 'categories' => ['earrings'], 'min_order' => 1000]);
        $this->assertSame('EID20', $res['coupon']['code']);
        $coupon = Coupon::where('code', 'EID20')->first();
        $this->assertTrue((bool) $coupon->is_active);
        $this->assertSame([Category::where('slug', 'earrings')->value('id')], $coupon->category_ids);

        $this->assertStringContainsString('code', strtolower($this->fails('create_coupon', ['code' => 'EID20', 'type' => 'percent', 'value' => 5])));

        $vip = $this->ok('create_coupon', ['code' => 'VIP', 'type' => 'fixed', 'value' => 200, 'phones' => ['01711195772', '01811111111']]);
        $this->assertSame(2, $vip['phone_list']);
        $this->assertStringContainsString('not a Bangladeshi mobile', $this->fails('create_coupon', ['code' => 'BAD', 'type' => 'fixed', 'value' => 1, 'phones' => ['12345']]));

        Coupon::create(['code' => 'SPIN-1', 'type' => 'fixed', 'value' => 50, 'applies_to' => 'all', 'source' => 'spin_wheel', 'is_active' => true]);
        $this->assertSame(['VIP', 'EID20'], array_column($this->ok('list_coupons')['coupons'], 'code'));
        $this->assertCount(3, $this->ok('list_coupons', ['include_generated' => true])['coupons']);
    }

    public function test_offers_create_with_dhaka_deadline_and_list(): void
    {
        $p = $this->product();

        $res = $this->ok('create_offer', ['title' => 'Earring week', 'type' => 'order_percent', 'percent' => 15, 'applies_to' => 'products', 'products' => [$p->slug], 'ends_at' => '2026-12-31 23:59']);
        $offer = Offer::findOrFail($res['offer']['id']);
        $this->assertSame([$p->id], $offer->product_ids);
        $this->assertTrue((bool) $offer->is_active);
        $this->assertSame('2026-12-31 17:59', $offer->ends_at->utc()->format('Y-m-d H:i'), 'typed as Dhaka time, stored as UTC');

        $this->fails('create_offer', ['title' => 'No percent', 'type' => 'order_percent']);
        $this->assertSame(['Earring week'], array_column($this->ok('list_offers')['offers'], 'title'));
    }

    // ── Reviews ─────────────────────────────────────────────────────────────

    public function test_reviews_list_moderate_and_delete(): void
    {
        $p = $this->product();
        $r = Review::create(['product_id' => $p->id, 'author_name' => 'Nadia', 'rating' => 5, 'body' => 'Lovely', 'status' => 'pending']);

        $list = $this->ok('list_reviews', ['status' => 'pending']);
        $this->assertSame(1, $list['pending_total']);
        $this->assertSame('Nadia', $list['reviews'][0]['author']);

        $this->assertSame('approved', $this->ok('moderate_review', ['review_id' => $r->id, 'status' => 'approved'])['status']);
        $this->fails('moderate_review', ['review_id' => $r->id, 'status' => 'published']);

        $this->assertStringContainsString('confirm=true', $this->fails('delete_review', ['review_id' => $r->id]));
        $this->ok('delete_review', ['review_id' => $r->id, 'confirm' => true]);
        $this->assertNull(Review::find($r->id));
    }

    // ── Reports ─────────────────────────────────────────────────────────────

    public function test_sales_summary_counts_sales_like_the_dashboard(): void
    {
        $sold = $this->order(['status' => 'delivered']);
        $sold->items()->create(['product_id' => null, 'name' => 'Pearl Drops', 'price' => 2500, 'quantity' => 2, 'subtotal' => 5000]);
        $cancelled = $this->order(['order_number' => '20002', 'status' => 'cancelled']);
        $cancelled->items()->create(['name' => 'Gold Hoops', 'price' => 900, 'quantity' => 1, 'subtotal' => 900]);

        $s = $this->ok('sales_summary', ['period' => '7d']);

        $this->assertSame(1, $s['orders']);
        $this->assertEquals(5000, $s['revenue']);
        $this->assertSame('Pearl Drops', $s['top_products'][0]['name']);
        $this->assertSame(1, $s['orders_by_status']['cancelled']['orders']);
    }
}
