<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BDCourier lookup results, stored per phone number.
 *
 * These started life in the cache, which turned out to be the wrong home: every
 * deploy runs `php artisan optimize:clear`, and that flushes the whole cache
 * store — so a result an admin had just paid a credit for vanished the next
 * time the site was updated. Redis eviction would do the same.
 *
 * A lookup costs money, so it belongs somewhere durable. Freshness is decided
 * by `checked_at` rather than by an expiry the store controls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_checks', function (Blueprint $table) {
            $table->id();
            // Canonical 01XXXXXXXXX, the same form orders and customers store.
            $table->string('phone', 20)->unique();
            $table->json('payload');
            $table->decimal('success_ratio', 5, 2)->default(0);
            $table->unsignedInteger('total_parcel')->default(0);
            $table->unsignedInteger('reports_count')->default(0);
            $table->timestamp('checked_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_checks');
    }
};
