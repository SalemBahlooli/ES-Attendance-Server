<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sign-in log: every successful and failed attempt, by every method
 * (password, authenticator code, Microsoft, remember-me cookie).
 *
 * device_hash is sha1(browser|os|type) — stable across browser updates — and
 * is what "new device" detection compares against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sign_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('login')->nullable();          // what was typed
            $table->boolean('successful')->index();
            $table->string('method', 30)->nullable();      // password | authenticator | microsoft | remember_me
            $table->string('reason', 40)->nullable();      // why a failure failed
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_hash', 40)->nullable();
            $table->boolean('new_device')->default(false);
            $table->string('session_id')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['user_id', 'successful']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sign_ins');
    }
};
