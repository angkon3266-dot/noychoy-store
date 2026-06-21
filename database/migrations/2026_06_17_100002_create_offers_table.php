<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('title');                       // shown to customer, e.g. "Free delivery over ৳2000"
            $table->string('description')->nullable();
            // free_shipping | order_percent
            $table->string('type')->default('order_percent');
            $table->decimal('percent', 5, 2)->nullable();  // for order_percent
            $table->decimal('min_subtotal', 12, 2)->nullable(); // condition: cart subtotal ≥
            $table->unsignedInteger('min_qty')->nullable();     // condition: total items ≥
            $table->boolean('members_only')->default(false);    // only for logged-in customers
            $table->string('badge_label')->nullable();          // short tag on product card/PDP
            $table->boolean('show_on_pdp')->default(true);      // highlight on product page
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
