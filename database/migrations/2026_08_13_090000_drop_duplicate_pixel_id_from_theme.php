<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Remove the second copy of the Meta Pixel ID.
 *
 * It lived under Appearance → Marketing as `theme.meta_pixel_id` while the real
 * one lived in Meta Integration, and meta_pixel_id() preferred the latter. Two
 * editable copies with a silent precedence order meant an admin could read one
 * Pixel ID in the Appearance form while a completely different one fired on the
 * storefront — which is exactly what this install had.
 *
 * The Meta Integration value is the one that has been live, so it is left
 * untouched and this only clears the stale copy. Nothing about what fires
 * changes; the number simply stops being editable in a place that never won.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Read the row directly rather than through Setting::get(): that reads
        // through a forever-cache, and a data migration must not depend on
        // whether some earlier process happened to warm or bust it.
        $row = DB::table('settings')->where('key', 'theme')->value('value');

        if ($row === null) {
            return;
        }

        $theme = json_decode($row, true);

        if (! is_array($theme) || ! array_key_exists('meta_pixel_id', $theme)) {
            return;
        }

        unset($theme['meta_pixel_id']);

        DB::table('settings')->where('key', 'theme')->update([
            'value' => json_encode($theme),
            'updated_at' => now(),
        ]);

        // The cached copy still holds the key we just removed.
        Cache::forget('settings.all');
        Setting::flushMemo();
    }

    public function down(): void
    {
        // Nothing to restore: the key was dead weight, and re-adding an empty
        // one would just recreate the ambiguity this removed.
    }
};
