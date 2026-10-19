<?php

namespace App\Services\AI;

/** Provider-only adaptation; the original H1 definitions and validator remain unchanged. */
class OpenAiToolSchema
{
    public function definitions(array $definitions): array
    {
        return array_map(function ($definition): array {
            $schema = $definition['parameters'];
            if (($definition['strict'] ?? true) === false) {
                return ['type' => 'function', 'name' => $definition['name'], 'description' => $definition['description'],
                    'parameters' => $schema, 'strict' => false];
            }
            foreach ($schema['properties'] as $name => &$rule) {
                if (! in_array($name, $schema['required'], true)) {
                    $rule['type'] = [$rule['type'], 'null'];
                    if (isset($rule['enum'])) {
                        $rule['enum'][] = null;
                    }
                }
            }
            unset($rule);
            $schema['required'] = array_keys($schema['properties']);

            return ['type' => 'function', 'name' => $definition['name'], 'description' => $definition['description'],
                'parameters' => $schema, 'strict' => true];
        }, $definitions);
    }

    public function arguments(string $name, array $arguments, array $definitions): array
    {
        foreach ($definitions as $definition) {
            if ($definition['name'] !== $name || ($definition['strict'] ?? true) === false) {
                continue;
            }
            $schema = $definition['parameters'];
            foreach ($arguments as $field => $value) {
                if ($value === null && array_key_exists($field, $schema['properties']) && ! in_array($field, $schema['required'], true)) {
                    unset($arguments[$field]);
                }
            }
        }

        return $arguments;
    }
}
