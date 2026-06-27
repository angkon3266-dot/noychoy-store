<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recover data from the old SQLite database file into the current (MySQL) default
 * connection. Run on the server AFTER switching DB_CONNECTION to mysql and running
 * `php artisan migrate --force` (so the MySQL tables exist).
 *
 *   php artisan data:from-sqlite
 *   php artisan data:from-sqlite --path=/home/user/old/database.sqlite
 */
class ImportFromSqlite extends Command
{
    protected $signature = 'data:from-sqlite {--path= : Path to the old database.sqlite (defaults to database/database.sqlite)} {--force : Skip confirmation}';

    protected $description = 'Copy all data from the old SQLite file into the current MySQL database';

    /** Framework/transient tables we never copy. */
    protected array $skip = [
        'migrations', 'cache', 'cache_locks', 'sessions',
        'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ];

    /** Business tables in FK-safe order (parents before children). */
    protected array $tables = [
        'users', 'settings', 'categories', 'products', 'product_images', 'product_variants',
        'category_product', 'coupons', 'offers', 'customers', 'addresses', 'customer_offers',
        'point_transactions', 'orders', 'order_items', 'order_status_history', 'shipments',
        'reviews', 'product_loves', 'abandoned_carts', 'sms_logs',
        'suppliers', 'purchase_orders', 'purchase_order_items',
    ];

    public function handle(): int
    {
        $path = $this->option('path') ?: database_path('database.sqlite');

        if (! file_exists($path)) {
            $this->error("SQLite file not found at: {$path}");
            $this->line('Pass the correct path with --path=/full/path/to/database.sqlite');

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->error('Your default connection is still SQLite. Switch DB_CONNECTION=mysql in .env first.');

            return self::FAILURE;
        }

        config(['database.connections.sqlite_src' => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        $this->warn('This will REPLACE data in the current MySQL database with the contents of:');
        $this->line('  '.$path);
        if (! $this->option('force') && ! $this->confirm('Continue?', true)) {
            return self::SUCCESS;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $totalRows = 0;
        foreach ($this->tables as $table) {
            if (in_array($table, $this->skip, true)) {
                continue;
            }
            if (! Schema::connection('sqlite_src')->hasTable($table) || ! Schema::hasTable($table)) {
                $this->line("  · skip {$table} (missing in source or target)");
                continue;
            }

            // Only copy columns that exist in BOTH schemas (tolerant of drift).
            $srcCols = Schema::connection('sqlite_src')->getColumnListing($table);
            $dstCols = Schema::getColumnListing($table);
            $cols = array_values(array_intersect($srcCols, $dstCols));

            $rows = DB::connection('sqlite_src')->table($table)->get();
            DB::table($table)->truncate();

            $rows->chunk(500)->each(function ($chunk) use ($table, $cols) {
                $payload = $chunk->map(fn ($row) => collect((array) $row)->only($cols)->all())->all();
                if ($payload) {
                    DB::table($table)->insert($payload);
                }
            });

            $count = $rows->count();
            $totalRows += $count;
            $this->info(sprintf('  ✓ %-22s %d rows', $table, $count));
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->newLine();
        $this->info("Done — copied {$totalRows} rows into MySQL.");
        $this->line('Now run: php artisan config:cache && php artisan view:cache');

        return self::SUCCESS;
    }
}
