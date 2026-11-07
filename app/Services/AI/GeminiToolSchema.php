<?php

namespace App\Services\AI;

use App\Exceptions\AiAgentException;

/** Gemini's JSON Schema field preserves omission, clearing-null and numeric enums. */
class GeminiToolSchema
{
    public function definitions(array $definitions): array
    {
        return array_map(function (array $definition): array {
            $this->validate($definition['parameters']);

            return ['name' => $definition['name'], 'description' => $definition['description'],
                'parametersJsonSchema' => $definition['parameters']];
        }, $definitions);
    }

    private function validate(array $schema, int $depth = 0): void
    {
        // Fail closed on future keywords rather than silently weakening their semantics.
        if ($depth > 16 || array_diff(array_keys($schema), ['type', 'description', 'properties', 'required',
            'additionalProperties', 'items', 'enum', 'format', 'pattern', 'minimum', 'maximum',
            'minLength', 'maxLength', 'minItems', 'maxItems']) !== []) {
            throw new AiAgentException('ai_unavailable');
        }
        $types = is_array($schema['type'] ?? null) ? $schema['type'] : [$schema['type'] ?? null];
        if ($types === [] || array_diff($types, ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null']) !== []) {
            throw new AiAgentException('ai_unavailable');
        }
        foreach ($schema['properties'] ?? [] as $property) {
            $this->validate($property, $depth + 1);
        }
        if (isset($schema['items'])) {
            $this->validate($schema['items'], $depth + 1);
        }
    }
}
