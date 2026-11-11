<?php

namespace App\Services\AI\Actions;

use App\Services\Schedule\EffectiveScheduleCalculator;
use InvalidArgumentException;

/** Strict recursive JSON transport validation; calendar/business validity stays in management. */
class SemesterActionArgumentValidator
{
    public function __construct(private SemesterActionRegistry $registry, private EffectiveScheduleCalculator $calculator) {}

    public function validate(string $name, array $arguments, bool $read = false): array
    {
        $definition = $read ? $this->registry->findRead($name) : $this->registry->find($name);
        if ($definition === null) {
            throw new InvalidArgumentException;
        }
        $this->value($arguments, $definition['parameters']);
        if (in_array($name, ['propose_semester_rule_update', 'propose_semester_occurrence_update', 'propose_semester_settings_update'], true)
            && count($arguments) <= count($definition['parameters']['required'])) {
            throw new InvalidArgumentException;
        }
        if ($name === 'propose_semester_date_replace') {
            if (($arguments['mode'] === 'replace' && (! array_key_exists('lessons', $arguments) || array_key_exists('changes', $arguments)))
                || ! $this->unique($arguments['lessons'] ?? [], 'id')
                || ! $this->unique($arguments['changes'] ?? [], 'weekly_lesson_rule_id')) {
                throw new InvalidArgumentException;
            }
        }
        if ($name === 'propose_semester_working_saturday') {
            if (isset($arguments['source_week_parity']) !== isset($arguments['selected_weekly_rule_ids'])
                || (isset($arguments['selected_weekly_rule_ids']) && count($arguments['selected_weekly_rule_ids']) !== count(array_unique($arguments['selected_weekly_rule_ids'])))) {
                throw new InvalidArgumentException;
            }
        }

        return $arguments;
    }

    private function unique(array $rows, string $key): bool
    {
        $ids = array_column($rows, $key);

        return count($ids) === count(array_unique($ids));
    }

    private function value(mixed $value, array $schema): void
    {
        $type = match (true) {
            $value === null => 'null', is_bool($value) => 'boolean', is_int($value) => 'integer',
            is_string($value) => 'string', is_array($value) => ($schema['type'] === 'object' ? 'object' : 'array'),
            default => '',
        };
        if (! in_array($type, (array) $schema['type'], true)
            || (isset($schema['enum']) && ! in_array($value, $schema['enum'], true))) {
            throw new InvalidArgumentException;
        }
        if ($type === 'integer' && ($value < ($schema['minimum'] ?? PHP_INT_MIN) || $value > ($schema['maximum'] ?? PHP_INT_MAX))) {
            throw new InvalidArgumentException;
        }
        if ($type === 'string') {
            if (! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) < ($schema['minLength'] ?? 0)
                || mb_strlen($value) > ($schema['maxLength'] ?? 200)
                || (($schema['minLength'] ?? 0) > 0 && preg_match('/\A[\s\p{Z}\p{C}]*\z/u', $value))
                || (isset($schema['pattern']) && preg_match('~'.$schema['pattern'].'~D', $value) !== 1)) {
                throw new InvalidArgumentException;
            }
            if (($schema['format'] ?? null) === 'date') {
                $this->calculator->validateDate($value);
            }
        }
        if ($type === 'object') {
            if (($value !== [] && array_is_list($value)) || array_diff_key($value, $schema['properties']) !== []
                || array_diff($schema['required'], array_keys($value)) !== []) {
                throw new InvalidArgumentException;
            }
            foreach ($value as $key => $item) {
                $this->value($item, $schema['properties'][$key]);
            }
        }
        if ($type === 'array') {
            if (! array_is_list($value) || count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? 255)) {
                throw new InvalidArgumentException;
            }
            foreach ($value as $item) {
                $this->value($item, $schema['items']);
            }
        }
    }
}
