<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a funnel event was worth.
 *
 * The funnel could say "one person reached checkout" but never "…carrying
 * ৳4,500", which is the half that decides whether an abandoned checkout is
 * worth chasing. Both record points already know the money — the cart add has
 * the line total, the checkout start computes a subtotal for Meta — so it costs
 * nothing to keep it.
 *
 * Null, not 0, for events recorded before this column existed and for events
 * that have no money attached (a pageview): "we did not measure this" and
 * "this was worth nothing" are different facts and the dashboard reports them
 * differently.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('visits') || Schema::hasColumn('visits', 'value')) {
            return;
        }

        Schema::table('visits', function (Blueprint $table) {
            $table->decimal('value', 10, 2)->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('visits') || ! Schema::hasColumn('visits', 'value')) {
            return;
        }

        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('value');
        });
    }
};
