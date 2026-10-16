<?php

namespace App\Services\AI\Tools;

use App\Services\Planning\PlanningContextService;
use App\Services\Progress\ProgressComparisonService;
use InvalidArgumentException;

/** Validates only the registry's small scalar schema vocabulary; never coerces JSON values. */
class ToolArgumentValidator
{
    public function __construct(private PlanningContextService $context, private ProgressComparisonService $comparison) {}

    public function validate(array $definition, array $arguments): void
    {
        $schema = $definition['parameters'];
        if (array_diff_key($arguments, $schema['properties']) !== []) {
            throw new InvalidArgumentException;
        }
        foreach ($schema['required'] as $field) {
            if (! array_key_exists($field, $arguments)) {
                throw new InvalidArgumentException;
            }
        }
        foreach ($arguments as $field => $value) {
            $rule = $schema['properties'][$field];
            $validType = match ($rule['type']) {
                'string' => is_string($value),
                'integer' => is_int($value),
                default => false,
            };
            if (! $validType || (isset($rule['enum']) && ! in_array($value, $rule['enum'], true))) {
                throw new InvalidArgumentException;
            }
            if ($rule['type'] === 'integer' && ($value < $rule['minimum'] || $value > $rule['maximum'])) {
                throw new InvalidArgumentException;
            }
            if (($rule['format'] ?? null) === 'date') {
                $this->context->validateDates($value, $value);
            }
        }
        if (isset($arguments['start_date'], $arguments['end_date'])) {
            $this->context->validateDates($arguments['start_date'], $arguments['end_date']);
        }
        if ($definition['name'] === 'compare_progress_periods') {
            $this->comparison->validatePeriods($arguments['baseline_start_date'], $arguments['baseline_end_date'],
                $arguments['current_start_date'], $arguments['current_end_date']);
        }
    }
}
