<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Claude connector: tokens that act as a named admin user, a small OAuth
 * server so claude.ai can connect without a pasted token, and an audit trail
 * of every tool Claude calls.
 *
 * - api_tokens gains an owner (user_id). A token acts as that user and can only
 *   reach the admin sections the user's role can, so a token minted by a staff
 *   member cannot touch products. Tokens made before this migration had no
 *   owner and full access; they are given the first administrator, which is
 *   what they effectively were.
 * - kind = manual (pasted into Claude Code / Desktop) or oauth (minted by the
 *   OAuth flow for claude.ai). OAuth tokens carry a rotating refresh token on
 *   the same row, so one row is one connection and revoking it ends both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->default('manual')->after('name');
            $table->string('client_id', 64)->nullable()->after('kind');
            $table->string('refresh_hash', 64)->nullable()->unique()->after('token_hash');
            $table->timestamp('refresh_expires_at')->nullable()->after('expires_at');
        });

        $admin = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');
        if ($admin) {
            DB::table('api_tokens')->whereNull('user_id')->update(['user_id' => $admin]);
        }

        // Clients that registered themselves (RFC 7591). claude.ai registers one
        // per connector the first time it is added.
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 64)->unique();
            $table->string('name', 120);
            $table->json('redirect_uris');
            $table->string('secret_hash', 64)->nullable(); // only when the client asked for a secret
            $table->timestamps();
        });

        // One-time authorization codes, valid ten minutes. Hash only.
        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique();
            $table->string('client_id', 64);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('redirect_uri');
            $table->string('code_challenge', 128);
            $table->string('resource', 255)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mcp_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_token_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_name', 120)->nullable(); // survives the token being revoked
            $table->string('tool', 80);
            $table->json('arguments')->nullable();
            $table->boolean('ok')->default(true);
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_calls');
        Schema::dropIfExists('oauth_auth_codes');
        Schema::dropIfExists('oauth_clients');

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropUnique(['refresh_hash']);
            $table->dropColumn(['kind', 'client_id', 'refresh_hash', 'refresh_expires_at']);
        });
    }
};
