<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Visit;
use App\Services\DashboardAnalytics;
use App\Support\DateRange;
use App\Support\DeviceDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the shop is browsed and bought on (owner, 6 Oct 2026).
 */
class DeviceTrackingTest extends TestCase
{
    use RefreshDatabase;

    public static function agents(): array
    {
        return [
            'Facebook in-app on Android' => [
                'Mozilla/5.0 (Linux; Android 13; SM-A145F Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/484.0.0.42.110;]',
                'mobile', 'Android', 'Facebook app',
            ],
            'Instagram on iPhone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0.0.25.80 (iPhone14,5; iOS 17_6; en_US)',
                'mobile', 'iOS', 'Instagram app',
            ],
            'Messenger on Android' => [
                'Mozilla/5.0 (Linux; Android 12; RMX3261; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/128.0 Mobile Safari/537.36 [FB_IAB/Orca-Android;FBAV/470.0.0.40.110;]',
                'mobile', 'Android', 'Messenger app',
            ],
            'Chrome on an Android tablet' => [
                'Mozilla/5.0 (Linux; Android 14; SM-X210) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
                'tablet', 'Android', 'Chrome',
            ],
            'Safari on an iPad' => [
                'Mozilla/5.0 (iPad; CPU OS 16_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1',
                'tablet', 'iOS', 'Safari',
            ],
            'Edge on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0',
                'desktop', 'Windows', 'Edge',
            ],
            'Samsung Internet' => [
                'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0 Mobile Safari/537.36',
                'mobile', 'Android', 'Samsung Internet',
            ],
        ];
    }

    #[DataProvider('agents')]
    public function test_a_user_agent_reads_as_device_system_and_browser(string $ua, string $device, string $os, string $browser): void
    {
        $this->assertSame(['device' => $device, 'os' => $os, 'browser' => $browser], DeviceDetector::parse($ua));
    }

    public function test_no_user_agent_is_not_guessed(): void
    {
        $this->assertSame(['device' => null, 'os' => null, 'browser' => null], DeviceDetector::parse(''));
    }

    public function test_a_visit_records_what_it_was_made_on(): void
    {
        $this->withHeader('User-Agent', self::agents()['Facebook in-app on Android'][0])
            ->withCookie('visitor_token', 'tok-1')
            ->get('/shop')->assertOk();

        $visit = Visit::firstOrFail();
        $this->assertSame(['mobile', 'Android', 'Facebook app'], [$visit->device, $visit->os, $visit->browser]);
    }

    public function test_the_dashboard_counts_visitors_and_orders_per_device(): void
    {
        foreach (['a' => 'mobile', 'b' => 'mobile', 'c' => 'desktop'] as $token => $device) {
            Visit::create(['visitor_token' => $token, 'event' => 'page', 'path' => '/', 'device' => $device, 'os' => 'Android', 'browser' => 'Facebook app']);
        }
        Visit::create(['visitor_token' => 'a', 'event' => 'cart_add', 'path' => 'cart', 'device' => 'mobile', 'os' => 'Android', 'browser' => 'Facebook app']);
        Visit::create(['visitor_token' => 'old', 'event' => 'page', 'path' => '/']);
        Order::create([
            'order_number' => '50001', 'customer_name' => 'B', 'customer_phone' => '01712345678', 'shipping_address' => 'x',
            'subtotal' => 900, 'total' => 900, 'status' => 'processing', 'device' => 'mobile', 'browser' => 'Facebook app',
        ]);

        $d = app(DashboardAnalytics::class)->devices(DateRange::preset('today'));

        $mobile = collect($d['devices'])->firstWhere('key', 'mobile');
        $this->assertSame(2, $mobile['visitors']);
        $this->assertSame(1, $mobile['carted']);
        $this->assertSame(1, $mobile['orders']);
        $this->assertSame(900.0, $mobile['revenue']);
        $this->assertSame(3, $d['tracked']);
        $this->assertSame(1, $d['untracked']);
        $this->assertSame('Facebook app', $d['browsers'][0]['name']);
    }
}
