<?php

namespace App\Services\Planning;

use App\Exceptions\StudySessionConflictException;
use App\Models\User;
use App\Services\Reminders\ReminderDependencyService;
use Carbon\CarbonImmutable;
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
        $candidate = ['start' => $this->rules->timestamp($startsAt), 'end' => $this->rules->timestamp($endsAt)];
        $duration = (new TimeIntervalService)->durationSeconds($candidate);

        return $this->mutation->run($user, $id, function ($owner, $source, $task, $child, $interval, $now, $chain) use ($candidate, $duration): array {
            $transition = $this->rules->transition('reschedule', $source->status, $interval, $now);
            $this->mutation->validateChain($chain, $source->id);
            $originalSeconds = $interval['end'] - $interval['start'];
            if ($task->trashed() || $child?->trashed() || $duration > $originalSeconds
                || ! $this->fields->startsAreFuture([$candidate], $now)) {
                throw new StudySessionConflictException;
            }
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
        });
    }
}
