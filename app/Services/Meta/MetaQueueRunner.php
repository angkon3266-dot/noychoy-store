<?php

namespace App\Services\Meta;

use Illuminate\Support\Facades\Log;

/**
 * Best-effort "instant" queue drain for the Meta sync queue.
 *
 * This shared host has no long-running queue daemon, so the scheduled
 * `queue:work --stop-when-empty` (routes/console.php) is the *guaranteed* way
 * batched sync jobs get processed — but it only fires once a minute. To make
 * the merchant see products syncing the moment they click "Sync", we also try
 * to spawn a one-shot detached worker right after the batch is dispatched.
 *
 * It is intentionally best-effort: if exec() is disabled (common on shared
 * hosting) or the platform is Windows, we silently do nothing and rely on the
 * scheduler. It never throws into the web request.
 */
class MetaQueueRunner
{
    /** Kick a detached worker that drains the Meta queue, then exits. */
    public function kick(): void
    {
        try {
            if (! $this->canSpawn()) {
                return; // scheduler will pick the batch up within ~60s
            }

            $php = $this->phpBinary();
            $artisan = base_path('artisan');
            $connection = env('QUEUE_CONNECTION', 'database');
            $queue = config('meta.sync.queue', 'default');

            // Detach fully (setsid + nohup + background) so the worker keeps
            // running after this web request returns, and drain until empty.
            $command = sprintf(
                'setsid nohup %s %s queue:work %s --queue=%s --stop-when-empty --max-time=110 --sleep=1 --no-interaction > /dev/null 2>&1 &',
                escapeshellarg($php),
                escapeshellarg($artisan),
                escapeshellarg($connection),
                escapeshellarg($queue),
            );

            exec($command);
        } catch (\Throwable $e) {
            // Never let a failed kick break the dispatch — the scheduler covers it.
            Log::debug('Meta queue instant-kick skipped', ['error' => $e->getMessage()]);
        }
    }

    /** Whether we can safely spawn a background process on this platform. */
    private function canSpawn(): bool
    {
        if (stripos(PHP_OS, 'WIN') === 0) {
            return false; // the detach syntax below is POSIX-only
        }

        if (! function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('exec', $disabled, true);
    }

    /**
     * Resolve a PHP *CLI* binary. PHP_BINARY under a web SAPI points at the
     * FPM/LiteSpeed binary, which usually cannot run artisan — so prefer an
     * explicitly configured CLI path, then fall back to `php` on the PATH.
     */
    private function phpBinary(): string
    {
        $configured = config('meta.sync.worker_php');
        if (filled($configured)) {
            return $configured;
        }

        if (PHP_SAPI === 'cli' && defined('PHP_BINARY') && PHP_BINARY) {
            return PHP_BINARY;
        }

        return 'php';
    }
}
