<?php

namespace App\Observers;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\RecoveryCheckTriggerService;
use Illuminate\Database\Eloquent\Model;

/** Observe only planning inputs, never scan state, suggestions, jobs or receipts. */
class PlanningSourceObserver
{
    public function saved(Model $model): void
    {
        if (! $model->wasRecentlyCreated && $model->getChanges() === []) {
            return;
        }
        if ($model instanceof User && (! $model->wasChanged('timezone') || $model->wasRecentlyCreated)) {
            return;
        }
        $this->request($model);
    }

    public function deleted(Model $model): void
    {
        $this->request($model);
    }

    private function request(Model $model): void
    {
        $id = $model instanceof User ? $model->id : ($model instanceof Subtask
            ? Task::withTrashed()->whereKey($model->task_id)->value('user_id') : $model->user_id);
        if ($id !== null) {
            app(RecoveryCheckTriggerService::class)->request((int) $id);
        }
    }
}
