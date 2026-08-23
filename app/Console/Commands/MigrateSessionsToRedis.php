<?php

namespace App\Console\Commands;

use Illuminate\Cache\RedisStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Copy live sessions out of the `sessions` table and into the Redis session
 * store, so that flipping SESSION_DRIVER from `database` to `redis` logs
 * nobody out and empties nobody's cart.
 *
 * Run it BEFORE flipping the driver:
 *
 *   php artisan session:to-redis --dry-run     (report only, writes nothing)
 *   php artisan session:to-redis               (copy)
 *   php artisan session:to-redis --verify      (read every key back afterwards)
 *
 * Why this exists: the two handlers do NOT store the same bytes. Laravel's
 * DatabaseSessionHandler base64-encodes the payload on write and decodes it on
 * read; CacheBasedSessionHandler stores the payload raw. Copying the column
 * across verbatim would hand every visitor a corrupt session — which presents
 * as "everyone was logged out and every cart is empty", the exact thing this
 * command exists to prevent.
 *
 * The destination repository is built the same way SessionManager does it
 * (@see \Illuminate\Session\SessionManager::createRedisDriver), rather than by
 * hand-assembling a key. That matters: the final Redis key is
 * {redis options.prefix}{cache.prefix}{session id}, and reproducing that string
 * by hand is exactly the sort of thing that is silently wrong.
 */
class MigrateSessionsToRedis extends Command
{
    protected $signature = 'session:to-redis
                            {--dry-run : Report what would be copied without writing anything}
                            {--verify : After copying, read every key back and compare}
                            {--prune : Delete the database rows once they are safely in Redis}';

    protected $description = 'Copy live database sessions into Redis without logging anyone out';

    public function handle(): int
    {
        $table = config('session.table', 'sessions');

        if (! DB::getSchemaBuilder()->hasTable($table)) {
            $this->error("No `{$table}` table — nothing to migrate.");

            return self::FAILURE;
        }

        try {
            $repo = $this->redisSessionRepository();
        } catch (\Throwable $e) {
            $this->error('Could not build the Redis session store: '.$e->getMessage());

            return self::FAILURE;
        }

        // Fail loudly rather than silently writing sessions into the array or
        // file cache, which would look like success and log everyone out.
        if (! $repo->getStore() instanceof RedisStore) {
            $this->error(sprintf(
                'The resolved session cache store is %s, not Redis. Set CACHE_STORE=redis (or SESSION_STORE) first.',
                get_class($repo->getStore())
            ));

            return self::FAILURE;
        }

        // Diagnostics first: during a cut-over the operator needs to see where
        // the keys are actually going, not infer it.
        $store = $repo->getStore();
        $this->line('  redis connection  <info>'.(config('session.connection') ?: 'default').'</info>');
        $this->line('  redis database    <info>'.config('database.redis.'.(config('session.connection') ?: 'default').'.database').'</info>');
        $this->line('  key prefix        <info>'.config('database.redis.options.prefix').$store->getPrefix().'</info>');
        $this->line('  session lifetime  <info>'.config('session.lifetime').' min</info>');
        $this->newLine();

        // Prove the round trip BEFORE trusting any count. An empty or fully
        // expired sessions table would otherwise let this command print a
        // cheerful "copied 0" without ever having spoken to Redis — a green
        // light for a cut-over that then logs everyone out.
        $probeKey = '__session_migration_probe';

        try {
            $repo->put($probeKey, 'ok', 10);
            $readBack = $repo->get($probeKey);
            $repo->forget($probeKey);
        } catch (\Throwable $e) {
            $this->error('Redis round-trip failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($readBack !== 'ok') {
            $this->error('Redis round-trip returned '.var_export($readBack, true).' instead of "ok" — do NOT flip SESSION_DRIVER.');

            return self::FAILURE;
        }

        $this->info('Redis round-trip OK.');

        $lifetime = (int) config('session.lifetime', 120) * 60;
        $now = time();
        $dry = (bool) $this->option('dry-run');

        $copied = $skipped = $failed = 0;
        $ids = [];

        DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use (
            $repo, $lifetime, $now, $dry, &$copied, &$skipped, &$failed, &$ids
        ) {
            foreach ($rows as $row) {
                // Honour the session lifetime rather than resetting everyone's
                // clock: a session 119 minutes old should have 1 minute left,
                // not a fresh 120.
                $age = $now - (int) ($row->last_activity ?? 0);
                $ttl = $lifetime - $age;

                if ($ttl <= 0) {
                    $skipped++;

                    continue;
                }

                $payload = base64_decode((string) $row->payload, true);

                if ($payload === false || $payload === '') {
                    $this->warn("  skipped {$row->id}: payload is not valid base64");
                    $failed++;

                    continue;
                }

                if (! $dry) {
                    $repo->put($row->id, $payload, $ttl);
                    $ids[] = $row->id;
                }

                $copied++;
            }
        });

        $verb = $dry ? 'would copy' : 'copied';
        $this->info("Sessions: {$verb} {$copied}, skipped {$skipped} already expired, {$failed} unreadable.");

        if ($dry) {
            $this->line('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        if ($this->option('verify')) {
            $missing = 0;

            foreach ($ids as $id) {
                if ($repo->get($id) === null) {
                    $this->error("  MISSING after write: {$id}");
                    $missing++;
                }
            }

            if ($missing > 0) {
                $this->error("{$missing} session(s) did not survive the write — do NOT flip SESSION_DRIVER.");

                return self::FAILURE;
            }

            $this->info('Verified: every copied session reads back from Redis.');
        }

        if ($this->option('prune')) {
            // Only ever prune what we just copied, never the whole table.
            $deleted = 0;

            foreach (array_chunk($ids, 500) as $chunk) {
                $deleted += DB::table($table)->whereIn('id', $chunk)->delete();
            }

            $this->info("Pruned {$deleted} row(s) from `{$table}`.");
        }

        $this->newLine();
        $this->line('Next: set SESSION_DRIVER=redis in .env, then run `php artisan config:cache`.');

        return self::SUCCESS;
    }

    /**
     * Build the exact repository the `redis` session driver would use.
     *
     * Mirrors SessionManager::createRedisDriver() + createCacheHandler(): the
     * store name falls back to `redis`, and the connection is overridden from
     * session.connection — which, when null, resolves to the `default` Redis
     * connection (RedisManager::connection() does `$name ?: 'default'`), NOT
     * the `cache` connection. That is what keeps sessions in a different Redis
     * database from the cache, so `php artisan cache:clear` in deploy.sh
     * cannot flush them away.
     */
    protected function redisSessionRepository(): CacheRepository
    {
        $repo = clone Cache::store(config('session.store') ?: 'redis');

        $repo->getStore()->setConnection(config('session.connection'));

        return $repo;
    }
}
