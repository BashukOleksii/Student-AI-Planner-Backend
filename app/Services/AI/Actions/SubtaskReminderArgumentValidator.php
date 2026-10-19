<?php

namespace App\Services\AI\Actions;

use App\Services\Reminders\ReminderRules;
use App\Services\Tasks\SubtaskInputRules;
use Illuminate\Contracts\Validation\Factory;
use InvalidArgumentException;

class SubtaskReminderArgumentValidator
{
    public function __construct(private SubtaskReminderActionRegistry $registry, private Factory $validation, private ReminderRules $reminders) {}

    public function validate(string $name, array $arguments): array
    {
        $schema = $this->registry->find($name)['parameters'] ?? null;
        if ($schema === null || array_diff_key($arguments, $schema['properties']) !== []
            || array_diff($schema['required'], array_keys($arguments)) !== []) {
            throw new InvalidArgumentException;
        }
        foreach ($arguments as $field => $value) {
            $rule = $schema['properties'][$field];
            $types = (array) $rule['type'];
            if ($value === null && in_array('null', $types, true)) {
                continue;
            }
            $valid = in_array('integer', $types, true) ? is_int($value) : is_string($value);
            if (! $valid || (is_string($value) && ! mb_check_encoding($value, 'UTF-8'))
                || (is_int($value) && ($value < $rule['minimum'] || $value > $rule['maximum']))
                || (isset($rule['enum']) && ! in_array($value, $rule['enum'], true))
                || ($field === 'title' && preg_match('/\A[\s\p{Z}\p{C}]*\z/u', $value))) {
                throw new InvalidArgumentException;
            }
        }
        if (str_starts_with($name, 'propose_subtask_')) {
            $fields = array_diff_key($arguments, ['task_id' => true, 'subtask_id' => true]);
            if (($name === 'propose_subtask_update' && $fields === [])
                || $this->validation->make($fields, SubtaskInputRules::rules($name === 'propose_subtask_create', $fields))->fails()) {
                throw new InvalidArgumentException;
            }
        } elseif ($name !== 'propose_reminder_cancel') {
            $fields = array_diff_key($arguments, ['reminder_id' => true]);
            if ($this->reminders->errors($fields, $name === 'propose_reminder_create') !== []) {
                throw new InvalidArgumentException;
            }
        }

        return array_replace(array_intersect_key($schema['properties'], $arguments), $arguments);
    }
}
