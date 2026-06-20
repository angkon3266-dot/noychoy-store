<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('customer_email')->nullable();
            $table->text('shipping_address');
            $table->string('area')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->boolean('is_inside_dhaka')->default(false);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('shipping_cost', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('payment_method')->default('cod');
            $table->string('payment_status')->default('unpaid'); // unpaid | paid | refunded
            // pending | confirmed | processing | shipped | delivered | cancelled | returned
            $table->string('status')->default('pending');
            $table->string('coupon_code')->nullable();
            $table->text('notes')->nullable();       // customer note
            $table->text('admin_notes')->nullable(); // internal
            $table->string('source')->default('web');
            $table->unsignedBigInteger('woo_id')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
