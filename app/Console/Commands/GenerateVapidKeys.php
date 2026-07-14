<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\WebPushService;
use Illuminate\Console\Command;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:keys {--force : Overwrite existing keys (invalidates all current subscriptions)}';

    protected $description = 'Generate the VAPID keypair used to sign web-push messages.';

    public function handle(WebPushService $push): int
    {
        if (filled(Setting::get('webpush_public_key')) && ! $this->option('force')) {
            $this->warn('VAPID keys already exist. Re-run with --force to replace them (this will break existing subscriptions).');

            return self::FAILURE;
        }

        try {
            $keys = $push->generateKeys();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Setting::put('webpush_public_key', $keys['public']);
        Setting::put('webpush_private_key', $keys['private']);

        $this->info('VAPID keys generated and saved.');
        $this->line('Public key: '.$keys['public']);

        return self::SUCCESS;
    }
}
