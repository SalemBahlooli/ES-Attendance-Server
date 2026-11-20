<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core table: users, password reset tokens and sessions.
 *
 * Sessions live in the database (SESSION_DRIVER=database) so an admin — or the
 * person themselves — can see every device an account is signed in on and sign
 * one out by deleting its row. See AccountSecurityController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('username')->nullable()->unique();
            $table->string('phone', 50)->nullable();
            $table->string('job_title')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('locale', 10)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->text('notes')->nullable();

            // Access
            $table->boolean('is_active')->default(true)->index();
            $table->json('permissions')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();   // null for accounts that only use single sign-on
            $table->rememberToken();

            // Two-factor (authenticator app)
            $table->text('two_factor_secret')->nullable();          // encrypted
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // Single sign-on
            $table->string('microsoft_id')->nullable()->index();

            // Presence & bookkeeping
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();      // a tab is open (heartbeat)
            $table->timestamp('last_interaction_at')->nullable();   // a human is clicking
            $table->string('last_activity_page', 150)->nullable();
            $table->timestamp('announcements_seen_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
