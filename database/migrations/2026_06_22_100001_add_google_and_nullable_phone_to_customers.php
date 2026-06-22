<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('google_id')->nullable()->index()->after('woo_id');
            $table->string('avatar')->nullable()->after('google_id');
        });

        // Google sign-ups have no phone yet, so phone must allow NULL.
        // Doctrine/DBAL isn't required for this on SQLite/MySQL via raw change.
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'avatar']);
        });
    }
};
