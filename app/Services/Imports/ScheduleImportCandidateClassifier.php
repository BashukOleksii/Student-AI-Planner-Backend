<?php

namespace App\Services\Imports;

use App\Enums\LessonStatus;
use App\Enums\ScheduleImportRowStatus;
use App\Models\AcademicPeriod;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ScheduleImportCandidateClassifier
{
    private const IDENTITY_CONFLICT = 'Multiple imported occurrences with the same identity contain conflicting data.';

    private const SCHEDULE_CONFLICT = 'This imported occurrence conflicts with the projected schedule.';

    /**
     * Read-only classification of the B2 contract, preserving input order and fields.
     * Adds status (ScheduleImportRowStatus), operation (?string), validation_errors
     * (array<string, list<string>>) and matched_lesson_id (?int) to each occurrence.
     * Operations: create/update/restore/preserve/noop. Existing Lesson types are
     * never source-authoritative; future update/restore must retain the stored type.
     */
    public function classify(User $user, AcademicPeriod $academicPeriod, array $resolved): array
    {
        $period = $user->academicPeriods()->whereKey($academicPeriod->getKey())->first();
        if ($period === null || $resolved['academic_period_id'] !== $period->getKey()) {
            throw ValidationException::withMessages(['academic_period_id' => ['The selected academic period is invalid.']]);
        }

        $rows = $resolved['occurrences'];
        $groups = [];
        foreach ($rows as $index => $row) {
            $errors = $row['resolution_errors'];
            if ($row['academic_period_id'] !== $period->getKey()) {
                $errors['academic_period_id'] = ['The selected academic period is invalid.'];
            }
            if ($errors === [] && $row['fingerprint'] === null) {
                $errors['import_identity'] = ['The occurrence has no resolved import identity.'];
            }
            $rows[$index] = [...$row, ...$this->classification(ScheduleImportRowStatus::Invalid, null, null, $errors)];
            if ($errors === []) {
                $groups[$row['fingerprint']][] = $index;
            }
        }

        $histories = $groups === [] ? collect() : $user->lessons()->withTrashed()
            ->where('academic_period_id', $period->getKey())->whereIn('import_fingerprint', array_keys($groups))
            ->get()->keyBy('import_fingerprint');
        $results = [];
        $projected = [];
        $excludedIds = [];
        foreach ($groups as $fingerprint => $indices) {
            $source = $this->sourceValues($rows[$indices[0]]);
            $lesson = $histories->get($fingerprint);
            $results[$fingerprint] = $this->classification(ScheduleImportRowStatus::Valid, 'create', $lesson?->getKey());
            foreach ($indices as $index) {
                if ($this->sourceValues($rows[$index]) !== $source) {
                    $this->invalidate($results[$fingerprint], 'import_identity', self::IDENTITY_CONFLICT);
                    break;
                }
            }
            if ($results[$fingerprint]['status'] === ScheduleImportRowStatus::Invalid) {
                continue;
            }
            if ($lesson !== null) {
                if ($lesson->trashed()) {
                    $results[$fingerprint]['operation'] = 'restore';
                } elseif ($lesson->status !== LessonStatus::Active) {
                    $results[$fingerprint]['operation'] = 'preserve';

                    continue;
                } elseif ($this->lessonValues($lesson) === $source) {
                    $results[$fingerprint]['status'] = ScheduleImportRowStatus::Duplicate;
                    $results[$fingerprint]['operation'] = 'noop';
                } else {
                    $results[$fingerprint]['operation'] = 'update';
                }
                if (! $lesson->trashed() && $lesson->status === LessonStatus::Active) {
                    $excludedIds[] = $lesson->getKey();
                }
            }
            $projected[$fingerprint] = $source;
        }

        if ($projected !== []) {
            $start = min(array_column($projected, 'starts_at'));
            $end = max(array_column($projected, 'ends_at'));
            // All projected active histories are excluded together, before any conflict result.
            // Atomic preview checks target positions, never an arbitrary partial commit.
            $blockers = $user->lessons()->where('status', LessonStatus::Active)
                ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
                ->whereNotIn('id', $excludedIds)->get(['id', 'starts_at', 'ends_at']);
            foreach ($projected as $fingerprint => $interval) {
                foreach ($blockers as $blocker) {
                    if ($this->overlaps($interval, [
                        'starts_at' => $blocker->getRawOriginal('starts_at'),
                        'ends_at' => $blocker->getRawOriginal('ends_at'),
                    ])) {
                        $this->invalidate($results[$fingerprint], 'schedule', self::SCHEDULE_CONFLICT);
                        break;
                    }
                }
            }
            $fingerprints = array_keys($projected);
            usort($fingerprints, fn (string $a, string $b) => strcmp($projected[$a]['starts_at'], $projected[$b]['starts_at']) ?: strcmp($a, $b));
            $count = count($fingerprints);
            foreach ($fingerprints as $position => $fingerprint) {
                for ($next = $position + 1; $next < $count; $next++) {
                    $other = $fingerprints[$next];
                    if ($projected[$other]['starts_at'] >= $projected[$fingerprint]['ends_at']) {
                        break;
                    }
                    if ($this->overlaps($projected[$fingerprint], $projected[$other])) {
                        $this->invalidate($results[$fingerprint], 'schedule', self::SCHEDULE_CONFLICT);
                        $this->invalidate($results[$other], 'schedule', self::SCHEDULE_CONFLICT);
                    }
                }
            }
        }

        foreach ($groups as $fingerprint => $indices) {
            foreach ($indices as $position => $index) {
                $result = $results[$fingerprint];
                if ($position > 0 && $result['status'] !== ScheduleImportRowStatus::Invalid) {
                    $result['status'] = ScheduleImportRowStatus::Duplicate;
                    $result['operation'] = 'noop';
                }
                $rows[$index] = [...$rows[$index], ...$result];
            }
        }

        return [...$resolved, 'occurrences' => $rows];
    }

    private function classification(ScheduleImportRowStatus $status, ?string $operation, ?int $lessonId, array $errors = []): array
    {
        return ['status' => $status, 'operation' => $operation, 'validation_errors' => $errors, 'matched_lesson_id' => $lessonId];
    }

    private function invalidate(array &$result, string $field, string $message): void
    {
        $result['status'] = ScheduleImportRowStatus::Invalid;
        $result['operation'] = null;
        $result['validation_errors'][$field] = [$message];
    }

    private function sourceValues(array $row): array
    {
        return [
            'subject_id' => $row['subject_id'], 'academic_period_id' => $row['academic_period_id'],
            'teacher_id' => $row['teacher_id'], 'room' => $row['room'],
            'starts_at' => $this->databaseTime($row['starts_at_utc']), 'ends_at' => $this->databaseTime($row['ends_at_utc']),
        ];
    }

    private function lessonValues(Lesson $lesson): array
    {
        return [
            'subject_id' => (int) $lesson->subject_id, 'academic_period_id' => (int) $lesson->academic_period_id,
            'teacher_id' => $lesson->teacher_id === null ? null : (int) $lesson->teacher_id, 'room' => $lesson->room,
            // Persisted DATETIME values already represent UTC; do not cast through server timezone.
            'starts_at' => $lesson->getRawOriginal('starts_at'), 'ends_at' => $lesson->getRawOriginal('ends_at'),
        ];
    }

    private function databaseTime(string $utc): string
    {
        return substr($utc, 0, 10).' '.substr($utc, 11, 8);
    }

    private function overlaps(array $a, array $b): bool
    {
        return $a['starts_at'] < $b['ends_at'] && $a['ends_at'] > $b['starts_at'];
    }
}
