<?php

namespace App\Services\Imports;

use App\Enums\LessonType;
use App\Enums\ScheduleImportBatchStatus;
use App\Models\AcademicPeriod;
use App\Models\ScheduleImportBatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

class ScheduleImportPreviewRevalidator
{
    private const INTEGRITY_MESSAGE = 'This import preview is malformed. Create a new import preview.';

    private const UTC_ERROR = 'The stored occurrence UTC interval is invalid. Create a new import preview.';

    public function __construct(
        private ScheduleOccurrenceResolver $resolver,
        private ScheduleImportFingerprint $fingerprint,
        private ScheduleImportCandidateClassifier $classifier,
    ) {}

    /** Refresh existing occurrence rows in place; raw source and calendar instants are immutable. */
    public function revalidate(User $user, ScheduleImportBatch $batch): ScheduleImportBatch
    {
        return DB::transaction(function () use ($user, $batch): ScheduleImportBatch {
            $owner = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $batch = $owner->scheduleImportBatches()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($batch->status, [ScheduleImportBatchStatus::Uploaded, ScheduleImportBatchStatus::Validated], true)) {
                throw ValidationException::withMessages(['schedule_import' => ['This import preview cannot be revalidated.']]);
            }
            $period = $owner->academicPeriods()->whereKey($batch->academic_period_id)->lockForUpdate()->first();
            if ($period === null) {
                throw ValidationException::withMessages(['academic_period_id' => ['The selected academic period is invalid.']]);
            }
            $rows = $batch->rows()->orderBy('row_number')->lockForUpdate()->get();

            return $this->refreshLocked($owner, $batch, $period, $rows);
        });
    }

    /**
     * Shared C2a/C2b refresh. Caller holds User, owned Batch/Period and ordered Row
     * locks in the current transaction; this method never commits that transaction.
     */
    public function refreshLocked(User $owner, ScheduleImportBatch $batch, AcademicPeriod $period, Collection $rows): ScheduleImportBatch
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Preview refresh requires a locked transaction.');
        }
        $this->validateStructure($batch, $rows);

        // Preserve valid owned mappings, including renamed catalog entries.
        $subjectIds = $this->ownedIds($owner, $rows, 'subject_id', 'subjects');
        $teacherIds = $this->ownedIds($owner, $rows, 'teacher_id', 'teachers');
        $subjectNames = [];
        $teacherNames = [];
        foreach ($rows as $row) {
            if (! in_array($row->normalized_data['subject_id'] ?? null, $subjectIds, true)) {
                $subjectNames[] = $row->raw_data['subject_name'];
            }
            if (! in_array($row->normalized_data['teacher_id'] ?? null, $teacherIds, true)) {
                $teacherNames[] = $row->raw_data['teacher_name'];
            }
        }
        $references = $this->resolver->resolveNames($owner, $subjectNames, $teacherNames);
        $occurrences = [];
        foreach ($rows as $row) {
            $raw = $row->raw_data;
            $subjectId = $row->normalized_data['subject_id'] ?? null;
            $teacherId = $row->normalized_data['teacher_id'] ?? null;
            $errors = [];
            if (! in_array($subjectId, $subjectIds, true)) {
                $subject = $references['subjects'][$raw['subject_name']];
                $subjectId = $subject['id'];
                if ($subject['errors'] !== []) {
                    $errors['subject_name'] = $subject['errors'];
                }
            }
            if (! in_array($teacherId, $teacherIds, true)) {
                $teacher = $references['teachers'][$raw['teacher_name']];
                $teacherId = $teacher['id'];
                if ($teacher['errors'] !== []) {
                    $errors['teacher_name'] = $teacher['errors'];
                }
            }
            $fingerprint = null;
            try {
                // Validate even unresolved Subjects; no invalid interval reaches B3 projection.
                $this->fingerprint->validateInterval($raw['starts_at_utc'], $raw['ends_at_utc']);
                if ($subjectId !== null) {
                    $fingerprint = $this->fingerprint->generate($subjectId, $raw['starts_at_utc'], $raw['ends_at_utc']);
                }
            } catch (InvalidArgumentException) {
                $errors['preview_integrity'] = [self::UTC_ERROR];
            }
            $occurrences[] = [
                ...Arr::only($raw, [
                    'local_date', 'weekday', 'pair_label', 'parity', 'subject_name', 'teacher_name',
                    'room', 'starts_at_local', 'ends_at_local', 'starts_at_utc', 'ends_at_utc', 'source',
                ]),
                'type' => LessonType::Other, 'academic_period_id' => $period->getKey(),
                'subject_id' => $subjectId, 'teacher_id' => $teacherId,
                'fingerprint' => $fingerprint, 'resolution_errors' => $errors,
            ];
        }
        $firstRaw = $rows->first()?->raw_data;
        $classified = $this->classifier->classify($owner, $period, [
            'group' => $firstRaw['group'] ?? null, 'source' => $firstRaw['workbook_source'] ?? [],
            'academic_period_id' => $period->getKey(), 'occurrences' => $occurrences,
        ]);
        $counts = ['valid' => 0, 'invalid' => 0, 'duplicate' => 0];
        foreach ($classified['occurrences'] as $index => $occurrence) {
            $rows[$index]->update([
                'status' => $occurrence['status'],
                'normalized_data' => Arr::only([
                    ...$occurrence, 'type' => $occurrence['type']->value,
                ], [
                    'academic_period_id', 'subject_id', 'teacher_id', 'room', 'type',
                    'starts_at_utc', 'ends_at_utc', 'operation', 'matched_lesson_id',
                ]),
                'validation_errors' => $occurrence['validation_errors'],
                'fingerprint' => $occurrence['fingerprint'],
            ]);
            $counts[$rows[$index]->status->value]++;
        }
        $batch->update([
            'total_rows' => $rows->count(), 'valid_rows' => $counts['valid'],
            'invalid_rows' => $counts['invalid'], 'duplicate_rows' => $counts['duplicate'],
            'status' => $counts['invalid'] === 0 ? ScheduleImportBatchStatus::Validated : ScheduleImportBatchStatus::Uploaded,
            'error_message' => null, 'committed_at' => null,
        ]);

        return $batch->setRelation('rows', $rows);
    }

    private function ownedIds(User $user, Collection $rows, string $field, string $relation): array
    {
        $ids = $rows->map(fn ($row) => $row->normalized_data[$field] ?? null)
            ->filter(fn ($id) => is_int($id) && $id > 0)->unique()->values()->all();

        return $ids === [] ? [] : $user->$relation()->whereIn('id', $ids)->pluck('id')->all();
    }

    private function validateStructure(ScheduleImportBatch $batch, Collection $rows): void
    {
        if ($rows->count() !== $batch->total_rows) {
            $this->invalidStructure();
        }
        $firstRaw = $rows->first()?->raw_data;
        foreach ($rows as $index => $row) {
            $raw = $row->raw_data;
            if ($row->row_number !== $index + 1 || ! is_array($raw)) {
                $this->invalidStructure();
            }
            $validator = Validator::make($raw, [
                'group' => ['required', 'string'],
                'workbook_source' => ['required', 'array'],
                'workbook_source.worksheet' => ['required', 'string'],
                'workbook_source.range' => ['required', 'string'],
                'local_date' => ['required', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/'],
                'weekday' => ['required', 'integer:strict', 'between:1,7'],
                'pair_label' => ['required', 'string'],
                'parity' => ['required', 'in:all,numerator,denominator'],
                'subject_name' => ['required', 'string'],
                'teacher_name' => ['required', 'string'],
                'room' => ['present', 'nullable', 'string'],
                'starts_at_local' => ['required', 'string', 'regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/'],
                'ends_at_local' => ['required', 'string', 'regex:/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/'],
                'starts_at_utc' => ['required', 'string'],
                'ends_at_utc' => ['required', 'string'],
                'source' => ['required', 'array'],
                'source.worksheet' => ['required', 'string'],
                'source.range' => ['required', 'string'],
                'source.pair_range' => ['required', 'string'],
                'source.rows' => ['required', 'array', 'list', 'size:2'],
                'source.rows.*' => ['required', 'integer:strict', 'min:1'],
            ]);
            if ($validator->fails() || ! checkdate((int) substr($raw['local_date'], 5, 2), (int) substr($raw['local_date'], 8, 2), (int) substr($raw['local_date'], 0, 4))) {
                $this->invalidStructure();
            }
            // Calendar-label validation must not reinterpret dates in the server timezone.
            if (CarbonImmutable::createFromFormat('!Y-m-d', $raw['local_date'], 'UTC')->isoWeekday() !== $raw['weekday']
                || $raw['starts_at_local'] >= $raw['ends_at_local']
                || $raw['source']['rows'][0] > $raw['source']['rows'][1]
                || $raw['source']['worksheet'] !== $raw['workbook_source']['worksheet']
                || $raw['group'] !== $firstRaw['group'] || $raw['workbook_source'] !== $firstRaw['workbook_source']) {
                $this->invalidStructure();
            }
        }
    }

    private function invalidStructure(): never
    {
        throw ValidationException::withMessages(['schedule_import' => [self::INTEGRITY_MESSAGE]]);
    }
}
