<?php

namespace App\Services\Schedule;

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

        $days = (int) $anchor->diff($day)->format('%r%a');
        $weeks = (int) floor($days / 7);
        $parity = $weeks % 2 === 0 ? $schedule['first_week_parity']
            : ($schedule['first_week_parity'] === 'numerator' ? 'denominator' : 'numerator');
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
                'weekly_lesson_rule_id' => $snapshot === null ? $row['id'] : null,
                'schedule_date_exception_id' => $snapshot['id'] ?? null,
                'pair_number' => $row['pair_number'],
                'pair_label' => $row['pair_label'] ?? null,
                'subject_id' => $row['subject_id'],
                'teacher_id' => $row['teacher_id'] ?? null,
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
        if (! in_array($snapshot['kind'], ['replacement', 'working_saturday'], true)
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
