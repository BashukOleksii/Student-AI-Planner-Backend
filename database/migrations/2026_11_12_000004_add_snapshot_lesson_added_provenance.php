<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_date_exception_lessons', function (Blueprint $table): void {
            $table->boolean('is_added')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('schedule_date_exception_lessons')->whereNotNull('is_added')->exists()) {
            throw new RuntimeException('Cannot discard saved snapshot lesson provenance.');
        }
        Schema::table('schedule_date_exception_lessons', function (Blueprint $table): void {
            $table->dropColumn('is_added');
        });
    }
};
