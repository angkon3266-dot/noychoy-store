<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardAnalytics;
use App\Support\DateRange;
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

        $range = DateRange::preset('30d');

        // Touch every cached method.
        $a->periodComparison($range);
        $a->funnel($range);
        $a->visitorsByDay($range);
        $a->trafficSources($range);
        $a->topCampaigns($range);
        $a->viewedNotSold($range);
        $a->retention($range);
        $a->operations($range);

        foreach (['cmp.30d', 'funnel.30d', 'visitors.30d', 'src.30d.8', 'camp.30d', 'vns.30d', 'ret.30d', 'ops.30d'] as $key) {
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

        $range = DateRange::preset('30d');

        // Prime the cache, then read again so the second call comes from cache.
        $a->visitorsByDay($range);
        $a->trafficSources($range);
        $a->topCampaigns($range);
        $a->viewedNotSold($range);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $a->visitorsByDay($range));
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $a->trafficSources($range));
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $a->topCampaigns($range));
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $a->viewedNotSold($range));
    }

    public function test_a_poisoned_cache_entry_is_discarded_not_served(): void
    {
        // Exactly what a pre-fix entry looks like once it comes back out.
        Cache::put('dash.v2.visitors.30d', new \stdClass, 300);

        $result = $this->analytics()->visitorsByDay(DateRange::preset('30d'));

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
        $this->assertCount(30, $result);              // recomputed, not the junk
        $this->assertTrue($this->isPlain($this->cachedPayload('visitors.30d')));
    }

    public function test_the_dashboard_renders_with_a_poisoned_cache(): void
    {
        foreach (['visitors.30d', 'src.30d.8', 'camp.30d', 'vns.30d', 'ops.30d'] as $key) {
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
