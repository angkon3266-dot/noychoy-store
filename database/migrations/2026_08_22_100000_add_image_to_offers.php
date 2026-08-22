<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('offers', 'image')) {
            return;
        }

        Schema::table('offers', function (Blueprint $table) {
            // Picture for the "Deals of the Day" card. Null keeps the old
            // behaviour: borrow a photo from whatever the offer applies to.
            $table->string('image')->nullable()->after('badge_label');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
