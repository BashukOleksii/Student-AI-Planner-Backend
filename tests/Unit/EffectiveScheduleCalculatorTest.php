<?php

namespace Tests\Unit;

use App\Services\Imports\ScheduleWorkbookParser;
use App\Services\Schedule\EffectiveScheduleCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EffectiveScheduleCalculatorTest extends TestCase
{
    #[DataProvider('isoWeeks')]
    public function test_iso_week_labels_use_monday_and_nominal_calendar_dates(string $date, array $expected): void
    {
        $this->assertSame($expected, (new EffectiveScheduleCalculator)->weekDates($date));
    }

    public static function isoWeeks(): array
    {
        return [
            ['2026-09-07', ['2026-09-07', '2026-09-13']],
            ['2026-09-13', ['2026-09-07', '2026-09-13']],
            ['2026-09-14', ['2026-09-14', '2026-09-20']],
            ['2027-01-01', ['2026-12-28', '2027-01-03']],
            ['2026-03-29', ['2026-03-23', '2026-03-29']],
            ['2026-10-25', ['2026-10-19', '2026-10-25']],
        ];
    }

    #[DataProvider('unsupportedWeeks')]
    public function test_invalid_or_unrepresentable_week_labels_fail_explicitly(string $date): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EffectiveScheduleCalculator)->weekDates($date);
    }

    public static function unsupportedWeeks(): array
    {
        return [['2026-02-30'], ['2026-9-08'], ['0000-01-01'], ['9999-12-31']];
    }

    private function config(array $changes = []): array
    {
        return array_replace(['id' => 1, 'starts_on' => '2026-09-07', 'ends_on' => '2026-09-20',
            'parity_anchor_on' => '2026-09-07', 'first_week_parity' => 'numerator'], $changes);
    }

    private function rule(array $changes = []): array
    {
        return array_replace(['id' => 1, 'weekday' => 2, 'pair_number' => 4, 'pair_label' => '7-8',
            'week_pattern' => 'all', 'starts_at_local' => '13:20:00', 'ends_at_local' => '14:40:00',
            'subject_id' => 10, 'teacher_id' => null, 'room' => '122'], $changes);
    }

    public function test_real_fixture_has_fifteen_occurrences_each_week_and_exactly_one_alternating_tuesday_pair(): void
    {
        $parsed = (new ScheduleWorkbookParser)->parse(__DIR__.'/../Fixtures/ScheduleImports/Приклад розкладу.xlsx');
        $rules = [];
        foreach ($parsed['rules'] as $i => $rule) {
            $rules[] = $this->rule([...$rule, 'id' => $i + 1, 'subject_id' => $i + 1,
                'week_pattern' => $rule['parity'], 'pair_number' => (intdiv((int) $rule['pair_label'][0], 2) + 1)]);
        }
        $original = $rules;
        $calculator = new EffectiveScheduleCalculator;
        $weeks = [[], []];
        for ($i = 0; $i < 14; $i++) {
            $date = (new \DateTimeImmutable('2026-09-07'))->modify('+'.$i.' days')->format('Y-m-d');
            $rows = $calculator->calculate($this->config(), $rules, null, $date, 'Europe/Kyiv');
            array_push($weeks[intdiv($i, 7)], ...$rows);
            $this->assertSame($rows, $calculator->calculate($this->config(), $rules, null, $date, 'Europe/Kyiv'));
            $this->assertCount(count($rows), array_unique(array_column($rows, 'occurrence_id')));
        }
        $this->assertCount(16, $rules);
        $this->assertCount(15, $weeks[0]);
        $this->assertCount(15, $weeks[1]);
        foreach (['2026-09-08' => 8, '2026-09-15' => 9] as $date => $subjectId) {
            $last = array_values(array_filter($calculator->calculate($this->config(), $rules, null, $date, 'Europe/Kyiv'),
                fn (array $row): bool => $row['pair_number'] === 4));
            $this->assertCount(1, $last);
            $this->assertSame($subjectId, $last[0]['subject_id']);
            $this->assertSame($date.'T13:20:00+03:00', $last[0]['starts_at']);
        }
        $this->assertSame($original, $rules);
    }

    public function test_anchor_parity_is_configurable_and_works_backwards_across_year_boundary(): void
    {
        $config = $this->config(['starts_on' => '2026-12-21', 'ends_on' => '2027-01-10',
            'parity_anchor_on' => '2027-01-04', 'first_week_parity' => 'denominator']);
        $rules = [$this->rule(['week_pattern' => 'numerator']), $this->rule(['id' => 2, 'week_pattern' => 'denominator'])];
        $calculator = new EffectiveScheduleCalculator;
        foreach (['2026-12-22' => 2, '2026-12-29' => 1, '2027-01-05' => 2] as $date => $id) {
            $this->assertSame($id, $calculator->calculate($config, $rules, null, $date, 'UTC')[0]['weekly_lesson_rule_id']);
        }
    }

    public function test_snapshot_is_a_complete_frozen_day_and_empty_snapshot_cancels_every_pair(): void
    {
        $calculator = new EffectiveScheduleCalculator;
        $rules = [$this->rule()];
        $snapshot = ['id' => 7, 'occurs_on' => '2026-09-08', 'kind' => 'replacement', 'lessons' => [$this->rule(['id' => 9, 'subject_id' => 99])]];
        $first = $calculator->calculate($this->config(), $rules, $snapshot, '2026-09-08', 'Europe/Kyiv');
        $rules[0]['subject_id'] = 123;
        $this->assertSame($first, $calculator->calculate($this->config(), $rules, $snapshot, '2026-09-08', 'Europe/Kyiv'));
        $this->assertSame('replacement', $first[0]['source']);
        $this->assertSame('semester:1:2026-09-08:snapshot:9', $first[0]['occurrence_id']);
        $this->assertSame(123, $calculator->calculate($this->config(), $rules, null, '2026-09-15', 'Europe/Kyiv')[0]['subject_id']);
        $snapshot['lessons'] = [];
        $this->assertSame([], $calculator->calculate($this->config(), $rules, $snapshot, '2026-09-08', 'Europe/Kyiv'));
        $this->assertSame(123, $calculator->calculate($this->config(), $rules, null, '2026-09-08', 'Europe/Kyiv')[0]['subject_id']);
    }

    public function test_working_saturday_uses_only_its_saved_rows_and_never_recopies_friday(): void
    {
        $calculator = new EffectiveScheduleCalculator;
        $rules = [$this->rule(['weekday' => 5, 'subject_id' => 123])];
        $snapshot = ['id' => 7, 'occurs_on' => '2026-09-12', 'kind' => 'working_saturday', 'copied_weekday' => 5,
            'lessons' => [$this->rule(['subject_id' => 42])]];
        $result = $calculator->calculate($this->config(), $rules, $snapshot, '2026-09-12', 'Europe/Kyiv');
        $this->assertSame(42, $result[0]['subject_id']);
        $this->assertSame('working_saturday', $result[0]['source']);
        $this->assertSame([], $calculator->calculate($this->config(), $rules, null, '2026-09-19', 'Europe/Kyiv'));
    }

    #[DataProvider('boundaryDates')]
    public function test_semester_boundaries_are_inclusive(string $date, int $count): void
    {
        $config = $this->config(['starts_on' => '2026-09-08', 'ends_on' => '2026-09-15']);
        $this->assertCount($count, (new EffectiveScheduleCalculator)->calculate($config, [$this->rule()], null, $date, 'Europe/Kyiv'));
    }

    public static function boundaryDates(): array
    {
        return [['2026-09-01', 0], ['2026-09-08', 1], ['2026-09-15', 1], ['2026-09-22', 0]];
    }

    public function test_half_open_adjacency_is_valid_and_same_slot_on_disjoint_weeks_is_valid(): void
    {
        $rules = [$this->rule(['week_pattern' => 'numerator']),
            $this->rule(['id' => 2, 'week_pattern' => 'denominator']),
            $this->rule(['id' => 3, 'pair_number' => 5, 'starts_at_local' => '14:40:00', 'ends_at_local' => '15:00:00'])];
        $result = (new EffectiveScheduleCalculator)->calculate($this->config(), $rules, null, '2026-09-08', 'UTC');
        $this->assertCount(2, $result);
        $this->assertSame($result[0]['end'], $result[1]['start']);
    }

    #[DataProvider('invalidRules')]
    public function test_invalid_rules_fail_even_when_not_on_the_requested_weekday(array $changes): void
    {
        $rules = [$this->rule(), $this->rule(['id' => 2, ...$changes])];
        $this->expectException(InvalidArgumentException::class);
        (new EffectiveScheduleCalculator)->calculate($this->config(), $rules, null, '2026-09-07', 'UTC');
    }

    public static function invalidRules(): array
    {
        return [
            'duplicate' => [[]],
            'all overlaps numerator' => [['week_pattern' => 'numerator']],
            'overlap with other pair' => [['pair_number' => 5, 'starts_at_local' => '14:39:59', 'ends_at_local' => '15:00:00']],
            'duplicate pair with disjoint times' => [['starts_at_local' => '15:00:00', 'ends_at_local' => '16:00:00']],
            'negative time' => [['starts_at_local' => '-01:00:00']],
            'overnight' => [['starts_at_local' => '23:00:00', 'ends_at_local' => '01:00:00']],
            '24 hour end' => [['ends_at_local' => '24:00:00']],
            'zero duration' => [['ends_at_local' => '13:20:00']],
            'weekend rule' => [['weekday' => 6]],
            'pattern' => [['week_pattern' => 'odd']],
            'pair zero' => [['pair_number' => 0]],
        ];
    }

    #[DataProvider('dstCases')]
    public function test_local_dst_endpoints_are_resolved_strictly(string $date, string $from, string $to, string $timezone, ?int $seconds): void
    {
        $config = $this->config(['starts_on' => '2011-01-01', 'ends_on' => '2026-12-31']);
        $snapshot = ['id' => 1, 'occurs_on' => $date, 'kind' => 'replacement',
            'lessons' => [$this->rule(['starts_at_local' => $from, 'ends_at_local' => $to])]];
        if ($seconds === null) {
            $this->expectException(InvalidArgumentException::class);
        }
        $result = (new EffectiveScheduleCalculator)->calculate($config, [], $snapshot, $date, $timezone);
        if ($seconds !== null) {
            $this->assertSame($seconds, $result[0]['end'] - $result[0]['start']);
        }
    }

    public static function dstCases(): array
    {
        return [
            'Kyiv spring gap' => ['2026-03-29', '03:30:00', '05:00:00', 'Europe/Kyiv', null],
            'Kyiv autumn fold' => ['2026-10-25', '03:30:00', '05:00:00', 'Europe/Kyiv', null],
            'ambiguous end' => ['2026-10-25', '02:30:00', '03:30:00', 'Europe/Kyiv', null],
            'spring spanning transition' => ['2026-03-29', '02:30:00', '04:30:00', 'Europe/Kyiv', 3600],
            'autumn spanning transition' => ['2026-10-25', '02:30:00', '04:30:00', 'Europe/Kyiv', 10800],
            'skipped calendar date' => ['2011-12-30', '08:30:00', '09:50:00', 'Pacific/Apia', null],
            'ordinary Kyiv' => ['2026-09-08', '08:30:00', '09:50:00', 'Europe/Kyiv', 4800],
            'UTC' => ['2026-09-08', '08:30:00', '09:50:00', 'UTC', 4800],
            'named fixed zone' => ['2026-09-08', '08:30:00', '09:50:00', 'Etc/GMT-3', 4800],
        ];
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_dates_anchor_timezone_and_snapshot_are_rejected(array $config, string $date, string $timezone, ?array $snapshot): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EffectiveScheduleCalculator)->calculate($this->config($config), [], $snapshot, $date, $timezone);
    }

    public static function invalidConfiguration(): array
    {
        return [
            [['parity_anchor_on' => '2026-09-08'], '2026-09-08', 'UTC', null],
            [['first_week_parity' => 'all'], '2026-09-08', 'UTC', null],
            [['ends_on' => '2026-09-01'], '2026-09-08', 'UTC', null],
            [[], '2026-02-30', 'UTC', null],
            [[], '2026-9-08', 'UTC', null],
            [[], '2026-09-08', '+03:00', null],
            [[], '2026-09-08', 'Invalid/Zone', null],
            [[], '2026-09-08', 'UTC', ['occurs_on' => '2026-09-15', 'kind' => 'replacement', 'lessons' => []]],
            [[], '2026-09-08', 'UTC', ['occurs_on' => '2026-09-08', 'kind' => 'working_saturday', 'copied_weekday' => 5, 'lessons' => []]],
            [[], '2026-09-12', 'UTC', ['occurs_on' => '2026-09-12', 'kind' => 'working_saturday', 'copied_weekday' => 6, 'lessons' => []]],
            [[], '2026-09-12', 'UTC', ['occurs_on' => '2026-09-12', 'kind' => 'working_saturday', 'lessons' => []]],
        ];
    }
}
