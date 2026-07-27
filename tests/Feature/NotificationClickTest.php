<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The click-tracking redirect is a public-facing endpoint on our own domain.
 * It must only count clicks from people the notification was sent to, and must
 * not forward visitors to arbitrary external sites.
 */
class NotificationClickTest extends TestCase
{
    use RefreshDatabase;

    protected function customer(string $phone = '01711195772'): Customer
    {
        return Customer::create(['name' => 'Buyer', 'phone' => $phone, 'password' => 'secret123']);
    }

    protected function notification(array $attrs = []): CustomerNotification
    {
        return CustomerNotification::create(array_merge([
            'type' => 'update', 'audience' => 'all', 'title' => 'Sale', 'body' => 'Now on',
            'sent_at' => now(),
        ], $attrs));
    }

    public function test_a_recipient_click_is_counted(): void
    {
        $n = $this->notification(['url' => '/shop']);

        $this->actingAs($this->customer(), 'customer')
            ->get('/account/n/'.$n->id)
            ->assertRedirect('/shop');

        $this->assertSame(1, $n->fresh()->clicks);
    }

    public function test_a_notification_not_sent_to_this_customer_cannot_be_clicked(): void
    {
        // Targeted at a segment this customer isn't in.
        $n = $this->notification(['audience' => 'segment', 'url' => '/shop']);

        $this->actingAs($this->customer(), 'customer')
            ->get('/account/n/'.$n->id)
            ->assertNotFound();

        $this->assertSame(0, $n->fresh()->clicks);
    }

    public function test_an_unsent_notification_cannot_be_clicked(): void
    {
        $n = $this->notification(['sent_at' => null, 'url' => '/shop']);

        $this->actingAs($this->customer(), 'customer')
            ->get('/account/n/'.$n->id)
            ->assertNotFound();
    }

    public function test_an_off_site_url_is_not_followed(): void
    {
        $n = $this->notification(['url' => 'https://evil.example/phish']);

        $this->actingAs($this->customer(), 'customer')
            ->get('/account/n/'.$n->id)
            ->assertRedirect(route('account.notifications'));
    }

    public function test_an_absolute_url_on_our_own_host_is_followed(): void
    {
        $own = rtrim((string) config('app.url'), '/').'/shop';
        $n = $this->notification(['url' => $own]);

        $this->actingAs($this->customer(), 'customer')
            ->get('/account/n/'.$n->id)
            ->assertRedirect($own);
    }
}
