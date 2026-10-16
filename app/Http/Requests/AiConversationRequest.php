<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use stdClass;

class AiConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (($id = $this->route('conversation')) !== null) {
            $conversation = $this->user()->aiConversations()->whereKey($id)->firstOrFail();
            Gate::authorize($this->isMethod('POST') ? 'update' : 'view', $conversation);
        }

        return true;
    }

    public function validationData(): array
    {
        return $this->isMethod('GET') ? $this->query->all() : ($this->isJson() ? $this->json()->all() : $this->request->all());
    }

    public function rules(): array
    {
        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($validator->getData()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
            if ($this->isMethod('POST')) {
                $raw = $this->getContent();
                // An absent create body is equivalent to an empty object. Sending content always needs JSON.
                if ((! $this->isJson() && $this->request->count() !== 0)
                    || ($raw !== '' && (! $this->isJson() || ! json_decode($raw) instanceof stdClass || json_last_error() !== JSON_ERROR_NONE))
                    || $this->query->count() !== 0) {
                    $validator->errors()->add('request', 'A JSON object with only accepted fields is required.');
                }
            } elseif (! in_array($this->getContent(), ['', '[]', '{}'], true)) {
                $validator->errors()->add('request', 'GET bodies are not accepted.');
            }
        }];
    }
}
