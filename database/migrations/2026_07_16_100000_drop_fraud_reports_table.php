<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the courier fraud-check integration: drops the cached reports table
 * and deletes any stored courier-portal credentials from settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fraud_reports');

        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', 'like', 'fraud%')->delete();
        }
    }

    public function down(): void
    {
        // One-way removal — the fraud-check feature is gone.
    }
};
