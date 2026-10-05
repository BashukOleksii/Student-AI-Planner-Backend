<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'created_at']);
            $table->index('tool_call_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ai_messages
                ADD CONSTRAINT ai_messages_valid_role
                    CHECK (role IN ('system', 'user', 'assistant', 'tool'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
