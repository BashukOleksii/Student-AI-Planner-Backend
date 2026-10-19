<?php

namespace App\Services\Planning;

use App\Exceptions\StudyPlanConflictException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

class StudyPlanPersistenceService
{
    public function __construct(
        private StudyPlanningService $planner,
        private StudyPlanConfirmationService $confirmation,
        private PlanningWorkSelectionService $selection,
        private PlanningContextService $context,
        private PlanningConstraintService $constraints,
    ) {}

    public function preview(User $user, string $startDate, string $endDate, mixed $taskId = null, mixed $subtaskId = null): array
    {
        $plan = $this->planner->plan($user, $startDate, $endDate, $taskId, $subtaskId);
        $issued = CarbonImmutable::now('UTC')->getTimestamp();
        $parameters = ['start_date' => $startDate, 'end_date' => $endDate, 'task_id' => $taskId, 'subtask_id' => $subtaskId];

        return ['plan' => $plan, 'preview_token' => $this->confirmation->issue($user->getKey(), $parameters, $plan, $issued),
            'expires_at' => CarbonImmutable::createFromTimestampUTC($issued + StudyPlanConfirmationService::TOKEN_LIFETIME_SECONDS)->format('Y-m-d\TH:i:s\Z')];
    }

    /** Owns a fresh outermost transaction; never inherits an earlier consistent-read snapshot. */
    public function confirm(User $user, string $previewToken, mixed $acceptPartial = false): array
    {
        if (! is_bool($acceptPartial)) {
            throw ValidationException::withMessages(['accept_partial' => ['The accept partial field must be a boolean.']]);
        }
        $this->decode($previewToken, $user);
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Study plan confirmation requires an outermost transaction.');
        }

        return DB::transaction(fn () => $this->confirmWithinTransaction($user, $previewToken, $acceptPartial));
    }

    /** Trusted caller owns atomic commit and must lock owner before any consistent read. */
    public function confirmWithinTransaction(User $user, string $previewToken, mixed $acceptPartial = false): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Study plan execution requires an existing owner-first transaction.');
        }
        if (! is_bool($acceptPartial)) {
            throw ValidationException::withMessages(['accept_partial' => ['The accept partial field must be a boolean.']]);
        }
        $review = $this->decode($previewToken, $user);
        // First database read in this transaction is a current locking read.
        // Cooperating mutations hold this same owner lock before touching planning data.
        $owner = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail(['id', 'timezone']);
        $this->decode($previewToken, $owner); // A lock wait can outlive the token.
        $parameters = $review['parameters'];
        try {
            $current = $this->planner->plan($owner, $parameters['start_date'], $parameters['end_date'],
                $parameters['task_id'], $parameters['subtask_id']);
        } catch (ModelNotFoundException) {
            // Targets were owned at issue time; disappearing/reparented targets make this review stale.
            throw new StudyPlanConflictException;
        }
        if (! $this->confirmation->matches($review['plan'], $current)) {
            throw new StudyPlanConflictException;
        }
        $error = $this->confirmation->acceptanceError($current, $acceptPartial);
        if ($error !== null) {
            throw ValidationException::withMessages([$error => [$error === 'accept_partial'
                ? 'Explicit acceptance is required to save this partial plan.' : 'This preview contains no new sessions to save.']]);
        }
        $this->validateBatch($owner, $current, $parameters);
        $created = new Collection;
        foreach ($current['sessions'] as $proposal) {
            $session = $owner->studySessions()->create($this->confirmation->sessionAttributes($owner->getKey(), $proposal));
            if (! $session->exists) {
                throw new LogicException('A proposed session could not be saved.');
            }
            $created->push($session);
        }
        // Time can advance during a large batch or model event: fail and roll back all inserts.
        $this->decode($previewToken, $owner);
        if (! $this->confirmation->startsAreFuture($current['sessions'], CarbonImmutable::now('UTC'))) {
            throw new StudyPlanConflictException;
        }

        return ['plan' => $current, 'sessions' => $created];
    }

    private function decode(string $token, User $owner): array
    {
        try {
            return $this->confirmation->decode($token, $owner->getKey(), CarbonImmutable::now('UTC')->getTimestamp());
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['preview_token' => ['The preview token is invalid or expired. Obtain a fresh preview.']]);
        }
    }

    private function validateBatch(User $owner, array $plan, array $parameters): void
    {
        $selection = $this->selection->select($owner, $parameters['task_id'], $parameters['subtask_id']);
        $work = [];
        foreach ($selection['work_items'] as $item) {
            $work[$item['task_id'].':'.($item['subtask_id'] ?? 'task')] = $item;
        }
        $context = $this->context->load($owner, $parameters['start_date'], $parameters['end_date']);
        $allocated = [];
        foreach ($plan['sessions'] as $index => $proposal) {
            $this->confirmation->sessionAttributes($owner->getKey(), $proposal);
            $key = $proposal['task_id'].':'.($proposal['subtask_id'] ?? 'task');
            if (! isset($work[$key])) {
                throw new StudyPlanConflictException;
            }
            if (($allocated[$key] ?? 0) > PHP_INT_MAX - $proposal['duration_seconds']) {
                throw new StudyPlanConflictException;
            }
            $allocated[$key] = ($allocated[$key] ?? 0) + $proposal['duration_seconds'];
            if ($allocated[$key] > $work[$key]['effort']['unallocated_work_seconds']) {
                throw new StudyPlanConflictException;
            }
            $others = [];
            foreach ($plan['sessions'] as $otherIndex => $other) {
                if ($index !== $otherIndex) {
                    $others[] = ['start' => $other['start'], 'end' => $other['end']];
                }
            }
            if ($this->constraints->evaluate(['start' => $proposal['start'], 'end' => $proposal['end']],
                $context['free_intervals'], [...$context['break_anchors'], ...$others],
                [...$context['workload_sessions'], ...$others], $context['timezone'], $context['preferences'],
                $work[$key]['effective_deadline_utc']) !== []) {
                throw new StudyPlanConflictException;
            }
        }
        if (! $this->confirmation->startsAreFuture($plan['sessions'], CarbonImmutable::now('UTC'))) {
            throw new StudyPlanConflictException;
        }
    }
}
