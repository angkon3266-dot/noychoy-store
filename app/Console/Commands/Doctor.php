<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One command that answers "why is the site erroring?" without needing Tinker
 * (which this host can't run — shell_exec is disabled) or log spelunking.
 *
 * Checks the things that actually break in production: a migration that didn't
 * apply, an unwritable storage directory, a stalled queue, a cache driver that
 * isn't there.
 */
class Doctor extends Command
{
    protected $signature = 'app:doctor';

    protected $description = 'Check the database schema, queue, cache and storage for problems';

    /**
     * Columns that features depend on. If a migration silently didn't apply,
     * this is what tells you which one — and what it takes down with it.
     */
    protected const REQUIRED = [
        'orders.source_channel' => 'order attribution + dashboard traffic panel',
        'orders.source_campaign' => 'dashboard "campaigns that sold"',
        'orders.card_message' => 'per-order thank-you card message',
        'visits.source' => 'traffic source classification',
        'visits.campaign' => 'campaign attribution',
        'push_subscriptions.audience' => 'staff order alerts (and the scheduler)',
        'products.serial' => 'product IDs',
        'categories.google_category' => 'Google product category in the Meta feed',
    ];

    public function handle(): int
    {
        $problems = 0;

        $this->line('');
        $this->line('<options=bold>Database</>');
        $problems += $this->checkMigrations();
        $problems += $this->checkColumns();

        $this->line('');
        $this->line('<options=bold>Runtime</>');
        $problems += $this->checkStorage();
        $problems += $this->checkCache();
        $problems += $this->checkQueue();

        $this->line('');
        if ($problems === 0) {
            $this->info('No problems found.');

            return self::SUCCESS;
        }

        $this->warn($problems.' problem(s) found. Fix the database ones first — a missing column breaks every page that reads it.');

        return self::FAILURE;
    }

    protected function checkMigrations(): int
    {
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
        } catch (\Throwable $e) {
            $this->fail_('migrations table unreadable: '.$e->getMessage());

            return 1;
        }

        $files = collect(glob(database_path('migrations/*.php')))
            ->map(fn ($p) => basename($p, '.php'))->all();

        $pending = array_values(array_diff($files, $ran));

        if ($pending === []) {
            $this->ok('all '.count($files).' migrations applied');

            return 0;
        }

        $this->fail_(count($pending).' migration(s) NOT applied — run: php artisan migrate --force');
        foreach ($pending as $m) {
            $this->line('     · '.$m);
        }

        return 1;
    }

    protected function checkColumns(): int
    {
        $missing = [];

        foreach (self::REQUIRED as $path => $feature) {
            [$table, $column] = explode('.', $path, 2);
            try {
                if (! Schema::hasTable($table)) {
                    $missing[] = [$path, 'table missing', $feature];
                } elseif (! Schema::hasColumn($table, $column)) {
                    $missing[] = [$path, 'column missing', $feature];
                }
            } catch (\Throwable $e) {
                $missing[] = [$path, 'check failed', $feature];
            }
        }

        if ($missing === []) {
            $this->ok('all '.count(self::REQUIRED).' feature columns present');

            return 0;
        }

        $this->fail_(count($missing).' expected column(s) missing:');
        foreach ($missing as [$path, $why, $feature]) {
            $this->line("     · <fg=red>{$path}</> ({$why}) — breaks: {$feature}");
        }

        return 1;
    }

    protected function checkStorage(): int
    {
        $paths = [storage_path('logs'), storage_path('framework/views'), storage_path('app/public')];
        $bad = array_values(array_filter($paths, fn ($p) => ! is_writable($p)));

        if ($bad === []) {
            $this->ok('storage directories writable');

            return 0;
        }

        $this->fail_('not writable: '.implode(', ', $bad));

        return 1;
    }

    protected function checkCache(): int
    {
        try {
            $key = 'doctor.'.bin2hex(random_bytes(4));
            cache()->put($key, 'ok', 10);
            $got = cache()->get($key);
            cache()->forget($key);

            if ($got !== 'ok') {
                $this->fail_('cache store ('.config('cache.default').') did not return what was written');

                return 1;
            }
            $this->ok('cache store ('.config('cache.default').') working');

            return 0;
        } catch (\Throwable $e) {
            $this->fail_('cache store ('.config('cache.default').') failed: '.$e->getMessage());

            return 1;
        }
    }

    protected function checkQueue(): int
    {
        try {
            if (config('queue.default') !== 'database') {
                $this->ok('queue driver: '.config('queue.default'));

                return 0;
            }

            $pending = DB::table('jobs')->count();
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
            $oldest = DB::table('jobs')->min('created_at');

            // A backlog is only a problem if it isn't moving.
            $stalled = $pending > 0 && $oldest && (time() - (int) $oldest) > 600;

            if ($stalled) {
                $this->fail_("queue has {$pending} job(s), oldest ".round((time() - (int) $oldest) / 60)." min old — the scheduler may not be running (check cron runs: php artisan schedule:run)");

                return 1;
            }

            $this->ok("queue: {$pending} pending, {$failed} failed");

            return 0;
        } catch (\Throwable $e) {
            $this->fail_('queue check failed: '.$e->getMessage());

            return 1;
        }
    }

    protected function ok(string $msg): void
    {
        $this->line('  <fg=green>OK</>   '.$msg);
    }

    protected function fail_(string $msg): void
    {
        $this->line('  <fg=red>FAIL</> '.$msg);
    }
}
