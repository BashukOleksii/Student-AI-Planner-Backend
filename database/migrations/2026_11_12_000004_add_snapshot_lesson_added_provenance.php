<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No backfill: historical null links can mean either additions or deleted rules.
        Schema::table('schedule_date_exception_lessons', function (Blueprint $table): void {
            $table->boolean('is_added')->nullable();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('schedule_date_exception_lessons')->whereNotNull('is_added')->exists()) {
            throw new RuntimeException('Cannot discard saved snapshot lesson provenance.');
        }
        Schema::table('schedule_date_exception_lessons', function (Blueprint $table): void {
            $table->dropColumn('is_added');
        });
    }
};
