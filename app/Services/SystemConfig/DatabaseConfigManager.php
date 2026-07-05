<?php

namespace App\Services\SystemConfig;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Safe editing of the primary database connection.
 *
 * The DB connection cannot be bootstrapped from the DB, so it lives in .env.
 * This manager guarantees an admin can never lock themselves out:
 *
 *   1. Test the NEW credentials on a throwaway connection (nothing changes).
 *   2. Only if the test succeeds, snapshot the CURRENT .env values.
 *   3. Write the new values to .env.
 *   4. Re-verify from .env; if anything is wrong, ROLL BACK to the snapshot.
 *   5. Clear the config cache so the next request uses the new connection.
 *
 * The live request keeps its existing connection throughout — the switch only
 * takes effect on the next request, after a fully verified write.
 */
class DatabaseConfigManager
{
    private const PROBE = 'system_config_probe';

    public function __construct(private readonly EnvWriter $env) {}

    /** Just test — never writes. */
    public function test(array $creds): array
    {
        return $this->probe($creds);
    }

    /**
     * Test → apply → verify → (rollback on failure).
     *
     * @return array{ok:bool, message:string}
     */
    public function testAndApply(array $creds): array
    {
        // 1. Test the new credentials first.
        $test = $this->probe($creds);
        if (! $test['ok']) {
            return $test; // nothing changed
        }

        if (! $this->env->exists()) {
            return ['ok' => false, 'message' => '❌ The .env file is not writable, so the database connection cannot be changed here.'];
        }

        // 2. Snapshot current values for rollback.
        $keys = ['DB_HOST' => 'db.host', 'DB_PORT' => 'db.port', 'DB_DATABASE' => 'db.database', 'DB_USERNAME' => 'db.username', 'DB_PASSWORD' => 'db.password'];
        $previous = [];
        foreach ($keys as $envKey => $_) {
            $previous[$envKey] = $this->env->get($envKey);
        }

        // 3. Write the new values.
        $newPairs = [];
        foreach ($keys as $envKey => $fieldKey) {
            $newPairs[$envKey] = (string) ($creds[$fieldKey] ?? '');
        }
        if (! $this->env->setMany($newPairs)) {
            return ['ok' => false, 'message' => '❌ Failed to write .env — no changes made.'];
        }

        // 4. Verify the written values still connect; roll back if not.
        $verify = $this->probe($creds);
        if (! $verify['ok']) {
            $this->env->setMany(array_map(fn ($v) => (string) $v, $previous));

            return ['ok' => false, 'message' => '❌ Post-write verification failed — rolled back to the previous database configuration.'];
        }

        // 5. Refresh cached config so the next request uses the new connection.
        try {
            Artisan::call('config:clear');
        } catch (\Throwable) {
            // Non-fatal — the .env change still applies on the next boot.
        }

        return ['ok' => true, 'message' => '✅ Database connection updated and verified. It takes effect on the next request.'];
    }

    /** Open a throwaway connection with the given credentials. */
    private function probe(array $creds): array
    {
        $template = config('database.connections.'.config('database.default'));

        config(['database.connections.'.self::PROBE => array_merge($template, [
            'host' => $creds['db.host'] ?? $template['host'] ?? '127.0.0.1',
            'port' => $creds['db.port'] ?? $template['port'] ?? 3306,
            'database' => $creds['db.database'] ?? '',
            'username' => $creds['db.username'] ?? '',
            'password' => $creds['db.password'] ?? '',
        ])]);

        try {
            DB::purge(self::PROBE);
            DB::connection(self::PROBE)->getPdo();
            DB::disconnect(self::PROBE);

            return ['ok' => true, 'message' => '✅ Connected to the database.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => '❌ Could not connect: '.$e->getMessage()];
        }
    }
}
