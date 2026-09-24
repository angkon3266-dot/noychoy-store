<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Turn on the automatic BDCourier check for new orders (owner, 24 Sep 2026).
 *
 * "When a new order comes, the courier history with BDCourier should
 * automatically be checked. Skip for repeat buyers — they should show earlier
 * history." The behaviour is App\Jobs\CheckOrderCourier; this only flips the
 * switch it reads, so it arrives on with the deploy rather than waiting for
 * someone to find the checkbox in Admin → Integrations.
 *
 * Why a migration and not a config default: the live `integrations` setting is a
 * stored row, and a stored row beats anything in config — the shop's saved
 * integrations were written long before this key existed, so a default here
 * would never be read. The row has to be rewritten.
 *
 * This costs credits: one per first-ever order from a phone number, and nothing
 * for a returning customer. Undo it with the checkbox — or roll this migration
 * back, which puts the key back to off.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->set(true);
    }

    public function down(): void
    {
        $this->set(false);
    }

    /**
     * Rewrite just this one key, leaving the API key, base URL and thresholds
     * beside it exactly as the shop has them.
     */
    protected function set(bool $on): void
    {
        $int = Setting::get('integrations', []);
        $int = is_array($int) ? $int : [];

        $int['bdcourier_auto_check'] = $on;

        Setting::put('integrations', $int);
    }
};
