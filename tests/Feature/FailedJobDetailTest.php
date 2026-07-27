<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `queue:failed` lists failures but not their cause, and this host can't run
 * Tinker — so this command is the only way to read failed_jobs.exception on the
 * server. It must group identical causes and never mutate anything.
 */
class FailedJobDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function failure(string $class, string $message, string $at = '2026-07-27 15:27:42'): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $class, 'data' => ['commandName' => $class]]),
            'exception' => $message."\n#0 /app/vendor/some/frame.php(12): Something->call()\n#1 {main}",
            'failed_at' => $at,
        ]);
    }

    public function test_it_groups_identical_causes(): void
    {
        // The real-world shape: several jobs, one underlying problem.
        for ($i = 0; $i < 3; $i++) {
            $this->failure('App\Jobs\SendOrderPlacedEffects', 'ModelNotFoundException: No query results for model [App\Models\Order] 42');
        }
        $this->failure('App\Jobs\SendWebPush', 'RuntimeException: gateway timeout');

        $this->artisan('queue:why')
            ->expectsOutputToContain('3 × App\Jobs\SendOrderPlacedEffects')
            ->expectsOutputToContain('ModelNotFoundException')
            ->expectsOutputToContain('2 distinct cause(s) across 4 failed job(s)')
            ->assertExitCode(0);
    }

    public function test_it_can_filter_to_one_job_class(): void
    {
        $this->failure('App\Jobs\SendOrderPlacedEffects', 'ModelNotFoundException: order gone');
        $this->failure('App\Jobs\SendWebPush', 'RuntimeException: gateway timeout');

        $this->artisan('queue:why --job=SendOrderPlaced')
            ->expectsOutputToContain('SendOrderPlacedEffects')
            ->doesntExpectOutputToContain('SendWebPush')
            ->assertExitCode(0);
    }

    public function test_it_never_deletes_or_retries(): void
    {
        $this->failure('App\Jobs\SendOrderPlacedEffects', 'ModelNotFoundException: order gone');

        $this->artisan('queue:why')->assertExitCode(0);

        $this->assertSame(1, DB::table('failed_jobs')->count());
    }
}
