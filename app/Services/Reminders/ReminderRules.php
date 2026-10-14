<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Exceptions\ReminderConflictException;
use App\Services\Planning\StudySessionLifecycleRules;
use DateTimeImmutable;
use InvalidArgumentException;

/** Pure input and lifecycle rules; persisted relationship validation stays in the Service. */
class ReminderRules
{
    public const TARGETS = ['task_id', 'subtask_id', 'study_session_id'];

    public function timestamp(mixed $value): int
    {
        return (new StudySessionLifecycleRules)->timestamp($value);
    }

    public function future(mixed $value, DateTimeImmutable $now): void
    {
        if (new DateTimeImmutable('@'.$this->timestamp($value)) <= $now) {
            throw new InvalidArgumentException('The trigger must be strictly in the future.');
        }
    }

    public function targets(array $data): array
    {
        return array_filter(array_intersect_key($data, array_flip(self::TARGETS)), fn ($id) => $id !== null);
    }

    public function meaningful(mixed $message): bool
    {
        return is_string($message) && mb_check_encoding($message, 'UTF-8')
            && preg_match('/[^\p{Z}\p{C}\s]/u', $message) === 1;
    }

    /** Exact body validation shared by Requests and direct application callers. */
    public function errors(array $data, bool $create): array
    {
        $errors = [];
        $allowed = ['message', 'trigger_at', ...($create ? self::TARGETS : [])];
        foreach (array_diff(array_keys($data), $allowed) as $field) {
            $errors[$field] = 'This field is not accepted.';
        }
        if ($create && ! array_key_exists('trigger_at', $data)) {
            $errors['trigger_at'] = 'The trigger at field is required.';
        }
        if (! $create && array_intersect(array_keys($data), $allowed) === []) {
            $errors['reminder'] = 'At least one editable field is required.';
        }
        if (array_key_exists('trigger_at', $data)) {
            try {
                $this->timestamp($data['trigger_at']);
            } catch (InvalidArgumentException) {
                $errors['trigger_at'] = 'Use a real UTC timestamp in YYYY-MM-DDTHH:MM:SSZ format, within years 1000–9999.';
            }
        }
        if (array_key_exists('message', $data) && $data['message'] !== null
            && (! is_string($data['message']) || ! mb_check_encoding($data['message'], 'UTF-8') || mb_strlen($data['message'], 'UTF-8') > 500)) {
            $errors['message'] = 'The message must be null or a UTF-8 string of at most 500 characters.';
        }
        if ($create) {
            foreach ($this->targets($data) as $field => $id) {
                if (! is_int($id) || $id < 1) {
                    $errors[$field] = 'The target must be a positive integer.';
                }
            }
            if (count($this->targets($data)) > 1) {
                $errors['target'] = 'Only one reminder target is accepted.';
            }
            if ($this->targets($data) === [] && ! $this->meaningful($data['message'] ?? null)) {
                $errors['message'] = 'A general reminder requires a meaningful message.';
            }
        }

        return $errors;
    }

    public function editable(ReminderStatus $status, bool $absolute = true): void
    {
        if ($status !== ReminderStatus::Scheduled || ! $absolute) {
            throw new ReminderConflictException;
        }
    }
}
