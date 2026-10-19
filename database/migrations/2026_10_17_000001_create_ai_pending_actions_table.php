<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_pending_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignId('turn_id')->unique()->constrained('ai_conversation_turns')->cascadeOnDelete();
            $table->string('call_id', 128);
            $table->string('type', 32);
            $table->json('arguments');
            $table->json('preview');
            $table->char('target_hash', 64)->nullable();
            $table->string('status', 16)->default('pending');
            $table->dateTime('expires_at', 6);
            $table->json('receipt')->nullable();
            $table->dateTime('resolved_at', 6)->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at'], 'ai_actions_conversation_created_index');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE ai_pending_actions
                ADD CONSTRAINT ai_actions_valid_type CHECK (type IN
                    ('propose_task_create', 'propose_task_update', 'propose_task_complete', 'propose_task_reopen')),
                ADD CONSTRAINT ai_actions_valid_status CHECK (status IN ('pending', 'executed', 'rejected', 'expired')),
                ADD CONSTRAINT ai_actions_receipt_state CHECK
                    ((status = 'executed' AND receipt IS NOT NULL AND resolved_at IS NOT NULL)
                     OR (status <> 'executed' AND receipt IS NULL)),
                ADD CONSTRAINT ai_actions_resolution_state CHECK
                    ((status = 'pending' AND resolved_at IS NULL) OR (status <> 'pending' AND resolved_at IS NOT NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pending_actions');
    }
};
