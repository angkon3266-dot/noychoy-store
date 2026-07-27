<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Diagnostic tables grow with traffic, not with the catalogue — `visits` gains
 * a row per pageview. Pruning has to remove what's aged out and, more
 * importantly, never touch anything inside the retention window.
 */
class PruneLogsTest extends TestCase
{
    use RefreshDatabase;

    protected function visit(string $token, int $daysAgo): void
    {
        DB::table('visits')->insert([
            'visitor_token' => $token,
            'event' => 'page',
            'path' => '/',
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_it_deletes_only_rows_past_the_retention_window(): void
    {
        config(['retention.days' => ['visits' => 90]]);

        $this->visit('old', 120);
        $this->visit('edge', 89);      // inside the window by a day
        $this->visit('fresh', 1);

        $this->artisan('logs:prune')->assertExitCode(0);

        $remaining = DB::table('visits')->pluck('visitor_token')->all();
        $this->assertEqualsCanonicalizing(['edge', 'fresh'], $remaining);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        config(['retention.days' => ['visits' => 30]]);
        $this->visit('old', 400);

        $this->artisan('logs:prune --dry-run')->assertExitCode(0);

        $this->assertSame(1, DB::table('visits')->count());
    }

    public function test_zero_days_disables_pruning_for_a_table(): void
    {
        config(['retention.days' => ['visits' => 0]]);
        $this->visit('ancient', 5000);

        $this->artisan('logs:prune')->assertExitCode(0);

        $this->assertSame(1, DB::table('visits')->count());
    }

    public function test_a_missing_table_is_skipped_rather_than_fatal(): void
    {
        config(['retention.days' => ['table_that_does_not_exist' => 30, 'visits' => 30]]);
        $this->visit('old', 90);

        $this->artisan('logs:prune')->assertExitCode(0);

        $this->assertSame(0, DB::table('visits')->count());
    }

    public function test_batching_clears_a_table_larger_than_one_chunk(): void
    {
        config(['retention.days' => ['visits' => 10], 'retention.chunk' => 10]);

        for ($i = 0; $i < 25; $i++) {
            $this->visit('old-'.$i, 40);
        }
        $this->visit('keep', 1);

        $this->artisan('logs:prune')->assertExitCode(0);

        $this->assertSame(['keep'], DB::table('visits')->pluck('visitor_token')->all());
    }
}
