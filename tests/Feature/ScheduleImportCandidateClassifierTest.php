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
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleImportCandidateClassifierTest extends TestCase
{
    use RefreshDatabase;

    private const SCHEDULE_ERROR = ['schedule' => ['This imported occurrence conflicts with the projected schedule.']];

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
            $this->assertSame([
                ...$resolved['occurrences'][$index], 'status' => ScheduleImportRowStatus::Valid,
                'operation' => 'create', 'validation_errors' => [], 'matched_lesson_id' => null,
            ], $row);
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
        return [[LessonType::Other], [LessonType::Lecture], [LessonType::Laboratory]];
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
        return array_map(fn (string $change) => [$change], ['teacher', 'removed teacher', 'room', 'subject', 'start', 'end', 'moved outside target range']);
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
        return [[LessonStatus::Cancelled], [LessonStatus::Replaced]];
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
        return [[LessonStatus::Active], ...self::inactiveStatuses()];
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

    public function test_deleted_replaced_identity_cannot_restore_over_an_active_replacement(): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        $original = $this->history($user, $candidate, ['status' => LessonStatus::Replaced]);
        $replacement = $this->history($user, $candidate, ['import_fingerprint' => null, 'replaces_lesson_id' => $original->id]);
        $original->delete();
        $row = $this->classify($user, $period, [$candidate])[0];

        $this->assertClassification($row, ScheduleImportRowStatus::Invalid, null, $original->id, self::SCHEDULE_ERROR);
        $this->assertTrue($original->refresh()->trashed());
        $this->assertSame(LessonStatus::Active, $replacement->refresh()->status);
    }

    public function test_identical_input_projects_one_canonical_identity_and_type_is_not_authoritative(): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        $repeated = [...$candidate, 'type' => LessonType::Lecture, 'source' => ['worksheet' => 'Sheet1', 'rows' => [19, 20], 'range' => 'D19:F20', 'pair_range' => 'B18:F21']];
        $rows = $this->classify($user, $period, [$candidate, $repeated]);

        $this->assertClassification($rows[0], ScheduleImportRowStatus::Valid, 'create');
        $this->assertClassification($rows[1], ScheduleImportRowStatus::Duplicate, 'noop');
        $this->assertSame($candidate['source'], $rows[0]['source']);
        $this->assertSame($repeated['source'], $rows[1]['source']);
    }

    #[DataProvider('contradictoryFields')]
    public function test_conflicting_same_fingerprint_data_invalidates_every_row(string $field): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        $changed = $candidate;
        $changed[$field] = $field === 'room' ? 'different room' : Teacher::factory()->for($user)->create()->id;
        $rows = $this->classify($user, $period, [$candidate, $changed, $candidate]);

        foreach ($rows as $row) {
            $this->assertClassification($row, ScheduleImportRowStatus::Invalid, null, null, self::IDENTITY_ERROR);
        }
    }

    public static function contradictoryFields(): array
    {
        return [['room'], ['teacher_id']];
    }

    #[DataProvider('canonicalHistory')]
    public function test_overlapping_projected_identities_invalidate_both_groups_including_active_noop_and_repeats(bool $existing): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $first = $this->candidate($period, $subject, $teacher);
        $second = $this->candidate($period, $subject, $teacher, '2026-10-05T07:00:00Z', '2026-10-05T08:30:00Z');
        $lesson = $existing ? $this->history($user, $first) : null;
        $rows = $this->classify($user, $period, [$first, $second, $first]);

        foreach ([0, 2] as $index) {
            $this->assertClassification($rows[$index], ScheduleImportRowStatus::Invalid, null, $lesson?->id, self::SCHEDULE_ERROR);
        }
        $this->assertClassification($rows[1], ScheduleImportRowStatus::Invalid, null, null, self::SCHEDULE_ERROR);
        $reverse = $this->classify($user, $period, [$second, $first, $first]);
        $this->assertSame(array_column($rows, 'status'), array_column($reverse, 'status'));
    }

    public static function canonicalHistory(): array
    {
        return [[false], [true]];
    }

    public function test_projected_conflict_chain_checks_every_identity_without_simulating_partial_commits(): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidates = [
            $this->candidate($period, $subject, $teacher, '2026-10-05T06:00:00Z', '2026-10-05T07:00:00Z'),
            $this->candidate($period, $subject, $teacher, '2026-10-05T06:30:00Z', '2026-10-05T07:30:00Z'),
            $this->candidate($period, $subject, $teacher, '2026-10-05T07:00:00Z', '2026-10-05T08:00:00Z'),
            $this->candidate($period, $subject, $teacher, '2026-10-05T08:00:00Z', '2026-10-05T09:00:00Z'),
        ];
        $rows = $this->classify($user, $period, $candidates);

        foreach ([0, 1, 2] as $index) {
            $this->assertClassification($rows[$index], ScheduleImportRowStatus::Invalid, null, null, self::SCHEDULE_ERROR);
        }
        $this->assertClassification($rows[3], ScheduleImportRowStatus::Valid, 'create');
    }

    #[DataProvider('manualOverlaps')]
    public function test_manual_exact_or_partial_overlap_is_conflict_and_never_adopted(string $start, string $end): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        $manual = $this->history($user, $candidate, ['import_fingerprint' => null, 'starts_at' => $start, 'ends_at' => $end]);
        $row = $this->classify($user, $period, [$candidate])[0];

        $this->assertClassification($row, ScheduleImportRowStatus::Invalid, null, null, self::SCHEDULE_ERROR);
        $this->assertNull($manual->refresh()->import_fingerprint);
    }

    public static function manualOverlaps(): array
    {
        return [
            ['2026-10-05 06:00:00', '2026-10-05 07:30:00'],
            ['2026-10-05 05:30:00', '2026-10-05 06:30:00'],
            ['2026-10-05 07:00:00', '2026-10-05 08:00:00'],
            ['2026-10-05 05:00:00', '2026-10-05 08:00:00'],
            ['2026-10-05 06:30:00', '2026-10-05 07:00:00'],
        ];
    }

    public function test_adjacency_is_allowed_for_external_and_projected_intervals(): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $first = $this->candidate($period, $subject, $teacher);
        $second = $this->candidate($period, $subject, $teacher, '2026-10-05T07:30:00Z', '2026-10-05T08:30:00Z');
        $this->history($user, $first, ['import_fingerprint' => null, 'starts_at' => '2026-10-05 05:00:00', 'ends_at' => '2026-10-05 06:00:00']);
        $this->history($user, $first, ['import_fingerprint' => null, 'starts_at' => '2026-10-05 08:30:00', 'ends_at' => '2026-10-05 09:00:00']);
        $rows = $this->classify($user, $period, [$first, $second]);

        foreach ($rows as $row) {
            $this->assertClassification($row, ScheduleImportRowStatus::Valid, 'create');
        }
    }

    #[DataProvider('ineffectiveBlockers')]
    public function test_unrelated_ineffective_or_foreign_lessons_do_not_block_or_match(string $state): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        if (str_starts_with($state, 'foreign')) {
            [$otherUser, $otherPeriod, $otherSubject, $otherTeacher] = $this->context();
            $otherCandidate = $this->candidate($otherPeriod, $otherSubject, $otherTeacher);
            $this->history($otherUser, $otherCandidate, ['import_fingerprint' => $state === 'foreign imported' ? $candidate['fingerprint'] : null]);
        } else {
            $lesson = $this->history($user, $candidate, ['import_fingerprint' => null, 'status' => $state === 'deleted' ? LessonStatus::Active : LessonStatus::from($state)]);
            if ($state === 'deleted') {
                $lesson->delete();
            }
        }
        $row = $this->classify($user, $period, [$candidate])[0];

        $this->assertClassification($row, ScheduleImportRowStatus::Valid, 'create');
    }

    public static function ineffectiveBlockers(): array
    {
        return [['cancelled'], ['replaced'], ['deleted'], ['foreign manual'], ['foreign imported']];
    }

    #[DataProvider('canonicalHistory')]
    public function test_other_period_identity_is_not_matched_but_active_lessons_still_block(bool $active): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        $otherPeriod = AcademicPeriod::factory()->for($user)->create();
        $this->history($user, $candidate, ['academic_period_id' => $otherPeriod->id, 'status' => $active ? LessonStatus::Active : LessonStatus::Cancelled]);
        $row = $this->classify($user, $period, [$candidate])[0];

        $this->assertClassification($row, $active ? ScheduleImportRowStatus::Invalid : ScheduleImportRowStatus::Valid,
            $active ? null : 'create', null, $active ? self::SCHEDULE_ERROR : []);
    }

    #[DataProvider('canonicalHistory')]
    public function test_swapped_matching_histories_are_excluded_together_but_real_external_blockers_propagate(bool $external): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $first = $this->candidate($period, $subject, $teacher, '2026-10-05T06:00:00Z', '2026-10-05T07:00:00Z');
        $second = $this->candidate($period, $subject, $teacher, '2026-10-05T07:00:00Z', '2026-10-05T08:00:00Z');
        $firstLesson = $this->history($user, $first, ['starts_at' => '2026-10-05 07:00:00', 'ends_at' => '2026-10-05 08:00:00', 'type' => LessonType::Laboratory]);
        $secondLesson = $this->history($user, $second, ['starts_at' => '2026-10-05 06:00:00', 'ends_at' => '2026-10-05 07:00:00']);
        if ($external) {
            $this->history($user, $first, ['import_fingerprint' => null, 'starts_at' => '2026-10-05 06:15:00', 'ends_at' => '2026-10-05 06:45:00']);
        }
        $rows = $this->classify($user, $period, [$first, $second, $first]);

        $this->assertClassification($rows[0], $external ? ScheduleImportRowStatus::Invalid : ScheduleImportRowStatus::Valid,
            $external ? null : 'update', $firstLesson->id, $external ? self::SCHEDULE_ERROR : []);
        $this->assertClassification($rows[1], ScheduleImportRowStatus::Valid, 'update', $secondLesson->id);
        $this->assertClassification($rows[2], $external ? ScheduleImportRowStatus::Invalid : ScheduleImportRowStatus::Duplicate,
            $external ? null : 'noop', $firstLesson->id, $external ? self::SCHEDULE_ERROR : []);
        $this->assertSame(LessonType::Laboratory, $firstLesson->refresh()->type);
    }

    #[DataProvider('unresolvedFields')]
    public function test_resolution_errors_remain_invalid_and_matching_lessons_never_rescue_them(string $field): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        $this->history($user, $candidate);
        $errors = [$field.'_name' => ['An exact owned reference is required.']];
        $candidate[$field.'_id'] = null;
        $candidate['resolution_errors'] = $errors;
        if ($field === 'subject') {
            $candidate['fingerprint'] = null;
        }
        $row = $this->classify($user, $period, [$candidate])[0];

        $this->assertClassification($row, ScheduleImportRowStatus::Invalid, null, null, $errors);
        $this->assertSame($errors, $row['resolution_errors']);
        $this->assertSame($candidate['fingerprint'], $row['fingerprint']);
    }

    public static function unresolvedFields(): array
    {
        return [['subject'], ['teacher']];
    }

    public function test_contradictory_identity_is_not_projected_and_its_current_active_history_still_blocks(): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $first = $this->candidate($period, $subject, $teacher);
        $lesson = $this->history($user, $first);
        $contradiction = [...$first, 'room' => 'different'];
        $other = $this->candidate($period, $subject, $teacher, '2026-10-05T07:00:00Z', '2026-10-05T08:30:00Z');
        $rows = $this->classify($user, $period, [$first, $contradiction, $other]);

        foreach ([0, 1] as $index) {
            $this->assertClassification($rows[$index], ScheduleImportRowStatus::Invalid, null, $lesson->id, self::IDENTITY_ERROR);
        }
        $this->assertClassification($rows[2], ScheduleImportRowStatus::Invalid, null, null, self::SCHEDULE_ERROR);
    }

    public function test_read_only_queries_are_batched_and_external_blockers_are_time_bounded(): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        $this->history($user, $candidate, ['import_fingerprint' => null, 'starts_at' => '2026-11-01 06:00:00', 'ends_at' => '2026-11-01 07:30:00']);
        $before = $this->snapshot();
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(ScheduleImportCandidateClassifier::class)->classify($user, $period, $this->resolved($period, [$candidate]));
            $small = DB::getQueryLog();
            DB::flushQueryLog();
            $largeResult = app(ScheduleImportCandidateClassifier::class)->classify($user, $period, $this->resolved($period, array_fill(0, 500, $candidate)));
            $large = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertCount(3, $small);
        $this->assertSame(array_column($small, 'query'), array_column($large, 'query'));
        $this->assertSame(array_column($small, 'bindings'), array_column($large, 'bindings'));
        foreach ($large as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
        }
        $this->assertStringContainsString('`starts_at` < ?', $large[2]['query']);
        $this->assertStringContainsString('`ends_at` > ?', $large[2]['query']);
        $this->assertContains('2026-10-05 07:30:00', $large[2]['bindings']);
        $this->assertContains('2026-10-05 06:00:00', $large[2]['bindings']);
        $this->assertCount(500, $largeResult['occurrences']);
        $this->assertClassification($largeResult['occurrences'][0], ScheduleImportRowStatus::Valid, 'create');
        $this->assertClassification($largeResult['occurrences'][499], ScheduleImportRowStatus::Duplicate, 'noop');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_foreign_or_mismatched_period_is_rejected(): void
    {
        [$user, $period] = $this->context();
        $foreign = AcademicPeriod::factory()->create();
        $foreign->user_id = $user->id;
        $this->expectException(ValidationException::class);
        app(ScheduleImportCandidateClassifier::class)->classify($user, $foreign, $this->resolved($period, []));
    }

    public function test_result_and_occurrence_periods_must_match_the_selected_owned_period(): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $otherPeriod = AcademicPeriod::factory()->for($user)->create();
        $candidate = $this->candidate($otherPeriod, $subject, $teacher);
        $row = $this->classify($user, $period, [$candidate])[0];
        $this->assertClassification($row, ScheduleImportRowStatus::Invalid, null, null,
            ['academic_period_id' => ['The selected academic period is invalid.']]);

        $this->expectException(ValidationException::class);
        app(ScheduleImportCandidateClassifier::class)->classify($user, $period, $this->resolved($otherPeriod, []));
    }

    public function test_utc_classification_is_independent_of_server_timezone(): void
    {
        [$user, $period, $subject, $teacher] = $this->context();
        $candidate = $this->candidate($period, $subject, $teacher);
        $lesson = $this->history($user, $candidate);
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Apia');
            $row = $this->classify($user, $period, [$candidate])[0];
            $this->assertClassification($row, ScheduleImportRowStatus::Duplicate, 'noop', $lesson->id);
        } finally {
            date_default_timezone_set($timezone);
        }
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

        return [
            'local_date' => $date->toDateString(), 'weekday' => $date->isoWeekday(), 'pair_label' => '1-2', 'parity' => 'all',
            'subject_name' => $subject->name, 'teacher_name' => $teacher->name, 'room' => null, 'type' => LessonType::Other,
            'starts_at_local' => substr($start, 11, 8), 'ends_at_local' => substr($end, 11, 8), 'starts_at_utc' => $start, 'ends_at_utc' => $end,
            'source' => ['worksheet' => 'Sheet1', 'rows' => [3, 4], 'range' => 'D3:F4', 'pair_range' => 'B2:F5'],
            'academic_period_id' => $period->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'fingerprint' => (new ScheduleImportFingerprint)->generate($subject->id, $start, $end), 'resolution_errors' => [],
        ];
    }

    private function history(User $user, array $candidate, array $changes = []): Lesson
    {
        return Lesson::factory()->for($user)->create([
            'academic_period_id' => $candidate['academic_period_id'], 'subject_id' => $candidate['subject_id'],
            'teacher_id' => $candidate['teacher_id'], 'room' => $candidate['room'], 'type' => $candidate['type'],
            'starts_at' => str_replace(['T', 'Z'], [' ', ''], $candidate['starts_at_utc']),
            'ends_at' => str_replace(['T', 'Z'], [' ', ''], $candidate['ends_at_utc']),
            'import_fingerprint' => $candidate['fingerprint'], ...$changes,
        ]);
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
