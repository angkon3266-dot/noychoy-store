<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delete rows that have aged out of the diagnostic tables.
 *
 * These tables grow with traffic rather than with the catalogue: `visits` gains
 * a row per pageview, so an ad campaign can add hundreds of thousands of rows a
 * year. Left alone they fill a shared host's disk quota and slow every
 * COUNT(DISTINCT …) the dashboard runs.
 *
 * Deliberately conservative: only diagnostic tables, never business records,
 * and a table that doesn't exist is skipped rather than throwing.
 */
class PruneLogs extends Command
{
    protected $signature = 'logs:prune
        {--dry-run : Report what would be deleted without deleting it}
        {--table= : Prune only this table}
        {--archive-legacy : Compress and remove an oversized pre-rotation laravel.log}';

    protected $description = 'Delete diagnostic log rows older than their retention window';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('table');
        $chunk = max(100, (int) config('retention.chunk', 1000));
        $total = 0;

        foreach ((array) config('retention.days', []) as $table => $days) {
            if ($only && $only !== $table) {
                continue;
            }
            if ((int) $days <= 0) {
                $this->line("{$table}: retention disabled, skipped");

                continue;
            }
            if (! Schema::hasTable($table)) {
                $this->line("{$table}: table not present, skipped");

                continue;
            }

            $cutoff = now()->subDays((int) $days);
            $stale = DB::table($table)->where('created_at', '<', $cutoff);

            $count = (clone $stale)->count();
            if ($count === 0) {
                $this->line("{$table}: nothing older than {$days} days");

                continue;
            }

            if ($dry) {
                $this->line("{$table}: would delete <fg=yellow>{$count}</> row(s) older than {$days} days");
                $total += $count;

                continue;
            }

            // Delete in batches so one statement never runs long enough to lock
            // the table or trip the host's execution limit.
            $deleted = 0;
            do {
                $n = DB::table($table)->where('created_at', '<', $cutoff)->limit($chunk)->delete();
                $deleted += $n;
            } while ($n > 0);

            $this->line("{$table}: deleted <fg=green>{$deleted}</> row(s) older than {$days} days");
            $total += $deleted;
        }

        $this->legacyLogFile($dry);

        $this->info($dry
            ? "Dry run — {$total} row(s) would be removed."
            : "Done — {$total} row(s) removed.");

        return self::SUCCESS;
    }

    /**
     * The un-rotated laravel.log written before the daily channel was adopted.
     * Rotation stops it growing but does not remove what already accumulated,
     * and that file is not something to delete on a schedule — so it is only
     * reported, and only archived when explicitly asked for.
     */
    protected function legacyLogFile(bool $dry): void
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return;
        }

        $mb = round(filesize($path) / 1048576, 1);

        if ($mb < (float) config('retention.legacy_log_mb', 20)) {
            return;                       // small enough not to matter
        }

        if (! $this->option('archive-legacy')) {
            $this->line('');
            $this->warn("laravel.log is {$mb} MB — written before rotation was enabled, and now orphaned.");
            $this->line('  Logging has moved to laravel-YYYY-MM-DD.log, so this file no longer grows.');
            $this->line('  To compress and remove it: php artisan logs:prune --archive-legacy');

            return;
        }

        if ($dry) {
            $this->warn("Would archive laravel.log ({$mb} MB) to laravel.log.gz");

            return;
        }

        // Keep the contents — gzip, verify, then remove the original.
        $gz = $path.'.'.now()->format('Ymd-His').'.gz';

        $in = fopen($path, 'rb');
        $out = gzopen($gz, 'wb9');

        if (! $in || ! $out) {
            $this->error('Could not open the log for archiving; nothing removed.');

            return;
        }

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1048576));
        }
        fclose($in);
        gzclose($out);

        if (! is_file($gz) || filesize($gz) === 0) {
            $this->error('Archive looks empty; the original was left in place.');

            return;
        }

        unlink($path);
        $this->info('Archived laravel.log to '.basename($gz).' ('.round(filesize($gz) / 1048576, 1).' MB) and removed the original.');
    }
}
