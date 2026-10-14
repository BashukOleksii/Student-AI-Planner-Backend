<?php

namespace App\Services\Reminders;

use App\Enums\ReminderAnchor;
use App\Enums\ReminderStatus;
use App\Exceptions\ReminderConflictException;
use App\Services\Planning\StudySessionLifecycleRules;
use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;

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
    public function errors(array $data, bool $create, ?bool $relative = null): array
    {
        $errors = [];
        $allowed = $create ? ['message', 'trigger_at', 'anchor', 'offset_minutes', ...self::TARGETS]
            : match ($relative) {
                true => ['message', 'offset_minutes'], false => ['message', 'trigger_at'], default => ['message', 'trigger_at', 'offset_minutes']
            };
        $relativeCreation = $create && (array_key_exists('anchor', $data) || array_key_exists('offset_minutes', $data));
        foreach (array_diff(array_keys($data), $allowed) as $field) {
            $errors[$field] = 'This field is not accepted.';
        }
        if ($create && ! $relativeCreation && ! array_key_exists('trigger_at', $data)) {
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
        if (array_key_exists('offset_minutes', $data) || $relativeCreation) {
            try {
                $this->offset($data['offset_minutes'] ?? null);
            } catch (InvalidArgumentException) {
                $errors['offset_minutes'] = 'Use a signed integer between -32768 and 32767.';
            }
        }
        if ($relativeCreation) {
            $anchor = $data['anchor'] ?? null;
            if (! is_string($anchor) || ReminderAnchor::tryFrom($anchor) === null) {
                $errors['anchor'] = 'Use a supported non-null relative anchor.';
            }
            if (array_key_exists('trigger_at', $data)) {
                $errors['trigger_at'] = 'A relative trigger is computed by the server; omit trigger_at.';
            }
            $targets = $this->targets($data);
            if (count($targets) !== 1) {
                $errors['target'] = 'A relative reminder requires exactly one target.';
            } elseif (! isset($errors['anchor']) && $this->anchorFor(array_key_first($targets))->value !== $anchor) {
                $errors['anchor'] = 'The anchor is incompatible with the target.';
            }
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

    public function anchorFor(string $target): ReminderAnchor
    {
        return match ($target) {
            'task_id' => ReminderAnchor::TaskDeadline,
            'subtask_id' => ReminderAnchor::SubtaskDeadline,
            'study_session_id' => ReminderAnchor::SessionStart,
            default => throw new InvalidArgumentException('Unknown reminder target.'),
        };
    }

    public function offset(mixed $value): void
    {
        if (! is_int($value) || $value < -32768 || $value > 32767) {
            throw new InvalidArgumentException('Expected a signed SMALLINT integer offset.');
        }
    }

    public function databaseTime(string $wire): string
    {
        return gmdate('Y-m-d H:i:s', $this->timestamp($wire));
    }

    public function wireTime(string $database): string
    {
        $wire = str_replace(' ', 'T', $database).'Z';
        $this->timestamp($wire);

        return $wire;
    }

    public function resolve(?string $anchor, mixed $offset): string
    {
        $this->offset($offset);
        $seconds = $this->timestamp($anchor) + $offset * 60;
        if ($seconds < $this->timestamp('1000-01-01T00:00:00Z') || $seconds > $this->timestamp('9999-12-31T23:59:59Z')) {
            throw new OverflowException('The resolved trigger is outside MySQL DATETIME.');
        }

        return gmdate('Y-m-d\TH:i:s\Z', $seconds);
    }

    /** Empty changes for history; cancellation never supplies a replacement trigger. */
    public function reconcile(ReminderStatus $status, ?string $anchor, mixed $offset, DateTimeImmutable $now): array
    {
        if ($status !== ReminderStatus::Scheduled) {
            return [];
        }
        $this->offset($offset);
        if ($anchor !== null) {
            try {
                $trigger = $this->resolve($anchor, $offset);
                if (new DateTimeImmutable('@'.$this->timestamp($trigger)) > $now) {
                    return ['trigger_at' => $this->databaseTime($trigger)];
                }
            } catch (OverflowException) {
                // A legitimate deadline edit may leave no representable notification instant.
            }
        }

        return ['status' => ReminderStatus::Cancelled, 'sent_at' => null];
    }

    public function editable(ReminderStatus $status): void
    {
        if ($status !== ReminderStatus::Scheduled) {
            throw new ReminderConflictException;
        }
    }
}
