<?php

namespace App\Services\Imports;

use App\Services\Schedule\EffectiveScheduleCalculator;
use InvalidArgumentException;

/** Pure reconciliation; catalogs/ownership/locking belong to the import service. */
class SemesterScheduleImportPlanner
{
    public const FIELDS = ['weekday', 'pair_number', 'pair_label', 'week_pattern', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'room'];

    public function __construct(private EffectiveScheduleCalculator $calculator) {}

    public function values(array $rule): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = $rule[$field] ?? null;
        }

        return $values;
    }

    public function key(array $rule): string
    {
        return $rule['weekday'].':'.$rule['pair_number'].':'.$rule['week_pattern'];
    }

    public function pairNumber(string $label): int
    {
        return match ($label) {
            '1-2' => 1, '3-4' => 2, '5-6' => 3, '7-8' => 4,
            default => throw new InvalidArgumentException('Unsupported workbook pair label.'),
        };
    }

    /** @return array{rows: array, operations: array, projected_rules: array, errors: array, counts: array} */
    public function plan(array $incoming, array $existing): array
    {
        $byKey = $bySlot = $projected = [];
        foreach ($existing as $rule) {
            $bySlot[$this->key($rule)] = $rule;
            if ($rule['import_key'] ?? null) {
                $byKey[$rule['import_key']] = $rule;
            }
            $projected[$rule['id']] = ['id' => $rule['id'], ...$this->values($rule)];
        }
        $seen = $operations = $rows = $errors = [];
        $counts = array_fill_keys(['create', 'update', 'delete', 'noop', 'preserve', 'duplicate'], 0);
        foreach ($incoming as $index => $row) {
            $key = $this->key($row);
            $row = [...$row, 'import_key' => $key, 'operation' => null, 'matched_rule_id' => null];
            $candidate = $this->values($row);
            if (isset($seen[$key])) {
                if ($candidate === $seen[$key]) {
                    $row['operation'] = 'duplicate';
                    $counts['duplicate']++;
                } else {
                    $row['errors']['duplicate'] = ['Contradictory rows use the same weekday, pair and week pattern.'];
                }
                $rows[] = $row;

                continue;
            }
            $seen[$key] = $candidate;
            if ($row['errors'] !== []) {
                $rows[] = $row;

                continue;
            }
            $target = $byKey[$key] ?? $bySlot[$key] ?? null;
            $before = $target === null ? null : $this->values($target);
            if ($target === null) {
                $operation = 'create';
                $projected[-($index + 1)] = ['id' => -($index + 1), ...$candidate];
            } elseif ($this->managed($target) && $target['import_key'] === $key) {
                $operation = $before === $candidate ? 'noop' : 'update';
                $projected[$target['id']] = ['id' => $target['id'], ...$candidate];
            } else {
                $operation = 'preserve';
                if ($before !== $candidate) {
                    $row['errors']['protected_rule'] = ['This slot contains a manual or manually edited rule. It will not be overwritten.'];
                }
            }
            $row['operation'] = $operation;
            $row['matched_rule_id'] = $target['id'] ?? null;
            $operations[] = ['operation' => $operation, 'rule_id' => $target['id'] ?? null,
                'import_key' => $key, 'before' => $before, 'after' => $operation === 'preserve' ? $before : $candidate];
            $counts[$operation]++;
            $rows[] = $row;
        }
        foreach ($existing as $rule) {
            if (! $this->managed($rule) || isset($seen[$rule['import_key']])) {
                continue;
            }
            unset($projected[$rule['id']]);
            $operations[] = ['operation' => 'delete', 'rule_id' => $rule['id'], 'import_key' => $rule['import_key'],
                'before' => $this->values($rule), 'after' => null];
            $counts['delete']++;
        }
        try {
            $this->calculator->validateWeeklyRules(array_values($projected));
        } catch (InvalidArgumentException) {
            $errors['schedule'] = ['The projected weekly rules overlap or contain duplicate effective pairs. Manual rules are preserved.'];
        }

        return ['rows' => $rows, 'operations' => $operations, 'projected_rules' => array_values($projected), 'errors' => $errors, 'counts' => $counts];
    }

    public function managed(array $rule): bool
    {
        return ($rule['semester_schedule_import_id'] ?? null) !== null
            && ($rule['import_key'] ?? null) !== null && is_array($rule['import_baseline'] ?? null)
            && $this->values($rule) === $this->values($rule['import_baseline']);
    }
}
