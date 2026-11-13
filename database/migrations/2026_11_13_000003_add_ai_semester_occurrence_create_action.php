<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One atomic ALTER preserves every existing type and all pending actions/receipts.
        DB::statement(<<<'SQL'
            ALTER TABLE ai_pending_actions
                DROP CHECK ai_actions_valid_type,
                ADD CONSTRAINT ai_actions_valid_type CHECK (type IN (
                    'propose_task_create', 'propose_task_update', 'propose_task_complete', 'propose_task_reopen',
                    'propose_subtask_create', 'propose_subtask_update', 'propose_subtask_complete', 'propose_subtask_reopen',
                    'propose_reminder_create', 'propose_reminder_update', 'propose_reminder_cancel',
                    'propose_study_plan_create', 'propose_adaptive_replanning', 'propose_session_reschedule',
                    'propose_lesson_create', 'propose_lesson_update', 'propose_lesson_cancel', 'propose_lesson_replacement',
                    'propose_availability_create', 'propose_availability_update', 'propose_availability_delete',
                    'propose_session_mark_missed', 'propose_session_complete',
                    'propose_semester_rule_create', 'propose_semester_rule_update', 'propose_semester_rule_delete',
                    'propose_semester_date_replace', 'propose_semester_date_delete', 'propose_semester_working_saturday',
                    'propose_semester_occurrence_update', 'propose_semester_occurrence_cancel', 'propose_semester_settings_update',
                    'propose_semester_occurrence_create'
                ))
            SQL);
    }

    public function down(): void
    {
        // Never remove a type that may have retained pending actions or executed receipts.
        throw new RuntimeException('AI semester occurrence creation is forward-only. Restore compatibility with a new forward migration.');
    }
};
