<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('courier')->default('steadfast');
            $table->string('consignment_id')->nullable()->index();
            $table->string('tracking_code')->nullable()->index();
            $table->decimal('cod_amount', 12, 2)->default(0);
            $table->string('status')->nullable(); // steadfast delivery status
            $table->json('response')->nullable();  // raw API payload
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
