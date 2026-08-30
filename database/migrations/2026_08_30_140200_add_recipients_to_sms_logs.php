<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many people one row covers.
 *
 * A bulk send goes to the gateway as one comma-separated request, and the log
 * used to try to store that whole list in `phone` — 100 numbers is ~1,400
 * characters into a varchar(255), so the INSERT failed, took the send down with
 * it and 500'd the broadcast page. `phone` now holds a short summary for bulk
 * rows and this column carries the count that used to be implied by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sms_logs', 'recipients')) {
            return;
        }

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->unsignedInteger('recipients')->default(1)->after('phone');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sms_logs', 'recipients')) {
            return;
        }

        Schema::table('sms_logs', function (Blueprint $table) {
            $table->dropColumn('recipients');
        });
    }
};
