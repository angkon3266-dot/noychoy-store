<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each visit and each order was made on (owner, 6 Oct 2026: "add option
 * to see in which device the site is being browsed") — phone / tablet /
 * computer, the system, and the browser or in-app browser
 * (App\Support\DeviceDetector). Only the three coarse labels are kept, never
 * the raw user agent.
 *
 * Null for everything before this ran: "not measured" is not "computer".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('visits') && ! Schema::hasColumn('visits', 'device')) {
            Schema::table('visits', function (Blueprint $table) {
                $table->string('device', 10)->nullable()->after('ad_id');
                $table->string('os', 16)->nullable()->after('device');
                $table->string('browser', 24)->nullable()->after('os');
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'device')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('device', 10)->nullable();
                $table->string('browser', 24)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('visits') && Schema::hasColumn('visits', 'device')) {
            Schema::table('visits', function (Blueprint $table) {
                $table->dropColumn(['device', 'os', 'browser']);
            });
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'device')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn(['device', 'browser']);
            });
        }
    }
};
