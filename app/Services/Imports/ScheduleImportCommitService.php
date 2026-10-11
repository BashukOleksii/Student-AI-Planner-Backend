<?php

namespace App\Services\Imports;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\ScheduleImportBatchStatus;
use App\Enums\ScheduleImportRowStatus;
use App\Models\ScheduleImportBatch;
use App\Models\ScheduleImportRow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScheduleImportCommitService
{
    private const NORMALIZED_FIELDS = [
        'academic_period_id', 'subject_id', 'teacher_id', 'room', 'type',
        'starts_at_utc', 'ends_at_utc', 'operation', 'matched_lesson_id',
    ];

    public function __construct(private ScheduleImportPreviewRevalidator $revalidator) {}

    /**
     * Confirmation covers the stored preview. A changed refresh commits preview
     * changes only; unchanged confirmation applies all operations atomically.
     *
     * @return array{batch: ScheduleImportBatch, preview_changed: bool}
     */
    public function commit(User $user, ScheduleImportBatch $batch): array
    {
        return DB::transaction(function () use ($user, $batch): array {
            $owner = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $batch = $owner->scheduleImportBatches()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            if ($batch->status === ScheduleImportBatchStatus::Committed) {
                return ['batch' => $batch->load(['rows' => fn ($query) => $query->orderBy('row_number')]), 'preview_changed' => false];
            }
            if ($batch->status !== ScheduleImportBatchStatus::Validated) {
                throw ValidationException::withMessages(['schedule_import' => ['This import preview is not ready to commit.']]);
            }
            $period = $owner->academicPeriods()->whereKey($batch->academic_period_id)->lockForUpdate()->first();
            if ($period === null) {
                throw ValidationException::withMessages(['academic_period_id' => ['The selected academic period is invalid.']]);
            }
            $rows = $batch->rows()->orderBy('row_number')->lockForUpdate()->get();
            $batch->setRelation('rows', $rows);
            $confirmed = $this->confirmation($batch);
            $batch = $this->revalidator->refreshLocked($owner, $batch, $period, $rows);
            if ($batch->status !== ScheduleImportBatchStatus::Validated || $confirmed !== $this->confirmation($batch)) {
                // Returning normally preserves the refreshed preview; no Lesson has changed.
                return ['batch' => $batch, 'preview_changed' => true];
            }
            foreach ($batch->rows as $row) {
                $this->apply($owner, $batch, $row);
            }
            if (! $batch->update([
                'status' => ScheduleImportBatchStatus::Committed,
                'committed_at' => CarbonImmutable::now('UTC')->startOfSecond(),
                'error_message' => null,
            ])) {
                $this->invalidOperation();
            }

            return ['batch' => $batch, 'preview_changed' => false];
        });
    }

    private function confirmation(ScheduleImportBatch $batch): array
    {
        $rows = [];
        foreach ($batch->rows as $row) {
            $normalized = [];
            foreach (self::NORMALIZED_FIELDS as $field) {
                $normalized[$field] = $row->normalized_data[$field] ?? null;
            }
            $rows[] = [
                'row_number' => $row->row_number, 'status' => $row->status->value,
                'fingerprint' => $row->fingerprint, 'normalized_data' => $normalized,
                'validation_errors' => $row->validation_errors,
            ];
        }

        return $this->canonical([
            'status' => $batch->status->value, 'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows, 'invalid_rows' => $batch->invalid_rows,
            'duplicate_rows' => $batch->duplicate_rows, 'error_message' => $batch->error_message,
            'rows' => $rows,
        ]);
    }

    /** JSON object key order is not a confirmation change; scalar types/list order are. */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as &$item) {
            $item = $this->canonical($item);
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    private function apply(User $owner, ScheduleImportBatch $batch, ScheduleImportRow $row): void
    {
        $candidate = $row->normalized_data;
        $operation = $candidate['operation'] ?? null;
        if (! in_array($operation, ['create', 'update', 'restore', 'preserve', 'noop'], true)
            || $row->status !== ($operation === 'noop' ? ScheduleImportRowStatus::Duplicate : ScheduleImportRowStatus::Valid)
            || $row->validation_errors !== [] || $row->fingerprint === null
            || ($candidate['academic_period_id'] ?? null) !== $batch->academic_period_id) {
            $this->invalidOperation();
        }
        if (in_array($operation, ['preserve', 'noop'], true)) {
            return;
        }
        // Lookup the reserved identity, including deleted history, never a cached ID.
        $lesson = $owner->lessons()->withTrashed()->where('academic_period_id', $batch->academic_period_id)
            ->where('import_fingerprint', $row->fingerprint)->lockForUpdate()->first();
        if ($operation === 'create' ? $lesson !== null
            : ($lesson === null || $lesson->getKey() !== $candidate['matched_lesson_id']
                || $lesson->user_id !== $owner->getKey() || $lesson->academic_period_id !== $batch->academic_period_id
                || $lesson->import_fingerprint !== $row->fingerprint
                || ($operation === 'update' && ($lesson->trashed() || $lesson->status !== LessonStatus::Active))
                || ($operation === 'restore' && ! $lesson->trashed()))) {
            $this->invalidOperation();
        }
        $source = [
            'academic_period_id' => $batch->academic_period_id,
            'subject_id' => $candidate['subject_id'], 'teacher_id' => $candidate['teacher_id'],
            'room' => $candidate['room'], 'schedule_import_batch_id' => $batch->getKey(),
            'starts_at' => CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $row->raw_data['starts_at_utc'], 'UTC'),
            'ends_at' => CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $row->raw_data['ends_at_utc'], 'UTC'),
        ];
        if ($operation === 'create') {
            $created = $owner->lessons()->create([
                ...$source, 'replaces_lesson_id' => null, 'type' => LessonType::Other,
                'status' => LessonStatus::Active, 'import_fingerprint' => $row->fingerprint,
            ]);
            if (! $created->exists) {
                $this->invalidOperation();
            }
        } elseif ($operation === 'restore') {
            $lesson->fill([...$source, 'status' => LessonStatus::Active]);
            if (! $lesson->restore()) {
                $this->invalidOperation();
            }
        } elseif (! $lesson->update($source)) {
            $this->invalidOperation();
        }
        // Existing type, immutable fingerprint and replacement links are never assigned.
    }

    private function invalidOperation(): never
    {
        throw ValidationException::withMessages(['schedule_import' => ['This import preview could not be applied. Revalidate it before committing.']]);
    }
}
