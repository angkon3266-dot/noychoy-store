<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'category_id']);
        });

        // Backfill the pivot from the existing single category_id so nothing is lost.
        $rows = \DB::table('products')->whereNotNull('category_id')->get(['id', 'category_id']);
        foreach ($rows as $r) {
            \DB::table('category_product')->insertOrIgnore([
                'product_id' => $r->id,
                'category_id' => $r->category_id,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
    }
};
