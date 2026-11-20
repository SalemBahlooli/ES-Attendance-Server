<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements (shown in everyone's notification bell) and the in-app
 * changelog ("What's new").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('type', 20)->default('info');    // info | success | warning | danger | update
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('changelog_entries', function (Blueprint $table) {
            $table->id();
            $table->date('released_on');
            $table->string('version', 30)->nullable();
            $table->string('category', 20);                  // feature | improvement | fix | security | removed
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('is_published')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('changelog_entries');
        Schema::dropIfExists('announcements');
    }
};
