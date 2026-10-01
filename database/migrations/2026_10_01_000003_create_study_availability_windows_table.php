<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_availability_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->index(['user_id', 'day_of_week']);
            $table->unique(['user_id', 'day_of_week', 'starts_at', 'ends_at'], 'study_availability_windows_user_day_times_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE study_availability_windows
                ADD CONSTRAINT study_availability_windows_iso_weekday
                    CHECK (day_of_week BETWEEN 1 AND 7),
                ADD CONSTRAINT study_availability_windows_valid_interval
                    CHECK (starts_at < ends_at)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('study_availability_windows');
    }
};
