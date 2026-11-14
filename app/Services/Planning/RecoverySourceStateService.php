<?php

namespace App\Services\Planning;

use App\Exceptions\RecoverySuggestionException;
use App\Models\ScheduleDateException;
use App\Models\ScheduleDateExceptionLesson;
use App\Models\Subtask;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Bounded source snapshot and review fingerprint, not another scheduling algorithm. */
class RecoverySourceStateService
{
    public function __construct(private PlanningContextService $context) {}

    public function capture(User $user, string $start, string $end, ?CarbonImmutable $asOfUtc = null): array
    {
        $now = ($asOfUtc ?? CarbonImmutable::now('UTC'))->utc();
        $this->context->validateDates($start, $end);
        $owner = User::findOrFail($user->id);
        // Never truncate planning inputs: fail explicitly before invoking services that need complete history.
        $tasks = $this->bounded($owner->tasks()->withTrashed()->getQuery(), 200);
        $children = $this->bounded(Subtask::withTrashed()->whereIn('task_id', $tasks->modelKeys()), 500);
        $sessions = $this->bounded($owner->studySessions()->getQuery(), 1000);
        $this->context->validateSessionReferences($owner, $sessions);
        foreach ($sessions as $session) {
            $from = $this->context->utcSeconds($session->getRawOriginal('starts_at'));
            $to = $this->context->utcSeconds($session->getRawOriginal('ends_at'));
            if ($to <= $from || $to - $from > 31 * 86400) {
                throw new RecoverySuggestionException('analysis_limit_exceeded');
            }
        }
        $this->bounded($owner->lessons()->withTrashed()->getQuery(), 2000);
        $this->bounded($owner->subjects()->getQuery(), 500);
        $this->bounded($owner->teachers()->getQuery(), 500);
        $schedules = $this->bounded($owner->semesterSchedules()->getQuery(), 20);
        $periods = $this->bounded($owner->academicPeriods()->getQuery(), 100);
        foreach ($periods->whereIn('id', $schedules->pluck('academic_period_id')) as $period) {
            if (abs($period->starts_on->diffInDays($period->ends_on)) > 400) {
                throw new RecoverySuggestionException('analysis_limit_exceeded');
            }
        }
        $this->bounded(WeeklyLessonRule::where('user_id', $owner->id), 500);
        $this->bounded(ScheduleDateException::where('user_id', $owner->id), 500);
        $this->bounded(ScheduleDateExceptionLesson::where('user_id', $owner->id), 2000);
        $reminders = $this->bounded($owner->reminders()->where('status', 'scheduled')->getQuery(), 500);
        $this->bounded($owner->studyAvailabilityWindows()->getQuery(), 100);
        $context = $this->context->load($owner, $start, $end, null, true);
        foreach (['blocking_lessons', 'blocking_sessions', 'lesson_anchors', 'session_anchors', 'workload_records'] as $key) {
            usort($context[$key], fn ($a, $b) => (string) $a['id'] <=> (string) $b['id']);
        }
        foreach (['break_anchors', 'workload_sessions'] as $key) {
            usort($context[$key], fn ($a, $b) => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);
        }
        // Source state changes on a session start/end boundary, not on every ticking second.
        $states = $sessions->filter(fn ($session) => $session->status->value === 'planned')->values()->map(fn ($session) => ['id' => $session->id,
            'started' => $session->getRawOriginal('starts_at') <= $now->format('Y-m-d H:i:s'),
            'ended' => $session->getRawOriginal('ends_at') <= $now->format('Y-m-d H:i:s')])->all();
        // Existing adaptive analysis can expand to full original intervals. Keep automatic analysis inside its period.
        if ($context['range'] !== null) {
            foreach ($sessions as $session) {
                if ($session->status->value !== 'planned') {
                    continue;
                }
                $from = $this->context->utcSeconds($session->getRawOriginal('starts_at'));
                $to = $this->context->utcSeconds($session->getRawOriginal('ends_at'));
                if ($from < $context['range']['end'] && $to > $context['range']['start'] && $from > $now->getTimestamp()
                    && ($from < $context['range']['start'] || $to > $context['range']['end'])) {
                    throw new RecoverySuggestionException('analysis_limit_exceeded');
                }
            }
        }
        $deadlines = fn ($rows) => $rows->filter(fn ($row) => ! $row->trashed() && $row->status->isUnfinished())->values()->map(fn ($row) => ['id' => $row->id,
            'deadline_elapsed' => $row->getRawOriginal('deadline_at') === null ? null
                : $row->getRawOriginal('deadline_at') <= $now->format('Y-m-d H:i:s')])->all();
        $attributes = fn ($rows) => $rows->map->getRawOriginal()->all();
        // Invalidate pending reviews created under the former session-start outcome rule.
        $hash = $this->hash(['recovery_policy_version' => 3, 'review_lead_seconds' => LearningRecoveryAnalysisService::REVIEW_LEAD_SECONDS, 'owner_id' => $owner->id, 'timezone' => $owner->timezone, 'start_date' => $start, 'end_date' => $end,
            'tasks' => $attributes($tasks), 'subtasks' => $attributes($children), 'sessions' => $attributes($sessions),
            'scheduled_reminders' => $attributes($reminders), 'time_states' => $states,
            'task_deadline_states' => $deadlines($tasks), 'subtask_deadline_states' => $deadlines($children), 'context' => $context]);

        return ['owner' => $owner, 'source_hash' => $hash, 'sessions' => $sessions, 'tasks' => $tasks];
    }

    private function bounded(Builder $query, int $limit)
    {
        $rows = $query->orderBy('id')->limit($limit + 1)->get();
        if ($rows->count() > $limit) {
            throw new RecoverySuggestionException('analysis_limit_exceeded');
        }

        return $rows;
    }

    public function hash(mixed $value): string
    {
        $canonical = function (mixed $item) use (&$canonical): mixed {
            if (! is_array($item)) {
                return $item;
            }
            $item = array_map($canonical, $item);
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            return $item;
        };

        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR));
    }
}
