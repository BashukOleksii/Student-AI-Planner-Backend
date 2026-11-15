<?php

namespace Tests\Unit;

use App\Enums\StudySessionStatus;
use App\Exceptions\StudySessionConflictException;
use App\Services\Planning\StudySessionLifecycleRules;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[Group('unit')]
class CompactStudySessionLifecycleRulesTest extends TestCase
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
                'complete' => StudySessionStatus::Completed,
                'miss' => StudySessionStatus::Missed,
                'cancel' => StudySessionStatus::Cancelled,
                'reschedule' => StudySessionStatus::Rescheduled,
            }, $result['status']);
            $this->assertSame($action === 'complete' ? 45 : null, $result['actual_minutes']);
            $this->assertSame($action === 'complete' ? '2026-10-19 '.substr($clock, 11, 8) : null, $result['completed_at']);
        } finally {
            $this->assertSame($before, $interval);
        }
    }

    public static function transitions(): array
    {
        return ['planned complete 08:59:59.999999' => ['complete', StudySessionStatus::Planned, '2026-10-19T08:59:59.999999Z', false], 'cancelled reschedule 12:00:00.123456' => ['reschedule', StudySessionStatus::Cancelled, '2026-10-19T12:00:00.123456Z', false]];
    }

    #[DataProvider('badMinutes')]
    public function test_actual_minutes_never_coerce(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudySessionLifecycleRules)->actualMinutes($value);
    }

    public static function badMinutes(): array
    {
        return [0 => [0], 8 => [(object) []]];
    }

    public function test_new_completion_requires_reported_actual_and_maximum_is_valid(): void
    {
        $rules = new StudySessionLifecycleRules;
        $rules->actualMinutes(65535);
        $result = $rules->transition('complete', StudySessionStatus::Planned, ['start' => 1, 'end' => 62], new DateTimeImmutable('@62'), 65535);
        $this->assertSame(65535, $result['actual_minutes']);
        $this->assertSame('1970-01-01 00:01:02', $result['completed_at']);
        $this->expectException(InvalidArgumentException::class);
        $rules->transition('complete', StudySessionStatus::Planned, ['start' => 1, 'end' => 62], new DateTimeImmutable('@62'));
    }

    #[DataProvider('badTimestamps')]
    public function test_strict_wire_timestamp(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StudySessionLifecycleRules)->timestamp($value);
    }

    public static function badTimestamps(): array
    {
        return [0 => [null], 13 => ['10000-01-01T00:00:00Z']];
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
        $this->assertSame(['original_duration_seconds' => 3600, 'replacement_duration_seconds' => 1800, 'not_transferred_seconds' => 1800, 'unallocated_work_seconds' => 5400], $rules->replacementSummary(3600, 1800, 7200));
    }

    public function test_split_history_accepts_multiple_successors(): void
    {
        $records = $this->chain();
        $records[4] = $records[3];
        (new StudySessionLifecycleRules)->validateChain($records, 4);
        $this->assertCount(4, $records);
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
            'multiple foreign work' => $records[4] = [...$records[3], 'task_id' => 2],
            'planned_successor' => $records[4] = [...$records[3], 'rescheduled_from_session_id' => 3],
        };
        $this->expectException(UnexpectedValueException::class);
        (new StudySessionLifecycleRules)->validateChain($records, 3);
    }

    public static function chainCorruption(): array
    {
        return [0 => ['self'], 8 => ['planned_successor']];
    }

    #[DataProvider('invalidSummary')]
    public function test_replacement_cannot_inflate_reservation(int $original, int $replacement, int $unallocated): void
    {
        $this->expectException(StudySessionConflictException::class);
        (new StudySessionLifecycleRules)->replacementSummary($original, $replacement, $unallocated);
    }

    public static function invalidSummary(): array
    {
        return [0 => [60, 0, 60]];
    }

    private function chain(): array
    {
        $row = ['user_id' => 1, 'task_id' => 1, 'subtask_id' => null, 'status' => 'rescheduled', 'rescheduled_from_session_id' => null];

        return [1 => $row, 2 => [...$row, 'rescheduled_from_session_id' => 1], 3 => [...$row, 'rescheduled_from_session_id' => 2, 'status' => 'planned']];
    }
}
