<?php

namespace Tests\Unit;

use App\Enums\StudySessionStatus;
use App\Exceptions\StudySessionConflictException;
use App\Services\Planning\StudySessionLifecycleRules;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class StudySessionLifecycleRulesTest extends TestCase
{
    #[DataProvider('transitions')]
    public function test_exact_transition_policy(string $action, StudySessionStatus $status, string $clock, bool $allowed): void
    {
        $rules = new StudySessionLifecycleRules;
        $interval = ['start' => strtotime('2026-10-19T09:00:00Z'), 'end' => strtotime('2026-10-19T10:00:00Z')];
        $before = $interval;
        if (! $allowed) {
            $this->expectException(StudySessionConflictException::class);
        }
        try {
            $result = $rules->transition($action, $status, $interval, new DateTimeImmutable($clock), 45);
            $this->assertSame(match ($action) {
                'complete' => StudySessionStatus::Completed, 'miss' => StudySessionStatus::Missed,
                'cancel' => StudySessionStatus::Cancelled, 'reschedule' => StudySessionStatus::Rescheduled,
            }, $result['status']);
            $this->assertSame($action === 'complete' ? 45 : null, $result['actual_minutes']);
            $this->assertSame($action === 'complete' ? '2026-10-19 '.substr($clock, 11, 8) : null, $result['completed_at']);
        } finally {
            $this->assertSame($before, $interval);
        }
    }

    public static function transitions(): iterable
    {
        foreach (StudySessionStatus::cases() as $status) {
            foreach (['complete', 'miss', 'cancel', 'reschedule'] as $action) {
                foreach (['08:59:59.999999', '09:00:00.000000', '09:00:00.000001', '09:59:59.999999', '10:00:00.000000', '12:00:00.123456'] as $time) {
                    $allowed = $status === StudySessionStatus::Planned && match ($action) {
                        'complete' => $time >= '09:00:00.000000', 'miss' => $time >= '10:00:00.000000',
                        default => $time < '09:00:00.000000',
                    };
                    yield $status->value.' '.$action.' '.$time => [$action, $status, '2026-10-19T'.$time.'Z', $allowed];
                }
            }
        }
    }

    #[DataProvider('badMinutes')]
    public function test_actual_minutes_never_coerce(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudySessionLifecycleRules)->actualMinutes($value);
    }

    public static function badMinutes(): array
    {
        return [[0], [-1], [65536], [1.0], ['45'], [true], [false], [[]], [new \stdClass]];
    }

    public function test_null_actual_retains_fallback_and_maximum_is_valid(): void
    {
        $rules = new StudySessionLifecycleRules;
        $rules->actualMinutes(65535);
        $result = $rules->transition('complete', StudySessionStatus::Planned, ['start' => 1, 'end' => 62], new DateTimeImmutable('@62'));
        $this->assertNull($result['actual_minutes']);
        $this->assertSame('1970-01-01 00:01:02', $result['completed_at']);
    }

    #[DataProvider('badTimestamps')]
    public function test_strict_wire_timestamp(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudySessionLifecycleRules)->timestamp($value);
    }

    public static function badTimestamps(): array
    {
        return array_map(fn ($value) => [$value], [null, 1792400400, true, [], '2026-10-19T09:00:00+00:00',
            '2026-10-19T09:00:00.000Z', '2026-10-19 09:00:00', '2026-02-29T09:00:00Z', '2026-10-19T24:00:00Z',
            '2026-10-19T09:00:60Z', ' 2026-10-19T09:00:00Z', '2026-10-19T09:00:00Z ', '0999-12-31T23:59:59Z', '10000-01-01T00:00:00Z']);
    }

    public function test_timestamp_round_trip_at_mysql_bounds_and_leap_day(): void
    {
        foreach (['1000-01-01T00:00:00Z', '9999-12-31T23:59:59Z', '2028-02-29T09:00:01Z'] as $value) {
            $this->assertSame($value, gmdate('Y-m-d\TH:i:s\Z', (new StudySessionLifecycleRules)->timestamp($value)));
        }
    }

    public function test_valid_chain_and_partial_summary_do_not_modify_inputs(): void
    {
        $records = $this->chain();
        $before = $records;
        $rules = new StudySessionLifecycleRules;
        $rules->validateChain($records, 3);
        $this->assertSame($before, $records);
        $this->assertSame(['original_duration_seconds' => 3600, 'replacement_duration_seconds' => 1800,
            'not_transferred_seconds' => 1800, 'unallocated_work_seconds' => 5400], $rules->replacementSummary(3600, 1800, 7200));
    }

    #[DataProvider('chainCorruption')]
    public function test_invalid_graphs_fail_closed(string $kind): void
    {
        $records = $this->chain();
        match ($kind) {
            'self' => $records[3]['rescheduled_from_session_id'] = 3,
            'cycle' => $records[1]['rescheduled_from_session_id'] = 3,
            'foreign' => $records[1]['user_id'] = 2,
            'task' => $records[2]['task_id'] = 2,
            'child' => $records[2]['subtask_id'] = 2,
            'predecessor_status' => $records[2]['status'] = 'completed',
            'missing' => $records[1]['rescheduled_from_session_id'] = 99,
            'multiple' => $records[4] = [...$records[3]],
            'planned_successor' => $records[4] = [...$records[3], 'rescheduled_from_session_id' => 3],
        };
        $this->expectException(UnexpectedValueException::class);
        (new StudySessionLifecycleRules)->validateChain($records, 3);
    }

    public static function chainCorruption(): array
    {
        return array_map(fn ($kind) => [$kind], ['self', 'cycle', 'foreign', 'task', 'child', 'predecessor_status', 'missing', 'multiple', 'planned_successor']);
    }

    #[DataProvider('invalidSummary')]
    public function test_replacement_cannot_inflate_reservation(int $original, int $replacement, int $unallocated): void
    {
        $this->expectException(StudySessionConflictException::class);
        (new StudySessionLifecycleRules)->replacementSummary($original, $replacement, $unallocated);
    }

    public static function invalidSummary(): array
    {
        return [[60, 0, 60], [60, 61, 120], [60, 60, 59]];
    }

    private function chain(): array
    {
        $row = ['user_id' => 1, 'task_id' => 1, 'subtask_id' => null, 'status' => 'rescheduled', 'rescheduled_from_session_id' => null];

        return [1 => $row, 2 => [...$row, 'rescheduled_from_session_id' => 1],
            3 => [...$row, 'rescheduled_from_session_id' => 2, 'status' => 'planned']];
    }
}
