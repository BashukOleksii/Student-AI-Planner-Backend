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
                'propose_task_complete' => ['Після підтвердження завдання буде завершено, а залежні заплановані нагадування та майбутні заплановані сесії — скасовано. Стани підзавдань та історія сесій збережуться.'],
                'propose_task_reopen' => ['Після підтвердження завдання повернеться до виконання. Скасовані нагадування та завершені, пропущені, скасовані чи перенесені сесії не створюватимуться знову.'],
                'propose_task_update' => ['Після підтвердження зміняться лише вказані поля. При зміні дедлайну відносні нагадування буде узгоджено з новою датою з урахуванням їхнього стану.'],
                default => ['Після підтвердження буде створено одне завдання до виконання. Підзавдання, навчальні сесії та нагадування не створюються.'],
            }];
    }
}
