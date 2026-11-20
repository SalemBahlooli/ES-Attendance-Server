<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity log: who did what, to which record, from where.
 *
 *   action       short verb key: created, updated, deleted, restored, signed_out_devices, ...
 *   subject      the record the action was about (polymorphic, nullable)
 *   causer_id    the user who did it (null = the system / a scheduled job)
 *   properties   JSON: {"changes": {"column": {"old": .., "new": ..}}, ...any extra}
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 60)->index();
            $table->string('description', 500)->nullable();
            $table->nullableMorphs('subject');
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('properties')->nullable();
            $table->string('route', 150)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
