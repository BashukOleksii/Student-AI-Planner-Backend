<?php

namespace App\Services\Tasks;

use App\Exceptions\ReminderIntegrityException;
use App\Models\Task;
use Illuminate\Validation\ValidationException;

class TaskEstimateService
{
    /** @return array{has_subtasks: bool, estimated_minutes: ?int} */
    public function resolve(Task $task): array
    {
        // The relation excludes soft-deleted children, but includes completed ones.
        $totals = $task->subtasks()->selectRaw(
            'COUNT(*) AS total, COUNT(estimated_minutes) AS known, SUM(estimated_minutes) AS minutes',
        )->toBase()->first();
        $hasSubtasks = (int) $totals->total > 0;
        $estimate = $hasSubtasks
            ? ((int) $totals->known === (int) $totals->total ? (int) $totals->minutes : null)
            : $task->estimated_minutes;

        if ($hasSubtasks && $estimate !== null && $estimate > 65535) {
            throw ValidationException::withMessages([
                'estimated_minutes' => ['Загальна оцінка підзавдань не може перевищувати 65535 хвилин.'],
            ]);
        }

        return ['has_subtasks' => $hasSubtasks, 'estimated_minutes' => $estimate];
    }

    /** The caller must hold the owner and parent locks in the mutation transaction. */
    public function synchronize(Task $task): void
    {
        $resolved = $this->resolve($task);
        if ($resolved['has_subtasks'] && $task->estimated_minutes !== $resolved['estimated_minutes']) {
            if (! $task->update(['estimated_minutes' => $resolved['estimated_minutes']])) {
                throw new ReminderIntegrityException;
            }
        }
    }
}
