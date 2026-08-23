<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('abandoned_carts', 'sms_reminded_at')) {
            return;
        }

        Schema::table('abandoned_carts', function (Blueprint $table) {
            // Stamped when the recovery SMS is queued. Null = never texted.
            // Separate from push_reminded_at because they are different
            // channels with different costs: push is free, SMS is not.
            $table->timestamp('sms_reminded_at')->nullable()->after('contacted');
        });
    }

    public function down(): void
    {
        Schema::table('abandoned_carts', function (Blueprint $table) {
            $table->dropColumn('sms_reminded_at');
        });
    }
};
