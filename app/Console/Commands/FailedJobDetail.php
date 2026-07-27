<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Show why queued jobs failed. `queue:failed` lists them but not the exception,
 * and this host can't run Tinker (shell_exec is disabled), so there is
 * otherwise no way to read failed_jobs.exception from the server.
 *
 * Read-only: never retries, never deletes.
 */
class FailedJobDetail extends Command
{
    protected $signature = 'queue:why
        {--job= : Only jobs whose class contains this string}
        {--lines=6 : Lines of stack trace to show}
        {--full : Show the whole exception}';

    protected $description = 'Show the exception behind each failed queue job (read-only)';

    public function handle(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            $this->warn('No failed_jobs table.');

            return self::SUCCESS;
        }

        $rows = DB::table('failed_jobs')->orderByDesc('failed_at')->get();

        if ($rows->isEmpty()) {
            $this->info('No failed jobs.');

            return self::SUCCESS;
        }

        $filter = (string) $this->option('job');
        $lines = max(1, (int) $this->option('lines'));

        // Group identical causes: eight jobs failing the same way is one problem.
        $groups = [];

        foreach ($rows as $row) {
            $class = $this->jobClass($row->payload);
            if ($filter !== '' && ! str_contains(strtolower($class), strtolower($filter))) {
                continue;
            }

            // First line of the exception is the message + where it was thrown.
            $headline = trim(strtok((string) $row->exception, "\n"));
            $key = $class.'|'.$headline;

            $groups[$key] ??= ['class' => $class, 'headline' => $headline, 'count' => 0, 'latest' => null, 'exception' => $row->exception, 'uuid' => $row->uuid];
            $groups[$key]['count']++;
            $groups[$key]['latest'] ??= $row->failed_at;
        }

        if ($groups === []) {
            $this->warn('No failed jobs matched.');

            return self::SUCCESS;
        }

        foreach ($groups as $g) {
            $this->line('');
            $this->line('<options=bold;fg=red>'.$g['count'].' × '.$g['class'].'</>');
            $this->line('  latest: '.$g['latest'].'   uuid: '.$g['uuid']);
            $this->line('  <fg=yellow>'.$g['headline'].'</>');

            if ($this->option('full')) {
                $this->line($g['exception']);

                continue;
            }

            $trace = array_slice(explode("\n", (string) $g['exception']), 1, $lines);
            foreach ($trace as $t) {
                if (trim($t) !== '') {
                    $this->line('    '.trim($t));
                }
            }
        }

        $this->line('');
        $this->info(count($groups).' distinct cause(s) across '.$rows->count().' failed job(s).');
        $this->line('Nothing was retried or deleted. Use --full for the complete trace.');

        return self::SUCCESS;
    }

    /** Pull the job class out of the serialized payload without unserializing it. */
    protected function jobClass(?string $payload): string
    {
        $data = json_decode((string) $payload, true);

        return $data['displayName']
            ?? ($data['data']['commandName'] ?? 'unknown job');
    }
}
