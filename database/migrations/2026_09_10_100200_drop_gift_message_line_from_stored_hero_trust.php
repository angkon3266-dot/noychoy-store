<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * "Gift message included" has come off the hero trust line. Changing the
 * shipped default does nothing for a store whose owner ever saved the
 * homepage form — the stored value wins — so drop the line from there too.
 * Every other line is left exactly as saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $home = Setting::get('home_content');

        if (! is_array($home) || ! is_array($home['hero_trust'] ?? null)) {
            return;
        }

        $kept = array_values(array_filter(
            $home['hero_trust'],
            fn ($line) => ! (is_string($line) && preg_match('/gift\s*message/i', $line)),
        ));

        if (count($kept) !== count($home['hero_trust'])) {
            $home['hero_trust'] = $kept;
            Setting::put('home_content', $home);
        }
    }

    public function down(): void
    {
        // One-way on purpose: the line was removed deliberately, not lost.
    }
};
