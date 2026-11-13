<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recovery_scan_states', function (Blueprint $table): void {
            $table->unsignedBigInteger('requested_version')->default(0);
            $table->unsignedBigInteger('checked_version')->default(0);
            $table->dateTime('requested_at', 6)->nullable();
            $table->uuid('queued_token')->nullable();
            $table->dateTime('queued_until', 6)->nullable();
            $table->dateTime('running_at', 6)->nullable();
            $table->dateTime('last_successful_at', 6)->nullable();
            $table->dateTime('last_error_at', 6)->nullable();
            $table->string('last_error_code', 32)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Delivery state is forward-only. Use a compatibility migration.');
    }
};
