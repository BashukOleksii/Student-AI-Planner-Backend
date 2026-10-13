<?php

namespace App\Services\Planning;

use App\Models\StudySession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;
use UnexpectedValueException;

/** Shared transaction and dependency locking for single-session mutations. */
class StudySessionMutationContextService
{
    public function __construct(private PlanningContextService $context, private StudySessionLifecycleRules $rules) {}

    public function run(User $user, string $id, Closure $action): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Study session mutation requires an outermost transaction.');
        }

        return DB::transaction(function () use ($user, $id, $action): mixed {
            // The first read is a current read, before establishing any RR snapshot.
            $owner = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail(['id', 'timezone']);
            $source = $owner->studySessions()->whereKey($id)->firstOrFail();
            Gate::forUser($owner)->authorize('update', $source);
            $task = $owner->tasks()->withTrashed()->whereKey($source->task_id)->lockForUpdate()->first();
            $child = $source->subtask_id === null || $task === null ? null
                : $task->subtasks()->withTrashed()->whereKey($source->subtask_id)->lockForUpdate()->first();
            if ($task === null || ($source->subtask_id !== null && $child === null)) {
                throw new UnexpectedValueException('Invalid study session source relationships.');
            }
            // Discover both directions without filtering owners: a foreign edge must fail closed.
            // Visited IDs guarantee termination even on a corrupt cycle. Lock the component by ID.
            $records = [];
            $pending = [$source->id];
            while ($pending !== []) {
                $batch = StudySession::whereIn('id', $pending)->orWhereIn('rescheduled_from_session_id', $pending)->get();
                $next = [];
                foreach ($batch as $row) {
                    if (! isset($records[$row->id])) {
                        $records[$row->id] = $row;
                        $next[] = $row->id;
                        if ($row->rescheduled_from_session_id !== null && ! isset($records[$row->rescheduled_from_session_id])) {
                            $next[] = $row->rescheduled_from_session_id;
                        }
                    }
                }
                $pending = array_values(array_unique($next));
            }
            foreach ($records as $record) {
                if ($record->user_id !== $owner->id || $record->task_id !== $source->task_id || $record->subtask_id !== $source->subtask_id) {
                    throw new UnexpectedValueException('Invalid study session source relationships.');
                }
            }
            $locked = StudySession::whereIn('id', array_keys($records))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked[$source->id];
            $interval = ['start' => $this->context->utcSeconds($source->getRawOriginal('starts_at')),
                'end' => $this->context->utcSeconds($source->getRawOriginal('ends_at'))];
            $now = CarbonImmutable::now('UTC'); // Capture after contention, retain full precision.

            return $action($owner, $source, $task, $child, $interval, $now, $locked);
        });
    }

    public function validateChain(Collection $records, int $sourceId): void
    {
        $this->rules->validateChain($records->map(fn (StudySession $row): array => [
            'user_id' => $row->user_id, 'task_id' => $row->task_id, 'subtask_id' => $row->subtask_id,
            'rescheduled_from_session_id' => $row->rescheduled_from_session_id, 'status' => $row->status->value,
        ])->all(), $sourceId);
    }
}
