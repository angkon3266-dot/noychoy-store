<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each admin's own arrangement of the dashboard.
 *
 * Owner, 2026-09-18: "I need to be able to move around the analytics, top,
 * bottom as per my needs." Stored on the user rather than in settings because
 * the need is personal — the card she reads first on her phone each morning
 * is not the one the manager reads first at a desk.
 *
 *  - dashboard_layout  {"order": [block keys…], "hidden": [block keys…]}, as
 *                      App\Support\DashboardLayout reads and writes it. Null
 *                      is "never arranged": the registry's default order, with
 *                      the old store-wide ⚙ panel choice still honoured.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'dashboard_layout')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('dashboard_layout')->nullable()->after('role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'dashboard_layout')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('dashboard_layout');
            });
        }
    }
};
