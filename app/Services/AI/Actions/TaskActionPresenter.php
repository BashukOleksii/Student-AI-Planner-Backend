<?php

namespace App\Services\AI\Actions;

use App\Models\Task;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class TaskActionPresenter
{
    public function task(Task $task, ?int $visibleSubjectId): array
    {
        $values = $task->only([
            'title', 'description', 'subject_id', 'priority', 'estimated_minutes', 'deadline_at', 'status', 'completed_at',
        ]);
        $values['subject_id'] = $visibleSubjectId;
        foreach (['deadline_at', 'completed_at'] as $field) {
            $raw = $task->getRawOriginal($field);
            $values[$field] = $raw === null ? null : CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $raw, 'UTC')->format('Y-m-d\TH:i:s\Z');
        }

        return ['id' => $task->id, ...$this->values($values)];
    }

    public function values(array $values): array
    {
        foreach ($values as &$value) {
            if ($value instanceof BackedEnum) {
                $value = $value->value;
            } elseif ($value instanceof DateTimeInterface) {
                $value = CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
            }
        }
        unset($value);
        ksort($values);

        return $values;
    }

    public function fingerprint(Task $task): string
    {
        $values = $task->getRawOriginal();
        ksort($values);

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public function preview(string $name, ?Task $task, array $candidate, ?int $visibleSubjectId = null): array
    {
        $before = $task === null ? null : $this->task($task, $visibleSubjectId);

        return ['action' => $name, 'confirmation_required' => true,
            'before' => $before, 'changes' => $this->values($candidate),
            'effects' => match ($name) {
                'propose_task_complete' => ['Complete the task and cancel dependent scheduled reminders. Subtask and study-session statuses are preserved.'],
                'propose_task_reopen' => ['Reopen the task. Previously cancelled reminders and retired study sessions are not recreated.'],
                'propose_task_update' => ['Update the supplied fields. Deadline changes reconcile relative reminders using current lifecycle rules.'],
                default => ['Create one pending task. No subtasks, study sessions or reminders are created.'],
            }];
    }
}
