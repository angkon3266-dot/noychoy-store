<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;

/**
 * Applies the admin-managed SMTP settings (stored in the settings table) to the
 * live mailer config at runtime. Lets the store owner manage email from the
 * dashboard instead of editing .env — and works even when config is cached,
 * because it overrides the in-memory config after the cache loads.
 */
class MailConfigurator
{
    public function apply(): void
    {
        try {
            if (! Setting::get('mail_enabled', false)) {
                return;
            }

            $host = Setting::get('mail_host');
            if (blank($host)) {
                return;
            }

            $encryption = Setting::get('mail_encryption', 'ssl');

            Config::set('mail.default', 'smtp');
            Config::set('mail.mailers.smtp.host', $host);
            Config::set('mail.mailers.smtp.port', (int) Setting::get('mail_port', 465));
            Config::set('mail.mailers.smtp.username', Setting::get('mail_username'));
            Config::set('mail.mailers.smtp.password', Setting::get('mail_password'));
            Config::set('mail.mailers.smtp.encryption', $encryption === 'none' ? null : $encryption);

            if (filled($from = Setting::get('mail_from_address'))) {
                Config::set('mail.from.address', $from);
            }
            if (filled($fromName = Setting::get('mail_from_name'))) {
                Config::set('mail.from.name', $fromName);
            }
        } catch (\Throwable $e) {
            // Settings table not ready (fresh install / mid-migration) — leave .env config in place.
        }
    }
}
