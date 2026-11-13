<?php

namespace App\Services\Planning;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read-only diagnostics reuse the allocator's acceptance rules; no task feasibility is inferred. */
class PlanningDiagnosticsService
{
    public function __construct(private PlanningContextService $context, private StudySessionAllocationService $allocator) {}

    public function analyze(User $owner, string $start, string $end, ?int $minimumSeconds = null): array
    {
        return DB::transaction(function () use ($owner, $start, $end, $minimumSeconds): array {
            $context = $this->context->load($owner, $start, $end, null, true);
            $now = CarbonImmutable::now('UTC');
            $slots = [];
            foreach ($context['free_intervals'] as $slot) {
                if ($minimumSeconds !== null && $minimumSeconds > $slot['end'] - $slot['start']) {
                    continue;
                }
                $probe = $context;
                $probe['range'] = $slot;
                $probe['free_intervals'] = [$slot];
                // A private synthetic work unit exercises the pure allocator; no model/ID is exposed or written.
                $allocation = $this->allocator->allocate([['task_id' => 1, 'subtask_id' => null,
                    'effective_deadline_utc' => null, 'effort' => ['unallocated_work_seconds' => $slot['end'] - $slot['start']]]],
                    $probe, $now->getTimestamp() + 1, true);
                $candidate = $allocation['sessions'][0] ?? null;
                $slots[] = [...$slot, 'can_schedule' => $candidate !== null,
                    'candidate' => $candidate === null ? null : ['start' => $candidate['start'], 'end' => $candidate['end']],
                    'reason_codes' => $candidate !== null ? [] : $allocation['work_results'][0]['reason_codes']];
            }
            $preferences = $context['preferences'];
            $warnings = [];
            foreach (['max_daily_study_minutes', 'max_weekly_study_minutes'] as $limit) {
                if ($preferences['min_session_minutes'] !== null && $preferences[$limit] !== null
                    && $preferences['min_session_minutes'] > $preferences[$limit]) {
                    $warnings[] = ['category' => 'configuration', 'code' => 'minimum_above_'.$limit];
                }
            }
            if ($preferences['preferred_break_minutes'] !== null && $preferences['max_session_minutes'] !== null
                && $preferences['preferred_break_minutes'] > $preferences['max_session_minutes']) {
                $warnings[] = ['category' => 'configuration', 'code' => 'long_break'];
            }
            $configured = $owner->studyAvailabilityWindows()->exists();
            if (! $configured || ! collect($slots)->contains('can_schedule', true)) {
                $warnings[] = ['category' => 'calendar', 'code' => $configured ? 'no_suitable_time_in_period' : 'availability_not_configured'];
            }

            return ['as_of_utc' => $now->toISOString(), 'start_date' => $start, 'end_date' => $end,
                'timezone' => $context['timezone'], 'task_deadline_checked' => false,
                'preferences' => $preferences, 'warnings' => $warnings, 'slots' => $slots];
        });
    }
}
