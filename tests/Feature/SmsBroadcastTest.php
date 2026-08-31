<?php

namespace Tests\Feature;

use App\Jobs\SendBroadcastSms;
use App\Models\Customer;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Broadcasting to the whole customer list.
 *
 * This went wrong in production in the worst possible way: the log INSERT
 * overflowed `sms_logs.phone` (100 comma-joined numbers into a varchar(255)),
 * the catch block wrote the same oversized row again, and the second failure
 * escaped as a 500 — *after* the gateway had already charged for and delivered
 * the first hundred messages. The admin saw an error, the other 536 customers
 * got nothing, and no record of any of it was kept.
 */
class SmsBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);
    }

    protected function enableSms(): void
    {
        \App\Models\Setting::put('integrations', [
            'sms_enabled' => true,
            'sms_base_url' => 'https://sms.test',
            'sms_api_key' => 'key',
            'sms_secret_key' => 'secret',
            'sms_caller_id' => 'Meridian',
        ]);
    }

    protected function customers(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Customer::create([
                'name' => 'C'.$i,
                'phone' => '018'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function test_a_broadcast_to_the_whole_list_does_not_error(): void
    {
        Queue::fake();
        $this->enableSms();
        $this->customers(250);

        $res = $this->actingAs($this->admin())
            ->post('/admin/sms/broadcast', ['message' => 'Enjoy 10% OFF today with code DIS10.']);

        $res->assertRedirect();
        $res->assertSessionHasNoErrors();
        $res->assertSessionHas('success');

        // Three chunks of 100, 100, 50 — none of them left behind.
        Queue::assertPushed(SendBroadcastSms::class, 3);

        $queued = 0;
        Queue::assertPushed(SendBroadcastSms::class, function ($job) use (&$queued) {
            $queued += count($job->phones);

            return true;
        });
        $this->assertSame(250, $queued);
    }

    public function test_each_number_is_queued_once_in_the_form_the_gateway_wants(): void
    {
        Queue::fake();
        $this->enableSms();

        // Customer::phone stores `01…` canonically; the gateway wants `880…`.
        Customer::create(['name' => 'Her', 'phone' => '01712345678']);
        Customer::create(['name' => 'Him', 'phone' => '8801812345678']);

        $this->actingAs($this->admin())->post('/admin/sms/broadcast', ['message' => 'Hello']);

        Queue::assertPushed(SendBroadcastSms::class, function ($job) {
            sort($job->phones);

            return $job->phones === ['8801712345678', '8801812345678'];
        });
    }

    public function test_blacklisted_customers_are_left_out(): void
    {
        Queue::fake();
        $this->enableSms();

        Customer::create(['name' => 'Fine', 'phone' => '01711111111']);
        Customer::create(['name' => 'Opted out', 'phone' => '01722222222', 'blacklisted' => true]);

        $this->actingAs($this->admin())->post('/admin/sms/broadcast', ['message' => 'Hello']);

        Queue::assertPushed(SendBroadcastSms::class, function ($job) {
            return $job->phones === ['8801711111111'];
        });
    }

    public function test_a_bulk_send_is_logged_as_a_summary_not_a_wall_of_numbers(): void
    {
        $this->enableSms();
        Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD'], 200)]);

        $phones = collect(range(1, 100))
            ->map(fn ($i) => '018'.str_pad((string) $i, 8, '0', STR_PAD_LEFT))->implode(',');

        $ok = app(SmsService::class)->send($phones, 'Enjoy 10% OFF today.');

        $this->assertTrue($ok, 'the gateway accepted it, so send() must report success');

        $log = SmsLog::sole();
        $this->assertSame(100, $log->recipients);
        $this->assertLessThanOrEqual(255, strlen($log->phone));
        // A summary, not the list. The numbers are the most valuable thing this
        // shop holds and log rows travel differently from the database.
        $this->assertStringContainsString('100 recipients', $log->phone);
        $this->assertStringNotContainsString('88018000000', $log->phone);
        $this->assertSame('ACCEPTD', $log->status);
    }

    public function test_a_single_send_still_logs_the_number_itself(): void
    {
        $this->enableSms();
        Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD'], 200)]);

        app(SmsService::class)->send('01712345678', 'Your order is on its way.');

        $log = SmsLog::sole();
        $this->assertSame('8801712345678', $log->phone);
        $this->assertSame(1, $log->recipients);
    }

    public function test_a_failing_log_write_never_fails_a_send_that_already_went_out(): void
    {
        $this->enableSms();
        Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD'], 200)]);

        // Exactly what the overflow looked like: the write throws, the message
        // has already been delivered and paid for.
        \Illuminate\Support\Facades\Schema::drop('sms_logs');

        $ok = app(SmsService::class)->send('01712345678', 'Hello');

        $this->assertTrue($ok, 'a broken audit table must not turn a delivered SMS into a failure');
    }

    public function test_the_broadcast_job_sends_its_chunk_in_one_gateway_call(): void
    {
        $this->enableSms();
        Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD'], 200)]);

        (new SendBroadcastSms(['01711111111', '01722222222', '01733333333'], 'Hello'))
            ->handle(app(SmsService::class));

        Http::assertSentCount(1);
        $this->assertSame(3, SmsLog::sole()->recipients);
    }

    public function test_a_rejected_chunk_is_thrown_so_the_queue_retries_it(): void
    {
        $this->enableSms();
        Http::fake(['*' => Http::response(['Status' => '105', 'Text' => 'REJECTD'], 200)]);

        $this->expectException(\RuntimeException::class);

        (new SendBroadcastSms(['01711111111'], 'Hello'))->handle(app(SmsService::class));
    }

    /**
     * A "%" in the wording must not reach the gateway through the URL.
     *
     * The gateway decodes its query string twice, so a correctly-escaped
     * "20%25 OFF" arrived as a stray "% O", the parse of the whole query
     * string collapsed, and the destination went with it — every promotional
     * broadcast quoting a discount came back "-55 Destination ID Empty" and
     * not one customer was messaged. The text belongs in the form body, which
     * is parsed once.
     */
    public function test_the_message_travels_in_the_body_not_the_url(): void
    {
        $this->enableSms();
        Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD'], 200)]);

        $message = "Meridian \u{c9}clat: up to 20% OFF & free delivery.\n\nShop: meridianeclat.shop";

        app(SmsService::class)->send('01711111111', $message);

        Http::assertSent(function ($request) use ($message) {
            $this->assertSame('POST', $request->method());
            $this->assertStringNotContainsString('?', $request->url(),
                'nothing may ride in the query string — the gateway double-decodes it');

            $this->assertSame($message, $request['messageContent'], 'the wording must arrive verbatim');
            $this->assertSame('8801711111111', $request['toUser']);

            return true;
        });
    }

    public function test_a_discount_broadcast_is_accepted_for_every_recipient(): void
    {
        $this->enableSms();
        Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD'], 200)]);

        (new SendBroadcastSms(['01711111111', '01722222222'], 'Enjoy 20% OFF today.'))
            ->handle(app(SmsService::class));

        $log = SmsLog::sole();
        $this->assertSame(2, $log->recipients);
        $this->assertSame('ACCEPTD', $log->status);
    }

    public function test_broadcasting_with_sms_switched_off_says_so_instead_of_queueing(): void
    {
        Queue::fake();
        $this->customers(5);

        $res = $this->actingAs($this->admin())->post('/admin/sms/broadcast', ['message' => 'Hello']);

        $res->assertSessionHas('error');
        Queue::assertNothingPushed();
    }
}
