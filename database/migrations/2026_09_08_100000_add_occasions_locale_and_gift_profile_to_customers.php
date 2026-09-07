<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Special dates — day and month only. Nobody wants to type a birth
            // year at a jewelry checkout, and a wish needs neither.
            foreach (['birthday', 'anniversary'] as $occasion) {
                if (! Schema::hasColumn('customers', $occasion.'_day')) {
                    $table->unsignedTinyInteger($occasion.'_day')->nullable();
                    $table->unsignedTinyInteger($occasion.'_month')->nullable();
                    // Stamped BEFORE a message is queued (at-most-once for
                    // paid SMS); the job un-stamps if nothing went out.
                    $table->timestamp($occasion.'_reminded_at')->nullable();
                    $table->timestamp($occasion.'_wished_at')->nullable();
                }
            }

            // The language the customer chose (or wrote to the assistant in).
            if (! Schema::hasColumn('customers', 'locale')) {
                $table->string('locale', 5)->nullable();
            }

            // Gift-finder answers: recipient / occasion / budget, used to
            // personalise rows, pushes and SMS.
            if (! Schema::hasColumn('customers', 'gift_profile')) {
                $table->json('gift_profile')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'birthday_day', 'birthday_month', 'birthday_reminded_at', 'birthday_wished_at',
                'anniversary_day', 'anniversary_month', 'anniversary_reminded_at', 'anniversary_wished_at',
                'locale', 'gift_profile',
            ]);
        });
    }
};
