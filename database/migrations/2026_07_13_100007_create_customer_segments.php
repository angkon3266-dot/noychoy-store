<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gender (optional) so segments can target by it.
        Schema::table('customers', function (Blueprint $table) {
            $table->string('gender', 12)->nullable()->after('email');   // male|female|other
        });

        Schema::create('customer_segments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('dynamic');   // dynamic (rules) | manual (picked list)
            $table->json('rules')->nullable();            // dynamic filter rules
            $table->timestamps();
        });

        // Manual segment membership.
        Schema::create('customer_segment_members', function (Blueprint $table) {
            $table->foreignId('customer_segment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->primary(['customer_segment_id', 'customer_id']);
        });

        // Which members a targeted notification was delivered to (snapshot at send).
        Schema::create('customer_notification_recipients', function (Blueprint $table) {
            $table->foreignId('customer_notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->primary(['customer_notification_id', 'customer_id'], 'cnr_pk');
            $table->index('customer_id');
        });

        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->foreignId('segment_id')->nullable()->after('audience')->constrained('customer_segments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('segment_id');
        });
        Schema::dropIfExists('customer_notification_recipients');
        Schema::dropIfExists('customer_segment_members');
        Schema::dropIfExists('customer_segments');
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('gender'));
    }
};
