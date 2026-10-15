<?php

namespace Tests\Unit;

use App\Services\Planning\AvailabilityOccurrenceService;
use App\Services\Planning\FreeTimeService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\TimeIntervalService;
use App\Services\Progress\ProgressStatisticsService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProgressStatisticsServiceTest extends TestCase
{
    private function service(): ProgressStatisticsService
    {
        $intervals = new TimeIntervalService;
        $availability = new AvailabilityOccurrenceService($intervals);

        return new ProgressStatisticsService(new PlanningContextService($intervals, $availability,
            new FreeTimeService($availability, $intervals)), $intervals);
    }

    private function coverage(): array
    {
        return [['start' => strtotime('2026-10-15T00:00:00Z'), 'end' => strtotime('2026-10-16T00:00:00Z')]];
    }

    #[DataProvider('completions')]
    public function test_completion_classification_uses_status_and_exact_persisted_instants(string $status, ?string $completed, ?string $deadline, array $expected): void
    {
        $this->assertSame($expected, $this->service()->completionMetrics([
            ['status' => $status, 'completed_at' => $completed, 'deadline_at' => $deadline],
        ], $this->coverage()));
        $this->assertSame($expected['total'], $expected['on_time'] + $expected['late'] + $expected['without_deadline']);
    }

    public static function completions(): iterable
    {
        $zero = ['total' => 0, 'on_time' => 0, 'late' => 0, 'without_deadline' => 0];
        foreach (['on_time' => '2026-10-15 12:00:01', 'equality' => '2026-10-15 12:00:00',
            'late' => '2026-10-15 11:59:59', 'without_deadline' => null] as $kind => $deadline) {
            $key = $kind === 'equality' ? 'on_time' : $kind;
            yield $kind => ['completed', '2026-10-15 12:00:00', $deadline, array_replace($zero, ['total' => 1, $key => 1])];
        }
        yield 'pending with no event' => ['pending', null, null, $zero];
        yield 'pending does not invent old completion' => ['pending', '2026-10-15 12:00:00', null, $zero];
        yield 'lower equality' => ['completed', '2026-10-15 00:00:00', null, array_replace($zero, ['total' => 1, 'without_deadline' => 1])];
        yield 'before lower' => ['completed', '2026-10-14 23:59:59', null, $zero];
        yield 'upper equality' => ['completed', '2026-10-16 00:00:00', null, $zero];
        yield 'before upper' => ['completed', '2026-10-15 23:59:59', null, array_replace($zero, ['total' => 1, 'without_deadline' => 1])];
    }

    #[DataProvider('sessionStatuses')]
    public function test_full_scheduled_seconds_are_separate_from_known_actual_minutes(string $status, ?int $actual): void
    {
        $record = ['status' => $status, 'starts_at' => '2026-10-15 23:59:59',
            'ends_at' => '2026-10-16 01:00:00', 'actual_minutes' => $actual];
        $result = $this->service()->sessionMetrics([$record], $this->coverage());
        foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $key) {
            $this->assertSame(['count' => $status === $key ? 1 : 0, 'scheduled_seconds' => $status === $key ? 3601 : 0], $result[$key]);
        }
        $this->assertSame($status === 'completed' ? ($actual ?? 0) : 0, $result['completed_actual_minutes']);
        $this->assertSame((int) ($status === 'completed' && $actual !== null), $result['completed_with_actual_minutes']);
        $this->assertSame((int) ($status === 'completed' && $actual === null), $result['completed_without_actual_minutes']);
    }

    public static function sessionStatuses(): array
    {
        return [['planned', null], ['completed', null], ['completed', 1], ['completed', 90], ['completed', 65535],
            ['missed', null], ['rescheduled', null], ['cancelled', null]];
    }

    public function test_start_attribution_excludes_overlapping_preperiod_and_upper_boundary_sessions(): void
    {
        $records = [];
        foreach (['2026-10-14 23:59:59', '2026-10-15 00:00:00', '2026-10-16 00:00:00'] as $start) {
            $records[] = ['status' => 'completed', 'starts_at' => $start, 'ends_at' => '2026-10-16 01:00:00', 'actual_minutes' => 2];
        }
        $result = $this->service()->sessionMetrics($records, $this->coverage());
        $this->assertSame(['count' => 1, 'scheduled_seconds' => 90000], $result['completed']);
        $this->assertSame(2, $result['completed_actual_minutes']);
    }

    public function test_empty_data_and_skipped_membership_have_exact_zero_totals(): void
    {
        $service = $this->service();
        $this->assertSame(['total' => 0, 'on_time' => 0, 'late' => 0, 'without_deadline' => 0], $service->completionMetrics([], []));
        $result = $service->sessionMetrics([], []);
        $this->assertCount(8, $result);
        foreach (['planned', 'completed', 'missed', 'rescheduled', 'cancelled'] as $status) {
            $this->assertSame(['count' => 0, 'scheduled_seconds' => 0], $result[$status]);
        }
        $this->assertSame(0, $result['completed_actual_minutes']);
        $this->assertSame(0, $result['completed_with_actual_minutes']);
        $this->assertSame(0, $result['completed_without_actual_minutes']);
    }

    public function test_disjoint_membership_does_not_count_envelope_gaps(): void
    {
        $coverage = [['start' => 0, 'end' => 10], ['start' => 20, 'end' => 30]];
        $rows = [];
        foreach ([0, 9, 10, 19, 20, 29, 30] as $second) {
            $rows[] = ['status' => 'completed', 'completed_at' => gmdate('Y-m-d H:i:s', $second), 'deadline_at' => null];
        }
        $this->assertSame(4, $this->service()->completionMetrics($rows, $coverage)['total']);
    }

    public function test_calculations_are_deterministic_utc_and_do_not_mutate_inputs_or_combine_parent_child_metrics(): void
    {
        $service = $this->service();
        $parent = [['status' => 'pending', 'completed_at' => null, 'deadline_at' => null]];
        $children = [['status' => 'completed', 'completed_at' => '2026-10-15 12:00:00', 'deadline_at' => null]];
        $sessions = [['status' => 'completed', 'starts_at' => '2026-10-15 00:00:00', 'ends_at' => '2026-10-15 00:01:01', 'actual_minutes' => null]];
        $before = [$parent, $children, $sessions];
        $original = date_default_timezone_get();
        try {
            foreach (['UTC', 'Pacific/Auckland', 'America/New_York'] as $timezone) {
                date_default_timezone_set($timezone);
                $this->assertSame(0, $service->completionMetrics($parent, $this->coverage())['total']);
                $this->assertSame(1, $service->completionMetrics($children, $this->coverage())['without_deadline']);
                $this->assertSame(['count' => 1, 'scheduled_seconds' => 61], $service->sessionMetrics($sessions, $this->coverage())['completed']);
            }
        } finally {
            date_default_timezone_set($original);
        }
        $this->assertSame($before, [$parent, $children, $sessions]);
    }

    #[DataProvider('invalidUtc')]
    public function test_invalid_persisted_timestamps_fail_instead_of_normalizing(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->completionMetrics([['status' => 'completed', 'completed_at' => $value, 'deadline_at' => null]], $this->coverage());
    }

    public static function invalidUtc(): array
    {
        return [['2026-02-29 12:00:00'], ['2026-10-15 12:00:00.000001'], ['2026-10-15T12:00:00Z'], ['0000-00-00 00:00:00']];
    }
}
