<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ai_pending_actions
                DROP CHECK ai_actions_valid_type,
                ADD CONSTRAINT ai_actions_valid_type CHECK (type IN (
                    'propose_task_create', 'propose_task_update', 'propose_task_complete', 'propose_task_reopen',
                    'propose_subtask_create', 'propose_subtask_update', 'propose_subtask_complete', 'propose_subtask_reopen',
                    'propose_reminder_create', 'propose_reminder_update', 'propose_reminder_cancel',
                    'propose_study_plan_create', 'propose_adaptive_replanning', 'propose_session_reschedule',
                    'propose_lesson_create', 'propose_lesson_update', 'propose_lesson_cancel', 'propose_lesson_replacement'
                ))
            SQL);
    }

    public function down(): void
    {
        // Forward-only: preserve reviewed Lesson history and receipts even when no current rows exist.
        throw new RuntimeException('Stage 11 is forward-only. Restore compatibility with a new forward migration.');
    }
};
