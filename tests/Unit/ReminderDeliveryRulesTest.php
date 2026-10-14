<?php

namespace Tests\Unit;

use App\Services\Reminders\ReminderDeliveryRules;
use App\Services\Reminders\ReminderRules;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class ReminderDeliveryRulesTest extends TestCase
{
    #[DataProvider('dueTimes')]
    public function test_due_with_full_precision_clock(string $trigger, string $now, bool $due): void
    {
        $this->assertSame($due, $this->rules()->due($trigger, new DateTimeImmutable($now)));
    }

    public static function dueTimes(): array
    {
        return [['2026-10-19T08:00:00Z', '2026-10-19T08:00:00Z', true], ['2026-10-19T08:00:00Z', '2026-10-19T08:00:00.500000Z', true],
            ['2026-10-19T08:00:01Z', '2026-10-19T08:00:00.999999Z', false], ['2026-10-18T08:00:00Z', '2026-10-19T08:00:00Z', true],
            ['2026-10-25T01:30:00Z', '2026-10-25T03:30:00+02:00', true],
            ['2026-03-29T01:30:00Z', '2026-03-29T04:30:00+03:00', true],
            ['2027-01-01T00:00:00Z', '2026-12-31T23:59:59Z', false]];
    }

    public function test_due_rejects_invalid_calendar_wire_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->rules()->due('2026-02-29T08:00:00Z', new DateTimeImmutable('2026-10-19T08:00:00Z'));
    }

    #[DataProvider('targets')]
    public function test_payload_uses_deterministic_generic_text_and_exact_identity(string $field, string $message): void
    {
        foreach ([null, '', '  '] as $text) {
            $input = [$field => 12, 'user_id' => 99, 'password' => 'secret', 'title' => 'Private title'];
            $before = $input;
            $data = $this->rules()->payload(1, $input, $text, '2026-10-19T08:00:00Z');
            $this->assertSame($before, $input);
            $this->assertSame($message, $data['message']);
            $this->assertSame(12, $data[$field]);
            $this->assertSame(['reminder_id', 'message', ...ReminderRules::TARGETS, 'trigger_at'], array_keys($data));
            $this->assertSame($data, $this->rules()->stored($data, 1));
            $this->assertSame($data, $this->rules()->stored($data, null));
        }
    }

    public static function targets(): array
    {
        return [['task_id', 'You have a task reminder.'], ['subtask_id', 'You have a subtask reminder.'], ['study_session_id', 'You have a study session reminder.']];
    }

    public function test_general_message_and_whitespace_are_preserved(): void
    {
        $data = $this->rules()->payload(1, [], '  Review notes  ', '2026-10-19T08:00:00Z');
        $this->assertSame('  Review notes  ', $data['message']);
        $this->assertSame($data, $this->rules()->stored($data, 1));
        $this->assertNull($data['task_id']);
    }

    #[DataProvider('badPayloads')]
    public function test_malformed_payloads_are_rejected(array $change, mixed $reference): void
    {
        $data = $this->rules()->payload(1, [], 'Notes', '2026-10-19T08:00:00Z');
        $this->expectException(UnexpectedValueException::class);
        $this->rules()->stored(array_replace($data, $change), $reference);
    }

    public static function badPayloads(): array
    {
        return [[['reminder_id' => '1'], 1], [['reminder_id' => 0], null], [[], 2], [['message' => null], 1], [['message' => ''], 1],
            [['message' => []], 1], [['message' => str_repeat('a', 501)], 1], [['message' => "\xff"], 1],
            [['task_id' => '1'], 1], [['task_id' => 1.0], 1], [['task_id' => true], 1], [['task_id' => 0], 1], [['task_id' => 1, 'subtask_id' => 2], 1],
            [['trigger_at' => '2026-02-29T08:00:00Z'], 1], [['trigger_at' => null], 1], [['password' => 'secret'], 1]];
    }

    private function rules(): ReminderDeliveryRules
    {
        return new ReminderDeliveryRules(new ReminderRules);
    }
}
