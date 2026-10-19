<?php

namespace App\Services\Planning;

use App\Enums\StudySessionStatus;
use App\Exceptions\StudyPlanConflictException;
use App\Models\Subtask;
use App\Models\User;
use App\Services\Reminders\ReminderDependencyService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

class StudyReplanningPersistenceService
{
    public function __construct(
        private StudyReplanningPreviewService $preview,
        private StudyReplanningConfirmationService $confirmation,
        private StudyPlanConfirmationService $fields,
        private StudySessionMutationContextService $mutation,
        private StudySessionLifecycleRules $rules,
        private PlanningWorkSelectionService $selection,
        private PlanningContextService $context,
        private PlanningScenarioService $scenario,
        private ReminderDependencyService $reminders,
    ) {}

    /** Lightweight HTTP adapter; the E1 application operation remains SELECT-only. */
    public function preview(User $user, string $startDate, string $endDate, mixed $taskId = null, mixed $subtaskId = null): array
    {
        $review = $this->preview->preview($user, $startDate, $endDate, $taskId, $subtaskId);
        $issued = CarbonImmutable::now('UTC')->getTimestamp();
        $parameters = ['start_date' => $startDate, 'end_date' => $endDate, 'task_id' => $taskId, 'subtask_id' => $subtaskId];

        return [...$review, 'preview_token' => $this->confirmation->issue($user->getKey(), $parameters, $review, $issued),
            'expires_at' => CarbonImmutable::createFromTimestampUTC($issued + StudyReplanningConfirmationService::TOKEN_LIFETIME_SECONDS)->format('Y-m-d\TH:i:s\Z')];
    }

    public function confirm(User $user, string $token, mixed $acceptPartial = false, mixed $acceptUnplacedCancellation = false): array
    {
        foreach (['accept_partial' => $acceptPartial, 'accept_unplaced_cancellation' => $acceptUnplacedCancellation] as $field => $value) {
            if (! is_bool($value)) {
                throw ValidationException::withMessages([$field => ['This field must be a boolean.']]);
            }
        }
        $this->decode($token, $user);
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Adaptive replanning confirmation requires an outermost transaction.');
        }

        return DB::transaction(fn () => $this->confirmWithinTransaction($user, $token, $acceptPartial, $acceptUnplacedCancellation));
    }

    /** Same E2 writer as REST, with commit owned by a trusted owner-first caller. */
    public function confirmWithinTransaction(User $user, string $token, mixed $acceptPartial = false, mixed $acceptUnplacedCancellation = false): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Adaptive replanning execution requires an existing owner-first transaction.');
        }
        foreach (['accept_partial' => $acceptPartial, 'accept_unplaced_cancellation' => $acceptUnplacedCancellation] as $field => $value) {
            if (! is_bool($value)) {
                throw ValidationException::withMessages([$field => ['This field must be a boolean.']]);
            }
        }
        // First read is current/locking: never establish a RR snapshot before owner contention.
        $owner = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail(['id', 'timezone']);
        $payload = $this->decode($token, $owner);
        $parameters = $payload['parameters'];
        $affected = $this->confirmation->affected($payload['review']);
        $ids = $payload['review']['released_session_ids'];
        $tasks = $owner->tasks()->withTrashed()->whereKey(array_unique(array_column($affected, 'task_id')))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $children = Subtask::withTrashed()->whereIn('task_id', $tasks->modelKeys())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $chains = $this->mutation->lockChainRecords($owner, $ids);
        $sources = new Collection;
        foreach ($affected as $row) {
            $source = $chains->get($row['study_session_id']);
            if ($source === null) {
                throw new StudyPlanConflictException;
            }
            Gate::forUser($owner)->authorize('update', $source);
            if ($source->status !== StudySessionStatus::Planned) {
                throw new StudyPlanConflictException;
            }
            if ($source->task_id !== $row['task_id'] || $source->subtask_id !== $row['subtask_id']
                || ! $tasks->has($source->task_id)
                || ($source->subtask_id !== null && (! $children->has($source->subtask_id)
                    || $children[$source->subtask_id]->task_id !== $source->task_id))) {
                throw new UnexpectedValueException('Invalid replanning source relationships.');
            }
            // Each component is validated separately: independent work units need not share attribution.
            $component = $this->component($chains, $source->id);
            $this->mutation->validateChain($component, $source->id);
            $interval = $this->interval($source);
            if (! $this->fields->matches($interval, $row['original'])) {
                throw new StudyPlanConflictException;
            }
            $this->rules->transition($row['replacement'] === null ? 'cancel' : 'reschedule', $source->status,
                $interval, CarbonImmutable::now('UTC'));
            $sources->push($source);
        }
        $scheduled = new Collection;
        // All session/history locks precede reminder locks, in deterministic source-ID order.
        foreach ($sources->sortBy('id') as $source) {
            $scheduled = $scheduled->concat($this->reminders->scheduledForSession($owner, $source));
        }
        try {
            $current = $this->preview->preview($owner, $parameters['start_date'], $parameters['end_date'],
                $parameters['task_id'], $parameters['subtask_id']);
        } catch (ModelNotFoundException) {
            throw new StudyPlanConflictException;
        }
        if (! $this->confirmation->matches($payload['review'], $current)) {
            throw new StudyPlanConflictException;
        }
        $error = $this->confirmation->acceptanceError($current, $acceptPartial, $acceptUnplacedCancellation);
        if ($error !== null) {
            throw ValidationException::withMessages([$error => [match ($error) {
                'accept_partial' => 'Explicit acceptance is required for partial or unplaced replacements.',
                'accept_unplaced_cancellation' => 'Explicit acceptance is required to cancel unplaced source sessions.',
                default => 'This preview contains no actionable replanning changes.',
            }]]);
        }
        $affected = $this->confirmation->affected($current);
        $this->assertFuture($affected);
        $this->decode($token, $owner);
        // Independently validate before any write, excluding the entire exact release set.
        $selection = $this->selection->select($owner, $parameters['task_id'], $parameters['subtask_id'], $ids);
        $snapshot = $this->context->load($owner, $parameters['start_date'], $parameters['end_date'], null, true);
        $this->confirmation->validateBatch($owner->id, $current, $selection['work_items'],
            $this->scenario->without($snapshot, $ids), CarbonImmutable::now('UTC'));
        $this->assertFuture($affected);
        $this->decode($token, $owner);
        foreach ($affected as $row) {
            $source = $sources->firstWhere('id', $row['study_session_id']);
            if (! $source->update($this->rules->transition($row['replacement'] === null ? 'cancel' : 'reschedule',
                $source->status, $this->interval($source), CarbonImmutable::now('UTC')))) {
                throw new LogicException('A replanning source could not be retired.');
            }
        }
        // Independent current rules after the exact release hypothesis is realized transactionally.
        // No inserts yet; C8 sees retained planned and completed scheduled workload exactly once.
        $selection = $this->selection->select($owner, $parameters['task_id'], $parameters['subtask_id']);
        $context = $this->context->load($owner, $parameters['start_date'], $parameters['end_date']);
        $this->confirmation->validateBatch($owner->id, $current, $selection['work_items'], $context, CarbonImmutable::now('UTC'));
        $created = new Collection;
        foreach ($affected as $row) {
            if ($row['replacement'] === null) {
                continue;
            }
            $attributes = $this->fields->sessionAttributes($owner->id, $row['replacement']);
            $attributes['rescheduled_from_session_id'] = $row['study_session_id'];
            $successor = $owner->studySessions()->create($attributes);
            if (! $successor->exists) {
                throw new LogicException('A replanning successor could not be saved.');
            }
            $created->push($successor);
        }
        $this->reminders->cancel($scheduled);
        $this->assertFuture($affected);
        $this->decode($token, $owner);

        return ['original_sessions' => $sources, 'replacement_sessions' => $created,
            'summary' => ['rescheduled_sessions' => $created->count(),
                'cancelled_unplaced_sessions' => $sources->count() - $created->count(),
                'created_replacements' => $created->count(), 'untransferred_seconds' => $current['summary']['untransferred_seconds']]];
    }

    private function decode(string $token, User $owner): array
    {
        try {
            return $this->confirmation->decode($token, $owner->getKey(), CarbonImmutable::now('UTC')->getTimestamp());
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['preview_token' => ['The preview token is invalid or expired. Obtain a fresh preview.']]);
        }
    }

    private function interval($source): array
    {
        return ['start' => $this->context->utcSeconds($source->getRawOriginal('starts_at')),
            'end' => $this->context->utcSeconds($source->getRawOriginal('ends_at'))];
    }

    private function assertFuture(array $affected): void
    {
        $proposals = array_values(array_filter(array_column($affected, 'replacement')));
        if (! $this->fields->startsAreFuture([...array_column($affected, 'original'), ...$proposals], CarbonImmutable::now('UTC'))) {
            throw new StudyPlanConflictException;
        }
    }

    private function component(Collection $records, int $sourceId): Collection
    {
        $ids = [$sourceId];
        do {
            $before = $ids;
            foreach ($records as $record) {
                if (in_array($record->id, $ids, true) || in_array($record->rescheduled_from_session_id, $ids, true)) {
                    $ids[] = $record->id;
                    if ($record->rescheduled_from_session_id !== null) {
                        $ids[] = $record->rescheduled_from_session_id;
                    }
                }
            }
            $ids = array_values(array_unique($ids));
        } while ($ids !== $before);

        return $records->only($ids)->keyBy('id');
    }
}
