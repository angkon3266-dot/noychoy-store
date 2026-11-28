<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the site up when the host's Redis is not.
 *
 * Cache and sessions live in the hosting account's Redis. Twice it has gone
 * down and taken every page with it: 2026-10-15, and 2026-11-27 19:00 UTC for
 * seven hours (first "MISCONF … not able to persist on disk", so reads worked
 * and every write failed, then the process died). The owner chose to keep
 * Redis with an automatic fallback (2026-11-28).
 *
 * Before anything touches the cache, apply() asks whether Redis is healthy: it
 * answers PING and its last snapshot save did not fail (a failed save makes
 * Redis refuse writes). The answer is kept in a small file for a few seconds,
 * so a normal page costs one file read, not a Redis round trip. While Redis is
 * unhealthy, cache, sessions and locks run on the database tables that already
 * exist (cache, cache_locks, sessions); the site is a little slower and anyone
 * logged in signs in again, but nothing 500s. Once Redis is healthy again the
 * site moves back by itself and empties the Redis cache, which may hold values
 * that changed meanwhile (deploys do the same).
 *
 * Laravel's own "failover" cache store was not enough: it does not cover
 * sessions or the scheduler's locks, and in the MISCONF state it would keep
 * serving Redis's stale reads while writing elsewhere.
 */
class RedisHealth
{
    /** How long one answer is trusted. Shorter while down, to come back quickly. */
    public const HEALTHY_SECONDS = 30;

    public const DOWN_SECONDS = 15;

    /** Test seam: fn (): ?string — null when healthy, else the reason. */
    public static ?Closure $probe = null;

    /** Whether this process is running on the database instead of Redis. */
    protected static bool $fallenBack = false;

    public static function file(): string
    {
        return storage_path('framework/redis-health.json');
    }

    /** Point cache and sessions away from Redis if it is not healthy. Call before either is used. */
    public static function apply(): void
    {
        static::$fallenBack = false;

        $cacheOnRedis = config('cache.default') === 'redis';
        $sessionsOnRedis = config('session.driver') === 'redis';
        if (! $cacheOnRedis && ! $sessionsOnRedis) {
            return;
        }

        if (static::check()['healthy']) {
            return;
        }

        static::$fallenBack = true;
        $changes = [];
        if ($cacheOnRedis) {
            $changes['cache.default'] = 'database';
        }
        if ($sessionsOnRedis) {
            $changes['session.driver'] = 'database';
            $changes['session.connection'] = null;
            $changes['session.store'] = null;
        }
        config($changes);
    }

    public static function fallenBack(): bool
    {
        return static::$fallenBack;
    }

    /**
     * The current verdict, probing Redis if the stored one is stale.
     *
     * @return array{healthy: bool, checked: int, reason: ?string, down_since: ?int, last_outage: ?array{from: int, to: int, reason: ?string}}
     */
    public static function check(): array
    {
        $state = static::state();
        $ttl = $state['healthy'] ? self::HEALTHY_SECONDS : self::DOWN_SECONDS;
        if ($state['checked'] && now()->getTimestamp() - $state['checked'] < $ttl) {
            return $state;
        }

        $reason = static::probe();
        $now = now()->getTimestamp();
        $was = $state['healthy'];
        $state['healthy'] = $reason === null;
        $state['checked'] = $now;
        $state['reason'] = $reason;

        if ($was && ! $state['healthy']) {
            $state['down_since'] = $now;
            static::log('Redis is unavailable; cache and sessions now run on the database', ['reason' => $reason]);
        } elseif (! $was && $state['healthy']) {
            $state['last_outage'] = ['from' => $state['down_since'] ?? $now, 'to' => $now, 'reason' => $state['last_reason'] ?? null];
            $state['down_since'] = null;
            static::recovered();
        }
        if ($reason !== null) {
            $state['last_reason'] = $reason;
        }

        static::write($state);

        return $state;
    }

    /** What the file says, without probing. */
    public static function state(): array
    {
        $defaults = ['healthy' => true, 'checked' => 0, 'reason' => null, 'down_since' => null, 'last_outage' => null, 'last_reason' => null];
        $raw = @file_get_contents(static::file());
        $saved = $raw ? json_decode($raw, true) : null;

        return is_array($saved) ? array_merge($defaults, $saved) : $defaults;
    }

    /** null when Redis answers and can take writes; otherwise why not. */
    protected static function probe(): ?string
    {
        if (static::$probe) {
            return (static::$probe)();
        }

        if (! extension_loaded('redis')) {
            return 'the phpredis extension is not loaded';
        }

        $cfg = config('database.redis.default', []);
        $host = (string) ($cfg['host'] ?? '127.0.0.1');
        $socket = str_starts_with($host, '/') || str_starts_with($host, 'unix:');

        try {
            $r = new \Redis;
            $socket
                ? $r->connect(preg_replace('#^unix://?#', '/', $host), 0, 0.5)
                : $r->connect($host, (int) ($cfg['port'] ?? 6379), 0.5);
            $r->setOption(\Redis::OPT_READ_TIMEOUT, 0.5);
            if (filled($cfg['password'] ?? null)) {
                filled($cfg['username'] ?? null)
                    ? $r->auth([$cfg['username'], $cfg['password']])
                    : $r->auth($cfg['password']);
            }
            $r->ping();
            $persistence = $r->info('persistence');
            $r->close();
        } catch (\Throwable $e) {
            return 'no answer: '.$e->getMessage();
        }

        if (($persistence['rdb_last_bgsave_status'] ?? 'ok') === 'err') {
            return 'its last snapshot save failed, so it refuses writes (MISCONF)';
        }

        return null;
    }

    protected static function recovered(): void
    {
        static::log('Redis is back; cache and sessions run on Redis again', []);

        // Values changed during the outage went to the database, so the
        // Redis cache may hold older ones. Sessions are on another Redis
        // database and are not touched.
        try {
            app('cache')->store('redis')->flush();
        } catch (\Throwable $e) {
            static::log('Could not empty the Redis cache after it came back', ['error' => $e->getMessage()]);
        }
    }

    protected static function write(array $state): void
    {
        $file = static::file();
        $tmp = $file.'.'.getmypid().'.tmp';
        if (@file_put_contents($tmp, json_encode($state)) !== false) {
            @rename($tmp, $file);
        }
    }

    /** At error level: production logs nothing below it (LOG_LEVEL=error). */
    protected static function log(string $message, array $context): void
    {
        try {
            Log::error($message, $context);
        } catch (\Throwable) {
            // Logging must never be the thing that breaks the request.
        }
    }
}
