<?php

namespace Tests\Unit;

use App\Services\Planning\WorkloadConstraintService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkloadConstraintServiceTest extends TestCase
{
    #[DataProvider('workloadCases')]
    public function test_exact_scheduled_workload_is_deterministic_and_inputs_are_unchanged(
        array $candidate, array $sessions, string $timezone, ?int $daily, ?int $weekly, array $expected,
    ): void {
        $originalCandidate = $candidate;
        $originalSessions = $sessions;
        $service = new WorkloadConstraintService;
        $this->assertSame($expected, $service->evaluate($candidate, $sessions, $timezone, $daily, $weekly));
        $this->assertSame($expected, $service->evaluate($candidate, $sessions, $timezone, $daily, $weekly));
        $this->assertSame($expected, $service->evaluate(array_reverse($candidate, true), array_reverse($sessions), $timezone, $daily, $weekly));
        $this->assertSame($originalCandidate, $candidate);
        $this->assertSame($originalSessions, $sessions);
    }

    public static function workloadCases(): array
    {
        $i = self::interval(...);
        $hour = $i('2026-10-12T09:00:00Z', '2026-10-12T10:00:00Z');
        $daily = ['daily_study_limit_exceeded'];
        $weekly = ['weekly_study_limit_exceeded'];

        return [
            'no limits no sessions' => [$hour, [], 'UTC', null, null, []],
            'no limits existing workload' => [$hour, [$i('2026-10-12T01:00:00Z', '2026-10-12T08:00:00Z')], 'UTC', null, null, []],
            'exact daily' => [$hour, [], 'UTC', 60, null, []],
            'one second above daily' => [$i('2026-10-12T09:00:00Z', '2026-10-12T10:00:01Z'), [], 'UTC', 60, null, $daily],
            'exact weekly' => [$hour, [], 'UTC', null, 60, []],
            'one second above weekly' => [$i('2026-10-12T09:00:00Z', '2026-10-12T10:00:01Z'), [], 'UTC', null, 60, $weekly],
            'both exact' => [$hour, [], 'UTC', 60, 60, []],
            'both exceed stable order' => [$hour, [$i('2026-10-12T10:00:00Z', '2026-10-12T10:00:01Z')], 'UTC', 60, 60, [...$daily, ...$weekly]],
            'existing plus candidate equality' => [$hour, [$i('2026-10-12T08:30:00Z', '2026-10-12T09:00:00Z')], 'UTC', 90, 90, []],
            'multiple sessions same day' => [$hour, [$i('2026-10-12T08:00:00Z', '2026-10-12T08:15:00Z'), $i('2026-10-12T08:15:00Z', '2026-10-12T08:30:01Z')], 'UTC', 90, 90, [...$daily, ...$weekly]],
            'other days same week count weekly' => [$hour, [$i('2026-10-13T09:00:00Z', '2026-10-13T10:00:00Z'), $i('2026-10-14T09:00:00Z', '2026-10-14T10:00:00Z')], 'UTC', 60, 120, $weekly],
            'unaffected excessive day ignored' => [$hour, [$i('2026-10-13T00:00:00Z', '2026-10-13T23:00:00Z')], 'UTC', 60, null, []],
            'unaffected week ignored' => [$hour, [$i('2026-10-19T00:00:00Z', '2026-10-20T00:00:00Z')], 'UTC', 60, 60, []],
            'session crosses midnight' => [$i('2026-10-13T00:30:00Z', '2026-10-13T01:00:00Z'), [$i('2026-10-12T23:30:00Z', '2026-10-13T00:30:00Z')], 'UTC', 60, 90, []],
            'session midnight portion one second over' => [$i('2026-10-13T00:30:00Z', '2026-10-13T01:00:00Z'), [$i('2026-10-12T23:29:59Z', '2026-10-13T00:30:01Z')], 'UTC', 60, null, $daily],
            'candidate crosses midnight' => [$i('2026-10-12T23:30:00Z', '2026-10-13T00:30:00Z'), [], 'UTC', 30, 60, []],
            'multiple violated days unique code' => [$i('2026-10-12T23:00:00Z', '2026-10-13T01:00:00Z'), [], 'UTC', 30, null, $daily],
            'session crosses Sunday Monday' => [$hour, [$i('2026-10-11T23:30:00Z', '2026-10-12T00:30:00Z')], 'UTC', null, 90, []],
            'candidate crosses ISO weeks equality' => [$i('2026-10-11T23:30:00Z', '2026-10-12T00:30:00Z'), [], 'UTC', 30, 30, []],
            'multiple violated weeks unique code' => [$i('2026-10-11T23:30:00Z', '2026-10-12T00:30:00Z'), [$i('2026-10-11T22:00:00Z', '2026-10-11T22:30:00Z'), $i('2026-10-12T01:00:00Z', '2026-10-12T01:30:00Z')], 'UTC', 30, 30, [...$daily, ...$weekly]],
            'ISO week-year differs from calendar year' => [$i('2021-01-01T00:00:00Z', '2021-01-01T00:01:00Z'), [$i('2020-12-31T10:00:00Z', '2020-12-31T11:00:00Z')], 'UTC', null, 60, $weekly],
            'ISO new year begins in December' => [$i('2025-12-29T00:00:00Z', '2025-12-29T00:01:00Z'), [$i('2026-01-01T10:00:00Z', '2026-01-01T11:00:00Z')], 'UTC', null, 60, $weekly],
            'same week number different ISO year ignored' => [$i('2021-01-04T00:00:00Z', '2021-01-04T00:01:00Z'), [$i('2020-01-01T00:00:00Z', '2020-01-02T00:00:00Z')], 'UTC', null, 1, []],
            'Kyiv spring day exact 23 hours' => [$i('2026-03-28T22:00:00Z', '2026-03-29T21:00:00Z'), [], 'Europe/Kyiv', 1380, 1380, []],
            'Kyiv spring day plus second' => [$i('2026-03-28T22:00:00Z', '2026-03-29T21:00:00Z'), [$i('2026-03-29T01:00:00Z', '2026-03-29T01:00:01Z')], 'Europe/Kyiv', 1380, 1380, [...$daily, ...$weekly]],
            'Kyiv spring span is one elapsed hour' => [$i('2026-03-29T00:30:00Z', '2026-03-29T01:30:00Z'), [], 'Europe/Kyiv', 60, 60, []],
            'Kyiv autumn day exact 25 hours' => [$i('2026-10-24T21:00:00Z', '2026-10-25T22:00:00Z'), [], 'Europe/Kyiv', 1500, 1500, []],
            'Kyiv autumn day exceeds 24 hours' => [$i('2026-10-24T21:00:00Z', '2026-10-25T22:00:00Z'), [], 'Europe/Kyiv', 1440, null, $daily],
            'Kyiv fold occurrences both counted' => [$i('2026-10-25T01:45:00Z', '2026-10-25T02:00:00Z'), [$i('2026-10-25T00:15:00Z', '2026-10-25T00:45:00Z'), $i('2026-10-25T01:15:00Z', '2026-10-25T01:45:00Z')], 'Europe/Kyiv', 60, 60, [...$daily, ...$weekly]],
            'Kyiv local midnight differs from UTC' => [$i('2026-10-12T20:30:00Z', '2026-10-12T21:30:00Z'), [], 'Europe/Kyiv', 30, 60, []],
            'Kyiv midnight contribution shares next local day' => [$i('2026-10-12T21:00:00Z', '2026-10-12T21:30:00Z'), [$i('2026-10-13T00:00:00Z', '2026-10-13T00:30:01Z')], 'Europe/Kyiv', 60, null, $daily],
            'Kathmandu non-hour midnight' => [$i('2026-10-12T18:00:00Z', '2026-10-12T18:30:00Z'), [], 'Asia/Kathmandu', 15, 30, []],
            'named fixed offset zone' => [$i('2026-10-12T21:30:00Z', '2026-10-12T22:30:00Z'), [], 'Etc/GMT-2', 30, 60, []],
            'Apia skipped date has no invented duration' => [$i('2011-12-30T09:30:00Z', '2011-12-30T10:30:00Z'), [], 'Pacific/Apia', 30, 60, []],
            'overlapping records additive' => [$i('2026-10-12T10:00:00Z', '2026-10-12T10:10:00Z'), [$i('2026-10-12T09:00:00Z', '2026-10-12T09:30:00Z'), $i('2026-10-12T09:15:00Z', '2026-10-12T09:45:00Z')], 'UTC', 60, 60, [...$daily, ...$weekly]],
            'duplicate-equivalent records not deduplicated' => [$i('2026-10-12T10:00:00Z', '2026-10-12T10:10:00Z'), [$i('2026-10-12T09:00:00Z', '2026-10-12T09:10:00Z'), $i('2026-10-12T09:00:00Z', '2026-10-12T09:10:00Z')], 'UTC', 25, 25, [...$daily, ...$weekly]],
            'negative Unix endpoints' => [$i(-60, 0), [], 'UTC', 1, 1, []],
            'half open midnight end no next day contribution' => [$i('2026-10-12T23:00:00Z', '2026-10-13T00:00:00Z'), [$i('2026-10-13T00:00:00Z', '2026-10-13T02:00:00Z')], 'UTC', 60, null, []],
            'minimum configured minute' => [$i(0, 60), [], 'UTC', 1, 1, []],
            'maximum weekly minute conversion' => [$i(0, 3932100), [], 'UTC', null, 65535, []],
            'representable grand total with no limits' => [$i(0, 1), [$i(0, PHP_INT_MAX - 1)], 'UTC', null, null, []],
            'integer maximum second' => [$i(PHP_INT_MAX - 1, PHP_INT_MAX), [], 'UTC', 1, 1, []],
            'integer minimum second' => [$i(PHP_INT_MIN, PHP_INT_MIN + 1), [], 'UTC', 1, 1, []],
        ];
    }

    private static function interval(string|int $start, string|int $end): array
    {
        return ['start' => is_int($start) ? $start : (new DateTimeImmutable($start))->getTimestamp(),
            'end' => is_int($end) ? $end : (new DateTimeImmutable($end))->getTimestamp()];
    }

    #[DataProvider('invalidInputs')]
    public function test_malformed_input_is_rejected_even_when_limits_are_absent(array $candidate, array $sessions, string $timezone, mixed $daily, mixed $weekly): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WorkloadConstraintService)->evaluate($candidate, $sessions, $timezone, $daily, $weekly);
    }

    public static function invalidInputs(): array
    {
        $valid = ['start' => 0, 'end' => 1];
        $cases = [];
        foreach ([[], [0, 60], ['start' => 0, 'end' => 0], ['start' => 1, 'end' => 0], ['start' => 0], ['end' => 1], ['start' => '0', 'end' => 1], ['start' => 0, 'end' => 1.0], ['start' => false, 'end' => 1], ['start' => 0, 'end' => 1, 'status' => 'planned']] as $index => $interval) {
            $cases['candidate '.$index] = [$interval, [], 'UTC', null, null];
            $cases['session '.$index] = [$valid, [$interval], 'UTC', null, null];
        }
        foreach ([null, 1, 'interval', (object) $valid] as $index => $interval) {
            $cases['non-array session '.$index] = [$valid, [$interval], 'UTC', null, null];
        }
        foreach ([0, -1, 65536, PHP_INT_MAX, '60', 1.0, true, false, [], (object) ['minutes' => 1]] as $index => $limit) {
            $cases['daily '.$index] = [$valid, [], 'UTC', $limit, null];
            $cases['weekly '.$index] = [$valid, [], 'UTC', null, $limit];
        }
        foreach (['', 'Invalid/Timezone', '+03:00', 'EET', 'utc', ' Europe/Kyiv '] as $timezone) {
            $cases['timezone '.$timezone] = [$valid, [], $timezone, null, null];
        }
        $cases['reversed limits'] = [$valid, [], 'UTC', 61, 60];
        $cases['non-list sessions'] = [$valid, [5 => $valid], 'UTC', null, null];
        $cases['candidate duration overflow'] = [['start' => PHP_INT_MIN, 'end' => PHP_INT_MAX], [], 'UTC', null, null];
        $cases['session duration overflow'] = [$valid, [['start' => PHP_INT_MIN, 'end' => PHP_INT_MAX]], 'UTC', null, null];
        $cases['sum overflow'] = [$valid, [['start' => 0, 'end' => PHP_INT_MAX]], 'UTC', null, null];
        $cases['positive local offset overflow'] = [['start' => PHP_INT_MAX - 1, 'end' => PHP_INT_MAX], [], 'Etc/GMT-2', 1, null];
        $cases['negative local offset overflow'] = [['start' => PHP_INT_MIN, 'end' => PHP_INT_MIN + 1], [], 'Etc/GMT+2', 1, null];

        return $cases;
    }

    public function test_server_timezone_does_not_change_calendar_allocation(): void
    {
        $original = date_default_timezone_get();
        try {
            foreach (['UTC', 'Europe/Kyiv', 'Pacific/Apia', 'Asia/Kathmandu'] as $serverTimezone) {
                date_default_timezone_set($serverTimezone);
                $this->assertSame([], (new WorkloadConstraintService)->evaluate(
                    self::interval('2026-10-12T20:30:00Z', '2026-10-12T21:30:00Z'), [], 'Europe/Kyiv', 30, 60,
                ));
            }
        } finally {
            date_default_timezone_set($original);
        }
    }
}
