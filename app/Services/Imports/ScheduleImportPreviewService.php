<?php

namespace App\Services\Imports;

use App\Enums\ScheduleImportBatchStatus;
use App\Enums\ScheduleImportRowStatus;
use App\Exceptions\ScheduleOccurrenceMaterializationException;
use App\Exceptions\ScheduleWorkbookParserException;
use App\Models\AcademicPeriod;
use App\Models\ScheduleImportBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ScheduleImportPreviewService
{
    public function __construct(
        private ScheduleWorkbookParser $parser,
        private ScheduleOccurrenceMaterializer $materializer,
        private ScheduleOccurrenceResolver $resolver,
        private ScheduleImportCandidateClassifier $classifier,
    ) {}

    public function create(User $user, int $academicPeriodId, UploadedFile $file): ScheduleImportBatch
    {
        $this->ownedPeriod($user, $academicPeriodId);
        $path = $file->getPathname();
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash the uploaded workbook.');
        }
        $filename = $this->safeFilename($file->getClientOriginalName());
        $template = null;
        $processingError = null;
        // Raw workbook reading deliberately precedes the owner lock/transaction.
        try {
            $template = $this->parser->parse($path);
        } catch (ScheduleWorkbookParserException $exception) {
            $processingError = $exception->getMessage();
        }

        return DB::transaction(function () use ($user, $academicPeriodId, $hash, $filename, $template, $processingError): ScheduleImportBatch {
            $owner = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $period = $this->ownedPeriod($owner, $academicPeriodId, true);
            $occurrences = [];
            if ($processingError === null) {
                try {
                    $materialized = $this->materializer->materialize($template, $period, $owner->timezone);
                    $resolved = $this->resolver->resolve($owner, $period, $materialized);
                    $classified = $this->classifier->classify($owner, $period, $resolved);
                    $occurrences = $classified['occurrences'];
                } catch (ScheduleOccurrenceMaterializationException $exception) {
                    $processingError = $exception->getMessage();
                }
            }

            $counts = ['valid' => 0, 'invalid' => 0, 'duplicate' => 0];
            foreach ($occurrences as $occurrence) {
                $counts[$occurrence['status']->value]++;
            }
            $batch = $owner->scheduleImportBatches()->create([
                'academic_period_id' => $period->getKey(),
                'original_filename' => $filename,
                'file_hash' => $hash,
                'status' => $processingError !== null ? ScheduleImportBatchStatus::Failed
                    : ($counts[ScheduleImportRowStatus::Invalid->value] === 0 ? ScheduleImportBatchStatus::Validated : ScheduleImportBatchStatus::Uploaded),
                'total_rows' => count($occurrences),
                'valid_rows' => $counts['valid'],
                'invalid_rows' => $counts['invalid'],
                'duplicate_rows' => $counts['duplicate'],
                'error_message' => $processingError,
                'committed_at' => null,
            ]);
            foreach ($occurrences as $index => $occurrence) {
                $batch->rows()->create([
                    'row_number' => $index + 1,
                    'status' => $occurrence['status'],
                    'raw_data' => $this->rawData($template, $occurrence),
                    'normalized_data' => $this->normalizedData($occurrence),
                    'validation_errors' => $occurrence['validation_errors'],
                    'fingerprint' => $occurrence['fingerprint'],
                ]);
            }

            return $batch->load(['rows' => fn ($query) => $query->orderBy('row_number')]);
        });
    }

    private function ownedPeriod(User $user, int $id, bool $lock = false): AcademicPeriod
    {
        $query = $user->academicPeriods()->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $period = $query->first();
        if ($period === null) {
            throw ValidationException::withMessages(['academic_period_id' => ['The selected academic period is invalid.']]);
        }

        return $period;
    }

    private function safeFilename(string $filename): string
    {
        $basename = basename(str_replace('\\', '/', $filename));
        $basename = preg_replace('/[\x00-\x1F\x7F]/u', '', $basename);

        return mb_substr($basename ?: 'schedule.xlsx', 0, 255);
    }

    /** Source data contains no Lesson type: Other is exclusively a domain fallback. */
    private function rawData(array $template, array $row): array
    {
        return [
            'group' => $template['group'],
            'workbook_source' => $template['source'],
            'local_date' => $row['local_date'],
            'weekday' => $row['weekday'],
            'pair_label' => $row['pair_label'],
            'parity' => $row['parity'],
            'subject_name' => $row['subject_name'],
            'teacher_name' => $row['teacher_name'],
            'room' => $row['room'],
            'starts_at_local' => $row['starts_at_local'],
            'ends_at_local' => $row['ends_at_local'],
            'starts_at_utc' => $row['starts_at_utc'],
            'ends_at_utc' => $row['ends_at_utc'],
            'source' => $row['source'],
        ];
    }

    private function normalizedData(array $row): array
    {
        return [
            'academic_period_id' => $row['academic_period_id'],
            'subject_id' => $row['subject_id'],
            'teacher_id' => $row['teacher_id'],
            'room' => $row['room'],
            'type' => $row['type']->value,
            'starts_at_utc' => $row['starts_at_utc'],
            'ends_at_utc' => $row['ends_at_utc'],
            'operation' => $row['operation'],
            'matched_lesson_id' => $row['matched_lesson_id'],
        ];
    }
}
