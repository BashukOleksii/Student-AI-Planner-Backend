<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TASK_TYPES = "'propose_task_create', 'propose_task_update', 'propose_task_complete', 'propose_task_reopen'";

    public function up(): void
    {
        // InnoDB otherwise uses (user_id, updated_at) to support the owner FK. Updating
        // that index rechecks/locks User, so a history-only rejection can block on a
        // domain writer. Bind the FK to a stable user_id index, then restore list sorting.
        DB::statement('ALTER TABLE ai_conversations ADD INDEX ai_conversations_owner_reference_index (user_id), DROP INDEX ai_conversations_user_id_updated_at_index');
        DB::statement('ALTER TABLE ai_conversations ADD INDEX ai_conversations_user_id_updated_at_index (user_id, updated_at)');
        $this->replace(self::TASK_TYPES.", 'propose_subtask_create', 'propose_subtask_update', 'propose_subtask_complete', 'propose_subtask_reopen', 'propose_reminder_create', 'propose_reminder_update', 'propose_reminder_cancel'");
    }

    public function down(): void
    {
        // Preserve reviewed history and receipts. Operators must export/purge H3b history before downgrading.
        if (DB::table('ai_pending_actions')->whereNotIn('type', [
            'propose_task_create', 'propose_task_update', 'propose_task_complete', 'propose_task_reopen',
        ])->exists()) {
            throw new RuntimeException('Cannot downgrade action types while H3b action history exists.');
        }
        $this->replace(self::TASK_TYPES);
        DB::statement('ALTER TABLE ai_conversations DROP INDEX ai_conversations_owner_reference_index');
    }

    private function replace(string $types): void
    {
        DB::statement('ALTER TABLE ai_pending_actions DROP CHECK ai_actions_valid_type, ADD CONSTRAINT ai_actions_valid_type CHECK (type IN ('.$types.'))');
    }
};
