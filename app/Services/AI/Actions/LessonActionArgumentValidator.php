<?php

namespace App\Services\AI\Actions;

use App\Services\Schedule\LessonInputRules;
use Illuminate\Contracts\Validation\Factory;
use InvalidArgumentException;

class LessonActionArgumentValidator
{
    public function __construct(private LessonActionRegistry $registry, private Factory $validation) {}

    public function validate(string $name, array $arguments): array
    {
        $schema = $this->registry->find($name)['parameters'] ?? null;
        if ($schema === null || array_diff_key($arguments, $schema['properties']) !== []
            || array_diff($schema['required'], array_keys($arguments)) !== []
            || ($name === 'propose_lesson_update' && count($arguments) < 2)) {
            throw new InvalidArgumentException;
        }
        foreach ($arguments as $field => $value) {
            $types = (array) $schema['properties'][$field]['type'];
            if ($value === null && in_array('null', $types, true)) {
                continue;
            }
            if ((in_array('integer', $types, true) ? ! is_int($value) : ! is_string($value))
                || (is_string($value) && ! mb_check_encoding($value, 'UTF-8'))) {
                throw new InvalidArgumentException;
            }
        }
        $creating = in_array($name, ['propose_lesson_create', 'propose_lesson_replacement'], true);
        $rules = array_intersect_key(LessonInputRules::rules($creating), $schema['properties']);
        if (isset($schema['properties']['lesson_id'])) {
            $rules['lesson_id'] = ['required', 'integer:strict', 'min:1'];
        }
        if ($this->validation->make($arguments, $rules)->fails()) {
            throw new InvalidArgumentException;
        }

        return array_replace(array_intersect_key($schema['properties'], $arguments), $arguments);
    }
}
