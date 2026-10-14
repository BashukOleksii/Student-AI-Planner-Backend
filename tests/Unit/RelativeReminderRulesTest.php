<?php

namespace Tests\Unit;

use App\Enums\ReminderStatus;
use App\Services\Reminders\ReminderRules;
use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RelativeReminderRulesTest extends TestCase
{
    #[DataProvider('offsets')]
    public function test_exact_offset_types_and_signed_smallint(mixed $offset, bool $valid): void
    {
        if (! $valid) {
            $this->expectException(InvalidArgumentException::class);
        }
        (new ReminderRules)->offset($offset);
        $this->assertTrue($valid);
    }

    public static function offsets(): array
    {
        return [[-32768, true], [32767, true], [-60, true], [0, true], [30, true],
            [-32769, false], [32768, false], ['0', false], ['-60', false], [0.0, false], [-60.0, false],
            [true, false], [false, false], [null, false], [[], false], [new \stdClass, false], [PHP_INT_MAX, false]];
    }

    #[DataProvider('arithmetic')]
    public function test_exact_utc_arithmetic(string $anchor, int $offset, string $expected): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Kyiv');
        try {
            $rules = new ReminderRules;
            $this->assertSame($expected, $rules->resolve($anchor, $offset));
            $this->assertSame($offset * 60, $rules->timestamp($expected) - $rules->timestamp($anchor));
            $this->assertSame($expected, $rules->wireTime($rules->databaseTime($expected)));
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public static function arithmetic(): array
    {
        return [['2026-10-20T12:00:00Z', -60, '2026-10-20T11:00:00Z'],
            ['2026-10-20T12:00:00Z', 0, '2026-10-20T12:00:00Z'], ['2026-10-20T12:00:00Z', 30, '2026-10-20T12:30:00Z'],
            ['2028-03-01T00:00:00Z', -1, '2028-02-29T23:59:00Z'],
            ['2026-12-31T23:45:00Z', 30, '2027-01-01T00:15:00Z'],
            ['2026-03-29T00:30:00Z', 60, '2026-03-29T01:30:00Z'],
            ['2026-10-25T00:30:00Z', 60, '2026-10-25T01:30:00Z'],
            ['1000-01-01T00:00:00Z', 0, '1000-01-01T00:00:00Z'], ['9999-12-31T23:59:59Z', 0, '9999-12-31T23:59:59Z'],
            ['2026-11-20T12:00:00Z', -32768, '2026-10-28T17:52:00Z'],
            ['2026-11-20T12:00:00Z', 32767, '2026-12-13T06:07:00Z']];
    }

    #[DataProvider('overflows')]
    public function test_overflow_is_rejected_without_clamping(string $anchor, int $offset): void
    {
        $this->expectException(OverflowException::class);
        (new ReminderRules)->resolve($anchor, $offset);
    }

    public static function overflows(): array
    {
        return [['1000-01-01T00:00:00Z', -1], ['9999-12-31T23:59:59Z', 1]];
    }

    #[DataProvider('missingAnchors')]
    public function test_missing_or_invalid_anchor_is_not_invented(?string $anchor): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ReminderRules)->resolve($anchor, 0);
    }

    public static function missingAnchors(): array
    {
        return [[null], [''], ['2026-02-29T00:00:00Z'], ['2026-10-20 12:00:00'], ['2026-10-20T12:00:00+03:00']];
    }

    public function test_anchor_compatibility_and_creation_modes_leave_input_unchanged(): void
    {
        $rules = new ReminderRules;
        foreach (ReminderRules::TARGETS as $target) {
            foreach (['task_deadline', 'subtask_deadline', 'session_start'] as $anchor) {
                $data = [$target => 1, 'anchor' => $anchor, 'offset_minutes' => -60, 'message' => null];
                $before = $data;
                $errors = $rules->errors($data, true);
                $this->assertSame($rules->anchorFor($target)->value !== $anchor, isset($errors['anchor']));
                $this->assertSame($before, $data);
            }
        }
        $this->assertSame([], $rules->errors(['message' => 'General', 'trigger_at' => '2026-10-20T12:00:00Z'], true));
        $this->assertArrayHasKey('anchor', $rules->errors(['message' => 'General', 'trigger_at' => '2026-10-20T12:00:00Z', 'anchor' => null], true));
        $this->assertArrayHasKey('target', $rules->errors(['anchor' => 'task_deadline', 'offset_minutes' => 0], true));
        $this->assertArrayHasKey('trigger_at', $rules->errors(['task_id' => 1, 'anchor' => 'task_deadline', 'offset_minutes' => 0, 'trigger_at' => null], true));
        $this->assertArrayHasKey('offset_minutes', $rules->errors(['task_id' => 1, 'anchor' => 'task_deadline'], true));
        $this->assertArrayHasKey('anchor', $rules->errors(['task_id' => 1, 'offset_minutes' => 0], true));
        foreach ([true, [], 1, 'unknown'] as $anchor) {
            $this->assertArrayHasKey('anchor', $rules->errors(['task_id' => 1, 'anchor' => $anchor, 'offset_minutes' => 0], true));
        }
    }

    public function test_patch_preserves_mode_and_identity(): void
    {
        $rules = new ReminderRules;
        $this->assertSame([], $rules->errors(['offset_minutes' => 0, 'message' => null], false, true));
        $this->assertArrayHasKey('trigger_at', $rules->errors(['trigger_at' => '2026-10-20T12:00:00Z'], false, true));
        $this->assertArrayHasKey('offset_minutes', $rules->errors(['offset_minutes' => 0], false, false));
        foreach (['anchor', ...ReminderRules::TARGETS, 'status', 'user_id', 'sent_at'] as $field) {
            $this->assertArrayHasKey($field, $rules->errors([$field => null], false, true));
        }
    }

    #[DataProvider('decisions')]
    public function test_recalculation_or_cancellation_preserves_history(?string $anchor, int $offset, bool $cancel): void
    {
        $rules = new ReminderRules;
        $now = new DateTimeImmutable('2026-10-19T08:00:00.500000Z');
        $changes = $rules->reconcile(ReminderStatus::Scheduled, $anchor, $offset, $now);
        if ($cancel) {
            $this->assertSame(['status' => ReminderStatus::Cancelled, 'sent_at' => null], $changes);
            $this->assertArrayNotHasKey('trigger_at', $changes);
        } else {
            $this->assertSame(['trigger_at' => '2026-10-19 08:00:01'], $changes);
        }
        foreach ([ReminderStatus::Sent, ReminderStatus::Cancelled] as $status) {
            $this->assertSame([], $rules->reconcile($status, $anchor, $offset, $now));
        }
    }

    public static function decisions(): array
    {
        return [[null, 0, true], ['2026-10-19T08:00:00Z', 0, true], ['2026-10-19T08:00:01Z', 0, false],
            ['2026-10-19T08:01:00Z', -1, true], ['1000-01-01T00:00:00Z', -1, true], ['9999-12-31T23:59:59Z', 1, true]];
    }

    public function test_exact_clock_equality_cancels_and_invalid_persisted_anchor_fails(): void
    {
        $rules = new ReminderRules;
        $this->assertSame(ReminderStatus::Cancelled, $rules->reconcile(ReminderStatus::Scheduled, '2026-10-19T08:00:00Z', 0, new DateTimeImmutable('2026-10-19T08:00:00Z'))['status']);
        $this->expectException(InvalidArgumentException::class);
        $rules->reconcile(ReminderStatus::Scheduled, '2026-02-29T08:00:00Z', 0, new DateTimeImmutable('2026-10-19T08:00:00Z'));
    }
}
