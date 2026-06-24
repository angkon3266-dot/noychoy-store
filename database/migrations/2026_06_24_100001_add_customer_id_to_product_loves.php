<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_loves', function (Blueprint $table) {
            // Link a love to a logged-in customer so it can surface in their account.
            $table->foreignId('customer_id')->nullable()->after('product_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_loves', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
