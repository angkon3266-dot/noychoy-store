<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_preorder')->default(false)->after('in_stock');
            $table->date('preorder_release_date')->nullable()->after('is_preorder');
            $table->string('preorder_note')->nullable()->after('preorder_release_date');
        });

        Schema::table('categories', function (Blueprint $table) {
            // Category-level default: products in this category are pre-order.
            $table->boolean('is_preorder')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_preorder', 'preorder_release_date', 'preorder_note']);
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_preorder');
        });
    }
};
