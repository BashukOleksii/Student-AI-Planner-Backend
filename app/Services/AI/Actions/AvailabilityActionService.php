<?php

namespace App\Services\AI\Actions;

use App\Exceptions\AiAgentException;
use App\Http\Resources\StudyAvailabilityWindowResource;
use App\Models\AiPendingAction;
use App\Models\StudyAvailabilityWindow;
use App\Models\User;
use App\Services\Planning\StudyAvailabilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/** Thin proposals and confirmed adapters over the single authoritative availability writer. */
class AvailabilityActionService
{
    public function __construct(private AvailabilityActionArgumentValidator $validator,
        private StudyAvailabilityService $availability, private ActionTargetFingerprint $fingerprints) {}

    public function prepare(User $owner, string $name, array $arguments, string $callId): array
    {
        try {
            $arguments = $this->validator->validate($name, $arguments);
            if (! $owner->exists || $owner->getKey() === null) {
                return $this->error($name, 'access_denied');
            }

            return DB::transaction(function () use ($owner, $name, $arguments, $callId): array {
                $records = $this->load($owner, $name, $arguments);
                $preview = $this->preview($name, $arguments, $records);
                $proposal = ['id' => (string) Str::uuid(), 'type' => $name, 'call_id' => $callId, 'arguments' => $arguments,
                    'preview' => $preview, 'target_hash' => $this->fingerprints->hash($records)];

                return ['proposal' => $proposal, 'result' => ['ok' => true, 'tool' => $name, 'data' => [
                    'state' => 'confirmation_required', 'action_id' => $proposal['id'], 'call_id' => $callId, 'preview' => $preview,
                ]]];
            });
        } catch (ModelNotFoundException|AuthorizationException) {
            return $this->error($name, 'not_found');
        } catch (InvalidArgumentException|ValidationException) {
            return $this->error($name, 'invalid_arguments');
        } catch (Throwable) {
            return $this->error($name, 'execution_failed');
        }
    }

    /** Existing H3 confirmation owns conversation/action/owner locks and the outer commit. */
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
            $records = $this->load($owner, $action->type, $arguments, true);
        } catch (ModelNotFoundException|AuthorizationException) {
            throw new AiAgentException('action_stale');
        }
        if (! hash_equals($action->target_hash ?? '', $this->fingerprints->hash($records))) {
            throw new AiAgentException('action_stale');
        }
        try {
            $preview = $this->preview($action->type, $arguments, $records);
            if ($preview != $action->preview) {
                throw new AiAgentException('action_stale');
            }
            $currentOwner = $records['owner'];
            $target = $this->target($arguments, $records);
            $values = array_diff_key($arguments, ['availability_window_id' => true]);
            $result = match ($action->type) {
                'propose_availability_create' => $this->availability->create($currentOwner, $values),
                'propose_availability_update' => $this->availability->update($currentOwner, $target, $values),
                'propose_availability_delete' => $this->availability->delete($currentOwner, $target),
            };
        } catch (ValidationException) {
            throw new AiAgentException('action_validation_failed');
        }

        $deleted = $action->type === 'propose_availability_delete';
        if ($deleted) {
            if ($currentOwner->studyAvailabilityWindows()->whereKey($target->id)->exists()) {
                throw new AiAgentException('persistence_error');
            }
            $saved = null;
        } else {
            if (! $result instanceof StudyAvailabilityWindow || ! $result->exists || $result->getKey() === null) {
                throw new AiAgentException('persistence_error');
            }
            // Verify database state, not the potentially modified in-memory result of a model event.
            $persisted = $currentOwner->studyAvailabilityWindows()->find($result->getKey());
            $saved = $persisted === null ? null : $this->window($persisted);
            $expected = $preview['after'];
            $expected['id'] ??= $result->getKey();
            if ($saved != $expected) {
                throw new AiAgentException('persistence_error');
            }
        }

        return ['window' => $saved, 'deleted_window' => $deleted ? $preview['before'] : null,
            'timezone' => $currentOwner->timezone, 'effects' => $preview['effects']];
    }

    private function load(User $owner, string $name, array $arguments, bool $lock = false): array
    {
        // Fresh profile and complete owned configuration form one conservative review fingerprint.
        $owner = User::whereKey($owner->getKey())->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail(['id', 'timezone']);
        $records = ['owner' => $owner];
        foreach ($owner->studyAvailabilityWindows()->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get() as $window) {
            $records['window:'.$window->id] = $window;
        }
        if (isset($arguments['availability_window_id'])) {
            $target = $this->target($arguments, $records);
            if ($target === null) {
                throw new ModelNotFoundException;
            }
            Gate::forUser($owner)->authorize($name === 'propose_availability_delete' ? 'delete' : 'update', $target);
        }

        return $records;
    }

    private function target(array $arguments, array $records): ?StudyAvailabilityWindow
    {
        return isset($arguments['availability_window_id']) ? ($records['window:'.$arguments['availability_window_id']] ?? null) : null;
    }

    private function preview(string $name, array $arguments, array $records): array
    {
        $owner = $records['owner'];
        $target = $this->target($arguments, $records);
        $before = $target === null ? null : $this->window($target);
        $deleted = $name === 'propose_availability_delete';
        $candidate = $deleted ? null : $this->availability->prepare($owner,
            array_diff_key($arguments, ['availability_window_id' => true]), $target);

        return ['type' => $name, 'confirmation_required' => true, 'before' => $before,
            'after' => $candidate === null ? null : ['id' => $target?->id, ...$candidate],
            'timezone' => $owner->timezone,
            'effects' => ['changes_recurring_availability' => true, 'removes_availability_window' => $deleted,
                'reschedules_existing_sessions' => false, 'changes_planning_preferences' => false,
                'deletes_tasks' => false, 'deletes_lessons' => false, 'deletes_study_sessions' => false, 'changes_reminders' => false]];
    }

    private function window(StudyAvailabilityWindow $window): array
    {
        return (new StudyAvailabilityWindowResource($window))->resolve();
    }

    private function error(string $name, string $code): array
    {
        return ['proposal' => null, 'result' => ['ok' => false, 'tool' => $name, 'error' => ['code' => $code,
            'message' => match ($code) {
                'invalid_arguments' => 'Invalid availability action arguments.', 'not_found' => 'Resource not found.',
                'access_denied' => 'Access denied.', default => 'Availability proposal could not be prepared.',
            }]]];
    }
}
