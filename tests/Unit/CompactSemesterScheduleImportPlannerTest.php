<?php

namespace Tests\Unit;

use App\Services\Imports\SemesterScheduleImportPlanner;
use App\Services\Schedule\EffectiveScheduleCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class CompactSemesterScheduleImportPlannerTest extends TestCase
{
    private function planner(): SemesterScheduleImportPlanner
    {
        return new SemesterScheduleImportPlanner(new EffectiveScheduleCalculator);
    }

    private function rule(array $changes = []): array
    {
        return array_replace(['weekday' => 1, 'pair_number' => 1, 'pair_label' => '1-2', 'week_pattern' => 'all', 'starts_at_local' => '08:30:00', 'ends_at_local' => '09:50:00', 'subject_id' => 10, 'teacher_id' => 20, 'room' => null, 'errors' => []], $changes);
    }

    private function managed(array $changes = []): array
    {
        $values = $this->planner()->values($this->rule());

        return array_replace(['id' => 1, ...$values, 'import_key' => '1:1:all', 'import_baseline' => $values, 'semester_schedule_import_id' => 1], $changes);
    }

    #[DataProvider('pairs')]
    public function test_pair_labels_map_explicitly_to_numbers(string $label, int $number): void
    {
        $this->assertSame($number, $this->planner()->pairNumber($label));
    }

    public static function pairs(): array
    {
        return [0 => ['1-2', 1]];
    }

    public function test_unsupported_pair_is_never_inferred(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->planner()->pairNumber('9-10');
    }

    public function test_new_slot_is_created_after_deleting_old_slot_regardless_of_changes(): void
    {
        $row = $this->rule(['subject_id' => 11, 'room' => 'NEW', 'starts_at_local' => '09:00:00', 'ends_at_local' => '10:00:00']);
        $plan = $this->planner()->plan([$row], [$this->managed()]);
        $this->assertSame('1:1:all', $plan['rows'][0]['import_key']);
        $this->assertSame('create', $plan['rows'][0]['operation']);
        $this->assertSame(['delete', 'create'], array_column($plan['operations'], 'operation'));
        $this->assertNull($plan['rows'][0]['matched_rule_id']);
        $this->assertSame([], $plan['errors']);
    }

    public function test_reordered_baseline_keys_are_not_a_manual_edit(): void
    {
        $rule = $this->managed();
        $rule['import_baseline'] = array_reverse($rule['import_baseline'], true);
        $this->assertTrue($this->planner()->managed($rule));
        $this->assertSame('create', $this->planner()->plan([$this->rule()], [$rule])['rows'][0]['operation']);
    }

    public function test_all_old_rules_are_deleted_and_empty_import_is_invalid(): void
    {
        $manual = ['id' => 2, ...$this->rule(['weekday' => 2]), 'import_key' => null];
        $edited = $this->managed(['id' => 3, 'weekday' => 3, 'room' => 'Manual']);
        $plan = $this->planner()->plan([], [$this->managed(), $manual, $edited]);
        $this->assertSame([1, 2, 3], array_column($plan['operations'], 'rule_id'));
        $this->assertSame('delete', $plan['operations'][0]['operation']);
        $this->assertSame([], $plan['projected_rules']);
        $this->assertNotEmpty($plan['errors']['file']);
    }

    public function test_manual_rule_is_replaced_even_when_identical(): void
    {
        $manual = ['id' => 2, ...$this->rule(), 'import_key' => null];
        $same = $this->planner()->plan([$this->rule()], [$manual]);
        $this->assertSame('create', $same['rows'][0]['operation']);
        $this->assertSame([], $same['rows'][0]['errors']);
        $different = $this->planner()->plan([$this->rule(['room' => 'NEW'])], [$manual]);
        $this->assertSame([], $different['rows'][0]['errors']);
        $this->assertSame('NEW', $different['projected_rules'][0]['room']);
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
        $rows = [$this->rule(['week_pattern' => 'numerator']), $this->rule(['week_pattern' => 'denominator']), $this->rule(['pair_number' => 2, 'starts_at_local' => '09:50:00', 'ends_at_local' => '11:00:00'])];
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

    public function test_explicit_manual_flag_does_not_protect_a_rule_from_replacement(): void
    {
        $rule = $this->managed(['is_manually_managed' => true]);
        $this->assertFalse($this->planner()->managed($rule));
        $this->assertSame('create', $this->planner()->plan([$this->rule()], [$rule])['rows'][0]['operation']);
        $this->assertSame('delete', $this->planner()->plan([], [$rule])['operations'][0]['operation']);
    }
}
