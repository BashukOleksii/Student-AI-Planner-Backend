<?php

namespace Tests\Unit;

use App\Enums\LessonType;
use App\Exceptions\ScheduleOccurrenceMaterializationException;
use App\Models\AcademicPeriod;
use App\Services\Imports\ScheduleOccurrenceMaterializer;
use App\Services\Imports\ScheduleWorkbookParser;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Plain PHPUnit with in-memory period attributes; no Laravel boot or database.
class ScheduleOccurrenceMaterializerTest extends TestCase
{
    public function test_real_workbook_materializes_thirty_occurrences_with_correct_parity_and_provenance(): void
    {
        $template = (new ScheduleWorkbookParser)->parse(__DIR__.'/../Fixtures/ScheduleImports/Приклад розкладу.xlsx');
        $period = $this->period('2026-09-07', '2026-09-20');
        $attributes = $period->getAttributes();
        $result = (new ScheduleOccurrenceMaterializer)->materialize($template, $period, 'Europe/Kyiv');

        $this->assertSame(['group', 'source', 'occurrences'], array_keys($result));
        $this->assertSame('ПІ-231', $result['group']);
        $this->assertSame($template['source'], $result['source']);
        $this->assertCount(30, $result['occurrences']);
        $this->assertSame([
            '2026-09-07' => 4, '2026-09-08' => 4, '2026-09-09' => 2, '2026-09-10' => 3, '2026-09-11' => 2,
            '2026-09-14' => 4, '2026-09-15' => 4, '2026-09-16' => 2, '2026-09-17' => 3, '2026-09-18' => 2,
        ], array_count_values(array_column($result['occurrences'], 'local_date')));

        $utcTimes = [
            '1-2' => ['05:30:00', '06:50:00'], '3-4' => ['07:00:00', '08:20:00'],
            '5-6' => ['08:50:00', '10:10:00'], '7-8' => ['10:20:00', '11:40:00'],
        ];
        foreach ($result['occurrences'] as $occurrence) {
            $rule = array_values(array_filter($template['rules'], fn (array $rule) => $rule['source'] === $occurrence['source']))[0];
            $date = $occurrence['local_date'];
            $this->assertSame([
                'local_date' => $date,
                'weekday' => $rule['weekday'],
                'pair_label' => $rule['pair_label'],
                'parity' => $rule['parity'],
                'subject_name' => $rule['subject_name'],
                'teacher_name' => $rule['teacher_name'],
                'room' => $rule['room'],
                'type' => LessonType::Other,
                'starts_at_local' => $rule['starts_at_local'],
                'ends_at_local' => $rule['ends_at_local'],
                'starts_at_utc' => $date.'T'.$utcTimes[$rule['pair_label']][0].'Z',
                'ends_at_utc' => $date.'T'.$utcTimes[$rule['pair_label']][1].'Z',
                'source' => $rule['source'],
            ], $occurrence);
        }
        foreach ($template['rules'] as $rule) {
            $copies = array_values(array_filter($result['occurrences'], fn (array $occurrence) => $occurrence['source'] === $rule['source']));
            $this->assertCount($rule['parity'] === 'all' ? 2 : 1, $copies);
        }
        foreach ([
            ['2026-09-08', 'numerator', 'Філософія', 'Багас Л.М.', '122'],
            ['2026-09-15', 'denominator', 'Проект інтерфейсів користувача ПС', 'Атаман В.О.', '221'],
        ] as [$date, $parity, $subject, $teacher, $room]) {
            $slot = array_values(array_filter($result['occurrences'], fn (array $occurrence) => $occurrence['local_date'] === $date && $occurrence['pair_label'] === '7-8'));
            $this->assertCount(1, $slot);
            $this->assertSame([$parity, $subject, $teacher, $room, '13:20:00', '14:40:00', $date.'T10:20:00Z', $date.'T11:40:00Z'], [
                $slot[0]['parity'], $slot[0]['subject_name'], $slot[0]['teacher_name'], $slot[0]['room'],
                $slot[0]['starts_at_local'], $slot[0]['ends_at_local'], $slot[0]['starts_at_utc'], $slot[0]['ends_at_utc'],
            ]);
        }
        $this->assertSame($attributes, $period->getAttributes());
        $this->assertFalse($period->exists);
        $this->assertSame($result, (new ScheduleOccurrenceMaterializer)->materialize($template, $period, 'Europe/Kyiv'));
    }

    public function test_kyiv_dst_offset_is_resolved_for_each_occurrence_date(): void
    {
        $occurrences = $this->materialize([$this->rule()], '2026-10-19', '2026-10-26', 'Europe/Kyiv')['occurrences'];

        $this->assertSame(['2026-10-19', '2026-10-26'], array_column($occurrences, 'local_date'));
        $this->assertSame(['2026-10-19T05:30:00Z', '2026-10-26T06:30:00Z'], array_column($occurrences, 'starts_at_utc'));
        $this->assertSame(['2026-10-19T06:50:00Z', '2026-10-26T07:50:00Z'], array_column($occurrences, 'ends_at_utc'));
    }

    #[DataProvider('transitionIntervals')]
    public function test_start_and_end_resolve_independently_across_a_clock_change(string $date, string $start, string $end): void
    {
        $rule = $this->rule(weekday: 7, start: '02:30:00', end: '04:30:00');
        $occurrences = $this->materialize([$rule], $date, $date, 'Europe/Kyiv')['occurrences'];

        $this->assertCount(1, $occurrences);
        $this->assertSame($start, $occurrences[0]['starts_at_utc']);
        $this->assertSame($end, $occurrences[0]['ends_at_utc']);
    }

    public static function transitionIntervals(): array
    {
        return [
            'spring' => ['2026-03-29', '2026-03-29T00:30:00Z', '2026-03-29T01:30:00Z'],
            'autumn' => ['2026-10-25', '2026-10-24T23:30:00Z', '2026-10-25T02:30:00Z'],
        ];
    }

    public function test_partial_starting_week_is_numerator_and_boundaries_are_inclusive(): void
    {
        $rules = [];
        foreach ([1, 2, 3] as $weekday) {
            foreach (['numerator', 'denominator'] as $parity) {
                $rules[] = $this->rule(weekday: $weekday, parity: $parity);
            }
        }
        $occurrences = $this->materialize($rules, '2026-09-09', '2026-09-15')['occurrences'];

        $this->assertSame(['2026-09-09', '2026-09-14', '2026-09-15'], array_column($occurrences, 'local_date'));
        $this->assertSame(['numerator', 'denominator', 'denominator'], array_column($occurrences, 'parity'));
        $this->assertSame([3, 1, 2], array_column($occurrences, 'weekday'));
    }

    public function test_single_day_period_includes_sunday_and_anchors_it_as_numerator(): void
    {
        $occurrences = $this->materialize([
            $this->rule(weekday: 7, parity: 'numerator'), $this->rule(weekday: 7, parity: 'denominator'),
        ], '2026-09-20', '2026-09-20')['occurrences'];

        $this->assertCount(1, $occurrences);
        $this->assertSame('2026-09-20', $occurrences[0]['local_date']);
        $this->assertSame(7, $occurrences[0]['weekday']);
        $this->assertSame('numerator', $occurrences[0]['parity']);
    }

    public function test_parity_alternates_across_a_fifty_three_week_year(): void
    {
        // ISO weeks 53, 1, 2, 3: their numeric odd/even values cannot define parity.
        $occurrences = $this->materialize([
            $this->rule(parity: 'numerator'), $this->rule(parity: 'denominator'),
        ], '2020-12-28', '2021-01-18')['occurrences'];

        $this->assertSame(['2020-12-28', '2021-01-04', '2021-01-11', '2021-01-18'], array_column($occurrences, 'local_date'));
        $this->assertSame(['numerator', 'denominator', 'numerator', 'denominator'], array_column($occurrences, 'parity'));
    }

    public function test_order_is_chronological_with_source_ties_independent_of_input_order(): void
    {
        $rules = [
            $this->rule(weekday: 2, row: 15),
            $this->rule(row: 30, start: '09:00:00', end: '10:30:00'),
            $this->rule(row: 20, end: '09:30:00'),
            $this->rule(row: 10, end: '09:30:00'),
            $this->rule(row: 40, end: '09:00:00'),
        ];
        $result = $this->materialize($rules, '2026-09-07', '2026-09-08');

        $this->assertSame([40, 10, 20, 30, 15], array_map(fn (array $occurrence) => $occurrence['source']['rows'][0], $result['occurrences']));
        $this->assertSame(['2026-09-07', '2026-09-07', '2026-09-07', '2026-09-07', '2026-09-08'], array_column($result['occurrences'], 'local_date'));
        $this->assertSame($result, $this->materialize(array_reverse($rules), '2026-09-07', '2026-09-08'));
    }

    public function test_server_default_timezone_does_not_affect_calendar_dates_or_utc_conversion(): void
    {
        $originalTimezone = date_default_timezone_get();
        try {
            // This DATE was skipped in Apia; casting it in the server timezone would alter it.
            date_default_timezone_set('Pacific/Apia');
            $result = $this->materialize([$this->rule(weekday: 5)], '2011-12-30', '2011-12-30', 'Europe/Kyiv');
            date_default_timezone_set('America/Los_Angeles');
            $this->assertSame($result, $this->materialize([$this->rule(weekday: 5)], '2011-12-30', '2011-12-30', 'Europe/Kyiv'));
            $this->assertSame('2011-12-30', $result['occurrences'][0]['local_date']);
            $this->assertSame('2011-12-30T06:30:00Z', $result['occurrences'][0]['starts_at_utc']);
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_source_tie_breaker_compares_numeric_worksheet_names_as_text(): void
    {
        $first = $this->rule();
        $first['source']['worksheet'] = '1';
        $second = $this->rule();
        $second['source']['worksheet'] = '01';
        $result = $this->materialize([$first, $second], '2026-09-07', '2026-09-07');

        $this->assertSame(['01', '1'], array_column(array_column($result['occurrences'], 'source'), 'worksheet'));
        $this->assertSame($result, $this->materialize([$second, $first], '2026-09-07', '2026-09-07'));
    }

    public function test_timezone_with_a_non_hour_offset_is_supported(): void
    {
        $result = $this->materialize([$this->rule()], '2026-09-07', '2026-09-07', 'Asia/Kathmandu');

        $this->assertSame('2026-09-07T02:45:00Z', $result['occurrences'][0]['starts_at_utc']);
        $this->assertSame('2026-09-07T04:05:00Z', $result['occurrences'][0]['ends_at_utc']);
    }

    #[DataProvider('unsupportedLocalTimes')]
    public function test_nonexistent_or_ambiguous_endpoint_fails_without_silent_adjustment(string $date, string $zone, int $weekday, string $start, string $end, string $message): void
    {
        $this->expectException(ScheduleOccurrenceMaterializationException::class);
        $this->expectExceptionMessage($message);
        $this->materialize([$this->rule(weekday: $weekday, start: $start, end: $end)], $date, $date, $zone);
    }

    public static function unsupportedLocalTimes(): array
    {
        return [
            'gap start' => ['2026-03-29', 'Europe/Kyiv', 7, '03:30:00', '04:30:00', 'Nonexistent local time 2026-03-29 03:30:00'],
            'gap end' => ['2026-03-29', 'Europe/Kyiv', 7, '02:30:00', '03:30:00', 'Nonexistent local time 2026-03-29 03:30:00'],
            'fold start' => ['2026-10-25', 'Europe/Kyiv', 7, '03:30:00', '04:30:00', 'Ambiguous local time 2026-10-25 03:30:00'],
            'fold end' => ['2026-10-25', 'Europe/Kyiv', 7, '02:30:00', '03:30:00', 'Ambiguous local time 2026-10-25 03:30:00'],
            'skipped date' => ['2011-12-30', 'Pacific/Apia', 5, '08:30:00', '09:50:00', 'Nonexistent local time 2011-12-30 08:30:00'],
        ];
    }

    #[DataProvider('invalidRules')]
    public function test_invalid_rule_input_throws_a_controlled_exception(array $changes): void
    {
        $this->expectException(ScheduleOccurrenceMaterializationException::class);
        $this->materialize([array_replace($this->rule(), $changes)], '2026-09-07', '2026-09-07');
    }

    public static function invalidRules(): array
    {
        return [
            'weekday zero' => [['weekday' => 0]],
            'weekday eight' => [['weekday' => 8]],
            'weekday string' => [['weekday' => '1']],
            'unknown parity' => [['parity' => 'odd']],
            'time without seconds' => [['starts_at_local' => '08:30']],
            'time with fraction' => [['starts_at_local' => '08:30:00.5']],
            'invalid hour' => [['ends_at_local' => '24:00:00']],
            'equal times' => [['ends_at_local' => '08:30:00']],
            'reversed times' => [['ends_at_local' => '08:00:00']],
            'missing source rows' => [['source' => ['worksheet' => 'Sheet1']]],
        ];
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_period_throws_a_controlled_exception(?string $start, ?string $end): void
    {
        $this->expectException(ScheduleOccurrenceMaterializationException::class);
        $this->materialize([$this->rule()], $start, $end);
    }

    public static function invalidPeriods(): array
    {
        return [
            ['2026-09-08', '2026-09-07'], [null, '2026-09-07'], ['2026-09-07', null],
            ['2026-02-30', '2026-03-07'], ['not-a-date', '2026-09-07'],
        ];
    }

    #[DataProvider('invalidTimezones')]
    public function test_non_iana_timezone_is_rejected(string $timezone): void
    {
        $this->expectException(ScheduleOccurrenceMaterializationException::class);
        $this->expectExceptionMessage('Expected an IANA timezone name');
        $this->materialize([$this->rule()], '2026-09-07', '2026-09-07', $timezone);
    }

    public static function invalidTimezones(): array
    {
        return [[''], ['+03:00'], ['EEST'], ['EST'], ['Invalid/Zone']];
    }

    public function test_empty_template_preserves_metadata_without_occurrences(): void
    {
        $this->assertSame([
            'group' => 'ПІ-231', 'source' => ['worksheet' => 'Sheet1', 'range' => 'A1:F81'], 'occurrences' => [],
        ], $this->materialize([], '2026-09-07', '2026-09-20'));
    }

    public function test_materialization_requires_no_database_connection_resolver(): void
    {
        $resolver = Model::getConnectionResolver();
        Model::unsetConnectionResolver();
        try {
            $result = $this->materialize([$this->rule()], '2026-09-07', '2026-09-07');
            $this->assertCount(1, $result['occurrences']);
            $this->assertNull(Model::getConnectionResolver());
        } finally {
            if ($resolver !== null) {
                Model::setConnectionResolver($resolver);
            }
        }
    }

    private function period(?string $start, ?string $end): AcademicPeriod
    {
        return (new AcademicPeriod)->setRawAttributes(['starts_on' => $start, 'ends_on' => $end]);
    }

    private function materialize(array $rules, ?string $start, ?string $end, string $timezone = 'UTC'): array
    {
        return (new ScheduleOccurrenceMaterializer)->materialize([
            'group' => 'ПІ-231', 'source' => ['worksheet' => 'Sheet1', 'range' => 'A1:F81'], 'rules' => $rules,
        ], $this->period($start, $end), $timezone);
    }

    private function rule(int $weekday = 1, string $parity = 'all', int $row = 3, string $start = '08:30:00', string $end = '09:50:00'): array
    {
        return [
            'weekday' => $weekday, 'pair_label' => '1-2', 'starts_at_local' => $start, 'ends_at_local' => $end,
            'parity' => $parity, 'subject_name' => 'Предмет', 'teacher_name' => 'Викладач І.П.', 'room' => null,
            'source' => ['worksheet' => 'Sheet1', 'rows' => [$row, $row + 1], 'range' => 'D'.$row.':F'.($row + 1), 'pair_range' => 'B'.($row - 1).':F'.($row + 2)],
        ];
    }
}
