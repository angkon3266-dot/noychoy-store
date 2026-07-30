<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which admin has dismissed which alert.
 *
 * Alerts themselves are derived from live data rather than stored — a
 * low-stock warning is simply "this product's stock is low right now" — so the
 * only thing worth persisting is the human decision to stop being told. Keyed
 * by a stable string per alert (e.g. "stock.low.42") and by user, because one
 * admin marking something read shouldn't hide it from their colleague.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_alert_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('alert_key', 120);
            $table->timestamp('created_at')->nullable();

            // One row per admin per alert; re-reading is a no-op, not a duplicate.
            $table->unique(['user_id', 'alert_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_alert_reads');
    }
};
