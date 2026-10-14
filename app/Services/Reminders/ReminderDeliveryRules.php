<?php

namespace App\Services\Reminders;

use DateTimeImmutable;
use InvalidArgumentException;
use UnexpectedValueException;

/** Pure due comparisons and immutable safe inbox snapshots. */
class ReminderDeliveryRules
{
    public const TYPE = 'reminder';

    public function __construct(private ReminderRules $rules) {}

    public function due(string $trigger, DateTimeImmutable $now): bool
    {
        return new DateTimeImmutable('@'.$this->rules->timestamp($trigger)) <= $now;
    }

    public function payload(int $id, array $targets, mixed $message, string $trigger): array
    {
        $this->rules->timestamp($trigger);
        if ($id < 1 || count($this->rules->targets($targets)) > 1
            || ($message !== null && (! is_string($message) || ! mb_check_encoding($message, 'UTF-8') || mb_strlen($message, 'UTF-8') > 500))) {
            throw new UnexpectedValueException('Invalid reminder payload.');
        }
        $ids = [];
        foreach (ReminderRules::TARGETS as $field) {
            $value = $targets[$field] ?? null;
            if ($value !== null && (! is_int($value) || $value < 1)) {
                throw new UnexpectedValueException('Invalid reminder payload.');
            }
            $ids[$field] = $value;
        }
        $target = array_key_first($this->rules->targets($ids));
        if ($target === null && ! $this->rules->meaningful($message)) {
            throw new UnexpectedValueException('Invalid general reminder message.');
        }
        $display = $this->rules->meaningful($message) ? $message : match ($target) {
            'task_id' => 'You have a task reminder.', 'subtask_id' => 'You have a subtask reminder.',
            'study_session_id' => 'You have a study session reminder.',
        };

        return ['reminder_id' => $id, 'message' => $display, ...$ids, 'trigger_at' => $trigger];
    }

    /** Validate stored history without consulting mutable target or Reminder rows. */
    public function stored(mixed $data, mixed $reference): array
    {
        $fields = ['reminder_id', 'message', ...ReminderRules::TARGETS, 'trigger_at'];
        if (! is_array($data) || count($data) !== count($fields) || array_diff($fields, array_keys($data)) !== []
            || ! is_int($data['reminder_id']) || ($reference !== null && $reference !== $data['reminder_id'])
            || ! $this->rules->meaningful($data['message']) || ! is_string($data['trigger_at'])) {
            throw new UnexpectedValueException('Invalid notification history.');
        }
        try {
            $payload = $this->payload($data['reminder_id'], $data, $data['message'], $data['trigger_at']);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException('Invalid notification history.', 0, $exception);
        }
        foreach ($payload as $field => $value) {
            if ($data[$field] !== $value) {
                throw new UnexpectedValueException('Invalid notification history.');
            }
        }

        return $payload;
    }
}
