<?php

namespace App\Http\Requests;

class AiConversationIndexRequest extends AiConversationRequest
{
    public function rules(): array
    {
        $integer = ['sometimes', 'bail', 'required', 'string', 'regex:/\A[1-9][0-9]*\z/', 'integer', 'min:1'];

        return ['page' => [...$integer, 'max:2147483647'], 'per_page' => [...$integer, 'max:50'],
            ...($this->route('conversation') ? ['through_id' => ['sometimes', 'bail', 'required', 'string', 'regex:/\A(?:0|[1-9][0-9]*)\z/', 'integer', 'min:0', 'max:'.PHP_INT_MAX]] : [])];
    }
}
