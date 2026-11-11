<?php

namespace App\Services\AI\Tools;

use App\Models\Lesson;
use App\Models\User;
use App\Services\Planning\CalendarBusyIntervalService;
use App\Services\Planning\PlanningContextService;
use App\Services\Schedule\EffectiveScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Read-only presentation adapter over planning's authoritative calendar selection. */
class EffectiveScheduleReadService
{
    public const LIMIT = 100;

    public function __construct(
        private CalendarBusyIntervalService $calendar,
        private PlanningContextService $context,
        private EffectiveScheduleService $effective,
        private ToolResultPresenter $presenter,
    ) {}

    public function date(User $user, string $date): array
    {
        return DB::transaction(fn () => $this->readDate($user, $date));
    }

    private function readDate(User $user, string $date): array
    {
        $owner = $user->newQuery()->findOrFail($user->getKey(), ['id', 'timezone']);
        $coverage = $this->context->dateIntervals($date, $date, $owner->timezone);
        $occupancy = [];
        foreach ($coverage as $part) {
            foreach ($this->calendar->between($owner, $part['start'], $part['end'], false) as $record) {
                if (count(array_filter($coverage, fn ($day) => $record['start'] < $day['end'] && $record['end'] > $day['start'])) > 0) {
                    $occupancy[$record['id']] = $record;
                }
            }
        }

        $semesters = [];
        if ($coverage !== []) {
            foreach ($this->effective->schedulesInRange($owner, $date, $date) as $schedule) {
                $semesters[$schedule->id] = $this->effective->forRange($owner, $schedule, $date, $date)['lessons'];
            }
        }
        $legacyIds = array_values(array_unique(array_filter(array_map(fn ($row) => $row['lesson_id'] ?? null, $occupancy), 'is_int')));
        $legacy = $legacyIds === [] ? collect() : $owner->lessons()->whereKey($legacyIds)
            ->with(['subject', 'teacher', 'replacedLesson'])->get()->keyBy('id');
        $lessons = [];
        foreach ($occupancy as $record) {
            if ($record['source_type'] === 'legacy_lesson') {
                $lesson = $legacy->get($record['lesson_id']);
                if ($lesson !== null) {
                    $lessons[] = $this->legacy($owner, $lesson, $record);
                }

                continue;
            }
            foreach ($semesters[$record['semester_schedule_id']] ?? [] as $row) {
                if ($row['occurrence_id'] !== $record['id']) {
                    continue;
                }
                $lessons[] = [
                    'id' => $row['occurrence_id'], 'source_identity' => $row['occurrence_id'], 'source_type' => 'semester_occurrence',
                    'subject' => $row['subject'], 'teacher' => $row['teacher'], 'type' => $row['lesson_type'] ?? null,
                    'pair_number' => $row['pair_number'], 'pair_label' => $row['pair_label'], 'room' => $row['room'],
                    'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'],
                    'semester_schedule_id' => $record['semester_schedule_id'],
                    'weekly_lesson_rule_id' => $record['weekly_lesson_rule_id'],
                    'schedule_date_exception_id' => $record['schedule_date_exception_id'],
                ];
            }
        }
        usort($lessons, fn ($a, $b) => [strtotime($a['starts_at']), strtotime($a['ends_at']), (string) $a['id']]
            <=> [strtotime($b['starts_at']), strtotime($b['ends_at']), (string) $b['id']]);
        $total = count($lessons);

        return ['lessons' => array_slice($lessons, 0, self::LIMIT), 'meta' => [
            'timezone' => $owner->timezone, 'start_date' => $date, 'end_date' => $date,
            'limit' => self::LIMIT, 'returned' => min($total, self::LIMIT), 'total' => $total, 'truncated' => $total > self::LIMIT,
        ]];
    }

    private function legacy(User $owner, Lesson $lesson, array $occupancy): array
    {
        $row = $this->presenter->lesson($owner, $lesson);
        $subject = $row['subject'];
        $teacher = $row['teacher'];
        $toLocal = fn (int $timestamp) => CarbonImmutable::createFromTimestampUTC($timestamp)
            ->setTimezone($owner->timezone)->format('Y-m-d\TH:i:sP');

        return ['id' => $lesson->id, 'source_identity' => $occupancy['id'], 'source_type' => 'legacy_lesson',
            'subject' => $subject === null ? null : ['id' => $subject['id'], 'name' => $subject['name']],
            'teacher' => $teacher, 'type' => $lesson->type?->value, 'pair_number' => null, 'pair_label' => null,
            'room' => $lesson->room, 'starts_at' => $toLocal($occupancy['start']), 'ends_at' => $toLocal($occupancy['end']),
            'replaces_lesson_id' => $row['replaces_lesson_id']];
    }
}
