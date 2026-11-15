<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversation_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->char('input_hash', 64);
            $table->uuid('lease_token')->nullable();
            $table->dateTime('lease_expires_at', 6)->nullable();
            // Only the safe public committed result, used for durable idempotent replay.
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'request_id'], 'ai_turns_conversation_request_unique');
            $table->index(['conversation_id', 'lease_expires_at'], 'ai_turns_conversation_lease_index');
        });
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'id'], 'ai_messages_conversation_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropIndex('ai_messages_conversation_order_index');
        });
        Schema::dropIfExists('ai_conversation_turns');
    }
};
