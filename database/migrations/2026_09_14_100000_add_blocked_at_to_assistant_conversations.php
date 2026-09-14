<?php

use App\Services\Ai\ChatGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the guard stopped answering a chat.
 *
 * Reading the first six weeks of stored transcripts, three sessions in
 * forty-two were nothing but keyboard mash — one of them ninety messages of
 * "O" and "Ooooo", each a paid call. {@see ChatGuard} now closes a session
 * like that, and this is where the owner sees it happen: the row keeps the
 * questions that were asked and the moment the chat ended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_conversations', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->after('had_failure');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_conversations', function (Blueprint $table) {
            $table->dropColumn('blocked_at');
        });
    }
};
