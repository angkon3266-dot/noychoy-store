<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: a prior run may have partially applied before failing on the
        // over-length FK name, so guard every step against existing state.

        // Gender (optional) so segments can target by it.
        if (! Schema::hasColumn('customers', 'gender')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('gender', 12)->nullable()->after('email');   // male|female|other
            });
        }

        if (! Schema::hasTable('customer_segments')) {
            Schema::create('customer_segments', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('type')->default('dynamic');   // dynamic (rules) | manual (picked list)
                $table->json('rules')->nullable();            // dynamic filter rules
                $table->timestamps();
            });
        }

        // Manual segment membership.
        if (! Schema::hasTable('customer_segment_members')) {
            Schema::create('customer_segment_members', function (Blueprint $table) {
                $table->foreignId('customer_segment_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->primary(['customer_segment_id', 'customer_id']);
            });
        }

        // Which members a targeted notification was delivered to (snapshot at send).
        // A partial prior run may have created this table WITHOUT the foreign keys, so
        // drop and recreate to guarantee the correct (short-named) constraints.
        Schema::dropIfExists('customer_notification_recipients');
        Schema::create('customer_notification_recipients', function (Blueprint $table) {
            // Explicit short constraint/index names — the auto-generated FK name
            // ("customer_notification_recipients_customer_notification_id_foreign")
            // exceeds MySQL's 64-char identifier limit.
            $table->unsignedBigInteger('customer_notification_id');
            $table->unsignedBigInteger('customer_id');
            $table->foreign('customer_notification_id', 'cnr_notif_fk')
                ->references('id')->on('customer_notifications')->cascadeOnDelete();
            $table->foreign('customer_id', 'cnr_customer_fk')
                ->references('id')->on('customers')->cascadeOnDelete();
            $table->primary(['customer_notification_id', 'customer_id'], 'cnr_pk');
            $table->index('customer_id', 'cnr_customer_idx');
        });

        if (! Schema::hasColumn('customer_notifications', 'segment_id')) {
            Schema::table('customer_notifications', function (Blueprint $table) {
                $table->foreignId('segment_id')->nullable()->after('audience')->constrained('customer_segments')->nullOnDelete();
            });
        }
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
