<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the supplier ships to, as printed in the PDF's "Ship to" box — usually a
 * forwarder's warehouse rather than the store, so it is the owner's to write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->text('ship_to')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('ship_to');
        });
    }
};
