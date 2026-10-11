<?php

namespace App\Services\Imports;

use App\Enums\LessonType;
use App\Exceptions\ScheduleOccurrenceMaterializationException;
use App\Models\AcademicPeriod;
use Carbon\CarbonImmutable;
use DateTimeZone;

class ScheduleOccurrenceMaterializer
{
    /**
     * Expand the ScheduleWorkbookParser contract without querying or persisting.
     * The caller supplies an owned period and the user's persisted IANA timezone.
     * Source metadata and rule parity are preserved; type is a technical fallback.
     *
     * @param  array{group: string, source: array, rules: list<array>}  $template
     * @return array{
     *   group: string, source: array,
     *   occurrences: list<array{
     *     local_date: string, weekday: int, pair_label: string, parity: string,
     *     subject_name: string, teacher_name: string, room: ?string, type: LessonType,
     *     starts_at_local: string, ends_at_local: string,
     *     starts_at_utc: string, ends_at_utc: string, source: array
     *   }>
     * }
     */
    public function materialize(array $template, AcademicPeriod $academicPeriod, string $timezone): array
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
            throw new ScheduleOccurrenceMaterializationException('Expected an IANA timezone name.');
        }
        $zone = new DateTimeZone($timezone);
        $start = $this->periodDate($academicPeriod, 'starts_on');
        $end = $this->periodDate($academicPeriod, 'ends_on');
        if ($start->greaterThan($end)) {
            throw new ScheduleOccurrenceMaterializationException('AcademicPeriod.starts_on must not follow ends_on.');
        }
        if (! is_string($template['group'] ?? null) || ! is_array($template['source'] ?? null)
            || ! is_array($template['rules'] ?? null) || ! array_is_list($template['rules'])) {
            throw new ScheduleOccurrenceMaterializationException('Expected a parsed weekly schedule template.');
        }
        foreach ($template['rules'] as $index => $rule) {
            $this->validateRule($rule, $index);
        }

        $occurrences = [];
        // UTC here represents calendar labels only, so date arithmetic cannot skip a local day.
        $week = $start->startOfWeek(CarbonImmutable::MONDAY);
        for ($offset = 0; $week->lessThanOrEqualTo($end); $offset++, $week = $week->addWeek()) {
            $parity = $offset % 2 === 0 ? 'numerator' : 'denominator';
            foreach ($template['rules'] as $rule) {
                $date = $week->addDays($rule['weekday'] - 1);
                if ($date->lessThan($start) || $date->greaterThan($end)
                    || ($rule['parity'] !== 'all' && $rule['parity'] !== $parity)) {
                    continue;
                }
                $localDate = $date->toDateString();
                $startsAt = $this->localInstant($localDate, $rule['starts_at_local'], $zone);
                $endsAt = $this->localInstant($localDate, $rule['ends_at_local'], $zone);
                if (! $startsAt->lessThan($endsAt)) {
                    throw new ScheduleOccurrenceMaterializationException('Occurrence start must precede end at '.$localDate.' in '.$timezone.'.');
                }
                $occurrences[] = [
                    'local_date' => $localDate,
                    'weekday' => $rule['weekday'],
                    'pair_label' => $rule['pair_label'],
                    'parity' => $rule['parity'],
                    'subject_name' => $rule['subject_name'],
                    'teacher_name' => $rule['teacher_name'],
                    'room' => $rule['room'],
                    'type' => LessonType::Other,
                    'starts_at_local' => $rule['starts_at_local'],
                    'ends_at_local' => $rule['ends_at_local'],
                    'starts_at_utc' => $startsAt->format('Y-m-d\TH:i:s\Z'),
                    'ends_at_utc' => $endsAt->format('Y-m-d\TH:i:s\Z'),
                    'source' => $rule['source'],
                ];
            }
        }
        usort($occurrences, $this->compareOccurrences(...));

        return ['group' => $template['group'], 'source' => $template['source'], 'occurrences' => $occurrences];
    }

    private function periodDate(AcademicPeriod $period, string $field): CarbonImmutable
    {
        // Read DATE attributes directly: Eloquent date casts otherwise use the server timezone.
        $value = $period->getAttributes()[$field] ?? null;
        if (! is_string($value) || ! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}(?: 00:00:00)?$/D', $value)) {
            throw new ScheduleOccurrenceMaterializationException('AcademicPeriod.'.$field.' must be a calendar date.');
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), 'UTC');
        if ($date === null || $date->toDateString() !== substr($value, 0, 10) || $date->year < 1) {
            throw new ScheduleOccurrenceMaterializationException('AcademicPeriod.'.$field.' must be a valid calendar date.');
        }

        return $date;
    }

    private function validateRule(mixed $rule, int $index): void
    {
        if (! is_array($rule)) {
            throw new ScheduleOccurrenceMaterializationException('Invalid weekly schedule rule at index '.$index.'.');
        }
        $valid = is_int($rule['weekday'] ?? null) && $rule['weekday'] >= 1 && $rule['weekday'] <= 7
            && in_array($rule['parity'] ?? null, ['all', 'numerator', 'denominator'], true);
        foreach (['pair_label', 'subject_name', 'teacher_name', 'starts_at_local', 'ends_at_local'] as $field) {
            $valid = $valid && is_string($rule[$field] ?? null) && $rule[$field] !== '';
        }
        $valid = $valid && array_key_exists('room', $rule) && ($rule['room'] === null || is_string($rule['room']))
            && is_array($rule['source'] ?? null);
        $source = $rule['source'] ?? [];
        foreach (['worksheet', 'range', 'pair_range'] as $field) {
            $valid = $valid && is_string($source[$field] ?? null);
        }
        $valid = $valid && is_array($source['rows'] ?? null) && array_is_list($source['rows'])
            && count($source['rows']) === 2 && is_int($source['rows'][0]) && is_int($source['rows'][1])
            && $source['rows'][0] > 0 && $source['rows'][1] >= $source['rows'][0];
        if (! $valid) {
            throw new ScheduleOccurrenceMaterializationException('Invalid weekly schedule rule at index '.$index.'.');
        }
        foreach (['starts_at_local', 'ends_at_local'] as $field) {
            if (! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D', $rule[$field])) {
                throw new ScheduleOccurrenceMaterializationException('Expected local HH:MM:SS in rule '.$index.'.'.$field.'.');
            }
        }
        if ($rule['starts_at_local'] >= $rule['ends_at_local']) {
            throw new ScheduleOccurrenceMaterializationException('Local start must precede end in rule '.$index.'.');
        }
    }

    private function localInstant(string $date, string $time, DateTimeZone $zone): CarbonImmutable
    {
        $label = $date.' '.$time;
        $wallClock = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $label, 'UTC');
        // Test nearby IANA offsets rather than accepting PHP's automatic gap/fold choice.
        // Two days cover even date-line shifts; each endpoint gets its own transition window.
        $transitions = $zone->getTransitions($wallClock->getTimestamp() - 172800, $wallClock->getTimestamp() + 172800);
        $matches = [];
        foreach (array_unique(array_column($transitions, 'offset')) as $offset) {
            $instant = $wallClock->subSeconds($offset);
            if ($instant->setTimezone($zone)->format('Y-m-d H:i:s') === $label) {
                $matches[] = $instant;
            }
        }
        if (count($matches) !== 1) {
            throw new ScheduleOccurrenceMaterializationException((count($matches) === 0 ? 'Nonexistent' : 'Ambiguous')
                .' local time '.$label.' in '.$zone->getName().'.');
        }

        return $matches[0];
    }

    private function compareOccurrences(array $first, array $second): int
    {
        $secondKey = $this->sortKey($second);
        foreach ($this->sortKey($first) as $index => $value) {
            $comparison = is_string($value) ? strcmp($value, $secondKey[$index]) : $value <=> $secondKey[$index];
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    private function sortKey(array $occurrence): array
    {
        $source = $occurrence['source'];

        return [
            $occurrence['local_date'], $occurrence['starts_at_local'], $occurrence['ends_at_local'],
            $source['worksheet'], $source['rows'][0], $source['rows'][1], $source['range'], $source['pair_range'],
            $occurrence['subject_name'], $occurrence['teacher_name'], $occurrence['room'] !== null, $occurrence['room'] ?? '',
            $occurrence['parity'], $occurrence['pair_label'],
        ];
    }
}
