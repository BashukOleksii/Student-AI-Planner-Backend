<?php

namespace Tests\Unit;

use App\Enums\ReminderAnchor;
use App\Enums\ReminderStatus;
use App\Exceptions\ReminderConflictException;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Reminders\ReminderDependencyService;
use App\Services\Reminders\ReminderRules;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReminderRulesTest extends TestCase
{
    public function test_existing_dependency_target_anchor_rules_remain_strict(): void
    {
        $owner = new User;
        $owner->setRawAttributes(['id' => 1]);
        $helper = new ReminderDependencyService;
        foreach (['task_id' => ReminderAnchor::TaskDeadline, 'subtask_id' => ReminderAnchor::SubtaskDeadline, 'study_session_id' => ReminderAnchor::SessionStart] as $target => $anchor) {
            foreach ([null, ...ReminderAnchor::cases()] as $actual) {
                $reminder = new Reminder;
                $reminder->setRawAttributes(['user_id' => 1, 'task_id' => null, 'subtask_id' => null, 'study_session_id' => null,
                    $target => 1, 'anchor' => $actual?->value, 'offset_minutes' => $actual === null ? null : -30]);
                $this->assertSame($actual === null || $actual === $anchor, $helper->compatible($reminder, $owner));
                $reminder->user_id = 2;
                $this->assertFalse($helper->compatible($reminder, $owner));
            }
        }
        foreach ([[], ['task_id' => 1, 'subtask_id' => 2]] as $targets) {
            $reminder = new Reminder;
            $reminder->setRawAttributes(['user_id' => 1, 'task_id' => null, 'subtask_id' => null, 'study_session_id' => null,
                ...$targets, 'anchor' => null, 'offset_minutes' => null]);
            $this->assertFalse($helper->compatible($reminder, $owner));
        }
        foreach ([['anchor' => null, 'offset_minutes' => 30], ['anchor' => 'task_deadline', 'offset_minutes' => null]] as $metadata) {
            $reminder = new Reminder;
            $reminder->setRawAttributes(['user_id' => 1, 'task_id' => 1, 'subtask_id' => null, 'study_session_id' => null, ...$metadata]);
            $this->assertFalse($helper->compatible($reminder, $owner));
        }
    }

    #[DataProvider('invalidInputs')]
    public function test_exact_input_validation(array $change, string $field): void
    {
        $data = ['trigger_at' => '2026-10-20T07:45:00Z', 'message' => 'Review notes'];
        $this->assertArrayHasKey($field, (new ReminderRules)->errors(array_replace($data, $change), true));
    }

    public static function invalidInputs(): array
    {
        $cases = [];
        foreach (['2026-02-29T07:45:00Z', '2026-13-20T07:45:00Z', '2026-10-20T24:00:00Z', '2026-10-20T07:45:60Z',
            '2026-10-20T07:45:00+00:00', '2026-10-20T07:45:00.0Z', '2026-10-20T07:45:00z',
            ' 2026-10-20T07:45:00Z', "2026-10-20T07:45:00Z\n", '0999-10-20T07:45:00Z', null, true, 100, []] as $value) {
            $cases[] = [['trigger_at' => $value], 'trigger_at'];
        }
        foreach (ReminderRules::TARGETS as $field) {
            foreach (['12', 12.0, true, false, 0, -1, [], ''] as $id) {
                $cases[] = [[$field => $id], $field];
            }
        }
        foreach (['user_id', 'status', 'sent_at', 'anchor', 'offset_minutes', 'id', 'created_at', 'updated_at', 'unknown'] as $field) {
            $cases[] = [[$field => null], $field];
        }
        foreach ([12, true, [], str_repeat('a', 501), "\xff"] as $value) {
            $cases[] = [['message' => $value], 'message'];
        }
        foreach ([null, '', '  ', "\n\t", "\u{00A0}\u{200B}"] as $value) {
            $cases[] = [['message' => $value], 'message'];
        }
        $cases[] = [['task_id' => 1, 'subtask_id' => 2], 'target'];

        return $cases;
    }

    public function test_classification_message_limits_and_representable_dates(): void
    {
        $rules = new ReminderRules;
        $this->assertSame([], $rules->targets(['task_id' => null]));
        foreach (ReminderRules::TARGETS as $field) {
            $this->assertSame([$field => 1], $rules->targets([$field => 1]));
            $this->assertSame([], $rules->errors(['trigger_at' => '9999-12-31T23:59:59Z', $field => 1, 'message' => null], true));
        }
        $this->assertSame([], $rules->errors(['trigger_at' => '1000-01-01T00:00:00Z', 'message' => str_repeat('ї', 500)], true));
        $this->assertSame([], $rules->errors(['trigger_at' => '2028-02-29T00:00:00Z', 'message' => 'Notes'], true));
        $this->assertArrayHasKey('trigger_at', $rules->errors(['message' => 'Notes'], true));
        $this->assertArrayHasKey('reminder', $rules->errors([], false));
        $this->assertSame([], $rules->errors(['message' => null], false));
        $this->assertArrayHasKey('task_id', $rules->errors(['task_id' => null], false));
    }

    public function test_message_length_is_utf8_independent_of_php_internal_encoding(): void
    {
        $encoding = mb_internal_encoding();
        mb_internal_encoding('ISO-8859-1');
        try {
            $this->assertSame([], (new ReminderRules)->errors(['trigger_at' => '2026-10-20T08:00:00Z', 'message' => str_repeat('ї', 500)], true));
            $this->assertArrayHasKey('message', (new ReminderRules)->errors(['trigger_at' => '2026-10-20T08:00:00Z', 'message' => str_repeat('ї', 501)], true));
        } finally {
            mb_internal_encoding($encoding);
        }
    }

    #[DataProvider('futureBoundaries')]
    public function test_full_precision_future(string $trigger, bool $valid): void
    {
        if (! $valid) {
            $this->expectException(InvalidArgumentException::class);
        }
        (new ReminderRules)->future($trigger, new DateTimeImmutable('2026-10-19T08:00:00.500000Z'));
        $this->assertTrue($valid);
    }

    public static function futureBoundaries(): array
    {
        return [['2026-10-19T07:59:59Z', false], ['2026-10-19T08:00:00Z', false], ['2026-10-19T08:00:01Z', true]];
    }

    #[DataProvider('transitions')]
    public function test_transitions_and_historical_immutability(ReminderStatus $status, bool $absolute, bool $valid): void
    {
        if (! $valid) {
            $this->expectException(ReminderConflictException::class);
        }
        (new ReminderRules)->editable($status, $absolute);
        $this->assertTrue($valid);
    }

    public static function transitions(): array
    {
        return [[ReminderStatus::Scheduled, true, true], [ReminderStatus::Scheduled, false, false],
            [ReminderStatus::Sent, true, false], [ReminderStatus::Cancelled, true, false]];
    }
}
