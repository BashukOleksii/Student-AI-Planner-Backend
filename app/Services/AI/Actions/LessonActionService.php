<?php

namespace App\Services\AI\Actions;

use App\Exceptions\AiAgentException;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Schedule\LessonService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/** Thin Lesson adapters. Proposal preparation never calls any domain writer. */
class LessonActionService
{
    public function __construct(private LessonActionArgumentValidator $validator, private LessonService $lessons,
        private LessonActionPresenter $presenter, private ActionTargetFingerprint $fingerprints) {}

    public function prepare(User $owner, string $name, array $arguments, string $callId): array
    {
        try {
            $arguments = $this->validator->validate($name, $arguments);
            if (! $owner->exists || $owner->getKey() === null) {
                return $this->error($name, 'access_denied');
            }

            return DB::transaction(function () use ($owner, $name, $arguments, $callId): array {
                $records = $this->load($owner, $arguments);
                $preview = $this->preview($owner, $name, $arguments, $records);
                $proposal = ['id' => (string) Str::uuid(), 'type' => $name, 'call_id' => $callId, 'arguments' => $arguments,
                    'preview' => $preview, 'target_hash' => $this->fingerprints->hash($records)];

                return ['proposal' => $proposal, 'result' => ['ok' => true, 'tool' => $name, 'data' => [
                    'state' => 'confirmation_required', 'action_id' => $proposal['id'], 'call_id' => $callId, 'preview' => $preview,
                ]]];
            });
        } catch (ModelNotFoundException) {
            return $this->error($name, 'not_found');
        } catch (InvalidArgumentException|ValidationException) {
            return $this->error($name, 'invalid_arguments');
        } catch (Throwable) {
            return $this->error($name, 'execution_failed');
        }
    }

    /** H3 holds conversation/action/owner locks before its first consistent read. */
    public function execute(User $owner, AiPendingAction $action): array
    {
        if (DB::transactionLevel() === 0) {
            throw new AiAgentException('persistence_error');
        }
        try {
            $arguments = $this->validator->validate($action->type, $action->arguments);
        } catch (InvalidArgumentException) {
            throw new AiAgentException('action_validation_failed');
        }
        try {
            $records = $this->load($owner, $arguments, true);
        } catch (ModelNotFoundException) {
            throw new AiAgentException('action_stale');
        }
        if (! hash_equals($action->target_hash ?? '', $this->fingerprints->hash($records))) {
            throw new AiAgentException('action_stale');
        }
        try {
            // Re-run domain preflight and compare the complete material review, including labels/links.
            if ($this->preview($owner, $action->type, $arguments, $records) != $action->preview) {
                throw new AiAgentException('action_stale');
            }
            $values = array_diff_key($arguments, ['lesson_id' => true]);
            $lesson = $records['lesson'];
            $result = match ($action->type) {
                'propose_lesson_create' => $this->lessons->create($owner, $values),
                'propose_lesson_update' => $this->lessons->update($owner, $lesson, $values),
                'propose_lesson_cancel' => $this->lessons->cancel($owner, $lesson),
                'propose_lesson_replacement' => $this->lessons->createReplacement($owner, $lesson, $values),
            };
        } catch (ValidationException) {
            throw new AiAgentException('action_validation_failed');
        }

        if (! $result->exists || $result->getKey() === null || $result->trashed()) {
            throw new AiAgentException('persistence_error');
        }
        $receipt = ['lesson' => $this->presenter->lesson($owner, $result),
            'original' => $action->type === 'propose_lesson_replacement' ? $this->presenter->lesson($owner, $lesson->refresh()) : null];
        $expected = $action->preview['after'];
        $expected['id'] ??= $result->id;
        // Model event cancellation must never become a successful receipt for an unwritten change.
        if ($receipt['lesson'] != $expected || ($receipt['original'] !== null
            && ($receipt['original']['status'] !== 'replaced' || $receipt['original']['replacement_id'] !== $result->id))) {
            throw new AiAgentException('persistence_error');
        }

        return $receipt;
    }

    private function load(User $owner, array $arguments, bool $lock = false): array
    {
        $lesson = isset($arguments['lesson_id']) ? $owner->lessons()->whereKey($arguments['lesson_id'])
            ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail() : null;
        if ($lesson !== null) {
            Gate::forUser($owner)->authorize('update', $lesson);
        }
        $original = $lesson?->replaces_lesson_id === null ? null : $owner->lessons()->withTrashed()->whereKey($lesson->replaces_lesson_id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        $replacement = $lesson?->replacement()->withTrashed()->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if ($replacement !== null && $replacement->user_id !== $owner->id) {
            throw new ModelNotFoundException;
        }
        $records = compact('lesson', 'original', 'replacement');
        // Bind all reviewed association identities and labels, including old and proposed values.
        foreach (['subject_id' => 'subjects', 'teacher_id' => 'teachers', 'academic_period_id' => 'academicPeriods'] as $field => $relation) {
            $ids = array_filter([$arguments[$field] ?? null, $lesson?->$field, $original?->$field, $replacement?->$field], fn ($id) => $id !== null);
            $ids = array_unique($ids);
            sort($ids);
            foreach ($ids as $id) {
                $records[$field.':'.$id] = $owner->$relation()->whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
            }
        }

        return $records;
    }

    private function preview(User $owner, string $name, array $arguments, array $records): array
    {
        $lesson = $records['lesson'];
        $values = array_diff_key($arguments, ['lesson_id' => true]);
        $candidate = match ($name) {
            'propose_lesson_create', 'propose_lesson_update' => $this->lessons->prepare($owner, $values, $lesson),
            'propose_lesson_replacement' => $this->lessons->prepareReplacement($owner, $lesson, $values)['candidate'],
            default => [],
        };
        if ($name === 'propose_lesson_cancel') {
            $this->lessons->prepareCancel($owner, $lesson);
        }
        $before = $lesson === null ? null : $this->presenter->lesson($owner, $lesson);
        $after = match ($name) {
            'propose_lesson_cancel' => [...$before, 'status' => 'cancelled'],
            'propose_lesson_update' => [...$before, ...$this->presenter->values($owner, $candidate)],
            default => ['id' => $name === 'propose_lesson_replacement' ? $records['replacement']?->id : null,
                ...$this->presenter->values($owner, [...$candidate, 'status' => 'active']),
                'replaces_lesson_id' => $name === 'propose_lesson_replacement' ? $lesson->id : null, 'replacement_id' => null],
        };

        return ['type' => $name, 'confirmation_required' => true, 'before' => $before, 'after' => $after,
            'effects' => ['original_becomes_replaced' => $name === 'propose_lesson_replacement',
                'restores_original' => false, 'study_sessions_rescheduled' => false,
                'import_identity_preserved' => true]];
    }

    private function error(string $name, string $code): array
    {
        return ['proposal' => null, 'result' => ['ok' => false, 'tool' => $name, 'error' => ['code' => $code,
            'message' => match ($code) {
                'invalid_arguments' => 'Invalid lesson action arguments.', 'not_found' => 'Resource not found.',
                'access_denied' => 'Access denied.', default => 'Lesson proposal could not be prepared.',
            }]]];
    }
}
