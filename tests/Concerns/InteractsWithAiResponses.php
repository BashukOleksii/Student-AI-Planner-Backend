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

    private function aiResponse(array $items): array
    {
        return ['id' => 'resp_1', 'status' => 'completed', 'output' => $items, 'error' => null, 'incomplete_details' => null];
    }
}
