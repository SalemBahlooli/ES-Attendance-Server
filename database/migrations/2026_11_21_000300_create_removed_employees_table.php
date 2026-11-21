<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee numbers that were deleted on purpose. The device's list of enrolled people
 * (LAN pull, LAN bridge, ADMS user records) does not bring them back; a new punch does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('removed_employees', function (Blueprint $table) {
            $table->id();
            $table->string('pin', 50)->unique();
            $table->string('name')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('removed_employees');
    }
};
