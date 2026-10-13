<?php

namespace App\Services\Planning;

use App\Enums\StudySessionStatus;
use App\Exceptions\StudySessionConflictException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use UnexpectedValueException;

/** Pure whole-second intervals with full-precision comparisons to the supplied clock. */
class StudySessionLifecycleRules
{
    public function actualMinutes(mixed $value): void
    {
        if ($value !== null && (! is_int($value) || $value < 1 || $value > 65535)) {
            throw new InvalidArgumentException('Actual minutes must be null or a positive unsigned SMALLINT integer.');
        }
    }

    public function timestamp(mixed $value): int
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $value)
            || substr($value, 0, 4) < '1000') {
            throw new InvalidArgumentException('Expected a representable whole-second UTC timestamp.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new InvalidArgumentException('Expected a real UTC calendar timestamp.');
        }

        return $date->getTimestamp();
    }

    public function transition(string $action, StudySessionStatus $status, array $interval, DateTimeImmutable $now, mixed $actualMinutes = null): array
    {
        (new TimeIntervalService)->durationSeconds($interval);
        $this->actualMinutes($actualMinutes);
        $start = new DateTimeImmutable('@'.$interval['start']);
        $end = new DateTimeImmutable('@'.$interval['end']);
        $allowed = match ($action) {
            'complete' => $start <= $now,
            'miss' => $end <= $now,
            'cancel', 'reschedule' => $start > $now,
            default => throw new InvalidArgumentException('Unknown session action.'),
        };
        if ($status !== StudySessionStatus::Planned || ! $allowed) {
            throw new StudySessionConflictException;
        }

        return ['status' => match ($action) {
            'complete' => StudySessionStatus::Completed,
            'miss' => StudySessionStatus::Missed,
            'cancel' => StudySessionStatus::Cancelled,
            'reschedule' => StudySessionStatus::Rescheduled,
        }, 'completed_at' => $action === 'complete' ? $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null,
            'actual_minutes' => $action === 'complete' ? $actualMinutes : null];
    }

    /** Connected records discovered by the application, keyed by integer ID. No input mutation. */
    public function validateChain(array $records, int $sourceId): void
    {
        $source = $records[$sourceId] ?? null;
        if ($source === null) {
            $this->corrupt();
        }
        $children = [];
        $validated = [];
        foreach ($records as $id => $record) {
            if ($record['user_id'] !== $source['user_id'] || $record['task_id'] !== $source['task_id']
                || $record['subtask_id'] !== $source['subtask_id']) {
                $this->corrupt();
            }
            $parentId = $record['rescheduled_from_session_id'];
            if ($parentId !== null) {
                if ($id === $parentId || ! isset($records[$parentId])
                    || $records[$parentId]['status'] !== StudySessionStatus::Rescheduled->value) {
                    $this->corrupt();
                }
                $children[$parentId][] = $id;
            }
            $seen = [];
            $current = $id;
            while ($current !== null && ! isset($validated[$current])) {
                if (isset($seen[$current]) || ! isset($records[$current])) {
                    $this->corrupt();
                }
                $seen[$current] = true;
                $current = $records[$current]['rescheduled_from_session_id'];
            }
            $validated += $seen;
        }
        foreach ($records as $id => $record) {
            $count = count($children[$id] ?? []);
            if ($count !== ($record['status'] === StudySessionStatus::Rescheduled->value ? 1 : 0)) {
                $this->corrupt();
            }
        }
    }

    public function replacementSummary(int $originalSeconds, int $replacementSeconds, int $unallocatedBefore): array
    {
        if ($replacementSeconds <= 0 || $replacementSeconds > $originalSeconds || $replacementSeconds > $unallocatedBefore) {
            throw new StudySessionConflictException;
        }

        return ['original_duration_seconds' => $originalSeconds, 'replacement_duration_seconds' => $replacementSeconds,
            'not_transferred_seconds' => $originalSeconds - $replacementSeconds,
            'unallocated_work_seconds' => $unallocatedBefore - $replacementSeconds];
    }

    private function corrupt(): never
    {
        throw new UnexpectedValueException('Invalid study session source relationships.');
    }
}
