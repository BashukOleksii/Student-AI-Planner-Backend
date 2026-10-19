<?php

namespace App\Services\Planning;

use App\Exceptions\StudySessionConflictException;
use App\Models\User;
use App\Services\Reminders\ReminderDependencyService;
use Carbon\CarbonImmutable;
use Closure;
use LogicException;

class StudySessionReschedulingService
{
    public function __construct(
        private StudySessionMutationContextService $mutation,
        private StudySessionLifecycleRules $rules,
        private PlanningWorkSelectionService $selection,
        private PlanningCandidateEvaluationService $evaluation,
        private StudyPlanConfirmationService $fields,
        private ReminderDependencyService $reminders,
    ) {}

    public function reschedule(User $user, string $id, mixed $startsAt, mixed $endsAt): array
    {
        return $this->mutation->run($user, $id, $this->action($startsAt, $endsAt));
    }

    public function rescheduleWithinTransaction(User $user, string $id, mixed $startsAt, mixed $endsAt): array
    {
        return $this->mutation->runWithinTransaction($user, $id, $this->action($startsAt, $endsAt));
    }

    /** Preliminary checks only: final effort and C10 feasibility are checked after source retirement. */
    public function preview(User $user, string $id, mixed $startsAt, mixed $endsAt): array
    {
        $candidate = ['start' => $this->rules->timestamp($startsAt), 'end' => $this->rules->timestamp($endsAt)];
        $duration = (new TimeIntervalService)->durationSeconds($candidate);

        return $this->mutation->read($user, $id, function ($owner, $source, $task, $child, $interval, $now, $chain) use ($candidate, $duration): array {
            $this->preflight($source, $task, $child, $interval, $now, $chain, $candidate, $duration);

            return ['original' => $source, 'candidate' => $candidate,
                'summary' => array_diff_key($this->rules->replacementSummary($interval['end'] - $interval['start'], $duration, $duration),
                    ['unallocated_work_seconds' => true]),
                'records' => ['task' => $task, 'child' => $child, ...$chain->mapWithKeys(fn ($row) => ['session_'.$row->id => $row])->all()]];
        });
    }

    private function preflight($source, $task, $child, $interval, $now, $chain, array $candidate, int $duration): array
    {
        $transition = $this->rules->transition('reschedule', $source->status, $interval, $now);
        $this->mutation->validateChain($chain, $source->id);
        if ($task->trashed() || $child?->trashed() || $duration > $interval['end'] - $interval['start']
            || ! $this->fields->startsAreFuture([$candidate], $now)) {
            throw new StudySessionConflictException;
        }

        return $transition;
    }

    private function action(mixed $startsAt, mixed $endsAt): Closure
    {
        $candidate = ['start' => $this->rules->timestamp($startsAt), 'end' => $this->rules->timestamp($endsAt)];
        $duration = (new TimeIntervalService)->durationSeconds($candidate);

        return function ($owner, $source, $task, $child, $interval, $now, $chain) use ($candidate, $duration): array {
            $transition = $this->preflight($source, $task, $child, $interval, $now, $chain, $candidate, $duration);
            $originalSeconds = $interval['end'] - $interval['start'];
            $reminders = $this->reminders->scheduledForSession($owner, $source);
            // Release only inside this transaction. All later failures restore the old reservation.
            if (! $source->update($transition)) {
                throw new LogicException('The source session could not be retired.');
            }
            $selection = $this->selection->select($owner, $source->task_id, $source->subtask_id);
            $work = collect($selection['work_items'])->first(fn ($item) => $item['task_id'] === $source->task_id
                && $item['subtask_id'] === $source->subtask_id);
            if ($work === null) {
                throw new StudySessionConflictException;
            }
            $summary = $this->rules->replacementSummary($originalSeconds, $duration, $work['effort']['unallocated_work_seconds']);
            if ($this->evaluation->evaluate($owner, $candidate, $source->task_id, $source->subtask_id) !== []) {
                throw new StudySessionConflictException;
            }
            $attributes = $this->fields->sessionAttributes($owner->id, [
                ...$candidate, 'duration_seconds' => $duration, 'task_id' => $source->task_id, 'subtask_id' => $source->subtask_id,
            ]);
            $attributes['rescheduled_from_session_id'] = $source->id;
            $replacement = $owner->studySessions()->create($attributes);
            if (! $replacement->exists) {
                throw new LogicException('The replacement session could not be saved.');
            }
            $this->reminders->cancel($reminders);
            // Report Stage A's current post-insert total, including reservations that may have
            // elapsed during this operation, rather than equating it to the transferred shortfall.
            $current = $this->selection->select($owner, $source->task_id, $source->subtask_id);
            $unit = collect([...$current['work_items'], ...$current['excluded_items']])->first(fn ($item) => $item['task_id'] === $source->task_id && $item['subtask_id'] === $source->subtask_id);
            if ($unit === null || $unit['effort'] === null) {
                throw new StudySessionConflictException;
            }
            $summary['unallocated_work_seconds'] = $unit['effort']['unallocated_work_seconds'];
            // A slow insert/event must not leave a replacement already started at commit.
            if (! $this->fields->startsAreFuture([$candidate], CarbonImmutable::now('UTC'))) {
                throw new StudySessionConflictException;
            }

            return ['original' => $source, 'replacement' => $replacement, 'summary' => $summary];
        };
    }
}
