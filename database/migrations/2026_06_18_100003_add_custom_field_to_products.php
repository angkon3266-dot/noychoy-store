<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('custom_label')->nullable()->after('tags');  // e.g. "Material"
            $table->string('custom_value')->nullable()->after('custom_label'); // e.g. "22k Gold"
            $table->boolean('custom_show')->default(false)->after('custom_value'); // show on product page
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['custom_label', 'custom_value', 'custom_show']);
        });
    }
};
