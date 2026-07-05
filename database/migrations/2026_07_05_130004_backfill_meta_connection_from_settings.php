<?php

use App\Models\MetaAsset;
use App\Models\MetaConnection;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;

/**
 * One-time, idempotent backfill: seed the new Token Manager (meta_connections +
 * meta_assets) from the existing Setting('meta_integration') so the modular
 * architecture starts with the current live connection already in place. The
 * legacy Setting is left untouched — nothing breaks.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Skip if already connected, or nothing to migrate.
        if (MetaConnection::where('provider', 'meta')->exists()) {
            return;
        }

        $settings = Setting::get('meta_integration');
        if (! is_array($settings) || empty($settings)) {
            return;
        }

        $token = null;
        if (! empty($settings['token_encrypted'])) {
            try {
                $token = Crypt::decryptString($settings['token_encrypted']);
            } catch (\Throwable) {
                $token = null;
            }
        }

        if (! $token && empty($settings['business_id']) && empty($settings['catalog_id'])) {
            return; // nothing worth migrating
        }

        $connection = MetaConnection::create([
            'provider' => 'meta',
            'access_token' => $token,
            'token_expires_at' => $settings['token_expires_at'] ?? null,
            // Commerce is what the current install used — record its scopes as granted.
            'granted_scopes' => $token ? ['business_management', 'catalog_management'] : [],
            'business_id' => $settings['business_id'] ?? null,
            'business_name' => $settings['connected_business_name'] ?? null,
            'health_status' => ($settings['last_connection_ok'] ?? false) ? 'ok' : ($token ? 'needs_reconnect' : 'disconnected'),
            'last_health_at' => $settings['last_connection_at'] ?? null,
        ]);

        if (! empty($settings['catalog_id'])) {
            MetaAsset::create([
                'meta_connection_id' => $connection->id,
                'type' => 'catalog',
                'external_id' => (string) $settings['catalog_id'],
                'name' => $settings['connected_catalog_name'] ?? null,
                'is_selected' => true,
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive backfill — nothing to reverse (legacy Setting remains).
    }
};
