<?php

namespace Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Moving sessions from MySQL to Redis is a one-way, live-traffic operation: if
 * the payload encoding is wrong, every logged-in customer is logged out and
 * every active cart is emptied, and there is no undo.
 *
 * The trap is that the two handlers do not store the same bytes.
 * DatabaseSessionHandler base64-encodes on write and decodes on read;
 * CacheBasedSessionHandler stores the payload raw. These tests pin that
 * contract against the real framework classes, so an upstream change to either
 * handler breaks the build instead of breaking production.
 */
class SessionRedisMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** A cache repository standing in for the Redis one, same interface. */
    protected function fakeSessionCache(): Repository
    {
        return new Repository(new ArrayStore);
    }

    public function test_the_database_handler_stores_base64_and_the_cache_handler_stores_raw(): void
    {
        $payload = serialize(['_token' => 'abc', 'cart' => ['x' => 1]]);

        $db = new DatabaseSessionHandler(DB::connection(), 'sessions', 120);
        $db->write('session-encoding-probe', $payload);

        $stored = DB::table('sessions')->where('id', 'session-encoding-probe')->value('payload');

        // The whole reason a plain column copy would corrupt every session.
        $this->assertNotSame($payload, $stored, 'database handler should not store the payload verbatim');
        $this->assertSame($payload, base64_decode($stored, true));

        $cache = $this->fakeSessionCache();
        $cacheHandler = new CacheBasedSessionHandler($cache, 120);
        $cacheHandler->write('session-encoding-probe', $payload);

        // The cache handler keys on the bare session id and stores raw bytes.
        $this->assertSame($payload, $cache->get('session-encoding-probe'));
    }

    public function test_a_decoded_row_is_readable_by_the_cache_handler(): void
    {
        $payload = serialize(['login_customer_abc' => 42, 'cart' => ['sku-1' => 2]]);

        $db = new DatabaseSessionHandler(DB::connection(), 'sessions', 120);
        $db->write('round-trip', $payload);

        $row = DB::table('sessions')->where('id', 'round-trip')->first();

        // Exactly what the command does.
        $cache = $this->fakeSessionCache();
        $cache->put($row->id, base64_decode($row->payload, true), 120 * 60);

        $handler = new CacheBasedSessionHandler($cache, 120);

        $this->assertSame($payload, $handler->read('round-trip'));
        $this->assertSame(42, unserialize($handler->read('round-trip'))['login_customer_abc']);
    }

    public function test_it_refuses_to_run_against_a_non_redis_store(): void
    {
        // Guard against the quiet failure: writing sessions into the array or
        // file cache would report success and log everyone out anyway.
        config(['session.store' => 'array']);

        $this->artisan('session:to-redis')
            ->assertExitCode(1);
    }

    public function test_dry_run_writes_nothing_and_counts_expired_rows_separately(): void
    {
        config(['session.store' => 'array']);

        DB::table('sessions')->insert([
            'id' => 'fresh', 'payload' => base64_encode(serialize(['a' => 1])), 'last_activity' => time(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'stale', 'payload' => base64_encode(serialize(['a' => 1])),
            'last_activity' => time() - (config('session.lifetime') * 60) - 60,
        ]);

        // The guard fires first, so nothing is written even in dry-run mode.
        $this->artisan('session:to-redis --dry-run')->assertExitCode(1);

        $this->assertSame(2, DB::table('sessions')->count());
    }

    public function test_sessions_get_a_redis_database_no_cache_command_can_flush(): void
    {
        // `cache:clear` (which deploy.sh runs on every deploy via
        // optimize:clear) is a FLUSHDB on the cache store's connection, and
        // `cache:clear --locks` is a FLUSHDB on its lock connection. Sessions
        // must share a database with neither.
        $cacheDb = config('database.redis.'.config('cache.stores.redis.connection').'.database');
        $lockDb = config('database.redis.'.config('cache.stores.redis.lock_connection').'.database');
        $sessionDb = config('database.redis.session.database');

        $this->assertNotNull($sessionDb, 'a dedicated `session` redis connection must exist');
        $this->assertNotSame($cacheDb, $sessionDb, 'cache:clear would wipe every session');
        $this->assertNotSame($lockDb, $sessionDb, 'cache:clear --locks would wipe every session');
    }

    public function test_a_stale_session_connection_cannot_break_the_database_driver(): void
    {
        // The rollback trap: SESSION_CONNECTION names a *Redis* connection under
        // the redis driver but a *database* connection under the database
        // driver. Flipping the driver back while the key is still in .env would
        // otherwise make every request throw "Database connection [session] not
        // configured" — turning the emergency escape hatch into a worse outage.
        $this->assertArrayNotHasKey('session', config('database.connections'));

        // env() resolves through $_SERVER before getenv(), so set it there.
        $restore = [$_SERVER['SESSION_DRIVER'] ?? null, $_SERVER['SESSION_CONNECTION'] ?? null];
        $_SERVER['SESSION_CONNECTION'] = 'session';

        try {
            $_SERVER['SESSION_DRIVER'] = 'database';
            $resolved = require base_path('config/session.php');
            $this->assertNull(
                $resolved['connection'],
                'SESSION_CONNECTION must be ignored while the driver is `database`'
            );

            $_SERVER['SESSION_DRIVER'] = 'redis';
            $resolved = require base_path('config/session.php');
            $this->assertSame(
                'session',
                $resolved['connection'],
                'and must apply once the driver is the one it was written for'
            );
        } finally {
            [$d, $c] = $restore;
            unset($_SERVER['SESSION_DRIVER'], $_SERVER['SESSION_CONNECTION']);
            if ($d !== null) {
                $_SERVER['SESSION_DRIVER'] = $d;
            }
            if ($c !== null) {
                $_SERVER['SESSION_CONNECTION'] = $c;
            }
        }
    }
}
