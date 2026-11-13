<?php

namespace App\Services\Planning;

use App\Enums\StudySessionStatus;
use App\Exceptions\StudySessionConflictException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** New remaining-work allocation with historical diagnostics, never source retirement. */
class StudyRecoveryPreviewService
{
    public function __construct(
        private StudyPlanPersistenceService $plans,
        private PlanningContextService $context,
        private StudySessionLifecycleRules $lifecycle,
        private TimeIntervalService $intervals,
    ) {}

    public function preview(User $user, string $startDate, string $endDate, mixed $taskId = null, mixed $subtaskId = null, mixed $notBeforeUtc = null): array
    {
        // One nonlocking MySQL snapshot covers the authoritative proposal and its diagnostics.
        return DB::transaction(function () use ($user, $startDate, $endDate, $taskId, $subtaskId, $notBeforeUtc): array {
            $preview = $notBeforeUtc === null ? $this->plans->preview($user, $startDate, $endDate, $taskId, $subtaskId)
                : $this->plans->preview($user, $startDate, $endDate, $taskId, $subtaskId, $notBeforeUtc);
            $plan = $preview['plan'];
            $now = CarbonImmutable::parse($plan['as_of_utc']);
            $units = [];
            foreach ([...$plan['work_results'], ...$plan['excluded_items']] as $item) {
                $units[$item['task_id'].':'.($item['subtask_id'] ?? 'task')] = $item['reason_codes'];
            }
            $taskIds = array_values(array_unique(array_column([...$plan['work_results'], ...$plan['excluded_items']], 'task_id')));
            // History is for the selected work across all dates, not just the new allocation horizon.
            $sessions = $user->studySessions()->whereIn('task_id', $taskIds)
                ->when($subtaskId !== null, fn ($query) => $query->where('subtask_id', $subtaskId))
                ->where(fn ($query) => $query->where('status', StudySessionStatus::Missed)
                    ->orWhere(fn ($planned) => $planned->where('status', StudySessionStatus::Planned)
                        ->where('starts_at', '<', $now->format('Y-m-d H:i:s.u'))))
                ->orderBy('starts_at')->orderBy('id')
                ->get(['id', 'task_id', 'subtask_id', 'status', 'starts_at', 'ends_at']);
            $this->context->validateSessionReferences($user, $sessions);

            $missed = $past = [];
            foreach ($sessions as $session) {
                $interval = ['start' => $this->context->utcSeconds($session->getRawOriginal('starts_at')),
                    'end' => $this->context->utcSeconds($session->getRawOriginal('ends_at'))];
                $actions = [];
                if ($session->status === StudySessionStatus::Planned) {
                    foreach (['complete', 'miss'] as $action) {
                        try {
                            $this->lifecycle->assertAllowed($action, $session->status, $interval, $now);
                            $actions[] = $action;
                        } catch (StudySessionConflictException) {
                            // In-progress plans cannot be marked missed until their end.
                        }
                    }
                }
                $row = ['study_session_id' => $session->id, 'task_id' => $session->task_id, 'subtask_id' => $session->subtask_id,
                    'starts_at' => gmdate('Y-m-d\TH:i:s\Z', $interval['start']), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $interval['end']),
                    'duration_seconds' => $this->intervals->durationSeconds($interval),
                    'work_reason_codes' => $units[$session->task_id.':'.($session->subtask_id ?? 'task')] ?? ['deleted_subtask'],
                    'allowed_lifecycle_actions' => $actions];
                if ($session->status === StudySessionStatus::Missed) {
                    $missed[] = $row;
                } else {
                    $past[] = $row;
                }
            }
            $actionable = $plan['sessions'] !== [];
            if (! $actionable) {
                unset($preview['preview_token'], $preview['expires_at']);
            }

            return ['study_plan' => $preview, 'recovery' => [
                'scope' => ['task_id' => $taskId, 'subtask_id' => $subtaskId],
                'history_scope' => 'selected_work_all_dates',
                'summary' => ['eligible_work_count' => count($plan['work_results']), 'missed_session_count' => count($missed),
                    'past_planned_session_count' => count($past), 'requires_lifecycle_decision' => $past !== [], 'actionable' => $actionable],
                'missed_sessions' => $missed, 'past_planned_sessions' => $past,
            ]];
        });
    }
}
