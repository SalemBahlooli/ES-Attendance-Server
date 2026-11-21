<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flexible hours (Settings → Optional features): a flexible shift has no fixed start, only
 * hours to work per day. Its start and end still mark the day's frame.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('flexible')->default(false)->after('weekend');
            // Minutes to work per day; empty = the length of the shift
            $table->unsignedSmallInteger('required_minutes')->nullable()->after('flexible');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['flexible', 'required_minutes']);
        });
    }
};
