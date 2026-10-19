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

        return DB::transaction(fn () => $this->runWithinTransaction($user, $id, $action));
    }

    public function runWithinTransaction(User $user, string $id, Closure $action): mixed
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Study session execution requires an existing owner-first transaction.');
        }
        // The first read is a current read, before establishing any RR snapshot.
        $owner = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail(['id', 'timezone']);

        return $this->inspect($owner, $id, $action, true);
    }

    /** SELECT-only source context; callers may share a read snapshot, never a writer. */
    public function read(User $user, string $id, Closure $action): mixed
    {
        $owner = User::whereKey($user->getKey())->firstOrFail(['id', 'timezone']);

        return $this->inspect($owner, $id, $action, false);
    }

    private function inspect(User $owner, string $id, Closure $action, bool $lock): mixed
    {
        $source = $owner->studySessions()->whereKey($id)->firstOrFail();
        Gate::forUser($owner)->authorize('update', $source);
        $task = $owner->tasks()->withTrashed()->whereKey($source->task_id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $child = $source->subtask_id === null || $task === null ? null
            : $task->subtasks()->withTrashed()->whereKey($source->subtask_id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($task === null || ($source->subtask_id !== null && $child === null)) {
            throw new UnexpectedValueException('Invalid study session source relationships.');
        }
        // Discover both directions without filtering owners: a foreign edge must fail closed.
        // Visited IDs guarantee termination even on a corrupt cycle. Lock the component by ID.
        $locked = $this->chainRecords($owner, [$source->id], $source, $lock);
        $source = $locked[$source->id];
        $interval = ['start' => $this->context->utcSeconds($source->getRawOriginal('starts_at')),
            'end' => $this->context->utcSeconds($source->getRawOriginal('ends_at'))];
        $now = CarbonImmutable::now('UTC'); // Capture after contention, retain full precision.

        return $action($owner, $source, $task, $child, $interval, $now, $locked);
    }

    /** Owner and work locks must already be held. Discover both directions, lock once by ID. */
    public function lockChainRecords(User $owner, array $sourceIds, ?StudySession $attribution = null): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('History locking requires a mutation transaction.');
        }

        return $this->chainRecords($owner, $sourceIds, $attribution, true);
    }

    private function chainRecords(User $owner, array $sourceIds, ?StudySession $attribution, bool $lock): Collection
    {
        $records = [];
        $pending = $sourceIds;
        while ($pending !== []) {
            $batch = StudySession::whereIn('id', $pending)->orWhereIn('rescheduled_from_session_id', $pending)->get();
            $next = [];
            foreach ($batch as $row) {
                // Preserve Stage D's pre-lock integrity boundary; never lock a foreign graph.
                // E2 supports multiple units and validates each owned component after locking.
                if ($row->user_id !== $owner->id || ($attribution !== null
                    && ($row->task_id !== $attribution->task_id || $row->subtask_id !== $attribution->subtask_id))) {
                    throw new UnexpectedValueException('Invalid study session source relationships.');
                }
                if (! isset($records[$row->id])) {
                    $records[$row->id] = true;
                    $next[] = $row->id;
                    if ($row->rescheduled_from_session_id !== null && ! isset($records[$row->rescheduled_from_session_id])) {
                        $next[] = $row->rescheduled_from_session_id;
                    }
                }
            }
            $pending = array_values(array_unique($next));
        }

        return StudySession::whereIn('id', array_keys($records))->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
    }

    public function validateChain(Collection $records, int $sourceId): void
    {
        $this->rules->validateChain($records->map(fn (StudySession $row): array => [
            'user_id' => $row->user_id, 'task_id' => $row->task_id, 'subtask_id' => $row->subtask_id,
            'rescheduled_from_session_id' => $row->rescheduled_from_session_id, 'status' => $row->status->value,
        ])->all(), $sourceId);
    }
}
