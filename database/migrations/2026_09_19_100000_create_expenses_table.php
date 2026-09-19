<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the business spends (owner, 2026-09-19: "log expenses related to
 * business with date and all").
 *
 * `spent_on` is the day the money went out on the shop's own calendar, a plain
 * date rather than a timestamp — an ad bill paid "on the 3rd" is the 3rd in
 * Dhaka whatever the server's clock says. The category is free text with a
 * suggested list (App\Models\Expense::CATEGORIES), because the owner will
 * want her own. A receipt is optional and stored privately.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('expenses')) {
            return;
        }

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('spent_on');
            $table->string('category', 60);
            $table->decimal('amount', 12, 2);
            $table->string('description', 255)->nullable();
            $table->string('paid_to', 120)->nullable();
            $table->string('paid_via', 40)->nullable();
            $table->string('receipt_path')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['spent_on', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
