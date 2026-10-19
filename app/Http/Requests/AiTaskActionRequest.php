<?php

namespace App\Http\Requests;

use App\Models\AiPendingAction;
use App\Services\AI\Actions\PlanningActionConsent;

class AiTaskActionRequest extends AiConversationRequest
{
    public function rules(): array
    {
        if ($this->isMethod('POST') && $this->routeIs('ai.conversations.actions.confirm')) {
            $type = AiPendingAction::where('conversation_id', $this->route('conversation'))->whereKey($this->route('action'))->value('type');

            return array_fill_keys(PlanningActionConsent::fields($type ?? ''), ['sometimes', 'required', 'boolean:strict']);
        }
        if (! $this->isMethod('GET') || $this->route('action') !== null) {
            return [];
        }
        $integer = ['sometimes', 'bail', 'required', 'string', 'regex:/\A[1-9][0-9]*\z/', 'integer', 'min:1'];

        return ['page' => [...$integer, 'max:2147483647'], 'per_page' => [...$integer, 'max:50']];
    }

    public function authorize(): bool
    {
        parent::authorize();
        if (($id = $this->route('action')) !== null) {
            AiPendingAction::where('conversation_id', $this->route('conversation'))->whereKey($id)->firstOrFail();
        }

        return true;
    }
}
