<?php

namespace Tests\Feature;

use App\Jobs\SendWebPush;
use App\Models\CustomerNotification;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Member notifications going out when, and where, the admin meant (6 Oct 2026).
 */
class NotificationTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::create(['name' => 'A', 'email' => 'a@b.test', 'password' => bcrypt('x'), 'role' => 'admin']);
    }

    public function test_a_scheduled_time_is_read_as_shop_time(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-06 08:00:00', 'UTC'));   // 14:00 in Dhaka

        $this->actingAs($this->admin())->post(route('admin.notifications.store'), [
            'title' => 'Evening drop', 'audience' => 'all', 'scheduled_at' => '2026-10-06T20:00',
        ])->assertSessionHasNoErrors();

        // 20:00 in Dhaka is 14:00 UTC — not 20:00 UTC, six hours late.
        $this->assertSame('2026-10-06 14:00:00', CustomerNotification::firstOrFail()->scheduled_at->utc()->toDateTimeString());
    }

    public function test_a_time_already_past_in_the_shop_is_refused(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 08:00:00', 'UTC'));   // 14:00 in Dhaka

        $this->actingAs($this->admin())->post(route('admin.notifications.store'), [
            'title' => 'Too late', 'audience' => 'all', 'scheduled_at' => '2026-10-06T13:00',
        ])->assertSessionHasErrors('scheduled_at');

        $this->assertSame(0, CustomerNotification::count());
    }

    public function test_a_guests_push_opens_the_link_itself_not_the_sign_in_page(): void
    {
        Queue::fake();
        Setting::put('webpush_enabled', true);
        Setting::put('webpush_public_key', str_repeat('a', 87));
        Setting::put('webpush_private_key', str_repeat('b', 43));

        $customer = \App\Models\Customer::create(['name' => 'M', 'phone' => '01711195772', 'password' => 'secret123']);
        foreach (['https://push.example/guest' => null, 'https://push.example/member' => $customer->id] as $endpoint => $customerId) {
            PushSubscription::create([
                'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashFor($endpoint),
                'p256dh' => 'k', 'auth' => 't', 'customer_id' => $customerId,
            ]);
        }
        $n = CustomerNotification::create(['type' => 'update', 'audience' => 'all', 'title' => 'Sale', 'url' => url('/shop')]);

        app(NotificationService::class)->deliverPush($n);

        $urls = [];
        Queue::assertPushed(SendWebPush::class, function ($job) use (&$urls) {
            $urls[] = $job->payload['url'];

            return true;
        });
        sort($urls);
        $this->assertSame([url('/account/n/'.$n->id), url('/shop')], $urls);
    }
}
