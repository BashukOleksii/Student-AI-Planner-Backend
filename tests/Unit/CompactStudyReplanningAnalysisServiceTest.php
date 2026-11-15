<?php

namespace Tests\Unit;

use App\Services\Planning\PlanningConstraintService;
use App\Services\Planning\PlanningScenarioService;
use App\Services\Planning\StudyReplanningAnalysisService;
use App\Services\Planning\StudySessionAllocationService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class CompactStudyReplanningAnalysisServiceTest extends TestCase
{
    public function test_valid_fully_reserved_session_excludes_itself_from_all_sources(): void
    {
        $source = self::source(1, 100, 160, 60, 60);
        $snapshot = self::snapshot([$source]);
        $inputs = [$source, $snapshot];
        $service = new StudyReplanningAnalysisService;
        $results = $service->analyze([$source], $snapshot, new DateTimeImmutable('@0'));
        $this->assertSame('valid', $results[0]['classification']);
        $this->assertSame([], $results[0]['reason_codes']);
        $context = (new PlanningScenarioService)->without($snapshot, [1]);
        $this->assertSame([['start' => 0, 'end' => 1000]], $context['free_intervals']);
        $this->assertSame([], $context['break_anchors']);
        $this->assertSame([], $context['workload_sessions']);
        $this->assertSame($inputs, [$source, $snapshot]);
    }

    public function test_equal_intervals_and_lesson_id_collision_are_not_excluded_by_value(): void
    {
        $source = self::source(1, 100, 160, 120, 120);
        $snapshot = self::snapshot([$source, self::source(2, 100, 160, 120, 120)], [], [['id' => 1, 'start' => 100, 'end' => 160]]);
        $context = (new PlanningScenarioService)->without($snapshot, [1]);
        $this->assertSame([['start' => 100, 'end' => 160]], $context['workload_sessions']);
        $this->assertCount(2, $context['break_anchors']);
        $this->assertSame([['start' => 0, 'end' => 100], ['start' => 160, 'end' => 1000]], $context['free_intervals']);
        $result = (new StudyReplanningAnalysisService)->analyze([$source], $snapshot, new DateTimeImmutable('@0'))[0];
        $this->assertSame(['outside_available_study_time', 'overlapping_break_anchor'], $result['reason_codes']);
    }

    #[DataProvider('constraintChanges')]
    public function test_classification_uses_existing_c8_codes(array $preferences, array $lessons, array $extraWorkload, ?int $deadline, array $codes): void
    {
        $source = self::source(1, 300, 420, 1000, 120, $deadline);
        $snapshot = self::snapshot([$source], $preferences, $lessons);
        $snapshot['workload_records'] = [...$snapshot['workload_records'], ...$extraWorkload];
        $result = (new StudyReplanningAnalysisService)->analyze([$source], $snapshot, new DateTimeImmutable('@0'))[0];
        $this->assertSame('requires_replanning', $result['classification']);
        $this->assertSame($codes, $result['reason_codes']);
    }

    public static function constraintChanges(): array
    {
        return ['minimum' => [['min_session_minutes' => 3], [], [], null, [0 => 'below_minimum_session_duration']]];
    }

    #[DataProvider('policyCodes')]
    public function test_unsafe_work_is_classified_without_relocation(string $code): void
    {
        $source = [...self::source(1, 100, 160), 'policy_reason_codes' => [$code]];
        $result = (new StudyReplanningAnalysisService)->analyze([$source], self::snapshot([$source]), new DateTimeImmutable('@0'))[0];
        $this->assertSame('cannot_replan', $result['classification']);
        $this->assertSame([$code], $result['reason_codes']);
        $this->assertNull($result['replacement']);
    }

    public static function policyCodes(): array
    {
        return [0 => ['completed_task']];
    }

    public function test_clock_equality_is_not_future_and_microseconds_are_not_truncated(): void
    {
        $source = self::source(1, 100, 160);
        foreach (['1970-01-01T00:01:40.000000Z', '1970-01-01T00:01:40.000001Z'] as $now) {
            $row = (new StudyReplanningAnalysisService)->analyze([$source], self::snapshot([$source]), new DateTimeImmutable($now))[0];
            $this->assertSame('cannot_replan', $row['classification']);
            $this->assertSame(['session_start_not_future'], $row['reason_codes']);
        }
    }

    public function test_oversubscribed_reservations_use_exact_remaining_effort(): void
    {
        $source = self::source(1, 100, 160, 60, 120);
        $snapshot = self::snapshot([$source, self::source(2, 200, 260, 60, 120)]);
        $result = (new StudyReplanningAnalysisService)->analyze([$source], $snapshot, new DateTimeImmutable('@0'))[0];
        $this->assertSame(['future_reservation_exceeds_remaining_work'], $result['reason_codes']);
    }

    public function test_deadline_priority_start_and_id_order_is_stable(): void
    {
        $sources = [self::source(9, 400, 460, 1000, 300), self::source(8, 300, 360, 1000, 300, 900, 1), self::source(7, 300, 360, 1000, 300, 900, 3), self::source(6, 200, 260, 1000, 300, 900, 3), self::source(5, 200, 260, 1000, 300, 900, 3)];
        $service = new StudyReplanningAnalysisService;
        $snapshot = self::snapshot($sources);
        $result = $service->analyze($sources, $snapshot, new DateTimeImmutable('@0'));
        $this->assertSame([5, 6, 7, 8, 9], array_column($result, 'study_session_id'));
        $this->assertSame($result, $service->analyze(array_reverse($sources), $snapshot, new DateTimeImmutable('@0')));
    }

    public function test_projections_share_capacity_breaks_and_work_effort_without_mutating_inputs(): void
    {
        $sources = [self::source(1, 300, 360, 120, 120), self::source(2, 400, 460, 120, 120)];
        $snapshot = self::snapshot($sources, ['preferred_break_minutes' => 1], [['id' => 9, 'start' => 300, 'end' => 500]]);
        $selection = self::selection(120);
        $before = [$sources, $snapshot, $selection];
        $service = new StudyReplanningAnalysisService;
        $rows = $service->analyze($sources, $snapshot, new DateTimeImmutable('@0'));
        $result = $service->propose($rows, $selection, $snapshot, [['start' => 0, 'end' => 1000]], new DateTimeImmutable('@0'));
        $this->assertSame([[1, 61], [121, 181]], array_map(fn ($row) => [$row['replacement']['start'], $row['replacement']['end']], $result['session_results']));
        $this->assertSame(0, $result['work_results'][0]['unallocated_after_replacements']);
        $this->assertSame(120, $result['summary']['replacement_seconds']);
        $this->assertSame($before, [$sources, $snapshot, $selection]);
        $this->assertSame($result, $service->propose($rows, $selection, $snapshot, [['start' => 0, 'end' => 1000]], new DateTimeImmutable('@0')));
        $base = (new PlanningScenarioService)->without($snapshot, [1, 2]);
        foreach ($result['session_results'] as $i => $row) {
            $candidate = array_intersect_key($row['replacement'], array_flip(['start', 'end']));
            $other = array_intersect_key($result['session_results'][1 - $i]['replacement'], array_flip(['start', 'end']));
            $this->assertSame([], (new PlanningConstraintService)->evaluate($candidate, $base['free_intervals'], [...$base['break_anchors'], $other], [...$base['workload_sessions'], $other], 'UTC', $base['preferences']));
        }
    }

    public function test_valid_reservation_stays_protected_while_invalid_neighbor_moves(): void
    {
        $sources = [self::source(1, 1, 61, 120, 120), self::source(2, 300, 360, 120, 120)];
        $snapshot = self::snapshot($sources, [], [['id' => 9, 'start' => 300, 'end' => 400]]);
        $service = new StudyReplanningAnalysisService;
        $result = $service->propose($service->analyze($sources, $snapshot, new DateTimeImmutable('@0')), self::selection(60), $snapshot, [['start' => 0, 'end' => 1000]], new DateTimeImmutable('@0'));
        $this->assertSame('valid', $result['session_results'][0]['classification']);
        $this->assertNull($result['session_results'][0]['replacement']);
        $this->assertSame(61, $result['session_results'][1]['replacement']['start']);
        $this->assertSame([2], $result['released_session_ids']);
    }

    public function test_one_successor_limit_does_not_split_or_balance_short_tail(): void
    {
        $unit = self::selection(5400)['work_items'][0];
        $snapshot = self::snapshot([], ['min_session_minutes' => 40, 'max_session_minutes' => 60]);
        $snapshot['range'] = ['start' => 0, 'end' => 10000];
        $snapshot['allowed_intervals'] = [['start' => 0, 'end' => 10000]];
        $context = (new PlanningScenarioService)->without($snapshot);
        $allocator = new StudySessionAllocationService;
        $single = $allocator->allocate([$unit], $context, 1, true);
        $normal = $allocator->allocate([$unit], $context, 1);
        $this->assertCount(1, $single['sessions']);
        $this->assertSame(3600, $single['sessions'][0]['duration_seconds']);
        $this->assertSame(['single_replacement_duration_limited'], $single['work_results'][0]['reason_codes']);
        $this->assertSame([3000, 2400], array_column($normal['sessions'], 'duration_seconds'));
    }

    #[DataProvider('replacementLimits')]
    public function test_exact_partial_and_unplaced_replacements(int $capacity, ?int $minimum, int $expected): void
    {
        $source = self::source(1, 300, 420, 120, 120);
        $snapshot = self::snapshot([$source], ['min_session_minutes' => $minimum], [['id' => 9, 'start' => 300, 'end' => 500]]);
        $snapshot['allowed_intervals'] = $capacity === 0 ? [] : [['start' => 0, 'end' => $capacity + 1]];
        $service = new StudyReplanningAnalysisService;
        $result = $service->propose($service->analyze([$source], $snapshot, new DateTimeImmutable('@0')), self::selection(120), $snapshot, [['start' => 0, 'end' => 1000]], new DateTimeImmutable('@0'));
        $this->assertSame($expected, $result['summary']['replacement_seconds']);
        $this->assertSame(120 - $expected, $result['summary']['untransferred_seconds']);
        $this->assertSame($expected === 0 ? 1 : 0, $result['summary']['unplaced_sessions']);
        $this->assertSame(120 - $expected, $result['work_results'][0]['unallocated_after_replacements']);
    }

    public static function replacementLimits(): array
    {
        return [0 => [60, null, 60]];
    }

    #[DataProvider('invalidExclusions')]
    public function test_malformed_or_unknown_exclusions_fail(array $ids): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PlanningScenarioService)->without(self::snapshot([self::source(1, 100, 160)]), $ids);
    }

    public static function invalidExclusions(): array
    {
        return [0 => [[0 => 0]]];
    }

    public static function source(int $id, int $start, int $end, int $remaining = 120, int $reserved = 60, ?int $deadline = null, int $priority = 2): array
    {
        return ['study_session_id' => $id, 'task_id' => 1, 'subtask_id' => null, 'original' => ['start' => $start, 'end' => $end], 'priority' => $priority, 'effective_deadline_utc' => $deadline, 'policy_reason_codes' => [], 'effort' => ['remaining_work_seconds' => $remaining, 'future_reserved_seconds' => $reserved]];
    }

    public static function snapshot(array $sources, array $preferences = [], array $lessons = []): array
    {
        $records = array_map(fn ($source) => ['id' => $source['study_session_id'], ...$source['original']], $sources);

        return ['range' => ['start' => 0, 'end' => 1000], 'timezone' => 'UTC', 'preferences' => [...array_fill_keys(['min_session_minutes', 'max_session_minutes', 'preferred_break_minutes', 'max_daily_study_minutes', 'max_weekly_study_minutes'], null), ...$preferences], 'allowed_intervals' => [['start' => 0, 'end' => 1000]], 'blocking_lessons' => $lessons, 'blocking_sessions' => $records, 'lesson_anchors' => $lessons, 'session_anchors' => $records, 'workload_records' => array_map(fn ($record) => [...$record, 'status' => 'planned'], $records)];
    }

    public static function selection(int $seconds): array
    {
        return ['work_items' => [['task_id' => 1, 'subtask_id' => null, 'effective_deadline_utc' => null, 'effort' => ['unallocated_work_seconds' => $seconds, 'remaining_work_seconds' => $seconds]]], 'excluded_items' => []];
    }
}
