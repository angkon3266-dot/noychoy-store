<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Order;
use App\Models\User;
use App\Support\DateRange;
use App\Support\ExpenseReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin → Expenses (owner, 2026-09-19): "log expenses related to business with
 * date and all" — and what is left of the orders' profit once they are paid.
 */
class ExpensesTest extends TestCase
{
    use RefreshDatabase;

    protected function user(string $role = 'admin'): User
    {
        return User::firstOrCreate(
            ['email' => $role.'@expenses.test'],
            ['name' => ucfirst($role), 'password' => bcrypt('x'), 'role' => $role],
        );
    }

    protected function log(array $overrides = [])
    {
        return $this->actingAs($this->user())->from(route('admin.expenses.index'))
            ->post(route('admin.expenses.store'), array_merge([
                'spent_on' => now()->toDateString(),
                'category' => 'Facebook / Instagram ads',
                'amount' => 2500,
                'description' => 'Boost for the Eid post',
                'paid_to' => 'Meta',
                'paid_via' => 'bKash',
            ], $overrides));
    }

    /** An order with one line, placed now unless told otherwise. */
    protected function order(float $total, float $cost, string $status = 'delivered', ?string $at = null): Order
    {
        static $n = 0;
        $n++;

        $order = Order::create([
            'order_number' => (string) (20000 + $n), 'customer_name' => 'Buyer', 'customer_phone' => '0171234567'.($n % 10),
            'shipping_address' => 'Road 1', 'subtotal' => $total, 'shipping_cost' => 0, 'discount' => 0,
            'member_discount' => 0, 'total' => $total, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'status' => $status, 'source' => 'web',
        ]);
        $order->items()->create([
            'name' => 'Ring', 'price' => $total, 'quantity' => 1, 'subtotal' => $total,
            'cost_price' => $cost, 'transport_cost' => 0,
        ]);

        if ($at) {
            $order->forceFill(['created_at' => $at])->saveQuietly();
        }

        return $order;
    }

    // ── Logging ──────────────────────────────────────────────────────────────

    public function test_the_owner_logs_an_expense_with_its_date_and_details(): void
    {
        $this->log(['spent_on' => '2026-09-03'])->assertRedirect(route('admin.expenses.index'))->assertSessionHas('success');

        $expense = Expense::sole();
        $this->assertSame('2026-09-03', $expense->spent_on->toDateString());
        $this->assertSame('Facebook / Instagram ads', $expense->category);
        $this->assertEquals(2500, (float) $expense->amount);
        $this->assertSame('Boost for the Eid post', $expense->description);
        $this->assertSame('Meta', $expense->paid_to);
        $this->assertSame('bKash', $expense->paid_via);
        $this->assertSame($this->user()->id, $expense->user_id);
    }

    public function test_a_typed_category_takes_the_suggested_spelling(): void
    {
        $this->log(['category' => '  stock   PURCHASE ']);

        $this->assertSame('Stock purchase', Expense::sole()->category);
    }

    public function test_her_own_category_is_kept_and_offered_next_time(): void
    {
        $this->log(['category' => 'Eid decorations']);

        $this->actingAs($this->user())->get(route('admin.expenses.index'))
            ->assertOk()
            ->assertSee('<option value="Eid decorations"></option>', false);
    }

    public function test_an_expense_needs_a_date_a_category_and_an_amount(): void
    {
        $this->log(['spent_on' => '', 'category' => '', 'amount' => 0])
            ->assertSessionHasErrors(['spent_on', 'category', 'amount']);

        $this->assertSame(0, Expense::count());
    }

    public function test_an_expense_can_be_corrected_and_deleted(): void
    {
        $this->log();
        $expense = Expense::sole();

        $this->actingAs($this->user())->put(route('admin.expenses.update', $expense), [
            'spent_on' => '2026-09-05', 'category' => 'Packaging', 'amount' => 900, 'description' => 'Boxes',
        ])->assertRedirect();

        $expense->refresh();
        $this->assertSame('Packaging', $expense->category);
        $this->assertEquals(900, (float) $expense->amount);

        $this->actingAs($this->user())->delete(route('admin.expenses.destroy', $expense))->assertRedirect();
        $this->assertSame(0, Expense::count());
    }

    public function test_duplicate_starts_the_log_form_from_an_entry_dated_today(): void
    {
        $this->log(['spent_on' => '2026-09-03', 'amount' => 2500, 'vat_rate' => 15, 'paid_via' => 'Nagad']);
        $expense = Expense::sole();

        $this->actingAs($this->user())->get(route('admin.expenses.index', ['copy' => $expense->id]))
            ->assertOk()
            ->assertSee('Duplicate an expense')
            ->assertSee('value="'.now(config('store.timezone', 'Asia/Dhaka'))->toDateString().'"', false)
            ->assertSee('value="Boost for the Eid post"', false)
            ->assertSee('<option value="Nagad" selected>', false)
            ->assertSee('Log the copy');

        // Logged with a change; the form is empty again afterwards.
        $this->actingAs($this->user())->from(route('admin.expenses.index', ['copy' => $expense->id, 'period' => 'all']))
            ->post(route('admin.expenses.store'), [
                'spent_on' => '2026-10-03', 'category' => $expense->category, 'amount' => 3000, 'vat_rate' => 15,
                'description' => $expense->description, 'paid_to' => 'Meta', 'paid_via' => 'Nagad',
            ])->assertRedirect(route('admin.expenses.index', ['period' => 'all']));

        $copy = Expense::latest('id')->first();
        $this->assertNotSame($expense->id, $copy->id);
        $this->assertEquals(450, (float) $copy->vat_amount);
        $this->assertSame('2026-10-03', $copy->spent_on->toDateString());
        $this->assertEquals(2500, (float) $expense->refresh()->amount);
    }

    // ── VAT ──────────────────────────────────────────────────────────────────

    public function test_vat_is_added_on_top_of_the_amount(): void
    {
        $this->log(['amount' => 2500, 'vat_rate' => 15])
            ->assertSessionHas('success', money(2875).' with VAT for Facebook / Instagram ads logged.');

        $expense = Expense::sole();
        $this->assertEquals(2500, (float) $expense->amount);
        $this->assertEquals(15, (float) $expense->vat_rate);
        $this->assertEquals(375, (float) $expense->vat_amount);
        $this->assertEquals(2875, $expense->total);
    }

    public function test_no_rate_is_no_vat(): void
    {
        $this->log(['amount' => 900, 'vat_rate' => '']);
        $this->log(['amount' => 100, 'vat_rate' => 0]);

        $this->assertSame(0, Expense::whereNotNull('vat_rate')->count());
        $this->assertEquals(0, (float) Expense::sum('vat_amount'));
        $this->log(['vat_rate' => 101])->assertSessionHasErrors('vat_rate');
    }

    public function test_changing_the_amount_or_rate_works_the_vat_out_again(): void
    {
        $this->log(['amount' => 1000, 'vat_rate' => 15]);
        $expense = Expense::sole();

        $this->actingAs($this->user())->put(route('admin.expenses.update', $expense), [
            'spent_on' => now()->toDateString(), 'category' => 'Website & software', 'amount' => 2000, 'vat_rate' => 7.5,
        ])->assertRedirect();
        $this->assertEquals(150, (float) $expense->refresh()->vat_amount);

        $this->actingAs($this->user())->put(route('admin.expenses.update', $expense), [
            'spent_on' => now()->toDateString(), 'category' => 'Website & software', 'amount' => 2000,
        ])->assertRedirect();
        $this->assertEquals(0, (float) $expense->refresh()->vat_amount);
        $this->assertNull($expense->vat_rate);
    }

    public function test_totals_and_the_csv_count_the_vat(): void
    {
        $this->order(10000, 4000);
        Expense::create(['spent_on' => now()->toDateString(), 'category' => 'Facebook / Instagram ads', 'amount' => 2000, 'vat_rate' => 15, 'vat_amount' => 300]);
        Expense::create(['spent_on' => now()->toDateString(), 'category' => 'Stock purchase', 'amount' => 1000, 'vat_rate' => 5, 'vat_amount' => 50]);

        $r = ExpenseReport::for(DateRange::preset('month'));
        $this->assertEquals(2300, $r['expenses']);
        $this->assertEquals(1050, $r['stock_bought']);
        $this->assertEquals(3350, $r['spent']);
        $this->assertEquals(3700, $r['net']); // 10,000 − 4,000 − 2,300

        $this->actingAs($this->user())->get(route('admin.expenses.index', ['category' => 'Facebook / Instagram ads']))
            ->assertSee(money(2300))
            ->assertSee(money(2000).' + 15% VAT');

        $csv = $this->actingAs($this->user())->get(route('admin.expenses.export', ['period' => 'all']))->streamedContent();
        $this->assertStringContainsString('"Facebook / Instagram ads",2000.00,15,300.00,2300.00', $csv);
    }

    // ── Receipts ─────────────────────────────────────────────────────────────

    public function test_a_receipt_is_kept_privately_and_shown_only_through_the_admin(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->log(['receipt' => UploadedFile::fake()->image('bill.jpg')]);
        $expense = Expense::sole();

        $this->assertNotNull($expense->receipt_path);
        Storage::disk('local')->assertExists($expense->receipt_path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'a receipt must never land on the public disk');

        $this->actingAs($this->user())->get(route('admin.expenses.receipt', $expense))->assertOk();

        auth()->logout();
        $this->get(route('admin.expenses.receipt', $expense))->assertRedirect(route('admin.login'));
    }

    public function test_deleting_an_expense_deletes_its_receipt(): void
    {
        Storage::fake('local');

        $this->log(['receipt' => UploadedFile::fake()->create('bill.pdf', 50, 'application/pdf')]);
        $expense = Expense::sole();
        $path = $expense->receipt_path;

        $this->actingAs($this->user())->delete(route('admin.expenses.destroy', $expense));

        Storage::disk('local')->assertMissing($path);
    }

    public function test_only_a_photo_or_a_pdf_is_taken_as_a_receipt(): void
    {
        Storage::fake('local');

        $this->log(['receipt' => UploadedFile::fake()->create('run.php', 5, 'application/x-php')])
            ->assertSessionHasErrors('receipt');

        $this->assertSame(0, Expense::count());
    }

    // ── Who may see it ───────────────────────────────────────────────────────

    public function test_expenses_are_the_owners_managers_and_staff_cannot_open_them(): void
    {
        foreach (['manager', 'staff'] as $role) {
            $this->actingAs($this->user($role))->get(route('admin.expenses.index'))->assertForbidden();
            $this->actingAs($this->user($role))->post(route('admin.expenses.store'), [
                'spent_on' => now()->toDateString(), 'category' => 'Other', 'amount' => 10,
            ])->assertForbidden();
        }

        $this->assertSame(0, Expense::count());
    }

    // ── The result ───────────────────────────────────────────────────────────

    public function test_net_profit_is_sales_less_product_cost_less_expenses(): void
    {
        $this->order(10000, 4000);
        $this->order(5000, 2000, 'booked');
        $this->order(8000, 3000, 'cancelled');   // no money came in
        $this->order(3000, 1000, 'returned');    // nor here

        Expense::create(['spent_on' => now()->toDateString(), 'category' => 'Facebook / Instagram ads', 'amount' => 1500]);
        Expense::create(['spent_on' => now()->toDateString(), 'category' => 'Courier & delivery', 'amount' => 500]);
        // Money out, but the pieces' cost already comes off as they sell.
        Expense::create(['spent_on' => now()->toDateString(), 'category' => 'Stock purchase', 'amount' => 20000]);
        // Outside the window.
        Expense::create(['spent_on' => now()->subMonths(2)->toDateString(), 'category' => 'Packaging', 'amount' => 999]);

        $r = ExpenseReport::for(DateRange::preset('30d'));

        $this->assertEquals(15000, $r['sales']);
        $this->assertSame(2, $r['orders']);
        $this->assertEquals(6000, $r['product_cost']);
        $this->assertEquals(9000, $r['gross']);
        $this->assertEquals(2000, $r['expenses']);
        $this->assertEquals(20000, $r['stock_bought']);
        $this->assertEquals(22000, $r['spent']);
        $this->assertEquals(7000, $r['net']);
        $this->assertSame(3, $r['count']);
    }

    public function test_the_window_compares_expense_dates_as_dates(): void
    {
        Expense::create(['spent_on' => now()->startOfMonth()->toDateString(), 'category' => 'Other', 'amount' => 100]);
        Expense::create(['spent_on' => now()->toDateString(), 'category' => 'Other', 'amount' => 50]);
        Expense::create(['spent_on' => now()->startOfMonth()->subDay()->toDateString(), 'category' => 'Other', 'amount' => 7]);

        $this->assertEquals(150, ExpenseReport::for(DateRange::preset('month'))['spent']);
        $this->assertEquals(157, ExpenseReport::for(DateRange::preset('all'))['spent']);
    }

    public function test_the_page_shows_this_month_and_what_is_left(): void
    {
        $this->order(10000, 4000);
        Expense::create(['spent_on' => now()->toDateString(), 'category' => 'Packaging', 'amount' => 1000, 'description' => 'Gift boxes']);

        $this->actingAs($this->user())->get(route('admin.expenses.index'))
            ->assertOk()
            ->assertSee('Gift boxes')
            ->assertSee('Profit after expenses')
            ->assertSee(money(5000)); // 10,000 − 4,000 − 1,000
    }

    public function test_the_list_filters_by_category_and_search(): void
    {
        Expense::create(['spent_on' => now()->toDateString(), 'category' => 'Packaging', 'amount' => 300, 'description' => 'Bubble wrap']);
        Expense::create(['spent_on' => now()->toDateString(), 'category' => 'Transport', 'amount' => 120, 'description' => 'Rickshaw to courier']);

        $this->actingAs($this->user())->get(route('admin.expenses.index', ['category' => 'Packaging']))
            ->assertSee('Bubble wrap')->assertDontSee('Rickshaw to courier');

        $this->actingAs($this->user())->get(route('admin.expenses.index', ['q' => 'rickshaw']))
            ->assertSee('Rickshaw to courier')->assertDontSee('Bubble wrap');
    }

    public function test_the_csv_carries_the_list_and_never_a_formula(): void
    {
        Expense::create(['spent_on' => '2026-09-02', 'category' => 'Other', 'amount' => 75.5, 'description' => '=HYPERLINK("x")']);

        $csv = $this->actingAs($this->user())->get(route('admin.expenses.export', ['period' => 'all']))->assertOk()->streamedContent();

        $this->assertStringContainsString('2026-09-02,Other,75.50', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    // ── On the dashboard ─────────────────────────────────────────────────────

    public function test_the_dashboard_shows_net_profit_to_the_owner_only(): void
    {
        $this->order(10000, 4000);
        Expense::create(['spent_on' => now()->toDateString(), 'category' => 'Salaries & staff', 'amount' => 3000]);

        $this->actingAs($this->user())->get('/admin?period=30d')
            ->assertOk()
            ->assertSee('Expenses &amp; net profit', false)
            ->assertSee(money(3000));

        // Staff see the dashboard, not the salaries.
        $this->actingAs($this->user('staff'))->get('/admin?period=30d')
            ->assertOk()
            ->assertDontSee('See expenses');
    }
}
