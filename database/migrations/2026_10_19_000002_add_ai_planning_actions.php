<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREVIOUS_TYPES = "'propose_task_create', 'propose_task_update', 'propose_task_complete', 'propose_task_reopen', 'propose_subtask_create', 'propose_subtask_update', 'propose_subtask_complete', 'propose_subtask_reopen', 'propose_reminder_create', 'propose_reminder_update', 'propose_reminder_cancel'";

    public function up(): void
    {
        DB::statement('ALTER TABLE ai_pending_actions ADD protected_payload LONGTEXT NULL, DROP CHECK ai_actions_valid_type, ADD CONSTRAINT ai_actions_valid_type CHECK (type IN ('.self::PREVIOUS_TYPES.", 'propose_study_plan_create', 'propose_adaptive_replanning', 'propose_session_reschedule'))");
    }

    public function down(): void
    {
        if (DB::table('ai_pending_actions')->whereIn('type', ['propose_study_plan_create', 'propose_adaptive_replanning', 'propose_session_reschedule'])->exists()
            || DB::table('ai_pending_actions')->whereNotNull('protected_payload')->exists()) {
            throw new RuntimeException('Cannot downgrade while protected planning action history exists.');
        }
        DB::statement('ALTER TABLE ai_pending_actions DROP protected_payload, DROP CHECK ai_actions_valid_type, ADD CONSTRAINT ai_actions_valid_type CHECK (type IN ('.self::PREVIOUS_TYPES.'))');
    }
};
