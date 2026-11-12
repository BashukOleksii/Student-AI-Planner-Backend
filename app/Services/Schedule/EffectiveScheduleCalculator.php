<?php

namespace App\Services\Schedule;

use App\Enums\LessonType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Pure calendar/rule calculations, shared by reads and future mutation preflight. */
class EffectiveScheduleCalculator
{
    public function validateDate(string $date): void
    {
        $this->calendarDate($date);
    }

    /** Inclusive Monday-Sunday labels, independent of locale and timezone offsets. */
    public function weekDates(string $date): array
    {
        $day = $this->calendarDate($date);
        $start = $day->modify('-'.((int) $day->format('N') - 1).' days')->format('Y-m-d');
        $end = $day->modify('+'.(7 - (int) $day->format('N')).' days')->format('Y-m-d');
        $this->calendarDate($start);
        $this->calendarDate($end);

        return [$start, $end];
    }

    /** Calendar parity is reusable for legacy effective-day Saturday copies. */
    public function weekParity(array $schedule, string $date): string
    {
        $anchor = $this->calendarDate($schedule['parity_anchor_on']);
        if ($anchor->format('N') !== '1' || ! in_array($schedule['first_week_parity'], ['numerator', 'denominator'], true)) {
            throw new InvalidArgumentException('Invalid semester parity anchor.');
        }
        $days = (int) $anchor->diff($this->calendarDate($date))->format('%r%a');
        $weeks = (int) floor($days / 7);

        return $weeks % 2 === 0 ? $schedule['first_week_parity']
            : ($schedule['first_week_parity'] === 'numerator' ? 'denominator' : 'numerator');
    }

    /**
     * Validate an entire semester without expanding/storing every ordinary date.
     * Ordinary offsets need two weekly cycles; every offset transition and saved
     * exception is checked separately through the same occurrence calculator.
     */
    public function validateSemester(array $schedule, array $rules, array $snapshots, string $timezone): void
    {
        $start = $this->calendarDate($schedule['starts_on']);
        $end = $this->calendarDate($schedule['ends_on']);
        $this->calculate($schedule, $rules, $snapshots[$schedule['starts_on']] ?? null, $schedule['starts_on'], $timezone);
        $dates = [];
        for ($day = $start, $i = 0; $i < 14 && $day <= $end; $i++, $day = $day->modify('+1 day')) {
            $dates[$day->format('Y-m-d')] = true;
        }
        $dates[$end->format('Y-m-d')] = true;
        $zone = new DateTimeZone($timezone);
        $transitions = $zone->getTransitions($start->getTimestamp() - 172800, $end->getTimestamp() + 172800) ?: [];
        $previous = $transitions[0]['offset'] ?? 0;
        foreach (array_slice($transitions, 1) as $transition) {
            $before = (new DateTimeImmutable('@'.($transition['ts'] + $previous)))->setTimezone(new DateTimeZone('UTC'));
            $after = (new DateTimeImmutable('@'.($transition['ts'] + $transition['offset'])))->setTimezone(new DateTimeZone('UTC'));
            $low = min($before->format('Y-m-d'), $after->format('Y-m-d'));
            $high = max($before->format('Y-m-d'), $after->format('Y-m-d'));
            for ($day = $this->calendarDate($low); $day->format('Y-m-d') <= $high; $day = $day->modify('+1 day')) {
                if ($day >= $start && $day <= $end) {
                    $dates[$day->format('Y-m-d')] = true;
                }
            }
            $previous = $transition['offset'];
        }
        foreach ($snapshots as $date => $snapshot) {
            if ($date < $schedule['starts_on'] || $date > $schedule['ends_on']) {
                throw new InvalidArgumentException('A saved exception is outside its academic period.');
            }
            $dates[$date] = true;
        }
        foreach (array_keys($dates) as $date) {
            $this->calculate($schedule, $rules, $snapshots[$date] ?? null, $date, $timezone);
        }
    }

    /**
     * Dates are nominal calendar labels, never UTC durations. A non-null snapshot
     * replaces the entire date, including when its lessons list is empty.
     *
     * @return list<array<string, mixed>> Virtual occurrences; never Lesson models.
     */
    public function calculate(array $schedule, array $rules, ?array $snapshot, string $date, string $timezone): array
    {
        $day = $this->calendarDate($date);
        $start = $this->calendarDate($schedule['starts_on']);
        $end = $this->calendarDate($schedule['ends_on']);
        $anchor = $this->calendarDate($schedule['parity_anchor_on']);
        if ($start > $end || $anchor->format('N') !== '1'
            || ! in_array($schedule['first_week_parity'], ['numerator', 'denominator'], true)) {
            throw new InvalidArgumentException('Invalid semester range or parity anchor.');
        }
        if (! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('Expected an IANA timezone.');
        }
        $zone = new DateTimeZone($timezone);
        $this->validateWeeklyRules($rules);
        if ($day < $start || $day > $end) {
            return [];
        }

        $parity = $this->weekParity($schedule, $date);
        $source = 'base';
        if ($snapshot !== null) {
            $this->validateSnapshot($snapshot);
            if ($snapshot['occurs_on'] !== $date) {
                throw new InvalidArgumentException('The snapshot belongs to another date.');
            }
            $selected = $snapshot['lessons'];
            $source = $snapshot['kind'];
        } else {
            $selected = array_values(array_filter($rules, fn (array $rule): bool => $rule['weekday'] === (int) $day->format('N')
                && in_array($rule['week_pattern'], ['all', $parity], true)));
        }

        $result = [];
        foreach ($selected as $row) {
            $from = $this->localInstant($date, $row['starts_at_local'], $zone);
            $to = $this->localInstant($date, $row['ends_at_local'], $zone);
            if ($from >= $to) {
                throw new InvalidArgumentException('The resolved lesson interval must increase.');
            }
            $result[] = [
                'occurrence_id' => 'semester:'.$schedule['id'].':'.$date.':'.($snapshot === null ? 'rule:' : 'snapshot:').$row['id'],
                'occurs_on' => $date,
                'source' => $source,
                'change_kind' => $this->changeKind($row, $rules, $snapshot, (int) $day->format('N'), $parity),
                'weekly_lesson_rule_id' => $snapshot === null ? $row['id'] : null,
                'schedule_date_exception_id' => $snapshot['id'] ?? null,
                'pair_number' => $row['pair_number'],
                'pair_label' => $row['pair_label'] ?? null,
                'lesson_type' => $row['lesson_type'] ?? null,
                'subject_id' => $row['subject_id'],
                'teacher_id' => $row['teacher_id'] ?? null,
                'teacher_ids' => $row['teacher_ids'] ?? (isset($row['teacher_id']) ? [$row['teacher_id']] : []),
                'room' => $row['room'] ?? null,
                'starts_at' => $from->format('Y-m-d\TH:i:sP'),
                'ends_at' => $to->format('Y-m-d\TH:i:sP'),
                'start' => $from->getTimestamp(),
                'end' => $to->getTimestamp(),
            ];
        }
        usort($result, fn (array $a, array $b): int => [$a['start'], $a['end'], $a['occurrence_id']] <=> [$b['start'], $b['end'], $b['occurrence_id']]);
        for ($i = 1; $i < count($result); $i++) {
            if ($result[$i]['start'] < $result[$i - 1]['end']) {
                throw new InvalidArgumentException('Effective lesson intervals overlap.');
            }
        }

        return $result;
    }

    /** Provenance, rather than a matching pair number, identifies the original rule. */
    private function changeKind(array $lesson, array $rules, ?array $snapshot, int $weekday, string $parity): ?string
    {
        if ($snapshot === null) {
            return 'base';
        }
        $sourceId = $lesson['source_weekly_rule_id'] ?? null;
        if ($sourceId === null) {
            // Null also represents detached/deleted rules in historical snapshots.
            return ($lesson['is_added'] ?? null) === true ? 'added' : null;
        }
        $originals = array_values(array_filter($rules, fn ($rule) => (int) $rule['id'] === (int) $sourceId));
        if (count($originals) !== 1) {
            return null;
        }
        $rule = $originals[0];
        $sourceWeekday = $snapshot['kind'] === 'working_saturday' ? $snapshot['copied_weekday'] : $weekday;
        $sourceParity = $snapshot['source_week_parity'] ?? $parity;
        if ($rule['weekday'] !== $sourceWeekday || ! in_array($rule['week_pattern'], ['all', $sourceParity], true)) {
            return 'modified';
        }

        return $this->comparableLesson($lesson) === $this->comparableLesson($rule) ? 'base' : 'modified';
    }

    private function comparableLesson(array $lesson): array
    {
        $teachers = $lesson['teacher_ids'] ?? [];
        if (isset($lesson['teacher_id'])) {
            $teachers[] = $lesson['teacher_id'];
        }
        $teachers = array_values(array_unique(array_map('intval', $teachers)));
        sort($teachers, SORT_NUMERIC);
        $text = static fn ($value) => ($value === null || trim($value) === '') ? null : trim($value);

        return [
            (int) $lesson['pair_number'], $text($lesson['pair_label'] ?? null),
            (int) $lesson['subject_id'], $teachers,
            $text($lesson['lesson_type'] ?? null), $text($lesson['room'] ?? null),
            // Inputs have already passed the canonical HH:MM:SS validation.
            $lesson['starts_at_local'], $lesson['ends_at_local'],
        ];
    }

    /** Validate both weeks, including days other than the requested date. */
    public function validateWeeklyRules(array $rules): void
    {
        foreach ($rules as $rule) {
            $this->validateLesson($rule);
            if (! is_int($rule['weekday'] ?? null) || $rule['weekday'] < 1 || $rule['weekday'] > 5
                || ! in_array($rule['week_pattern'] ?? null, ['all', 'numerator', 'denominator'], true)) {
                throw new InvalidArgumentException('Expected a Monday-Friday rule and a valid week pattern.');
            }
        }
        foreach (['numerator', 'denominator'] as $pattern) {
            for ($weekday = 1; $weekday <= 5; $weekday++) {
                $this->validateDay(array_values(array_filter($rules, fn (array $row): bool => $row['weekday'] === $weekday && in_array($row['week_pattern'], ['all', $pattern], true))));
            }
        }
    }

    public function validateSnapshot(array $snapshot): void
    {
        $date = $this->calendarDate($snapshot['occurs_on']);
        $copied = $snapshot['copied_weekday'] ?? null;
        $parity = $snapshot['source_week_parity'] ?? null;
        if (($parity !== null && ($snapshot['kind'] !== 'working_saturday' || ! in_array($parity, ['numerator', 'denominator'], true)))
            || ! in_array($snapshot['kind'], ['replacement', 'working_saturday'], true)
            || ($snapshot['kind'] === 'replacement' && $copied !== null)
            || ($snapshot['kind'] === 'working_saturday'
                && ($date->format('N') !== '6' || ! is_int($copied) || $copied < 1 || $copied > 5))) {
            throw new InvalidArgumentException('Invalid date exception or working Saturday.');
        }
        $this->validateDay($snapshot['lessons']);
    }

    private function validateDay(array $lessons): void
    {
        $pairs = [];
        foreach ($lessons as $lesson) {
            $this->validateLesson($lesson);
            if (isset($pairs[$lesson['pair_number']])) {
                throw new InvalidArgumentException('Duplicate pair number in the same effective day.');
            }
            $pairs[$lesson['pair_number']] = true;
        }
        usort($lessons, fn (array $a, array $b): int => strcmp($a['starts_at_local'], $b['starts_at_local']));
        for ($i = 1; $i < count($lessons); $i++) {
            if ($lessons[$i]['starts_at_local'] < $lessons[$i - 1]['ends_at_local']) {
                throw new InvalidArgumentException('Lesson intervals overlap in the same effective day.');
            }
        }
    }

    private function validateLesson(array $lesson): void
    {
        if (($lesson['lesson_type'] ?? null) !== null
            && (! is_string($lesson['lesson_type']) || LessonType::tryFrom($lesson['lesson_type']) === null)) {
            throw new InvalidArgumentException('Expected a supported nullable semester lesson type.');
        }
        foreach (['starts_at_local', 'ends_at_local'] as $field) {
            if (! is_string($lesson[$field] ?? null)
                || ! preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/', $lesson[$field])) {
                throw new InvalidArgumentException('Expected local HH:MM:SS within one calendar date.');
            }
        }
        if (! is_int($lesson['pair_number'] ?? null) || $lesson['pair_number'] < 1 || $lesson['pair_number'] > 255
            || $lesson['starts_at_local'] >= $lesson['ends_at_local']) {
            throw new InvalidArgumentException('Expected a positive pair number and increasing local interval.');
        }
    }

    private function calendarDate(string $date): DateTimeImmutable
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)
            || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new InvalidArgumentException('Expected a valid YYYY-MM-DD calendar date.');
        }

        return DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    }

    /** Reject gaps and folds instead of letting PHP silently normalize or select an offset. */
    private function localInstant(string $date, string $time, DateTimeZone $zone): DateTimeImmutable
    {
        $label = $date.' '.$time;
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $label, new DateTimeZone('UTC'))->getTimestamp();
        $transitions = $zone->getTransitions($wall - 172800, $wall + 172800);
        $offsets = $transitions === false
            ? [$zone->getOffset(new DateTimeImmutable('@'.$wall))]
            : array_unique(array_column($transitions, 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = (new DateTimeImmutable('@'.($wall - $offset)))->setTimezone($zone);
            if ($candidate->format('Y-m-d H:i:s') === $label) {
                $matches[] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            throw new InvalidArgumentException('Lesson endpoint is nonexistent or ambiguous in the user timezone.');
        }

        return $matches[0];
    }
}
