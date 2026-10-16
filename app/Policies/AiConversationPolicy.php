<?php

namespace App\Policies;

use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AiConversationPolicy
{
    public function view(User $user, AiConversation $conversation): Response
    {
        return $user->id === $conversation->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, AiConversation $conversation): Response
    {
        return $this->view($user, $conversation);
    }
}
