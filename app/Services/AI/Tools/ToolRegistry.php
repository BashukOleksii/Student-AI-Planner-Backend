<?php

namespace App\Services\AI\Tools;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;

/** Server-owned discovery contract. No caller-supplied registrations or dispatch targets. */
class ToolRegistry
{
    public function definitions(): array
    {
        $date = ['type' => 'string', 'format' => 'date', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'];
        $integer = ['type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX];
        $period = ['start_date' => $date, 'end_date' => $date];

        return [
            $this->definition('get_schedule_for_date', 'Read active lessons for one local calendar date, including effective replacements.',
                ['date' => $date], ['date']),
            $this->definition('list_tasks', 'Read active tasks with optional AND filters and bounded pagination, ordered by deadline then ID.', [
                'status' => ['type' => 'string', 'enum' => array_column(TaskStatus::cases(), 'value')],
                'priority' => [...$integer, 'enum' => array_column(TaskPriority::cases(), 'value')],
                'subject_id' => $integer,
                'page' => $integer,
                'per_page' => [...$integer, 'maximum' => 50],
            ], []),
            $this->definition('find_free_study_slots', 'Read free UTC intervals over at most 31 inclusive local dates. Free time does not guarantee a feasible study plan.',
                [...$period, 'min_slot_minutes' => [...$integer, 'maximum' => 65535]], array_keys($period)),
            $this->definition('list_study_sessions', 'Read original study session identities and all historical statuses overlapping at most 31 local dates. Returns at most 100 sessions with explicit truncation metadata.',
                $period, array_keys($period)),
            $this->definition('get_progress_statistics', 'Read current work counts and bounded period activity over at most 31 local dates, preserving unknown actual durations.',
                $period, array_keys($period)),
            $this->definition('get_daily_progress_trends', 'Read zero-filled daily activity and exact totals over at most 31 local dates.',
                $period, array_keys($period)),
            $this->definition('compare_progress_periods', 'Compare equal-length periods of at most 31 local dates each. Baseline must precede current without overlap. Returns factual integer differences, not historical snapshots or productivity judgments.',
                ['baseline_start_date' => $date, 'baseline_end_date' => $date, 'current_start_date' => $date, 'current_end_date' => $date],
                ['baseline_start_date', 'baseline_end_date', 'current_start_date', 'current_end_date']),
        ];
    }

    public function find(string $name): ?array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['name'] === $name) {
                return $definition;
            }
        }

        return null;
    }

    private function definition(string $name, string $description, array $properties, array $required): array
    {
        return ['name' => $name, 'description' => $description, 'parameters' => [
            'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false,
        ]];
    }
}
