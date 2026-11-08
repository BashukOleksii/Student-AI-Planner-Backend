<?php

namespace App\Services\AI\Actions;

use App\Services\Planning\StudyAvailabilityInputRules;
use Illuminate\Contracts\Validation\Factory;
use InvalidArgumentException;

class AvailabilityActionArgumentValidator
{
    public function __construct(private AvailabilityActionRegistry $registry, private Factory $validation) {}

    public function validate(string $name, array $arguments): array
    {
        $schema = $this->registry->find($name)['parameters'] ?? null;
        if ($schema === null || array_diff_key($arguments, $schema['properties']) !== []
            || array_diff($schema['required'], array_keys($arguments)) !== []
            || ($name === 'propose_availability_update' && count($arguments) < 2)) {
            throw new InvalidArgumentException;
        }
        foreach ($arguments as $field => $value) {
            $rule = $schema['properties'][$field];
            if (($rule['type'] === 'integer' ? ! is_int($value) : ! is_string($value))
                || (is_int($value) && ($value < $rule['minimum'] || $value > $rule['maximum']))) {
                throw new InvalidArgumentException;
            }
        }
        $rules = array_intersect_key(StudyAvailabilityInputRules::rules($name === 'propose_availability_create'), $schema['properties']);
        if (isset($schema['properties']['availability_window_id'])) {
            $rules['availability_window_id'] = ['required', 'integer:strict', 'min:1'];
        }
        if ($this->validation->make($arguments, $rules)->fails()) {
            throw new InvalidArgumentException;
        }

        // Canonical immutable field order, preserving omission exactly.
        return array_replace(array_intersect_key($schema['properties'], $arguments), $arguments);
    }
}
