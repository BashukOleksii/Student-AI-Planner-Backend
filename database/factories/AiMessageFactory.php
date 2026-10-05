<?php

namespace Database\Factories;

use App\Enums\AiMessageRole;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiMessage> */
class AiMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => AiConversation::factory(),
            'role' => AiMessageRole::User,
            'content' => 'Test message',
            'tool_calls' => null,
            'tool_call_id' => null,
        ];
    }
}
