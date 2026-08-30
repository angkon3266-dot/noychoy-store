<?php

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One chunk of a broadcast, sent as a single bulk call to the gateway.
 *
 * Queued rather than sent inside the web request. Six hundred customers is
 * seven gateway round-trips; at the gateway's 20-second timeout that is well
 * past PHP's max_execution_time, and a broadcast that dies half way through has
 * already charged the store for the half it sent. Off the request there is no
 * clock to beat, and a chunk that fails is retried on its own instead of
 * stopping the ones behind it.
 */
class SendBroadcastSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How many numbers go into one gateway call. */
    public const CHUNK = 100;

    public int $timeout = 300;

    public int $tries = 2;

    /**
     * Wait between attempts. The usual reason a chunk fails is the gateway
     * being briefly unreachable, and retrying instantly just fails again.
     */
    public int $backoff = 60;

    /** @param  array<int,string>  $phones */
    public function __construct(public array $phones, public string $message) {}

    public function handle(SmsService $sms): void
    {
        $phones = array_values(array_filter(array_map('trim', $this->phones)));

        if ($phones === []) {
            return;
        }

        // One request for the whole chunk: the gateway bills and rate-limits per
        // request, and 100 separate calls would take 100 times as long for the
        // same messages.
        $ok = $sms->send(implode(',', $phones), $this->message);

        if (! $ok) {
            Log::warning('SMS broadcast chunk not accepted', [
                'recipients' => count($phones),
                'attempt' => $this->attempts(),
            ]);

            // Let the queue retry it rather than silently dropping 100 people.
            throw new \RuntimeException('SMS gateway did not accept the broadcast chunk.');
        }
    }

    /**
     * Both attempts failed. The chunk is gone; say so where the store will see
     * it rather than leaving a silent hole in the send.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('SMS broadcast chunk failed for good', [
            'recipients' => count($this->phones),
            'error' => $e->getMessage(),
        ]);
    }
}
