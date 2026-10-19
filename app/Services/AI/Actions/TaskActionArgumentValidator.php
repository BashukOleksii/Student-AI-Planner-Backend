<?php

namespace App\Services\AI\Actions;

use App\Services\Tasks\TaskInputRules;
use Illuminate\Contracts\Validation\Factory;
use InvalidArgumentException;

class TaskActionArgumentValidator
{
    public function __construct(private TaskActionRegistry $registry, private Factory $validation) {}

    public function validate(string $name, array $arguments): array
    {
        $definition = $this->registry->find($name);
        if ($definition === null) {
            throw new InvalidArgumentException;
        }
        $schema = $definition['parameters'];
        if (array_diff_key($arguments, $schema['properties']) !== []
            || array_diff($schema['required'], array_keys($arguments)) !== []
            || ($name === 'propose_task_update' && count($arguments) < 2)) {
            throw new InvalidArgumentException;
        }
        foreach ($arguments as $field => $value) {
            $types = (array) $schema['properties'][$field]['type'];
            if ($value === null && in_array('null', $types, true)) {
                continue;
            }
            $valid = in_array('integer', $types, true) ? is_int($value) : is_string($value);
            if (! $valid || (is_string($value) && ! mb_check_encoding($value, 'UTF-8'))
                || ($field === 'title' && preg_match('/\A[\s\p{Z}\p{C}]*\z/u', $value))) {
                throw new InvalidArgumentException;
            }
        }
        $rules = array_intersect_key(TaskInputRules::rules($name === 'propose_task_create', $arguments), $schema['properties']);
        if (isset($schema['properties']['task_id'])) {
            $rules['task_id'] = ['required', 'integer:strict', 'min:1'];
        }
        if ($this->validation->make($arguments, $rules)->fails()) {
            throw new InvalidArgumentException;
        }

        // Fixed field order survives model key ordering; no caller-owned arrays or models are retained.
        return array_replace(array_intersect_key($schema['properties'], $arguments), $arguments);
    }
}
