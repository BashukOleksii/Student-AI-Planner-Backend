<?php

namespace Tests\Unit;

use App\Services\Imports\SemesterScheduleImportPlanner;
use App\Services\Schedule\EffectiveScheduleCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SemesterScheduleImportPlannerTest extends TestCase
{
    private function planner(): SemesterScheduleImportPlanner
    {
        return new SemesterScheduleImportPlanner(new EffectiveScheduleCalculator);
    }

    private function rule(array $changes = []): array
    {
        return array_replace(['weekday' => 1, 'pair_number' => 1, 'pair_label' => '1-2', 'week_pattern' => 'all',
            'starts_at_local' => '08:30:00', 'ends_at_local' => '09:50:00', 'subject_id' => 10, 'teacher_id' => 20, 'room' => null,
            'errors' => []], $changes);
    }

    private function managed(array $changes = []): array
    {
        $values = $this->planner()->values($this->rule());

        return array_replace(['id' => 1, ...$values, 'import_key' => '1:1:all', 'import_baseline' => $values,
            'semester_schedule_import_id' => 1], $changes);
    }

    #[DataProvider('pairs')]
    public function test_pair_labels_map_explicitly_to_numbers(string $label, int $number): void
    {
        $this->assertSame($number, $this->planner()->pairNumber($label));
    }

    public static function pairs(): array
    {
        return [['1-2', 1], ['3-4', 2], ['5-6', 3], ['7-8', 4]];
    }

    public function test_unsupported_pair_is_never_inferred(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->planner()->pairNumber('9-10');
    }

    public function test_slot_identity_survives_catalog_time_and_room_changes(): void
    {
        $row = $this->rule(['subject_id' => 11, 'room' => 'NEW', 'starts_at_local' => '09:00:00', 'ends_at_local' => '10:00:00']);
        $plan = $this->planner()->plan([$row], [$this->managed()]);
        $this->assertSame('1:1:all', $plan['rows'][0]['import_key']);
        $this->assertSame('update', $plan['rows'][0]['operation']);
        $this->assertSame(1, $plan['rows'][0]['matched_rule_id']);
        $this->assertSame([], $plan['errors']);
    }

    public function test_reordered_baseline_keys_are_not_a_manual_edit(): void
    {
        $rule = $this->managed();
        $rule['import_baseline'] = array_reverse($rule['import_baseline'], true);
        $this->assertTrue($this->planner()->managed($rule));
        $this->assertSame('noop', $this->planner()->plan([$this->rule()], [$rule])['rows'][0]['operation']);
    }

    public function test_manual_and_edited_missing_rows_are_preserved_while_removed_managed_rows_are_deleted(): void
    {
        $manual = ['id' => 2, ...$this->rule(['weekday' => 2]), 'import_key' => null];
        $edited = $this->managed(['id' => 3, 'weekday' => 3, 'room' => 'Manual']);
        $plan = $this->planner()->plan([], [$this->managed(), $manual, $edited]);
        $this->assertSame([1], array_column($plan['operations'], 'rule_id'));
        $this->assertSame('delete', $plan['operations'][0]['operation']);
        $this->assertSame([2, 3], array_column($plan['projected_rules'], 'id'));
    }

    public function test_identical_manual_rule_satisfies_import_without_adoption_but_difference_blocks(): void
    {
        $manual = ['id' => 2, ...$this->rule(), 'import_key' => null];
        $same = $this->planner()->plan([$this->rule()], [$manual]);
        $this->assertSame('preserve', $same['rows'][0]['operation']);
        $this->assertSame([], $same['rows'][0]['errors']);
        $different = $this->planner()->plan([$this->rule(['room' => 'NEW'])], [$manual]);
        $this->assertArrayHasKey('protected_rule', $different['rows'][0]['errors']);
        $this->assertSame(null, $different['projected_rules'][0]['room']);
    }

    public function test_identical_duplicates_are_classified_and_contradictions_are_errors(): void
    {
        $same = $this->planner()->plan([$this->rule(), $this->rule()], []);
        $this->assertSame(1, $same['counts']['create']);
        $this->assertSame(1, $same['counts']['duplicate']);
        $this->assertCount(1, $same['projected_rules']);
        $different = $this->planner()->plan([$this->rule(), $this->rule(['room' => 'DIFFERENT'])], []);
        $this->assertArrayHasKey('duplicate', $different['rows'][1]['errors']);
    }

    public function test_adjacent_intervals_and_alternating_slots_are_valid_but_all_week_overlap_is_not(): void
    {
        $rows = [$this->rule(['week_pattern' => 'numerator']), $this->rule(['week_pattern' => 'denominator']),
            $this->rule(['pair_number' => 2, 'starts_at_local' => '09:50:00', 'ends_at_local' => '11:00:00'])];
        $this->assertSame([], $this->planner()->plan($rows, [])['errors']);
        $rows[] = $this->rule(['pair_number' => 3, 'starts_at_local' => '09:49:59', 'ends_at_local' => '09:50:01']);
        $this->assertArrayHasKey('schedule', $this->planner()->plan($rows, [])['errors']);
    }

    public function test_missing_catalog_resolution_is_not_silently_written(): void
    {
        $result = $this->planner()->plan([$this->rule(['subject_id' => null, 'errors' => ['subject_name' => ['Missing subject']]])], []);
        $this->assertSame([], $result['operations']);
        $this->assertSame([], $result['projected_rules']);
        $this->assertNotEmpty($result['rows'][0]['errors']);
    }

    public function test_semester_validation_detects_a_skipped_weekday_far_after_the_first_two_weeks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new EffectiveScheduleCalculator)->validateSemester(['id' => 1, 'starts_on' => '2011-01-01', 'ends_on' => '2011-12-31',
            'parity_anchor_on' => '2011-01-03', 'first_week_parity' => 'numerator'],
            [['id' => 1, ...$this->rule(['weekday' => 5])]], [], 'Pacific/Apia');
    }

    public function test_empty_exception_can_cancel_a_skipped_day_and_ordinary_long_range_is_valid(): void
    {
        (new EffectiveScheduleCalculator)->validateSemester(['id' => 1, 'starts_on' => '2011-01-01', 'ends_on' => '2011-12-31',
            'parity_anchor_on' => '2011-01-03', 'first_week_parity' => 'numerator'],
            [['id' => 1, ...$this->rule(['weekday' => 5])]],
            ['2011-12-30' => ['id' => 1, 'occurs_on' => '2011-12-30', 'kind' => 'replacement', 'lessons' => []]], 'Pacific/Apia');
        $this->addToAssertionCount(1);
    }

    public function test_semester_validation_checks_frozen_exception_dst_and_period_bounds(): void
    {
        $config = ['id' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31',
            'parity_anchor_on' => '2026-01-05', 'first_week_parity' => 'numerator'];
        $snapshot = ['id' => 1, 'occurs_on' => '2026-03-29', 'kind' => 'replacement',
            'lessons' => [['id' => 1, ...$this->rule(['starts_at_local' => '03:30:00', 'ends_at_local' => '05:00:00'])]]];
        $this->expectException(InvalidArgumentException::class);
        (new EffectiveScheduleCalculator)->validateSemester($config, [], ['2026-03-29' => $snapshot], 'Europe/Kyiv');
    }
}
