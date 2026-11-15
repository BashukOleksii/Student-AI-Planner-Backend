<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\ScheduleImportRowStatus;
use App\Models\AcademicPeriod;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Imports\ScheduleImportCandidateClassifier;
use App\Services\Imports\ScheduleImportFingerprint;
use App\Services\Imports\ScheduleOccurrenceMaterializer;
use App\Services\Imports\ScheduleOccurrenceResolver;
use App\Services\Imports\ScheduleWorkbookParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('integration')]
#[Group('mysql')]
class CompactScheduleImportCandidateClassifierTest extends TestCase
{
    use RefreshDatabase;

    private const SCHEDULE_ERROR = ['schedule' => ['Це імпортоване заняття конфліктує з майбутнім розкладом.']];

    private const IDENTITY_ERROR = ['import_identity' => ['Multiple imported occurrences with the same identity contain conflicting data.']];

    public function test_real_workbook_pipeline_classifies_thirty_valid_creates_without_writes(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $period = AcademicPeriod::factory()->for($user)->create(['starts_on' => '2026-09-07', 'ends_on' => '2026-09-20']);
        $template = (new ScheduleWorkbookParser)->parse(__DIR__.'/../Fixtures/ScheduleImports/Приклад розкладу.xlsx');
        foreach (array_unique(array_column($template['rules'], 'subject_name')) as $name) {
            Subject::factory()->for($user)->create(['name' => $name]);
        }
        foreach (array_unique(array_column($template['rules'], 'teacher_name')) as $name) {
            Teacher::factory()->for($user)->create(['name' => $name]);
        }
        $materialized = (new ScheduleOccurrenceMaterializer)->materialize($template, $period, $user->timezone);
        $resolved = app(ScheduleOccurrenceResolver::class)->resolve($user, $period, $materialized);
        $before = $this->snapshot();
        $result = app(ScheduleImportCandidateClassifier::class)->classify($user, $period, $resolved);
        $this->assertCount(30, $result['occurrences']);
        foreach ($result['occurrences'] as $index => $row) {
            $this->assertSame([...$resolved['occurrences'][$index], 'status' => ScheduleImportRowStatus::Valid, 'operation' => 'create', 'validation_errors' => [], 'matched_lesson_id' => null], $row);
            $this->assertSame(LessonType::Other, $row['type']);
        }
        $this->assertSame($resolved['group'], $result['group']);
        $this->assertSame($resolved['source'], $result['source']);
        $this->assertSame($period->id, $result['academic_period_id']);
        $this->assertSame($before, $this->snapshot());
        foreach (['lessons', 'schedule_import_batches', 'schedule_import_rows'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    #[DataProvider('existingTypes')]
    public function test_active_identical_import_is_duplicate_even_with_a_corrected_type_and_no_batch(LessonType $type): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        $lesson = $this->history($user, $candidate, ['type' => $type]);
        $row = $this->classify($user, $period, [$candidate])[0];
        $this->assertClassification($row, ScheduleImportRowStatus::Duplicate, 'noop', $lesson->id);
        $this->assertSame(LessonType::Other, $row['type']);
        $this->assertSame($type, $lesson->refresh()->type);
        $this->assertNull($lesson->schedule_import_batch_id);
    }

    public static function existingTypes(): array
    {
        return [0 => [LessonType::Other]];
    }

    #[DataProvider('authoritativeChanges')]
    public function test_active_identity_source_changes_classify_update_and_preserve_stored_type(string $change): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        $changes = match ($change) {
            'teacher' => ['teacher_id' => Teacher::factory()->for($user)->create()->id],
            'removed teacher' => ['teacher_id' => null],
            'room' => ['room' => 'manually corrected'],
            'subject' => ['subject_id' => Subject::factory()->for($user)->create()->id],
            'start' => ['starts_at' => '2026-10-05 05:00:00'],
            'end' => ['ends_at' => '2026-10-05 08:00:00'],
            'moved outside target range' => ['starts_at' => '2026-10-06 09:00:00', 'ends_at' => '2026-10-06 10:30:00'],
        };
        $lesson = $this->history($user, $candidate, [...$changes, 'type' => LessonType::Laboratory]);
        $row = $this->classify($user, $period, [$candidate])[0];
        $this->assertClassification($row, ScheduleImportRowStatus::Valid, 'update', $lesson->id);
        $this->assertSame($candidate['fingerprint'], $lesson->refresh()->import_fingerprint);
        $this->assertSame(LessonType::Laboratory, $lesson->type);
        $this->assertSame(LessonType::Other, $row['type']);
    }

    public static function authoritativeChanges(): array
    {
        return [0 => ['teacher']];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_cancelled_or_replaced_identity_is_preserved_without_projecting_it(LessonStatus $status): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        $lesson = $this->history($user, $candidate, ['status' => $status, 'room' => 'manual change', 'type' => LessonType::Lecture]);
        $this->history($user, $candidate, ['import_fingerprint' => null]);
        $duplicate = [...$candidate, 'type' => LessonType::Laboratory];
        $rows = $this->classify($user, $period, [$candidate, $duplicate]);
        $this->assertClassification($rows[0], ScheduleImportRowStatus::Valid, 'preserve', $lesson->id);
        $this->assertClassification($rows[1], ScheduleImportRowStatus::Duplicate, 'noop', $lesson->id);
        $this->assertSame($status, $lesson->refresh()->status);
    }

    public static function inactiveStatuses(): array
    {
        return [0 => [LessonStatus::Cancelled]];
    }

    public function test_preserved_original_does_not_project_but_active_replacement_blocks_other_candidates(): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $originalCandidate = $this->candidate($period, $subject, $teacher);
        $original = $this->history($user, $originalCandidate, ['status' => LessonStatus::Replaced]);
        $replacement = $this->history($user, $originalCandidate, ['import_fingerprint' => null, 'replaces_lesson_id' => $original->id]);
        $otherSubject = Subject::factory()->for($user)->create();
        $otherCandidate = $this->candidate($period, $otherSubject, $teacher);
        $rows = $this->classify($user, $period, [$originalCandidate, $otherCandidate]);
        $this->assertClassification($rows[0], ScheduleImportRowStatus::Valid, 'preserve', $original->id);
        $this->assertClassification($rows[1], ScheduleImportRowStatus::Invalid, null, null, self::SCHEDULE_ERROR);
        $this->assertSame(LessonStatus::Active, $replacement->refresh()->status);
        $this->assertSame($original->id, $replacement->replaces_lesson_id);
    }

    #[DataProvider('allStatuses')]
    public function test_deleted_identity_is_reserved_for_restore_without_restoring_or_changing_type(LessonStatus $status): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        $lesson = $this->history($user, $candidate, ['status' => $status, 'type' => LessonType::Laboratory, 'room' => 'old room']);
        $lesson->delete();
        $row = $this->classify($user, $period, [$candidate])[0];
        $this->assertClassification($row, ScheduleImportRowStatus::Valid, 'restore', $lesson->id);
        $this->assertTrue($lesson->refresh()->trashed());
        $this->assertSame(LessonType::Laboratory, $lesson->type);
        $this->assertSame(LessonType::Other, $row['type']);
    }

    public static function allStatuses(): array
    {
        return [0 => [LessonStatus::Active]];
    }

    public function test_restore_conflict_propagates_to_repeated_rows_and_does_not_restore_history(): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        $lesson = $this->history($user, $candidate);
        $lesson->delete();
        $this->history($user, $candidate, ['import_fingerprint' => null]);
        $rows = $this->classify($user, $period, [$candidate, $candidate]);
        foreach ($rows as $row) {
            $this->assertClassification($row, ScheduleImportRowStatus::Invalid, null, $lesson->id, self::SCHEDULE_ERROR);
        }
        $this->assertTrue($lesson->refresh()->trashed());
    }

    private function context(): array
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $period = AcademicPeriod::factory()->for($user)->create(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $subject = Subject::factory()->for($user)->create();
        $teacher = Teacher::factory()->for($user)->create();

        return [$user, $period, $subject, $teacher];
    }

    private function candidate(AcademicPeriod $period, Subject $subject, Teacher $teacher, string $start = '2026-10-05T06:00:00Z', string $end = '2026-10-05T07:30:00Z'): array
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $start, 'UTC');

        return ['local_date' => $date->toDateString(), 'weekday' => $date->isoWeekday(), 'pair_label' => '1-2', 'parity' => 'all', 'subject_name' => $subject->name, 'teacher_name' => $teacher->name, 'room' => null, 'type' => LessonType::Other, 'starts_at_local' => substr($start, 11, 8), 'ends_at_local' => substr($end, 11, 8), 'starts_at_utc' => $start, 'ends_at_utc' => $end, 'source' => ['worksheet' => 'Sheet1', 'rows' => [3, 4], 'range' => 'D3:F4', 'pair_range' => 'B2:F5'], 'academic_period_id' => $period->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'fingerprint' => (new ScheduleImportFingerprint)->generate($subject->id, $start, $end), 'resolution_errors' => []];
    }

    private function history(User $user, array $candidate, array $changes = []): Lesson
    {
        return Lesson::factory()->for($user)->create(['academic_period_id' => $candidate['academic_period_id'], 'subject_id' => $candidate['subject_id'], 'teacher_id' => $candidate['teacher_id'], 'room' => $candidate['room'], 'type' => $candidate['type'], 'starts_at' => str_replace(['T', 'Z'], [' ', ''], $candidate['starts_at_utc']), 'ends_at' => str_replace(['T', 'Z'], [' ', ''], $candidate['ends_at_utc']), 'import_fingerprint' => $candidate['fingerprint'], ...$changes]);
    }

    private function resolved(AcademicPeriod $period, array $candidates): array
    {
        return ['group' => 'ПІ-231', 'source' => ['worksheet' => 'Sheet1', 'range' => 'A1:F81'], 'academic_period_id' => $period->id, 'occurrences' => $candidates];
    }

    private function classify(User $user, AcademicPeriod $period, array $candidates): array
    {
        $before = $this->snapshot();
        $result = app(ScheduleImportCandidateClassifier::class)->classify($user, $period, $this->resolved($period, $candidates));
        $this->assertSame($before, $this->snapshot());

        return $result['occurrences'];
    }

    private function assertClassification(array $row, ScheduleImportRowStatus $status, ?string $operation, ?int $matched = null, array $errors = []): void
    {
        $this->assertSame($status, $row['status']);
        $this->assertSame($operation, $row['operation']);
        $this->assertSame($matched, $row['matched_lesson_id']);
        $this->assertSame($errors, $row['validation_errors']);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['subjects', 'teachers', 'academic_periods', 'lessons', 'schedule_import_batches', 'schedule_import_rows'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all();
        }

        return $snapshot;
    }
}
