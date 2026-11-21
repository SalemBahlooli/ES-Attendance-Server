<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shift's break: when it starts, how long it is, and whether it counts as work time.
 * An unpaid break is taken off the worked time of anyone in at that time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->time('break_start')->nullable()->after('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(0)->after('break_start');
            $table->boolean('break_paid')->default(false)->after('break_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['break_start', 'break_minutes', 'break_paid']);
        });
    }
};
