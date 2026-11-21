<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The optional features (each one is switched on in Settings → Optional features):
 * the employee portal and its requests, shift schedules, special working hours
 * (Ramadan) and leave balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // The sign-in account of this employee (the employee portal)
            $table->foreignId('user_id')->nullable()->unique()->after('pin')->constrained()->nullOnDelete();
            // Annual leave days per year; empty = the default from Settings
            $table->unsignedSmallInteger('annual_leave_days')->nullable()->after('start_date');
        });

        // What employees ask for from the portal: a leave, or a punch they forgot
        Schema::create('employee_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10); // leave | punch
            $table->string('leave_type', 40)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->dateTime('punch_time')->nullable(); // device local time, like punches.punch_time
            $table->string('reason', 500)->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->unsignedBigInteger('result_id')->nullable(); // the leave or punch made on approval
            $table->timestamps();
            $table->index(['status', 'kind']);
        });

        // A different shift for an employee for some days (rotations: night one week, day the next)
        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'start_date', 'end_date']);
        });

        // Other working hours between two dates (Ramadan), for one shift or for all of them
        Schema::create('special_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('shift_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_periods');
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('employee_requests');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('annual_leave_days');
        });
    }
};
