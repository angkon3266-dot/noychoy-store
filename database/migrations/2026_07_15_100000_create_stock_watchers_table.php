<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_watchers')) {
            Schema::create('stock_watchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('push_subscription_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['product_id', 'push_subscription_id'], 'stock_watcher_unique');
            });
        }

        // Track when an abandoned cart was push-reminded (separate from admin "contacted").
        if (Schema::hasTable('abandoned_carts') && ! Schema::hasColumn('abandoned_carts', 'push_reminded_at')) {
            Schema::table('abandoned_carts', function (Blueprint $table) {
                $table->timestamp('push_reminded_at')->nullable()->after('contacted');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_watchers');
        if (Schema::hasColumn('abandoned_carts', 'push_reminded_at')) {
            Schema::table('abandoned_carts', fn (Blueprint $t) => $t->dropColumn('push_reminded_at'));
        }
    }
};
