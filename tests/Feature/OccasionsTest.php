<?php

namespace Tests\Feature;

use App\Console\Commands\RunOccasions;
use App\Jobs\SendOccasionMessage;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\CustomerOffer;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Birthday / anniversary messages cost an SMS segment each and must feel
 * personal, so these pin who is due (in Dhaka time, a set number of days
 * ahead for the reminder, on the day for the wish), that nobody is messaged
 * twice, that a failed send re-arms itself, and that the dates arrive from
 * both places customers can leave them.
 */
class OccasionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The default phone counts up rather than rolling a die: it used to be
     * `random_int(1, 9)`, which collided with the explicit 0171110000N numbers
     * the tests below use and failed the whole suite roughly one run in nine.
     */
    protected int $phoneSeq = 100;

    protected function customer(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Rima Sultana', 'phone' => '017111'.str_pad((string) $this->phoneSeq++, 5, '0', STR_PAD_LEFT), 'password' => 'secret123',
        ], $attrs));
    }

    protected function enableSms(): void
    {
        Setting::put('integrations', [
            'sms_enabled' => true, 'sms_base_url' => 'http://sms.test', 'sms_api_key' => 'k', 'sms_secret_key' => 's', 'sms_caller_id' => 'NC',
        ]);
    }

    public function test_the_due_queries_pick_the_reminder_and_the_wish_on_the_right_days(): void
    {
        Setting::put('occasion_reminder_days', 10);
        $today = store_time(now());
        $inTen = $today->copy()->addDays(10);

        $reminder = $this->customer(['phone' => '01711100001', 'birthday_day' => $inTen->day, 'birthday_month' => $inTen->month]);
        $wish = $this->customer(['phone' => '01711100002', 'anniversary_day' => $today->day, 'anniversary_month' => $today->month]);
        $this->customer(['phone' => '01711100003', 'birthday_day' => $inTen->day, 'birthday_month' => $inTen->month, 'blacklisted' => true]);
        $this->customer(['phone' => '01711100004', 'birthday_day' => $today->copy()->addDays(3)->day, 'birthday_month' => $today->copy()->addDays(3)->month]);

        $this->assertSame([$reminder->id], RunOccasions::dueQuery('birthday', 'reminder')->pluck('id')->all());
        $this->assertSame([], RunOccasions::dueQuery('birthday', 'wish')->pluck('id')->all());
        $this->assertSame([$wish->id], RunOccasions::dueQuery('anniversary', 'wish')->pluck('id')->all());
    }

    public function test_the_command_stamps_then_queues_and_never_repeats(): void
    {
        Queue::fake();
        $today = store_time(now());
        $c = $this->customer(['birthday_day' => $today->day, 'birthday_month' => $today->month]);

        $this->artisan('crm:occasions')->assertExitCode(0);
        Queue::assertPushed(SendOccasionMessage::class, fn ($job) => $job->customerId === $c->id && $job->occasion === 'birthday' && $job->kind === 'wish');
        $this->assertNotNull($c->fresh()->birthday_wished_at);

        $this->artisan('crm:occasions')->assertExitCode(0);
        Queue::assertPushed(SendOccasionMessage::class, 1);

        // Switched off in the admin: nothing is even looked at.
        Setting::put('occasion_enabled', false);
        $this->customer(['phone' => '01711100009', 'anniversary_day' => $today->day, 'anniversary_month' => $today->month]);
        $this->artisan('crm:occasions')->assertExitCode(0);
        Queue::assertPushed(SendOccasionMessage::class, 1);
    }

    public function test_the_wish_reaches_the_bell_and_the_phone_with_a_gift_when_configured(): void
    {
        $this->enableSms();
        Setting::put('occasion_offer_percent', 10);
        Setting::put('occasion_offer_days', 7);
        Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD'], 200)]);
        $c = $this->customer(['phone' => '01711100005', 'anniversary_day' => 5, 'anniversary_month' => 6]);
        $c->forceFill(['anniversary_wished_at' => now()])->saveQuietly();

        (new SendOccasionMessage($c->id, 'anniversary', 'wish'))->handle(app(\App\Services\SmsService::class), app(\App\Services\NotificationService::class));

        $offer = CustomerOffer::where('customer_id', $c->id)->firstOrFail();
        $this->assertSame('percent', $offer->type);
        $this->assertSame(10.0, (float) $offer->value);
        $this->assertTrue(CustomerNotification::where('type', 'occasion')->where('title', 'Happy anniversary, Rima!')->exists());
        Http::assertSent(fn ($request) => str_contains((string) $request['messageContent'], 'Happy anniversary, Rima!')
            && str_contains((string) $request['messageContent'], '10% off'));
        // Still stamped: something went out.
        $this->assertNotNull($c->fresh()->anniversary_wished_at);
    }

    public function test_a_failed_send_re_arms_the_customer_for_another_day(): void
    {
        $this->enableSms();
        Http::fake(['*' => Http::response(['Status' => 'ERROR'], 500)]);
        // A guest row: a phone but no login, so SMS is the only channel.
        $c = Customer::create(['name' => 'Guest', 'phone' => '01711100006', 'birthday_day' => 1, 'birthday_month' => 1]);
        $c->forceFill(['birthday_reminded_at' => now()])->saveQuietly();

        (new SendOccasionMessage($c->id, 'birthday', 'reminder'))->handle(app(\App\Services\SmsService::class), app(\App\Services\NotificationService::class));

        $this->assertNull($c->fresh()->birthday_reminded_at);
    }

    public function test_dates_are_saved_from_the_profile_and_from_checkout_but_never_overwritten(): void
    {
        $member = $this->customer(['phone' => '01711100007']);
        $this->actingAs($member, 'customer')->patch('/account/profile', [
            'name' => 'Rima Sultana', 'phone' => '01711100007', 'email' => '', 'gender' => '',
            'birthday_day' => 12, 'birthday_month' => 3, 'anniversary_day' => 20, 'anniversary_month' => '',
        ])->assertSessionHas('success');
        $fresh = $member->fresh();
        $this->assertSame([12, 3], [$fresh->birthday_day, $fresh->birthday_month]);
        $this->assertNull($fresh->anniversary_day, 'half a date is no date');

        auth('customer')->logout();
        $product = Product::create(['name' => 'Ring', 'slug' => 'ring-'.uniqid(), 'status' => 'published', 'price' => 1000, 'manage_stock' => false, 'in_stock' => true]);
        $this->post(route('cart.add', $product), ['qty' => 1]);
        $this->post(route('checkout.store'), [
            'name' => 'Guest Buyer', 'phone' => '01711100008', 'address' => 'Dhaka',
            'anniversary_day' => 9, 'anniversary_month' => 9,
        ]);
        $guest = Customer::where('phone', '01711100008')->firstOrFail();
        $this->assertSame([9, 9], [$guest->anniversary_day, $guest->anniversary_month]);

        // A second order with a different date keeps the first answer.
        $this->post(route('cart.add', $product), ['qty' => 1]);
        $this->post(route('checkout.store'), ['name' => 'Guest Buyer', 'phone' => '01711100008', 'address' => 'Dhaka', 'anniversary_day' => 1, 'anniversary_month' => 1]);
        $this->assertSame([9, 9], [$guest->fresh()->anniversary_day, $guest->fresh()->anniversary_month]);
    }
}
