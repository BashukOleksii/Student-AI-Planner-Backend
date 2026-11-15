<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('max_daily_study_minutes')->nullable();
            $table->unsignedSmallInteger('max_weekly_study_minutes')->nullable();
            $table->unsignedSmallInteger('preferred_break_minutes')->nullable();
            $table->unsignedSmallInteger('min_session_minutes')->nullable();
            $table->unsignedSmallInteger('max_session_minutes')->nullable();
            $table->timestamps();
        });

        // The installed Schema Builder does not provide a CHECK constraint API.
        DB::statement(<<<'SQL'
            ALTER TABLE planning_preferences
                ADD CONSTRAINT planning_preferences_max_daily_positive
                    CHECK (max_daily_study_minutes IS NULL OR max_daily_study_minutes > 0),
                ADD CONSTRAINT planning_preferences_max_weekly_positive
                    CHECK (max_weekly_study_minutes IS NULL OR max_weekly_study_minutes > 0),
                ADD CONSTRAINT planning_preferences_break_positive
                    CHECK (preferred_break_minutes IS NULL OR preferred_break_minutes > 0),
                ADD CONSTRAINT planning_preferences_min_session_positive
                    CHECK (min_session_minutes IS NULL OR min_session_minutes > 0),
                ADD CONSTRAINT planning_preferences_max_session_positive
                    CHECK (max_session_minutes IS NULL OR max_session_minutes > 0),
                ADD CONSTRAINT planning_preferences_session_bounds
                    CHECK (min_session_minutes IS NULL OR max_session_minutes IS NULL
                        OR max_session_minutes >= min_session_minutes),
                ADD CONSTRAINT planning_preferences_workload_bounds
                    CHECK (max_daily_study_minutes IS NULL OR max_weekly_study_minutes IS NULL
                        OR max_weekly_study_minutes >= max_daily_study_minutes)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_preferences');
    }
};
