<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact')->nullable();
            $table->string('country')->default('China');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('wechat')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number')->nullable()->unique();      // e.g. PO-202606-A3B7
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');           // pending|ordered|shipped|received|cancelled
            $table->date('ordered_at')->nullable();
            $table->date('expected_at')->nullable();
            $table->date('arrived_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('courier_name')->nullable();
            $table->string('courier_tracking')->nullable();
            $table->decimal('courier_cost', 12, 2)->nullable();     // shipping cost in $currency
            $table->decimal('processing_pct', 6, 2)->nullable();    // processing fee as % of (items + courier)
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->decimal('exchange_rate', 10, 4)->nullable();    // currency → BDT
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete(); // link to our catalog
            $table->string('product_name');
            $table->string('sku')->nullable();
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->unsignedInteger('received_qty')->default(0);
            $table->string('product_link')->nullable();
            $table->string('image_url')->nullable();
            $table->string('color')->nullable();
            $table->string('size')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
