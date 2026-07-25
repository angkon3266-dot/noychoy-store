<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Whatever a customer types, one canonical stored form: 01XXXXXXXXX. Enforced
 * on the model so no write path can bypass it, because a phone stored in a
 * second format silently splits a customer's order history in two.
 */
class PhoneNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public static function phoneFormats(): array
    {
        return [
            'local'            => ['01711195772'],
            'no leading zero'  => ['1711195772'],
            'dashes'           => ['01711-195772'],
            'spaces'           => ['017 1119 5772'],
            'country code'     => ['8801711195772'],
            'plus country'     => ['+8801711195772'],
            'plus and spaces'  => ['+880 1711-195772'],
            'zero and country' => ['+880 01711195772'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('phoneFormats')]
    public function test_every_format_stores_as_the_same_number(string $typed): void
    {
        $customer = Customer::create(['name' => 'Test', 'phone' => $typed]);

        $this->assertSame('01711195772', $customer->fresh()->phone, "failed for: {$typed}");
    }

    public function test_orders_store_the_same_canonical_form(): void
    {
        $order = Order::create([
            'order_number' => '40001', 'customer_name' => 'A', 'customer_phone' => '+880 1711-195772',
            'shipping_address' => 'X', 'subtotal' => 100, 'shipping_cost' => 0, 'discount' => 0,
            'member_discount' => 0, 'total' => 100, 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'status' => 'processing', 'source' => 'web',
        ]);

        $this->assertSame('01711195772', $order->fresh()->customer_phone);
    }

    public function test_an_updated_phone_is_normalised_too(): void
    {
        $customer = Customer::create(['name' => 'Test', 'phone' => '01711195772']);
        $customer->update(['phone' => '8801812345678']);

        $this->assertSame('01812345678', $customer->fresh()->phone);
    }

    public function test_a_blank_phone_stays_null_rather_than_empty_string(): void
    {
        $customer = Customer::create(['name' => 'Test', 'phone' => null]);

        $this->assertNull($customer->fresh()->phone);
    }

    public function test_normalize_command_is_a_dry_run_by_default(): void
    {
        // Insert straight through the query builder to dodge the mutator, the
        // way rows written before it existed would look.
        DB::table('customers')->insert(['name' => 'Legacy', 'phone' => '8801711195772', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('phones:normalize')->assertExitCode(0);

        $this->assertSame('8801711195772', DB::table('customers')->value('phone'));
    }

    public function test_normalize_command_rewrites_legacy_rows_with_force(): void
    {
        DB::table('customers')->insert(['name' => 'Legacy', 'phone' => '+880 1711-195772', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->insert([
            'order_number' => '40002', 'customer_name' => 'Legacy', 'customer_phone' => '8801711195772',
            'shipping_address' => 'X', 'subtotal' => 100, 'shipping_cost' => 0, 'discount' => 0,
            'member_discount' => 0, 'total' => 100, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => 'processing', 'source' => 'web', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('phones:normalize --force')->assertExitCode(0);

        $this->assertSame('01711195772', DB::table('customers')->value('phone'));
        $this->assertSame('01711195772', DB::table('orders')->value('customer_phone'));
    }

    public function test_merge_folds_duplicates_and_keeps_their_orders(): void
    {
        // The same person, saved twice in two formats before normalisation.
        $keepId = DB::table('customers')->insertGetId([
            'name' => 'Rahim', 'phone' => '01711195772', 'total_orders' => 2, 'total_spent' => 2000,
            'points' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dupId = DB::table('customers')->insertGetId([
            'name' => 'Rahim', 'phone' => '8801711195772', 'email' => 'rahim@example.com',
            'total_orders' => 1, 'total_spent' => 500, 'points' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Order::create([
            'order_number' => '40003', 'customer_id' => $dupId, 'customer_name' => 'Rahim',
            'customer_phone' => '01711195772', 'shipping_address' => 'X', 'subtotal' => 500,
            'shipping_cost' => 0, 'discount' => 0, 'member_discount' => 0, 'total' => 500,
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'status' => 'processing', 'source' => 'web',
        ]);

        $this->artisan('phones:normalize --force --merge')->assertExitCode(0);

        $this->assertDatabaseMissing('customers', ['id' => $dupId]);
        $this->assertSame(1, Customer::count());

        $kept = Customer::find($keepId);
        $this->assertSame('01711195772', $kept->phone);
        $this->assertSame('rahim@example.com', $kept->email);   // detail carried over
        $this->assertSame(150, $kept->points);                  // points added up
        $this->assertSame(1, Order::where('customer_id', $keepId)->count());
    }

    public function test_registering_with_another_format_of_an_existing_number_is_rejected(): void
    {
        Customer::create(['name' => 'Existing', 'phone' => '01711195772', 'password' => 'secret123']);

        // Same person, typed differently — must be caught by validation, not by
        // a database constraint error after the mutator rewrites it.
        $res = $this->post('/register', [
            'name' => 'Impostor',
            'phone' => '+880 1711-195772',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $res->assertSessionHasErrors('phone');
        $this->assertSame(1, Customer::count());
    }
}
