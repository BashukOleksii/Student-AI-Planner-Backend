<?php

namespace App\Services\AI\Actions;

use App\Services\Planning\PlanningContextService;
use App\Services\Planning\StudySessionLifecycleRules;
use App\Services\Planning\TimeIntervalService;
use InvalidArgumentException;

class PlanningActionArgumentValidator
{
    public function __construct(private PlanningActionRegistry $registry, private PlanningContextService $context,
        private StudySessionLifecycleRules $sessions, private TimeIntervalService $intervals) {}

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
            $validType = (in_array('integer', $types, true) && is_int($value))
                || (in_array('string', $types, true) && is_string($value) && mb_check_encoding($value, 'UTF-8'))
                || (in_array('null', $types, true) && $value === null);
            if (! $validType || (is_int($value) && ($value < ($rule['minimum'] ?? PHP_INT_MIN) || $value > ($rule['maximum'] ?? PHP_INT_MAX)))) {
                throw new InvalidArgumentException;
            }
        }
        if ($name === 'propose_session_reschedule') {
            $this->intervals->durationSeconds(['start' => $this->sessions->timestamp($arguments['starts_at']),
                'end' => $this->sessions->timestamp($arguments['ends_at'])]);
        } elseif (in_array($name, ['propose_session_mark_missed', 'propose_session_complete'], true)) {
            $this->sessions->actualMinutes($arguments['actual_minutes'] ?? null, $name === 'propose_session_complete');
        } else {
            $this->context->validateDates($arguments['start_date'], $arguments['end_date']);
            if (isset($arguments['subtask_id']) && ! isset($arguments['task_id'])) {
                throw new InvalidArgumentException;
            }
        }

        return array_replace(array_intersect_key($schema['properties'], $arguments), $arguments);
    }
}
