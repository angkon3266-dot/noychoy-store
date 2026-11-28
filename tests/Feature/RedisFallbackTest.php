<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminAlerts;
use App\Support\RedisHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The host's Redis went down twice and took every page with it (2026-10-15,
 * 2026-11-27). The site now moves cache and sessions to the database while
 * Redis is unhealthy, and back once it is not.
 */
class RedisFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected int $probes = 0;

    protected ?string $answer = null;

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(RedisHealth::file());
        RedisHealth::$probe = function () {
            $this->probes++;

            return $this->answer;
        };
        config(['cache.default' => 'redis', 'session.driver' => 'redis', 'session.connection' => 'session']);
    }

    protected function tearDown(): void
    {
        RedisHealth::$probe = null;
        @unlink(RedisHealth::file());
        parent::tearDown();
    }

    public function test_a_healthy_redis_is_left_alone_and_asked_only_every_half_minute(): void
    {
        RedisHealth::apply();
        RedisHealth::apply();

        $this->assertSame('redis', config('cache.default'));
        $this->assertSame('redis', config('session.driver'));
        $this->assertSame(1, $this->probes, 'the second page reads the stored answer');

        $this->travel(RedisHealth::HEALTHY_SECONDS + 1)->seconds();
        RedisHealth::apply();
        $this->assertSame(2, $this->probes);
    }

    public function test_when_redis_is_down_cache_and_sessions_move_to_the_database(): void
    {
        $this->answer = 'no answer: Connection refused';

        RedisHealth::apply();

        $this->assertTrue(RedisHealth::fallenBack());
        $this->assertSame('database', config('cache.default'));
        $this->assertSame('database', config('session.driver'));
        $this->assertNull(config('session.connection'));
        $this->assertNotNull(RedisHealth::state()['down_since']);
    }

    public function test_a_page_still_loads_while_redis_is_down(): void
    {
        $this->answer = 'its last snapshot save failed, so it refuses writes (MISCONF)';
        RedisHealth::apply();

        $this->get('/')->assertOk();
        $this->assertTrue(cache()->put('still-works', 1, 60));
        $this->assertSame(1, cache()->get('still-works'));
    }

    public function test_it_moves_back_by_itself_and_records_the_outage(): void
    {
        $this->answer = 'no answer: Connection refused';
        RedisHealth::apply();

        // Still within the short re-check window: stays on the database.
        $this->answer = null;
        $this->travel(5)->seconds();
        RedisHealth::apply();
        $this->assertSame(1, $this->probes);

        config(['cache.default' => 'redis', 'session.driver' => 'redis']);
        $this->travel(RedisHealth::DOWN_SECONDS + 1)->seconds();
        RedisHealth::apply();

        $this->assertFalse(RedisHealth::fallenBack());
        $this->assertSame('redis', config('cache.default'));
        $state = RedisHealth::state();
        $this->assertTrue($state['healthy']);
        $this->assertNull($state['down_since']);
        $this->assertSame('no answer: Connection refused', $state['last_outage']['reason']);
    }

    public function test_the_admin_bell_says_so_while_down_and_after(): void
    {
        $this->answer = 'no answer: Connection refused';
        RedisHealth::apply();
        config(['cache.default' => 'array']);

        $titles = app(AdminAlerts::class)->all()->pluck('title')->all();
        $this->assertContains('Redis is down — the site is running on the database', $titles);

        $this->answer = null;
        $this->travel(RedisHealth::DOWN_SECONDS + 1)->seconds();
        RedisHealth::check();
        cache()->forget('admin.alerts.v1');

        $titles = app(AdminAlerts::class)->all()->pluck('title')->implode(' | ');
        $this->assertStringContainsString('the shop stayed open', $titles);
    }

    public function test_nothing_is_checked_when_redis_is_not_in_use(): void
    {
        config(['cache.default' => 'database', 'session.driver' => 'database']);
        RedisHealth::apply();

        $this->assertSame(0, $this->probes);
    }

    public function test_the_admin_cache_driver_setting_cannot_put_the_cache_back_on_a_dead_redis(): void
    {
        // system_configs cache.default is applied at boot, after the register()
        // check; AppServiceProvider re-applies the fallback straight after it.
        app(\App\Services\SystemConfig\SystemConfigRepository::class)->put('cache', 'cache.default', 'redis');
        $this->answer = 'no answer: Connection refused';

        RedisHealth::apply();
        app(\App\Services\SystemConfig\ConfigApplier::class)->apply();
        $this->assertSame('redis', config('cache.default'), 'the setting does override it');

        RedisHealth::apply();
        $this->assertSame('database', config('cache.default'));
        $this->assertSame(1, $this->probes, 'the second check reads the stored verdict');
    }
}
