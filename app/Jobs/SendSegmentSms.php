<?php

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Send one SMS to each phone in the batch (queued so a large send doesn't block). */
class SendSegmentSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    /** @param array<int,string> $phones */
    public function __construct(public array $phones, public string $message) {}

    public function handle(SmsService $sms): void
    {
        foreach ($this->phones as $phone) {
            if (blank($phone)) {
                continue;
            }
            try {
                $sms->send($phone, $this->message);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
