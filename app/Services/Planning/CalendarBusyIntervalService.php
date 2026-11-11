<?php

namespace App\Services\Planning;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Schedule\EffectiveScheduleService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Read-only class occupancy. No occurrence materialization or legacy synchronization. */
class CalendarBusyIntervalService
{
    public function __construct(
        private EffectiveScheduleService $effective,
        private AvailabilityOccurrenceService $availability,
        private TimeIntervalService $intervals,
    ) {}

    /**
     * UTC half-open selection. Clipping is optional for internal snapshots: full source
     * endpoints must survive different query envelopes without identity collisions.
     * Class identities are namespaced strings; they can never exclude study sessions.
     */
    public function between(User $user, int $start, int $end, bool $clip = true): array
    {
        $this->intervals->durationSeconds(['start' => $start, 'end' => $end]);

        return DB::transaction(function () use ($user, $start, $end, $clip): array {
            $owner = $user->newQuery()->findOrFail($user->getKey(), ['id', 'timezone']);
            [$first, $last] = $this->localDates($start, $end, $owner->timezone);
            $lessons = $owner->lessons()->where('status', LessonStatus::Active)
                ->where('starts_at', '<', gmdate('Y-m-d H:i:s', $end))
                ->where('ends_at', '>', gmdate('Y-m-d H:i:s', $start))->orderBy('id')->get();
            // Replacement rows deliberately have no import fields. Follow owned historical
            // originals (including soft-deleted history), rather than treating them as manual.
            $originals = $owner->lessons()->withTrashed()->whereKey($lessons->pluck('replaces_lesson_id')->filter()->unique()->all())
                ->get()->keyBy('id');
            $provenance = $lessons->concat($originals);
            $batches = $owner->scheduleImportBatches()->whereKey($provenance->pluck('schedule_import_batch_id')->filter()->unique()->all())
                ->get()->keyBy('id');
            $periods = $owner->academicPeriods()->whereKey($provenance->pluck('academic_period_id')->filter()->unique()->all())
                ->get()->keyBy('id');
            $schedules = $this->effective->schedulesInRange($owner, $first, $last, $provenance->pluck('academic_period_id')->filter()->unique()->all());
            $byPeriod = $schedules->keyBy('academic_period_id');
            $records = [];
            foreach ($schedules as $schedule) {
                $period = $schedule->academicPeriod;
                $from = max($first, $period->starts_on->format('Y-m-d'));
                $to = min($last, $period->ends_on->format('Y-m-d'));
                if ($from > $to) {
                    continue;
                }
                foreach ($this->effective->forRange($owner, $schedule, $from, $to)['lessons'] as $occurrence) {
                    $records[] = ['id' => $occurrence['occurrence_id'], 'source_type' => 'semester_occurrence',
                        'academic_period_id' => $period->id, 'semester_schedule_id' => $schedule->id,
                        'weekly_lesson_rule_id' => $occurrence['weekly_lesson_rule_id'],
                        'schedule_date_exception_id' => $occurrence['schedule_date_exception_id'],
                        'start' => $occurrence['start'], 'end' => $occurrence['end']];
                }
            }

            $coverage = [];
            foreach ($lessons as $lesson) {
                $origin = $lesson;
                if ($lesson->replaces_lesson_id !== null) {
                    $origin = $originals->get($lesson->replaces_lesson_id);
                    if ($origin === null || $origin->id === $lesson->id || $origin->replaces_lesson_id !== null
                        || $origin->status !== LessonStatus::Replaced) {
                        throw new InvalidArgumentException('Invalid legacy calendar replacement provenance.');
                    }
                }
                foreach ([$lesson, $origin] as $row) {
                    if ($row->academic_period_id !== null && ! $periods->has($row->academic_period_id)) {
                        throw new InvalidArgumentException('Invalid legacy calendar period ownership.');
                    }
                    if ($row->schedule_import_batch_id !== null) {
                        $batch = $batches->get($row->schedule_import_batch_id);
                        if ($batch === null || $batch->academic_period_id !== $row->academic_period_id) {
                            throw new InvalidArgumentException('Invalid legacy calendar import provenance.');
                        }
                    }
                }
                $interval = ['start' => $this->utcSeconds($lesson, 'starts_at'), 'end' => $this->utcSeconds($lesson, 'ends_at')];
                $this->intervals->durationSeconds($interval);
                $imported = $origin->schedule_import_batch_id !== null || $origin->import_fingerprint !== null;
                if ($imported && $origin->academic_period_id === null) {
                    throw new InvalidArgumentException('Imported calendar history requires an owned academic period.');
                }
                $parts = [$interval];
                if ($imported && $byPeriod->has($origin->academic_period_id)) {
                    $periodId = $origin->academic_period_id;
                    if (! isset($coverage[$periodId])) {
                        $period = $byPeriod[$periodId]->academicPeriod;
                        // Membership expansion preserves DST and historical date-line boundaries.
                        $coverage[$periodId] = $this->availability->expand($this->fullDayWindows(),
                            $period->starts_on->format('Y-m-d'), $period->ends_on->format('Y-m-d'), $owner->timezone);
                    }
                    $parts = $this->intervals->subtract([$interval], $coverage[$periodId]);
                }
                foreach ($parts as $part) {
                    $id = 'legacy_lesson:'.$lesson->id;
                    if ($part !== $interval) {
                        $id .= ':segment:'.$part['start'].':'.$part['end'];
                    }
                    $records[] = ['id' => $id, 'source_type' => 'legacy_lesson', 'lesson_id' => $lesson->id,
                        'academic_period_id' => $lesson->academic_period_id, 'replaces_lesson_id' => $lesson->replaces_lesson_id,
                        ...$part];
                }
            }

            $result = [];
            foreach ($records as $record) {
                if ($record['start'] >= $end || $record['end'] <= $start) {
                    continue;
                }
                if ($clip) {
                    $record['start'] = max($start, $record['start']);
                    $record['end'] = min($end, $record['end']);
                }
                $result[] = $record;
            }
            usort($result, fn ($a, $b) => [$a['start'], $a['end'], $a['id']] <=> [$b['start'], $b['end'], $b['id']]);

            return $result;
        });
    }

    /** Query labels include both sides of offset transitions, without assuming 24-hour local days. */
    private function localDates(int $start, int $end, string $timezone): array
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)
            && ! (str_starts_with($timezone, 'Etc/') && in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true))) {
            throw new InvalidArgumentException('Expected a persisted IANA timezone.');
        }
        $zone = new DateTimeZone($timezone);
        $points = [$start, $end - 1];
        foreach ($zone->getTransitions($start, $end) ?: [] as $transition) {
            $points[] = $transition['ts'];
            if ($transition['ts'] > $start) {
                $points[] = $transition['ts'] - 1;
            }
        }
        $dates = array_map(fn ($point) => (new DateTimeImmutable('@'.$point))->setTimezone($zone)->format('Y-m-d'), $points);
        $first = min($dates);
        $last = max($dates);
        if ((new DateTimeImmutable($first.' UTC'))->diff(new DateTimeImmutable($last.' UTC'))->days >= 128) {
            throw new InvalidArgumentException('Calendar occupancy requires at most 128 inclusive local dates.');
        }

        return [$first, $last];
    }

    private function fullDayWindows(): array
    {
        return array_map(fn ($day) => ['day_of_week' => $day, 'starts_at' => '00:00:00', 'ends_at' => '24:00:00'], range(1, 7));
    }

    private function utcSeconds(Lesson $lesson, string $field): int
    {
        $value = $lesson->getRawOriginal($field);
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC')) : false;
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Invalid legacy calendar UTC timestamp.');
        }

        return $date->getTimestamp();
    }
}
