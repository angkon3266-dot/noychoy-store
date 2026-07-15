<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rich push: big image + up to 2 action buttons on manual notifications.
        Schema::table('customer_notifications', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_notifications', 'image')) {
                $table->string('image')->nullable()->after('icon');
            }
            if (! Schema::hasColumn('customer_notifications', 'actions')) {
                $table->json('actions')->nullable()->after('image');   // [{label,url}, ...]
            }
        });

        if (! Schema::hasTable('drip_campaigns')) {
            Schema::create('drip_campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('trigger')->default('registration'); // registration | manual
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('drip_steps')) {
            Schema::create('drip_steps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('drip_campaign_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('position')->default(0);
                $table->unsignedInteger('delay_hours')->default(0);  // hours after enrolment
                $table->string('title');
                $table->text('body')->nullable();
                $table->string('url')->nullable();
                $table->string('image')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('drip_enrollments')) {
            Schema::create('drip_enrollments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('drip_campaign_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->timestamp('enrolled_at');
                $table->unsignedInteger('next_step')->default(0);
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['drip_campaign_id', 'customer_id'], 'drip_enrol_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drip_enrollments');
        Schema::dropIfExists('drip_steps');
        Schema::dropIfExists('drip_campaigns');
        Schema::table('customer_notifications', function (Blueprint $table) {
            $table->dropColumn(['image', 'actions']);
        });
    }
};
