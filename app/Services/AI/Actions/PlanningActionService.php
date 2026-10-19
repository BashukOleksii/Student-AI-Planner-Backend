<?php

namespace App\Services\AI\Actions;

use App\Exceptions\AiAgentException;
use App\Exceptions\StudyPlanConflictException;
use App\Exceptions\StudySessionConflictException;
use App\Http\Resources\StudyPlanResource;
use App\Http\Resources\StudyReplanningResource;
use App\Http\Resources\StudySessionResource;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\AI\AgentLimits;
use App\Services\Planning\StudyPlanConfirmationService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudyReplanningConfirmationService;
use App\Services\Planning\StudyReplanningPersistenceService;
use App\Services\Planning\StudySessionMutationContextService;
use App\Services\Planning\StudySessionReschedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/** Fixed domain adapters. No planning algorithms or independent confirmation state machine. */
class PlanningActionService
{
    public function __construct(private PlanningActionArgumentValidator $validator, private StudyPlanPersistenceService $plans,
        private StudyReplanningPersistenceService $replanning, private StudySessionReschedulingService $sessions,
        private StudySessionMutationContextService $mutation, private StudyPlanConfirmationService $planTokens,
        private StudyReplanningConfirmationService $replanningTokens, private ActionTargetFingerprint $fingerprints,
        private AgentLimits $limits) {}

    public function prepare(User $owner, string $name, array $arguments, string $callId): array
    {
        try {
            $arguments = $this->validator->validate($name, $arguments);
            if (! $owner->exists || $owner->getKey() === null) {
                return $this->error($name, 'access_denied');
            }

            return DB::transaction(function () use ($owner, $name, $arguments, $callId): array {
                $protected = $hash = null;
                if ($name === 'propose_session_reschedule') {
                    $result = $this->sessions->preview($owner, (string) $arguments['study_session_id'], $arguments['starts_at'], $arguments['ends_at']);
                    $hash = $this->fingerprints->hash($result['records']);
                    $review = ['original' => $this->session($result['original']),
                        'replacement' => ['task_id' => $result['original']->task_id, 'subtask_id' => $result['original']->subtask_id,
                            'starts_at' => $arguments['starts_at'], 'ends_at' => $arguments['ends_at']],
                        'summary' => array_diff_key($result['summary'], ['unallocated_work_seconds' => true]),
                        'validation' => 'preliminary', 'feasibility_confirmed' => false];
                    $required = [];
                    $actionable = true;
                } else {
                    $result = ($name === 'propose_study_plan_create' ? $this->plans : $this->replanning)->preview($owner,
                        $arguments['start_date'], $arguments['end_date'], $arguments['task_id'] ?? null, $arguments['subtask_id'] ?? null);
                    $protected = ['preview_token' => $result['preview_token'], 'expires_at' => $result['expires_at']];
                    if ($name === 'propose_study_plan_create') {
                        $review = (new StudyPlanResource(['plan' => $result['plan']]))->resolve(new Request);
                        $actionable = $result['plan']['sessions'] !== [];
                        $required = $result['plan']['summary']['fully_allocated'] ? [] : ['accept_partial'];
                    } else {
                        $raw = array_diff_key($result, ['preview_token' => true, 'expires_at' => true]);
                        $review = (new StudyReplanningResource($raw))->resolve(new Request);
                        $actionable = $this->replanningTokens->affected($raw) !== [];
                        $required = [];
                        if ($this->replanningTokens->acceptanceError($raw, false, true) === 'accept_partial') {
                            $required[] = 'accept_partial';
                        }
                        if ($this->replanningTokens->acceptanceError($raw, true, false) === 'accept_unplaced_cancellation') {
                            $required[] = 'accept_unplaced_cancellation';
                        }
                    }
                }
                $preview = ['action' => $name, 'confirmation_required' => $actionable, 'review' => $review,
                    'required_consent' => $required, 'effects' => [match ($name) {
                        'propose_study_plan_create' => 'Create only the reviewed planned sessions. A partial allocation needs explicit accept_partial consent.',
                        'propose_adaptive_replanning' => 'Preserve valid and cannot-replan sessions. Replace affected sources one-to-one; shorter replacements need partial consent and unplaced cancellation needs separate cancellation consent. Cancel source scheduled reminders without cloning them.',
                        default => 'Preliminary lifecycle and duration review only. Confirmation revalidates remaining effort and all planning constraints; the interval is not guaranteed feasible. Retire the source, create one successor, cancel scheduled source reminders, preserve history and do not clone reminders.',
                    }]];
                // Never trim a material effect. An oversized review cannot become confirmable.
                $this->limits->json($preview, $this->limits->get('max_tool_result_bytes'));
                if (! $actionable) {
                    return ['proposal' => null, 'result' => ['ok' => true, 'tool' => $name,
                        'data' => ['state' => 'no_actionable_plan', 'preview' => $preview]]];
                }
                $proposal = ['id' => (string) Str::uuid(), 'type' => $name, 'call_id' => $callId, 'arguments' => $arguments,
                    'preview' => $preview, 'target_hash' => $hash, 'protected_payload' => $protected];
                $this->expiration($owner, $proposal);
                $public = ['ok' => true, 'tool' => $name, 'data' => ['state' => 'confirmation_required',
                    'action_id' => $proposal['id'], 'call_id' => $callId, 'preview' => $preview]];
                $this->limits->json($public, $this->limits->get('max_tool_result_bytes'));

                return ['proposal' => $proposal, 'result' => $public];
            });
        } catch (ModelNotFoundException) {
            return $this->error($name, 'not_found');
        } catch (InvalidArgumentException|ValidationException|StudySessionConflictException|StudyPlanConflictException) {
            return $this->error($name, 'invalid_arguments');
        } catch (AiAgentException $exception) {
            return $this->error($name, $exception->errorCode === 'execution_limit_reached' ? 'review_too_large' : 'invalid_preview');
        } catch (Throwable) {
            return $this->error($name, 'execution_failed');
        }
    }

    /** Pure protected-token checks; persistence additionally compares this deadline to MySQL UTC. */
    public function expiration(User $owner, array $proposal): ?string
    {
        if ($proposal['type'] === 'propose_session_reschedule') {
            return null;
        }
        $protected = $proposal['protected_payload'] ?? null;
        if (! is_array($protected) || array_keys($protected) !== ['preview_token', 'expires_at']
            || ! is_string($protected['preview_token']) || ! is_string($protected['expires_at'])) {
            throw new AiAgentException('action_token_invalid');
        }
        $this->limits->json($protected, 262144);
        try {
            $payload = ($proposal['type'] === 'propose_study_plan_create' ? $this->planTokens : $this->replanningTokens)
                ->decode($protected['preview_token'], $owner->id, CarbonImmutable::now('UTC')->getTimestamp());
            $arguments = $proposal['arguments'];
            $expected = ['start_date' => $arguments['start_date'], 'end_date' => $arguments['end_date'],
                'task_id' => $arguments['task_id'] ?? null, 'subtask_id' => $arguments['subtask_id'] ?? null];
            if (! $this->planTokens->matches($expected, $payload['parameters'])
                || gmdate('Y-m-d\TH:i:s\Z', $payload['expires_at']) !== $protected['expires_at']) {
                throw new InvalidArgumentException;
            }
        } catch (InvalidArgumentException) {
            throw new AiAgentException('action_token_invalid');
        }

        return gmdate('Y-m-d H:i:s', $payload['expires_at']);
    }

    /** Called exclusively under H3's conversation/action/owner lock prefix. Never commits. */
    public function execute(User $owner, AiPendingAction $action, array $consent): array
    {
        if (DB::transactionLevel() === 0) {
            throw new AiAgentException('persistence_error');
        }
        $arguments = $this->validator->validate($action->type, $action->arguments);
        try {
            if ($action->type === 'propose_session_reschedule') {
                $result = $this->mutation->runWithinTransaction($owner, (string) $arguments['study_session_id'],
                    function ($currentOwner, $source, $task, $child, $interval, $now, $chain) use ($action, $arguments): array {
                        $records = ['task' => $task, 'child' => $child, ...$chain->mapWithKeys(fn ($row) => ['session_'.$row->id => $row])->all()];
                        if (! hash_equals($action->target_hash ?? '', $this->fingerprints->hash($records))) {
                            throw new AiAgentException('action_stale');
                        }

                        return $this->sessions->rescheduleWithinTransaction($currentOwner, (string) $source->id, $arguments['starts_at'], $arguments['ends_at']);
                    });

                return ['applied' => true, 'original' => $this->session($result['original']),
                    'replacement' => $this->session($result['replacement']), 'summary' => $result['summary']];
            }
            $this->expiration($owner, ['type' => $action->type, 'arguments' => $arguments, 'protected_payload' => $action->protected_payload]);
            $token = $action->protected_payload['preview_token'];
            if ($action->type === 'propose_study_plan_create') {
                $result = $this->plans->confirmWithinTransaction($owner, $token, $consent['accept_partial'] ?? false);

                return ['applied' => true, 'sessions' => $result['sessions']->map(fn ($row) => $this->session($row))->all(),
                    'summary' => $result['plan']['summary']];
            }
            $result = $this->replanning->confirmWithinTransaction($owner, $token, $consent['accept_partial'] ?? false,
                $consent['accept_unplaced_cancellation'] ?? false);

            return ['applied' => true, 'original_sessions' => $result['original_sessions']->map(fn ($row) => ['id' => $row->id, 'status' => $row->status->value])->all(),
                'replacement_sessions' => $result['replacement_sessions']->map(fn ($row) => $this->session($row))->all(), 'summary' => $result['summary']];
        } catch (StudyPlanConflictException|ModelNotFoundException) {
            throw new AiAgentException('action_stale');
        } catch (StudySessionConflictException) {
            throw new AiAgentException('action_conflict');
        } catch (UnexpectedValueException) {
            // Invalid persisted attribution/history also invalidates the reviewed operation.
            throw new AiAgentException('action_stale');
        } catch (ValidationException $exception) {
            $safe = array_intersect_key($exception->errors(), array_flip(['accept_partial', 'accept_unplaced_cancellation']));
            if ($safe !== []) {
                throw ValidationException::withMessages($safe);
            }
            if (isset($exception->errors()['preview_token'])
                && AiPendingAction::whereKey($action->id)->where('expires_at', '<=', DB::raw('UTC_TIMESTAMP(6)'))->exists()) {
                throw new AiAgentException('action_expired');
            }
            throw new AiAgentException(isset($exception->errors()['preview_token']) ? 'action_token_invalid' : 'action_validation_failed');
        }
    }

    private function session($session): array
    {
        return (new StudySessionResource($session))->resolve(new Request);
    }

    private function error(string $name, string $code): array
    {
        return ['proposal' => null, 'result' => ['ok' => false, 'tool' => $name, 'error' => ['code' => $code, 'message' => match ($code) {
            'not_found' => 'Resource not found.', 'access_denied' => 'Access denied.', 'invalid_arguments' => 'Invalid planning arguments or source state.',
            'review_too_large' => 'The complete planning review exceeds the configured limits. Narrow the scope.',
            'invalid_preview' => 'The planning review is invalid or expired. Request a fresh proposal.', default => 'Planning proposal could not be prepared.',
        }]]];
    }
}
