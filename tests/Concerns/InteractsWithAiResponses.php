<?php

namespace Tests\Concerns;

trait InteractsWithAiResponses
{
    private function aiText(string $text = 'Here is the answer.', string $type = 'output_text'): array
    {
        return ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'status' => 'completed',
            'content' => [['type' => $type, $type === 'refusal' ? 'refusal' : 'text' => $text, 'annotations' => []]]];
    }

    private function aiCall(string $name = 'list_tasks', string $arguments = '{}', string $id = 'call_1'): array
    {
        return ['type' => 'function_call', 'id' => 'fc_'.$id, 'call_id' => $id, 'name' => $name, 'arguments' => $arguments, 'status' => 'completed'];
    }

    /** Portable assertion view for the shared action regression suite. */
    private function aiInput($request): array
    {
        if (config('ai.provider') === 'openai') {
            return $request['input'];
        }
        $input = [];
        foreach (json_decode(json_encode($request['contents']), true) as $content) {
            foreach ($content['parts'] as $part) {
                $input[] = ['content' => $part['text'] ?? json_encode($part, JSON_THROW_ON_ERROR)];
            }
        }

        return $input;
    }

    private function aiResponse(array $items): array
    {
        if (config('ai.provider') === 'gemini') {
            $parts = [];
            foreach ($items as $item) {
                if ($item['type'] === 'function_call') {
                    $parts[] = ['functionCall' => ['id' => $item['call_id'], 'name' => $item['name'],
                        'args' => json_decode($item['arguments'], false, 64, JSON_THROW_ON_ERROR)]];
                } elseif ($item['type'] === 'message') {
                    foreach ($item['content'] as $part) {
                        $parts[] = ['text' => $part['text'] ?? $part['refusal']];
                    }
                } else {
                    throw new \LogicException('Unsupported shared AI test fixture.');
                }
            }

            return ['modelVersion' => config('ai.gemini_model'), 'candidates' => [['finishReason' => 'STOP',
                'content' => ['role' => 'model', 'parts' => $parts]]]];
        }

        return ['id' => 'resp_1', 'status' => 'completed', 'output' => $items, 'error' => null, 'incomplete_details' => null];
    }
}
