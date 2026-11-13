<?php

namespace App\Services\AI\Actions;

use App\Exceptions\AiAgentException;
use App\Exceptions\SemesterOccurrenceConflictException;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Schedule\EffectiveScheduleCalculator;
use App\Services\Schedule\SemesterScheduleManagementService;
use App\Services\Schedule\SemesterScheduleStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** Thin semester adapters using management's identical projection/validation and existing H3 receipts. */
class SemesterActionService
{
    public function __construct(private SemesterActionArgumentValidator $validator,
        private SemesterScheduleManagementService $management, private SemesterScheduleStateService $state,
        private EffectiveScheduleCalculator $calculator) {}

    public function prepare(User $owner, string $name, array $arguments, string $callId): array
    {
        try {
            $arguments = $this->validator->validate($name, $arguments);
            if (! $owner->exists || $owner->getKey() === null) {
                return $this->error($name, 'access_denied');
            }

            return DB::transaction(function () use ($owner, $name, $arguments, $callId): array {
                [$owner, $graph] = $this->load($owner, $arguments);
                $token = $this->state->fingerprint($owner, $graph);
                $preview = $this->preview($owner, $name, $arguments, $graph, $token);
                $proposal = ['id' => (string) Str::uuid(), 'type' => $name, 'call_id' => $callId,
                    'arguments' => $arguments, 'preview' => $preview, 'target_hash' => $token];

                return ['proposal' => $proposal, 'result' => ['ok' => true, 'tool' => $name, 'data' => [
                    'state' => 'confirmation_required', 'action_id' => $proposal['id'], 'call_id' => $callId, 'preview' => $preview,
                ]]];
            });
        } catch (ModelNotFoundException|AuthorizationException|NotFoundHttpException) {
            return $this->error($name, 'not_found');
        } catch (SemesterOccurrenceConflictException $exception) {
            return $this->error($name, 'invalid_arguments', $name === 'propose_semester_occurrence_create' ? $exception->reason : null);
        } catch (InvalidArgumentException|ValidationException) {
            return $this->error($name, 'invalid_arguments');
        } catch (ConflictHttpException) {
            return $this->error($name, 'action_stale');
        } catch (Throwable) {
            return $this->error($name, 'execution_failed');
        }
    }

    /** The existing confirmation service owns conversation/action/owner locks and receipt durability. */
    public function execute(User $owner, AiPendingAction $action): array
    {
        if (DB::transactionLevel() === 0) {
            throw new AiAgentException('persistence_error');
        }
        try {
            $arguments = $this->validator->validate($action->type, $action->arguments);
            [$owner, $graph] = $this->load($owner, $arguments, true);
            $token = $this->state->fingerprint($owner, $graph);
            if (! hash_equals($action->target_hash ?? '', $token)) {
                throw new AiAgentException('action_stale');
            }
            $preview = $this->preview($owner, $action->type, $arguments, $graph, $token);
            if ($this->state->hash($preview) !== $this->state->hash($action->preview)) {
                throw new AiAgentException('action_stale');
            }
            $saved = $this->apply($owner, $graph, $action->type, $arguments, $token, false);
            // Re-read from persistence rather than trusting model event return values.
            $persisted = $this->state->load($owner, $graph['period']->id, true);
            $after = $this->describe($persisted, $action->type, $arguments, true, $owner->timezone, $graph);
            if ($this->semantic($preview['after']) !== $this->semantic($after)) {
                throw new AiAgentException('persistence_error');
            }

            return ['semester_schedule_id' => $saved['schedule']->id, 'operation' => $action->type,
                'timezone' => $owner->timezone, 'after' => $after, 'effects' => $preview['effects']];
        } catch (ModelNotFoundException|AuthorizationException|NotFoundHttpException|ConflictHttpException) {
            throw new AiAgentException('action_stale');
        } catch (InvalidArgumentException|ValidationException) {
            throw new AiAgentException('action_validation_failed');
        }
    }

    private function load(User $user, array $arguments, bool $lock = false): array
    {
        $owner = User::whereKey($user->id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        $schedule = $owner->semesterSchedules()->findOrFail($arguments['semester_schedule_id']);
        Gate::forUser($owner)->authorize('update', $schedule);
        $graph = $this->state->load($owner, $schedule->academic_period_id, $lock);

        return [$owner, $graph];
    }

    private function preview(User $owner, string $name, array $arguments, array $graph, string $token): array
    {
        $projected = $this->apply($owner, $graph, $name, $arguments, $token, true);
        $before = $this->describe($graph, $name, $arguments, false, $owner->timezone);
        $after = $this->describe($projected, $name, $arguments, true, $owner->timezone, $graph);

        return ['type' => $name, 'confirmation_required' => true, 'semester_schedule_id' => $graph['schedule']->id,
            'timezone' => $owner->timezone,
            'before' => $before, 'after' => $after,
            'effects' => ['scope' => str_contains($name, '_rule_') ? 'recurring_rule'
                : ($name === 'propose_semester_settings_update' ? 'semester_settings' : 'one_date'),
                'changes_academic_calendar' => $this->semantic($before) !== $this->semantic($after), 'reschedules_study_sessions' => false,
                'changes_legacy_lessons' => false,
                'preserves_frozen_dates' => str_contains($name, '_rule_'),
                'may_suppress_imported_slot' => in_array($name, ['propose_semester_rule_delete', 'propose_semester_rule_update'], true),
                'restores_base_date' => $name === 'propose_semester_date_delete']];
    }

    private function apply(User $owner, array $graph, string $name, array $arguments, string $token, bool $previewOnly): array
    {
        $schedule = $graph['schedule'];
        $values = [...Arr::except($arguments, ['semester_schedule_id', 'weekly_lesson_rule_id', 'date', 'occurrence_id']),
            'schedule_token' => $token];

        return match ($name) {
            'propose_semester_rule_create' => $this->management->createRule($owner, $schedule, $values, $previewOnly),
            'propose_semester_rule_update' => $this->management->updateRule($owner, $schedule, $arguments['weekly_lesson_rule_id'], $values, $previewOnly),
            'propose_semester_rule_delete' => $this->management->deleteRule($owner, $schedule, $arguments['weekly_lesson_rule_id'], $values, $previewOnly),
            'propose_semester_date_replace' => $this->management->replaceDate($owner, $schedule, $arguments['date'], $values, $previewOnly),
            'propose_semester_date_delete' => $this->management->deleteException($owner, $schedule, $arguments['date'], $values, $previewOnly),
            'propose_semester_working_saturday' => $this->management->workingSaturday($owner, $schedule, $values, $previewOnly),
            'propose_semester_occurrence_create' => $this->management->createOccurrence($owner, $schedule, $arguments['date'], $values, $previewOnly),
            'propose_semester_occurrence_update', 'propose_semester_occurrence_cancel' => $this->management->changeOccurrence($owner, $schedule,
                $arguments['date'], $arguments['occurrence_id'], Arr::only($arguments, array_keys((new SemesterActionRegistry)->lessonFields())),
                $name === 'propose_semester_occurrence_cancel', $values, $previewOnly),
            'propose_semester_settings_update' => $this->management->update($owner, $schedule, $values, $previewOnly),
            default => throw new InvalidArgumentException,
        };
    }

    private function describe(array $graph, string $name, array $arguments, bool $after, string $timezone, ?array $original = null): ?array
    {
        if ($name === 'propose_semester_settings_update') {
            return [...$graph['schedule']->only(['id', 'group_name', 'first_week_parity']),
                'parity_anchor_on' => $graph['schedule']->parity_anchor_on->format('Y-m-d')];
        }
        if (str_contains($name, '_rule_')) {
            if ($name === 'propose_semester_rule_create') {
                $rule = $after ? $graph['rules']->first(fn ($rule) => $rule->id === -1
                    || ! $original['rules']->contains('id', $rule->id)) : null;
            } else {
                $rule = $graph['rules']->find($arguments['weekly_lesson_rule_id']);
            }
            if ($rule === null) {
                return null;
            }
            $row = $rule->only(['id', 'weekday', 'week_pattern', ...array_keys((new SemesterActionRegistry)->lessonFields())]);
            $row['id'] = $row['id'] < 1 ? null : $row['id'];

            return $this->associations($row, $graph);
        }
        $date = $arguments['date'] ?? $arguments['occurs_on'];
        $snapshot = $graph['snapshots'][$date] ?? null;
        $lessons = $this->calculator->calculate($this->state->config($graph),
            $graph['rules']->map->attributesToArray()->all(), $snapshot, $date, $timezone);

        return ['date' => $date, 'kind' => $snapshot['kind'] ?? 'base',
            'copied_weekday' => $snapshot['copied_weekday'] ?? null,
            'source_week_parity' => $snapshot['source_week_parity'] ?? null,
            'lessons' => array_map(fn ($row) => $this->associations(Arr::except($row, ['start', 'end']), $graph), $lessons)];
    }

    private function associations(array $row, array $graph): array
    {
        $row['subject'] = $graph['subjects']->find($row['subject_id'])?->only(['id', 'name']);
        $row['teacher'] = $graph['teachers']->find($row['teacher_id'])?->only(['id', 'name']);

        return json_decode(json_encode($row, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Generated identities differ between an unsaved projection and the verified saved result. */
    private function semantic(?array $value): string
    {
        $strip = function (mixed $item) use (&$strip): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                $item = Arr::except($item, ['id', 'occurrence_id', 'schedule_date_exception_id']);
            }

            return array_map($strip, $item);
        };

        return $this->state->hash($strip($value));
    }

    private function error(string $name, string $code, ?string $reason = null): array
    {
        return ['proposal' => null, 'result' => ['ok' => false, 'tool' => $name, 'error' => [
            'code' => $code, ...($reason === null ? [] : ['reason' => $reason]), 'message' => match ($code) {
                'invalid_arguments' => 'Invalid semester action arguments.', 'not_found' => 'Resource not found.',
                'access_denied' => 'Access denied.', 'action_stale' => 'The semester schedule changed. Read it again.',
                default => 'Semester proposal could not be prepared.',
            },
        ]]];
    }
}
