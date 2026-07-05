<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named, restorable full-configuration backups. Auto-created before every risky
 * operation (save/restore/import) and manually on demand. The payload is an
 * encrypted JSON snapshot of the whole config store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_backups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('creator_name')->nullable();
            $table->longText('payload'); // encrypted JSON of the full config store
            $table->unsignedInteger('size_bytes')->default(0);
            $table->json('modules')->nullable(); // sections included
            $table->boolean('is_auto')->default(false);
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_backups');
    }
};
