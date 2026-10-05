<?php

namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiConversation> */
class AiConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
        ];
    }
}
