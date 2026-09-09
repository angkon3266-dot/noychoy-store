<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A lead used to be a phone number and a list of product names. Everything
     * else the shopper typed — where to deliver it, which zone, who they are —
     * was thrown away unless they finished the order, so recovering the sale
     * meant asking for the address all over again on the phone.
     *
     * `visitor_token` is the one column that makes a lead's traffic source
     * knowable: visits are keyed on that cookie, abandoned carts on the session
     * id, and until now no row carried both.
     */
    public function up(): void
    {
        Schema::table('abandoned_carts', function (Blueprint $table) {
            if (! Schema::hasColumn('abandoned_carts', 'address')) {
                $table->text('address')->nullable()->after('email');
            }
            if (! Schema::hasColumn('abandoned_carts', 'area')) {
                $table->string('area', 120)->nullable()->after('address');
            }
            if (! Schema::hasColumn('abandoned_carts', 'is_inside_dhaka')) {
                // Nullable on purpose: "never chose a zone" is a different
                // fact from "chose outside Dhaka", and only one of them is
                // worth mentioning on the recovery call.
                $table->boolean('is_inside_dhaka')->nullable()->after('area');
            }
            if (! Schema::hasColumn('abandoned_carts', 'visitor_token')) {
                $table->string('visitor_token', 40)->nullable()->index()->after('session_id');
            }
            if (! Schema::hasColumn('abandoned_carts', 'last_contacted_at')) {
                $table->timestamp('last_contacted_at')->nullable()->after('contacted');
            }
        });

        // The sidebar badge and the dashboard alert both count exactly this
        // pair on every admin page render.
        Schema::table('abandoned_carts', function (Blueprint $table) {
            $table->index(['recovered', 'contacted'], 'abandoned_carts_open_index');
        });
    }

    public function down(): void
    {
        Schema::table('abandoned_carts', function (Blueprint $table) {
            // Both indexes go first. SQLite drops columns in place rather than
            // rebuilding the table, and it refuses to drop a column an index
            // still names ("error in index … after drop column"), so a down()
            // that only dropped the composite index would fail on `visitor_token`.
            $table->dropIndex('abandoned_carts_open_index');
            $table->dropIndex('abandoned_carts_visitor_token_index');
            $table->dropColumn(['address', 'area', 'is_inside_dhaka', 'visitor_token', 'last_contacted_at']);
        });
    }
};
