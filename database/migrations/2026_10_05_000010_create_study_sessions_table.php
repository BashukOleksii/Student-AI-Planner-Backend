<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subtask_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rescheduled_from_session_id')->nullable()->constrained('study_sessions')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('planned');
            $table->dateTime('completed_at')->nullable();
            $table->unsignedSmallInteger('actual_minutes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'starts_at']);
            $table->index(['task_id', 'starts_at']);
            $table->index(['subtask_id', 'starts_at']);
            $table->unique('rescheduled_from_session_id');
        });

        // Self-rescheduling is a future Service invariant: CHECK cannot reference
        // AUTO_INCREMENT id (3818) or the self SET NULL FK (3823).
        DB::statement(<<<'SQL'
            ALTER TABLE study_sessions
                ADD CONSTRAINT study_sessions_valid_interval
                    CHECK (starts_at < ends_at),
                ADD CONSTRAINT study_sessions_valid_status
                    CHECK (status IN ('planned', 'completed', 'missed', 'rescheduled', 'cancelled')),
                ADD CONSTRAINT study_sessions_positive_actual_minutes
                    CHECK (actual_minutes IS NULL OR actual_minutes > 0),
                ADD CONSTRAINT study_sessions_completion_consistency
                    CHECK ((status = 'completed' AND completed_at IS NOT NULL) OR (status <> 'completed' AND completed_at IS NULL)),
                ADD CONSTRAINT study_sessions_actual_requires_completion
                    CHECK (actual_minutes IS NULL OR status = 'completed')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('study_sessions');
    }
};
