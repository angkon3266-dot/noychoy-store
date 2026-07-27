<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * config/cache.php sets serializable_classes = false, so the cache will not
 * restore any object — a cached Collection or Eloquent model comes back as
 * __PHP_Incomplete_Class. Production hit exactly that:
 *
 *   DashboardAnalytics::visitorsByDay(): Return value must be of type
 *   Illuminate\Support\Collection, __PHP_Incomplete_Class returned
 *
 * These pin that every cached payload stays plain, and that a poisoned entry
 * left over from before the fix is discarded rather than served.
 */
class DashboardCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function analytics(): DashboardAnalytics
    {
        return app(DashboardAnalytics::class);
    }

    /** What the app writes to the cache for a given method. */
    protected function cachedPayload(string $key): mixed
    {
        return Cache::get('dash.v2.'.$key, 'ABSENT');
    }

    public function test_no_analytics_method_caches_an_object(): void
    {
        $a = $this->analytics();

        // Touch every cached method.
        $a->periodComparison(30);
        $a->funnel(30);
        $a->visitorsByDay(14);
        $a->trafficSources(30);
        $a->topCampaigns(30);
        $a->viewedNotSold(30);
        $a->retention(90);
        $a->operations(30);

        foreach (['cmp.30', 'funnel.30', 'visitors.14', 'src.30.8', 'camp.30', 'vns.30', 'ret.90', 'ops.30'] as $key) {
            $payload = $this->cachedPayload($key);
            $this->assertNotSame('ABSENT', $payload, "nothing cached for {$key}");
            $this->assertTrue(
                $this->isPlain($payload),
                "dash.v2.{$key} contains an object — it will come back as __PHP_Incomplete_Class",
            );
        }
    }

    public function test_the_methods_still_return_collections(): void
    {
        $a = $this->analytics();

        // Prime the cache, then read again so the second call comes from cache.
        $a->visitorsByDay(14);
        $a->trafficSources(30);
        $a->topCampaigns(30);
        $a->viewedNotSold(30);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $a->visitorsByDay(14));
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $a->trafficSources(30));
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $a->topCampaigns(30));
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $a->viewedNotSold(30));
    }

    public function test_a_poisoned_cache_entry_is_discarded_not_served(): void
    {
        // Exactly what a pre-fix entry looks like once it comes back out.
        Cache::put('dash.v2.visitors.14', new \stdClass, 300);

        $result = $this->analytics()->visitorsByDay(14);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
        $this->assertCount(14, $result);              // recomputed, not the junk
        $this->assertTrue($this->isPlain($this->cachedPayload('visitors.14')));
    }

    public function test_the_dashboard_renders_with_a_poisoned_cache(): void
    {
        foreach (['visitors.14', 'src.30.8', 'camp.30', 'vns.30', 'ops.30'] as $key) {
            Cache::put('dash.v2.'.$key, new \stdClass, 300);
        }

        $admin = User::create([
            'name' => 'Admin', 'email' => 'a@b.test', 'password' => bcrypt('secret'), 'role' => 'admin',
        ]);

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    protected function isPlain(mixed $v, int $depth = 0): bool
    {
        if ($depth > 8) {
            return false;
        }
        if (is_object($v)) {
            return false;
        }
        if (is_array($v)) {
            foreach ($v as $i) {
                if (! $this->isPlain($i, $depth + 1)) {
                    return false;
                }
            }
        }

        return true;
    }
}
