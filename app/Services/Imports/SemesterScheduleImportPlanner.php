<?php

namespace App\Services\Imports;

use App\Services\Schedule\EffectiveScheduleCalculator;
use InvalidArgumentException;

class SemesterScheduleImportPlanner
{
    public const FIELDS = ['weekday', 'pair_number', 'pair_label', 'week_pattern', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'room', 'teacher_ids'];

    public const LEGACY_FIELDS = ['weekday', 'pair_number', 'pair_label', 'week_pattern', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'room'];

    public function __construct(private EffectiveScheduleCalculator $calculator) {}

    public function values(array $rule): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = $rule[$field] ?? null;
        }

        $values['teacher_ids'] = $this->teacherIds($rule);

        return $values;
    }

    public function teacherIds(array $row): array
    {
        $ids = $row['teacher_ids'] ?? (isset($row['teacher_id']) ? [$row['teacher_id']] : []);
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
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
        $seen = $operations = $rows = $errors = $projected = [];
        $counts = array_fill_keys(['create', 'update', 'delete', 'noop', 'preserve', 'duplicate'], 0);
        foreach ($existing as $rule) {
            $operations[] = ['operation' => 'delete', 'rule_id' => $rule['id'], 'import_key' => $this->key($rule),
                'before' => $this->values($rule), 'after' => null];
            $counts['delete']++;
        }
        foreach ($incoming as $index => $row) {
            $key = $this->key($row);
            $row = [...$row, 'import_key' => $key, 'operation' => null, 'matched_rule_id' => null];
            $candidate = $this->values($row);
            if (isset($seen[$key])) {
                if ($candidate === $seen[$key]) {
                    $row['operation'] = 'duplicate';
                    $counts['duplicate']++;
                } else {
                    $row['errors']['duplicate'] = ['Рядки з однаковими днем, парою та типом тижня містять суперечливі дані.'];
                }
                $rows[] = $row;

                continue;
            }
            $seen[$key] = $candidate;
            if ($row['errors'] !== []) {
                $rows[] = $row;

                continue;
            }
            $row['operation'] = 'create';
            $operations[] = ['operation' => 'create', 'rule_id' => null, 'import_key' => $key, 'before' => null, 'after' => $candidate];
            $projected[] = ['id' => -($index + 1), ...$candidate];
            $counts['create']++;
            $rows[] = $row;
        }
        if ($incoming === []) {
            $errors['file'] = ['Файл не містить занять. Порожній імпорт не може замінити семестровий розклад.'];
        }
        try {
            $this->calculator->validateWeeklyRules($projected);
        } catch (InvalidArgumentException) {
            $errors['schedule'] = ['Нові тижневі заняття перетинаються або повторюють ту саму пару.'];
        }

        return ['rows' => $rows, 'operations' => $operations, 'projected_rules' => $projected, 'errors' => $errors, 'counts' => $counts];
    }

    public function blockingSuppressions(array $rule, array $suppressions): array
    {
        return array_values(array_filter($suppressions, fn ($slot) => $slot['weekday'] === $rule['weekday']
            && $slot['pair_number'] === $rule['pair_number']
            && ($slot['week_pattern'] === 'all' || $rule['week_pattern'] === 'all' || $slot['week_pattern'] === $rule['week_pattern'])));
    }

    public function managed(array $rule): bool
    {
        return ! ($rule['is_manually_managed'] ?? false)
            && ($rule['semester_schedule_import_id'] ?? null) !== null
            && ($rule['import_key'] ?? null) !== null && is_array($rule['import_baseline'] ?? null)
            && $this->values($rule) === $this->values($rule['import_baseline']);
    }
}
