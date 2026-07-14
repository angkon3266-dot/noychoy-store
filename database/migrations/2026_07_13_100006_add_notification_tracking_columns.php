<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Everything created after this is "unread" for the customer.
            $table->timestamp('notifications_read_at')->nullable()->after('points');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('announced_at')->nullable()->after('status');           // included in a new-arrivals notification
            $table->timestamp('preorder_announced_at')->nullable()->after('announced_at');
        });

        // Existing products are considered already-announced so the first batch
        // doesn't notify members about the entire back-catalogue.
        \DB::table('products')->whereNull('announced_at')->update(['announced_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('notifications_read_at'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['announced_at', 'preorder_announced_at']));
    }
};
