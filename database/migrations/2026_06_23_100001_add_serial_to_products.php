<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Internal sequential reference (1,2,3…). Shown on courier labels, never on the storefront.
            $table->unsignedInteger('serial')->nullable()->unique()->after('id');
        });

        // Backfill existing products in id order so each gets a stable serial.
        $n = 0;
        DB::table('products')->orderBy('id')->select('id')->each(function ($row) use (&$n) {
            DB::table('products')->where('id', $row->id)->update(['serial' => ++$n]);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('serial');
        });
    }
};
