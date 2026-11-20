<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The attendance tables.
 *
 * Punch times are the device's own clock (local time, no time zone), stored as
 * they arrive and never converted. Everything else follows the template: UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Work schedules. weekend holds day numbers (0 = Sunday ... 6 = Saturday).
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('grace_in')->default(15);
            $table->unsignedSmallInteger('grace_out')->default(0);
            $table->json('weekend');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // pin = the employee number on the device (the same number in every branch)
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('pin', 50)->unique();
            $table->string('name')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->date('start_date')->nullable();
            $table->timestamps();
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('sn', 120)->unique();
            $table->string('name')->nullable();
            $table->string('ip', 100)->nullable();
            $table->text('info')->nullable();
            $table->string('lan_ip', 100)->nullable();
            $table->unsignedInteger('lan_port')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        // One row per punch. A deleted or edited punch stays (deleted_at set), so the
        // device cannot send it back and there is a record of what changed.
        Schema::create('punches', function (Blueprint $table) {
            $table->id();
            $table->string('sn', 120);
            $table->string('pin', 50);
            $table->dateTime('punch_time');
            $table->integer('status')->nullable();
            $table->integer('verify')->nullable();
            $table->text('raw')->nullable();
            // adms (live from the device), file (USB / CSV), lan, branch or manual
            $table->string('source', 20)->default('adms');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->unsignedBigInteger('replaces_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->softDeletes();

            // The same employee at the same second is one punch, whatever its source
            $table->unique(['pin', 'punch_time']);
            $table->index('punch_time');
            $table->index('sn');
        });

        // Commands waiting for the device to pick them up on /iclock/getrequest
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->string('sn', 120)->index();
            $table->string('command', 500);
            $table->string('status', 20)->default('pending');
            $table->text('result')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
        });

        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('note')->nullable();
            // Set on leaves copied from another branch's file, so a newer file can update them
            $table->string('source', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('punch_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->string('label')->nullable();
            $table->string('sn', 120)->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('names')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['punch_imports', 'leaves', 'holidays', 'device_commands', 'punches', 'devices', 'employees', 'departments', 'shifts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
