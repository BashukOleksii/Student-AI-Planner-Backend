<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\ScheduleImportBatchStatus;
use App\Enums\ScheduleImportRowStatus;
use App\Models\AcademicPeriod;
use App\Models\Lesson;
use App\Models\ScheduleImportBatch;
use App\Models\ScheduleImportRow;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Imports\ScheduleImportCandidateClassifier;
use App\Services\Imports\ScheduleImportCommitService;
use App\Services\Imports\ScheduleImportFingerprint;
use App\Services\Imports\ScheduleImportPreviewRevalidator;
use App\Services\Imports\ScheduleOccurrenceMaterializer;
use App\Services\Imports\ScheduleWorkbookParser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ScheduleImportCommitTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../Fixtures/ScheduleImports/Приклад розкладу.xlsx';

    private const CHANGED_MESSAGE = 'The import preview changed. Review it and confirm again.';

    public function test_real_workbook_commits_thirty_owned_active_lessons_and_keeps_applied_preview(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11T08:15:30Z'));
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $confirmed = $this->rowSnapshot($batch);
        $response = $this->commit($user, $batch)->assertOk()->assertJsonPath('data.status', 'committed')
            ->assertJsonPath('data.committed_at', '2026-10-11T08:15:30Z')->assertJsonPath('data.total_rows', 30)
            ->assertJsonPath('data.valid_rows', 30)->assertJsonPath('data.invalid_rows', 0)
            ->assertJsonPath('data.duplicate_rows', 0)->assertJsonCount(30, 'data.rows');
        $this->assertArrayNotHasKey('message', $response->json());
        $this->assertDatabaseCount('lessons', 30);
        $this->assertSame(30, $batch->lessons()->count());
        $this->assertSame(30, Lesson::distinct()->count('import_fingerprint'));
        $this->assertSame($confirmed, $this->rowSnapshot($batch));
        foreach ($batch->rows()->orderBy('row_number')->get() as $row) {
            $lesson = $user->lessons()->where('import_fingerprint', $row->fingerprint)->sole();
            $this->assertSource($lesson, $row, $batch);
            $this->assertSame(LessonType::Other, $lesson->type);
            $this->assertSame($user->id, $lesson->user_id);
            $this->assertNull($lesson->replaces_lesson_id);
            $this->assertSame('create', $row->normalized_data['operation']);
            $this->assertSame(ScheduleImportRowStatus::Valid, $row->status);
        }
        $this->assertSame(range(1, 30), array_column($response->json('data.rows'), 'row_number'));
        $this->getJson('/api/schedule-imports/'.$batch->id)->assertOk()->assertExactJson($response->json());
    }

    public function test_same_workbook_reimport_commits_thirty_noops_without_touching_lessons_or_applying_provenance(): void
    {
        [$user, $period] = $this->context();
        $first = $this->upload($user, $period);
        $this->commit($user, $first)->assertOk();
        $before = $this->snapshot(['lessons']);
        $second = $this->upload($user, $period);
        $this->assertSame(30, $second->duplicate_rows);
        $this->assertSame(0, $second->valid_rows);
        $confirmed = $this->rowSnapshot($second);
        $this->commit($user, $second)->assertOk()->assertJsonPath('data.status', 'committed');
        $this->assertSame($before, $this->snapshot(['lessons']));
        $this->assertSame($confirmed, $this->rowSnapshot($second));
        $this->assertSame(30, $first->lessons()->count());
        $this->assertSame(0, $second->lessons()->count());
        foreach ($second->rows()->get() as $row) {
            $this->assertSame('noop', $row->normalized_data['operation']);
            $this->assertSame(ScheduleImportRowStatus::Duplicate, $row->status);
        }
    }

    #[DataProvider('correctedTypes')]
    public function test_update_reuses_identity_and_preserves_latest_type_even_if_changed_after_preview(LessonType $latestType): void
    {
        [$user, $period] = $this->context();
        $first = $this->upload($user, $period);
        $this->commit($user, $first)->assertOk();
        $row = $first->rows()->where('row_number', 1)->sole();
        $lesson = $user->lessons()->where('import_fingerprint', $row->fingerprint)->sole();
        $otherSubject = Subject::factory()->for($user)->create();
        $otherTeacher = Teacher::factory()->for($user)->create();
        $this->patchJson('/api/lessons/'.$lesson->id, [
            'subject_id' => $otherSubject->id, 'teacher_id' => $otherTeacher->id, 'room' => 'manual room',
            'type' => 'laboratory', 'starts_at' => '2026-09-06T08:00:00Z', 'ends_at' => '2026-09-06T09:00:00Z',
        ])->assertOk();
        $second = $this->upload($user, $period);
        $this->assertSame('update', $second->rows()->where('row_number', 1)->sole()->normalized_data['operation']);
        $this->patchJson('/api/lessons/'.$lesson->id, ['type' => $latestType->value])->assertOk();
        $untouched = $this->snapshotLessonsExcept($lesson->id);
        $this->commit($user, $second)->assertOk()->assertJsonPath('data.status', 'committed');
        $lesson->refresh();
        $this->assertSource($lesson, $second->rows()->where('row_number', 1)->sole(), $second);
        $this->assertSame($latestType, $lesson->type);
        $this->assertSame($untouched, $this->snapshotLessonsExcept($lesson->id));
        $this->assertSame(30, Lesson::withTrashed()->count());
        $this->assertSame(1, $second->lessons()->count());
    }

    public static function correctedTypes(): array
    {
        return [[LessonType::Laboratory], [LessonType::Lecture], [LessonType::Practical]];
    }

    #[DataProvider('deletedStatuses')]
    public function test_restore_reuses_deleted_row_preserves_type_and_activates_reserved_identity(bool $cancelBeforeDeletion): void
    {
        [$user, $period] = $this->context();
        $first = $this->upload($user, $period);
        $this->commit($user, $first)->assertOk();
        $row = $first->rows()->where('row_number', 1)->sole();
        $lesson = $user->lessons()->where('import_fingerprint', $row->fingerprint)->sole();
        $id = $lesson->id;
        $this->patchJson('/api/lessons/'.$id, [
            'type' => 'laboratory', 'room' => 'changed before deletion',
            'starts_at' => '2026-09-06T08:00:00Z', 'ends_at' => '2026-09-06T09:00:00Z',
        ])->assertOk();
        if ($cancelBeforeDeletion) {
            $this->postJson('/api/lessons/'.$id.'/cancel')->assertOk();
        }
        $this->deleteJson('/api/lessons/'.$id)->assertNoContent();
        $second = $this->upload($user, $period);
        $restore = $second->rows()->where('row_number', 1)->sole();
        $this->assertSame('restore', $restore->normalized_data['operation']);
        $this->commit($user, $second)->assertOk();
        $lesson->refresh();
        $this->assertSame($id, $lesson->id);
        $this->assertFalse($lesson->trashed());
        $this->assertSame(LessonType::Laboratory, $lesson->type);
        $this->assertSource($lesson, $restore->refresh(), $second);
        $this->assertSame(30, Lesson::withTrashed()->count());
        $this->assertSame(30, Lesson::where('status', LessonStatus::Active)->count());
    }

    public static function deletedStatuses(): array
    {
        return [[false], [true]];
    }

    public function test_cancelled_identity_is_preserved_without_source_or_batch_fk_changes(): void
    {
        [$user, $period] = $this->context();
        $first = $this->upload($user, $period);
        $this->commit($user, $first)->assertOk();
        $lesson = $first->lessons()->orderBy('id')->first();
        $this->patchJson('/api/lessons/'.$lesson->id, ['type' => 'laboratory', 'room' => 'user correction'])->assertOk();
        $this->postJson('/api/lessons/'.$lesson->id.'/cancel')->assertOk();
        $before = $this->snapshot(['lessons']);
        $second = $this->upload($user, $period);
        $this->assertSame('preserve', $second->rows()->where('row_number', 1)->sole()->normalized_data['operation']);
        $this->commit($user, $second)->assertOk();
        $this->assertSame($before, $this->snapshot(['lessons']));
        $this->assertSame(LessonStatus::Cancelled, $lesson->refresh()->status);
        $this->assertSame(0, $second->lessons()->count());
    }

    public function test_normal_replacement_workflow_remains_untouched_by_preserve_commit(): void
    {
        [$user, $period] = $this->context();
        $first = $this->upload($user, $period);
        $this->commit($user, $first)->assertOk();
        $original = $first->lessons()->orderBy('id')->first();
        $this->patchJson('/api/lessons/'.$original->id, ['type' => 'laboratory', 'room' => 'user correction'])->assertOk();
        $replacementId = $this->postJson('/api/lessons/'.$original->id.'/replacement', [
            'academic_period_id' => $period->id, 'subject_id' => $original->subject_id, 'type' => 'practical',
            'starts_at' => '2026-09-13T09:00:00Z', 'ends_at' => '2026-09-13T10:00:00Z',
        ])->assertCreated()->json('data.id');
        $before = $this->snapshot(['lessons']);
        $second = $this->upload($user, $period);
        $this->assertSame('preserve', $second->rows()->where('row_number', 1)->sole()->normalized_data['operation']);
        $this->commit($user, $second)->assertOk();
        $this->assertSame($before, $this->snapshot(['lessons']));
        $this->assertSame(LessonStatus::Replaced, $original->refresh()->status);
        $replacement = Lesson::findOrFail($replacementId);
        $this->assertSame(LessonStatus::Active, $replacement->status);
        $this->assertSame($original->id, $replacement->replaces_lesson_id);
        $this->assertSame(0, $second->lessons()->count());
    }

    public function test_stale_new_manual_blocker_returns_409_and_persists_invalid_preview_without_import_mutation(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $candidate = $batch->rows()->where('row_number', 1)->sole()->normalized_data;
        $manualId = $this->postJson('/api/lessons', [
            'subject_id' => $candidate['subject_id'], 'teacher_id' => $candidate['teacher_id'],
            'academic_period_id' => $period->id, 'type' => 'laboratory', 'room' => $candidate['room'],
            'starts_at' => $candidate['starts_at_utc'], 'ends_at' => $candidate['ends_at_utc'],
        ])->assertCreated()->json('data.id');
        $before = $this->snapshot(['lessons']);
        $response = $this->commit($user, $batch)->assertConflict()->assertJsonPath('message', self::CHANGED_MESSAGE)
            ->assertJsonPath('data.status', 'uploaded')->assertJsonPath('data.rows.0.status', 'invalid')
            ->assertJsonPath('data.rows.0.operation', null)->assertJsonPath('data.invalid_rows', 1)
            ->assertJsonPath('data.committed_at', null);
        $this->assertSame($before, $this->snapshot(['lessons']));
        $this->assertSame(0, $batch->lessons()->count());
        $this->getJson('/api/schedule-imports/'.$batch->id)->assertOk()->assertJsonPath('data', $response->json('data'));
        $this->commit($user, $batch)->assertUnprocessable()->assertJsonPath('errors.schedule_import', ['This import preview is not ready to commit.']);
        $this->postJson('/api/lessons/'.$manualId.'/cancel')->assertOk();
        $this->postJson('/api/schedule-imports/'.$batch->id.'/revalidate')->assertOk()->assertJsonPath('data.status', 'validated');
        $this->commit($user, $batch)->assertOk()->assertJsonPath('data.status', 'committed');
        $this->assertDatabaseCount('lessons', 31);
        $this->assertSame(LessonStatus::Cancelled, Lesson::findOrFail($manualId)->status);
    }

    public function test_another_import_commit_changes_create_to_noop_and_requires_a_second_confirmation(): void
    {
        [$user, $period] = $this->context();
        $pending = $this->upload($user, $period);
        $other = $this->upload($user, $period);
        $this->commit($user, $other)->assertOk();
        $before = $this->snapshot(['lessons']);
        $this->commit($user, $pending)->assertConflict()->assertJsonPath('message', self::CHANGED_MESSAGE)
            ->assertJsonPath('data.status', 'validated')->assertJsonPath('data.duplicate_rows', 30)
            ->assertJsonPath('data.rows.0.operation', 'noop')->assertJsonPath('data.committed_at', null);
        $this->assertSame($before, $this->snapshot(['lessons']));
        $this->commit($user, $pending)->assertOk()->assertJsonPath('data.status', 'committed');
        $this->assertSame($before, $this->snapshot(['lessons']));
        $this->assertSame(0, $pending->lessons()->count());
        $this->assertSame(30, $other->lessons()->count());
    }

    #[DataProvider('changingOperations')]
    public function test_noop_or_update_becoming_restore_requires_reconfirmation_without_restoring_early(string $originalOperation): void
    {
        [$user, $period] = $this->context();
        $first = $this->upload($user, $period);
        $this->commit($user, $first)->assertOk();
        $lesson = $first->lessons()->orderBy('id')->first();
        $this->patchJson('/api/lessons/'.$lesson->id, [
            'type' => 'laboratory', ...($originalOperation === 'update' ? ['room' => 'user correction'] : []),
        ])->assertOk();
        $second = $this->upload($user, $period);
        $this->assertSame($originalOperation, $second->rows()->where('row_number', 1)->sole()->normalized_data['operation']);
        $this->deleteJson('/api/lessons/'.$lesson->id)->assertNoContent();
        $before = $this->snapshot(['lessons']);
        $this->commit($user, $second)->assertConflict()->assertJsonPath('data.rows.0.operation', 'restore');
        $this->assertSame($before, $this->snapshot(['lessons']));
        $this->assertTrue($lesson->refresh()->trashed());
        $this->commit($user, $second)->assertOk();
        $this->assertFalse($lesson->refresh()->trashed());
        $this->assertSame(LessonType::Laboratory, $lesson->type);
        $this->assertSame($second->id, $lesson->schedule_import_batch_id);
        $this->assertSame(30, Lesson::withTrashed()->count());
    }

    public static function changingOperations(): array
    {
        return [['noop'], ['update']];
    }

    #[DataProvider('referenceKinds')]
    public function test_changed_reference_mapping_requires_reconfirmation_even_when_operation_remains_create(string $kind): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $relation = $kind === 'subject' ? 'subjects' : 'teachers';
        $this->deleteJson('/api/'.$relation.'/'.$row->normalized_data[$kind.'_id'])->assertNoContent();
        $newId = $this->postJson('/api/'.$relation, ['name' => $row->raw_data[$kind.'_name']])->assertCreated()->json('data.id');
        $this->commit($user, $batch)->assertConflict()->assertJsonPath('data.rows.0.operation', 'create')
            ->assertJsonPath('data.rows.0.'.$kind.'_id', $newId)->assertJsonPath('data.status', 'validated');
        $this->assertDatabaseCount('lessons', 0);
        $this->commit($user, $batch)->assertOk();
        $this->assertDatabaseCount('lessons', 30);
    }

    public static function referenceKinds(): array
    {
        return [['subject'], ['teacher']];
    }

    public function test_renames_and_audit_timestamp_changes_do_not_require_reconfirmation(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $this->patchJson('/api/subjects/'.$row->normalized_data['subject_id'], ['name' => 'Renamed subject'])->assertOk();
        $this->patchJson('/api/teachers/'.$row->normalized_data['teacher_id'], ['name' => 'Renamed teacher'])->assertOk();
        DB::table('schedule_import_rows')->where('schedule_import_batch_id', $batch->id)->update(['updated_at' => '2000-01-01 00:00:00']);
        DB::table('schedule_import_batches')->where('id', $batch->id)->update(['updated_at' => '2000-01-01 00:00:00']);
        $this->commit($user, $batch)->assertOk()->assertJsonPath('data.status', 'committed');
        $this->assertDatabaseCount('lessons', 30);
    }

    #[DataProvider('nonReadyStates')]
    public function test_non_ready_batches_are_rejected_without_revalidation_or_lesson_changes(ScheduleImportBatchStatus $status): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $batch->update(['status' => $status]);
        $before = $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']);
        $this->mock(ScheduleImportPreviewRevalidator::class)->shouldNotReceive('refreshLocked');
        $this->commit($user, $batch)->assertUnprocessable()->assertJsonPath('errors.schedule_import', ['This import preview is not ready to commit.']);
        $this->assertSame($before, $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']));
    }

    public static function nonReadyStates(): array
    {
        return [[ScheduleImportBatchStatus::Uploaded], [ScheduleImportBatchStatus::Failed], [ScheduleImportBatchStatus::Cancelled]];
    }

    public function test_committed_retry_returns_stored_history_and_retains_committed_at_exactly_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11T08:15:30Z'));
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $response = $this->commit($user, $batch)->assertOk();
        $lesson = $batch->lessons()->orderBy('id')->first();
        $this->postJson('/api/lessons/'.$lesson->id.'/cancel')->assertOk();
        $before = $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']);
        $this->travel(2)->days();
        $actual = app(ScheduleImportPreviewRevalidator::class);
        $this->mock(ScheduleImportPreviewRevalidator::class)->shouldNotReceive('refreshLocked');
        $this->commit($user, $batch)->assertOk()->assertExactJson($response->json());
        $this->assertSame($before, $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']));
        $this->app->instance(ScheduleImportPreviewRevalidator::class, $actual);
        $this->postJson('/api/schedule-imports/'.$batch->id.'/revalidate')->assertUnprocessable();
    }

    public function test_authentication_foreign_batch_and_service_ownership_are_enforced(): void
    {
        $batch = ScheduleImportBatch::factory()->create(['status' => ScheduleImportBatchStatus::Validated]);
        $this->postJson($this->url($batch))->assertUnauthorized();
        $other = User::factory()->create();
        $this->commit($other, $batch)->assertNotFound();
        $before = $this->snapshot(['lessons', 'schedule_import_batches']);
        $batch->user_id = $other->id;
        try {
            app(ScheduleImportCommitService::class)->commit($other, $batch);
            $this->fail('Forged in-memory ownership must not be trusted.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $this->snapshot(['lessons', 'schedule_import_batches']));
        }
    }

    public function test_empty_validated_preview_commits_without_inventing_lessons(): void
    {
        [$user, $period] = $this->context();
        $period->update(['starts_on' => '2026-09-12', 'ends_on' => '2026-09-13']);
        $batch = $this->upload($user, $period);
        $this->commit($user, $batch)->assertOk()->assertJsonPath('data.status', 'committed')
            ->assertJsonPath('data.total_rows', 0)->assertJsonCount(0, 'data.rows');
        $this->assertNotNull($batch->refresh()->committed_at);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_core_source_corruption_rolls_back_and_cannot_commit(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 30)->sole();
        $raw = $row->raw_data;
        unset($raw['source']);
        $row->update(['raw_data' => $raw]);
        $before = $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']);
        $this->commit($user, $batch)->assertUnprocessable()->assertJsonPath('errors.schedule_import', ['This import preview is malformed. Create a new import preview.']);
        $this->assertSame($before, $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']));
    }

    public function test_invalid_utc_source_refreshes_to_uploaded_and_returns_409_without_lessons(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $row->update(['raw_data' => [...$row->raw_data, 'starts_at_utc' => 'not UTC']]);
        $this->commit($user, $batch)->assertConflict()->assertJsonPath('data.status', 'uploaded')
            ->assertJsonPath('data.rows.0.status', 'invalid');
        $this->assertDatabaseCount('lessons', 0);
        $this->assertNull($batch->refresh()->committed_at);
    }

    public function test_foreign_period_legacy_state_fails_without_lesson_mutation(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $batch->update(['academic_period_id' => AcademicPeriod::factory()->create()->id]);
        $before = $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']);
        $this->commit($user, $batch)->assertUnprocessable()->assertJsonValidationErrors('academic_period_id');
        $this->assertSame($before, $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']));
    }

    public function test_commit_uses_snapshot_utc_without_rematerialization_or_server_timezone_interpretation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-11T08:15:30Z'));
        [$user, $period] = $this->context();
        $period->update(['starts_on' => '2011-12-30', 'ends_on' => '2011-12-30']);
        $batch = $this->upload($user, $period);
        $period->update(['starts_on' => '2026-09-01', 'ends_on' => '2026-10-31']);
        $user->update(['timezone' => 'UTC']);
        $this->mock(ScheduleWorkbookParser::class)->shouldNotReceive('parse');
        $this->mock(ScheduleOccurrenceMaterializer::class)->shouldNotReceive('materialize');
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Apia');
            $this->commit($user, $batch, ['user_id' => 999999, 'timezone' => 'America/New_York', 'academic_period_id' => 999999])
                ->assertOk()->assertJsonPath('data.status', 'committed')->assertJsonPath('data.total_rows', 2)
                ->assertJsonPath('data.committed_at', '2026-10-11T08:15:30Z');
            foreach ($batch->rows()->get() as $row) {
                $this->assertSource($user->lessons()->where('import_fingerprint', $row->fingerprint)->sole(), $row, $batch);
            }
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_absent_historical_imported_lessons_are_never_deleted_or_cancelled(): void
    {
        [$user, $period] = $this->context();
        $first = $this->upload($user, $period);
        $this->commit($user, $first)->assertOk();
        $subjectId = $first->rows()->where('row_number', 1)->sole()->normalized_data['subject_id'];
        Lesson::factory()->for($user)->create([
            'subject_id' => $subjectId, 'academic_period_id' => $period->id, 'schedule_import_batch_id' => $first->id,
            'starts_at' => '2026-09-13 12:00:00', 'ends_at' => '2026-09-13 13:00:00',
            'import_fingerprint' => app(ScheduleImportFingerprint::class)->generate($subjectId, '2026-09-13T12:00:00Z', '2026-09-13T13:00:00Z'),
        ]);
        $before = $this->snapshot(['lessons']);
        $second = $this->upload($user, $period);
        $this->commit($user, $second)->assertOk();
        $this->assertSame($before, $this->snapshot(['lessons']));
        $this->assertSame(31, Lesson::where('status', LessonStatus::Active)->count());
    }

    public function test_repeated_intra_file_identity_mutates_only_once_and_preserves_duplicate_row_history(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $last = $batch->rows()->where('row_number', 30)->sole();
        $duplicate = $last->replicate();
        $duplicate->row_number = 31;
        $duplicate->save();
        $batch->update(['total_rows' => 31, 'valid_rows' => 31]);
        $this->postJson('/api/schedule-imports/'.$batch->id.'/revalidate')->assertOk()->assertJsonPath('data.duplicate_rows', 1);
        $this->commit($user, $batch)->assertOk()->assertJsonPath('data.total_rows', 31);
        $this->assertDatabaseCount('lessons', 30);
        $this->assertSame('noop', $duplicate->refresh()->normalized_data['operation']);
        $this->assertSame(ScheduleImportRowStatus::Duplicate, $duplicate->status);
    }

    public function test_projected_swap_reconciliation_succeeds_without_partial_position_conflict_checks(): void
    {
        [$user, $period] = $this->context();
        $first = $this->upload($user, $period);
        $this->commit($user, $first)->assertOk();
        $rows = $first->rows()->orderBy('row_number')->take(2)->get();
        $a = $user->lessons()->where('import_fingerprint', $rows[0]->fingerprint)->sole();
        $b = $user->lessons()->where('import_fingerprint', $rows[1]->fingerprint)->sole();
        $this->patchJson('/api/lessons/'.$a->id, ['starts_at' => '2026-09-06T08:00:00Z', 'ends_at' => '2026-09-06T09:00:00Z'])->assertOk();
        $this->patchJson('/api/lessons/'.$b->id, ['starts_at' => $rows[0]->raw_data['starts_at_utc'], 'ends_at' => $rows[0]->raw_data['ends_at_utc']])->assertOk();
        $this->patchJson('/api/lessons/'.$a->id, ['starts_at' => $rows[1]->raw_data['starts_at_utc'], 'ends_at' => $rows[1]->raw_data['ends_at_utc']])->assertOk();
        $second = $this->upload($user, $period);
        $this->assertSame(2, $second->valid_rows);
        $this->commit($user, $second)->assertOk();
        $this->assertSource($a->refresh(), $rows[0], $second);
        $this->assertSource($b->refresh(), $rows[1], $second);
        $this->assertDatabaseCount('lessons', 30);
    }

    public function test_later_creation_failure_rolls_back_prior_update_restore_create_and_preview_refresh(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $rows = $batch->rows()->orderBy('row_number')->take(2)->get();
        $update = $this->history($user, $batch, $rows[0], ['room' => 'manual correction', 'type' => LessonType::Laboratory]);
        $restore = $this->history($user, $batch, $rows[1], ['type' => LessonType::Lecture]);
        $restore->delete();
        $this->postJson('/api/schedule-imports/'.$batch->id.'/revalidate')->assertOk();
        $before = $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']);
        $dispatcher = Lesson::getEventDispatcher();
        Lesson::setEventDispatcher(clone $dispatcher);
        $creates = 0;
        Lesson::creating(function () use (&$creates, $update, $restore, $rows): void {
            if (++$creates === 2) {
                $this->assertSame($rows[0]->raw_data['room'], $update->fresh()->room);
                $this->assertFalse(Lesson::withTrashed()->findOrFail($restore->id)->trashed());
                $this->assertSame(3, Lesson::withTrashed()->count());
                throw new RuntimeException('Later mutation failed');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->commit($user, $batch);
            $this->fail('The later failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Later mutation failed', $exception->getMessage());
        } finally {
            Lesson::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']));
        $this->assertNull($batch->refresh()->committed_at);
        $this->assertTrue($restore->refresh()->trashed());
    }

    public function test_batch_transition_failure_rolls_back_all_lesson_mutations(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $before = $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']);
        $dispatcher = ScheduleImportBatch::getEventDispatcher();
        ScheduleImportBatch::setEventDispatcher(clone $dispatcher);
        ScheduleImportBatch::updating(function (ScheduleImportBatch $candidate): void {
            if ($candidate->status === ScheduleImportBatchStatus::Committed) {
                $this->assertSame(30, Lesson::count());
                throw new RuntimeException('Batch transition failed');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->commit($user, $batch);
            $this->fail('Batch failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Batch transition failed', $exception->getMessage());
        } finally {
            ScheduleImportBatch::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']));
    }

    #[DataProvider('vetoedOperations')]
    public function test_unsuccessful_model_save_cannot_be_reported_as_a_successful_commit(string $operation): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        if (in_array($operation, ['update', 'restore'], true)) {
            $lesson = $this->history($user, $batch, $row, ['room' => 'manual correction']);
            if ($operation === 'restore') {
                $lesson->delete();
            }
            $this->postJson('/api/schedule-imports/'.$batch->id.'/revalidate')->assertOk();
        }
        $before = $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']);
        $dispatcher = Lesson::getEventDispatcher();
        Lesson::setEventDispatcher(clone $dispatcher);
        match ($operation) {
            'create' => Lesson::creating(fn () => false),
            'update' => Lesson::updating(fn () => false),
            'restore' => Lesson::restoring(fn () => false),
            'batch' => ScheduleImportBatch::updating(fn ($candidate) => $candidate->status !== ScheduleImportBatchStatus::Committed),
        };
        try {
            $this->commit($user, $batch)->assertUnprocessable()->assertJsonValidationErrors('schedule_import');
        } finally {
            Lesson::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']));
    }

    public static function vetoedOperations(): array
    {
        return [['create'], ['update'], ['restore'], ['batch']];
    }

    #[DataProvider('targetChanges')]
    public function test_unexpected_identity_or_lifecycle_change_after_refresh_rolls_back_without_operation_substitution(string $change): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $lesson = $change === 'create appeared' ? null : $this->history($user, $batch, $row, ['room' => 'manual correction']);
        if ($change === 'restore active') {
            $lesson->delete();
        }
        $this->postJson('/api/schedule-imports/'.$batch->id.'/revalidate')->assertOk();
        $before = $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']);
        $actual = app(ScheduleImportPreviewRevalidator::class);
        $otherPeriod = AcademicPeriod::factory()->for($user)->create();
        $this->mock(ScheduleImportPreviewRevalidator::class)->shouldReceive('refreshLocked')->once()
            ->andReturnUsing(function ($owner, $lockedBatch, $period, $rows) use ($actual, $change, $lesson, $row, $otherPeriod) {
                $result = $actual->refreshLocked($owner, $lockedBatch, $period, $rows);
                // Deliberately simulate an invariant violation by an uncoordinated writer.
                match ($change) {
                    'update missing' => $lesson->forceDelete(),
                    'update deleted' => $lesson->delete(),
                    'restore active' => $lesson->restore(),
                    'wrong period' => $lesson->update(['academic_period_id' => $otherPeriod->id]),
                    'changed fingerprint' => $lesson->update(['import_fingerprint' => str_repeat('a', 64)]),
                    'create appeared' => $this->history($owner, $lockedBatch, $row),
                };

                return $result;
            });
        $this->commit($user, $batch)->assertUnprocessable()->assertJsonValidationErrors('schedule_import');
        $this->assertSame($before, $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']));
    }

    public static function targetChanges(): array
    {
        return array_map(fn ($value) => [$value], ['update missing', 'update deleted', 'restore active', 'wrong period', 'changed fingerprint', 'create appeared']);
    }

    public function test_unexpected_fresh_operation_status_pair_rolls_back_earlier_creations(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $third = $batch->rows()->where('row_number', 3)->sole();
        $third->update(['normalized_data' => [...$third->normalized_data, 'operation' => 'noop']]);
        $classifier = app(ScheduleImportCandidateClassifier::class);
        $this->mock(ScheduleImportCandidateClassifier::class)->shouldReceive('classify')->once()
            ->andReturnUsing(function (...$arguments) use ($classifier) {
                $result = $classifier->classify(...$arguments);
                $result['occurrences'][2]['operation'] = 'noop'; // Valid/noop violates the operation contract.

                return $result;
            });
        $before = $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']);
        $this->commit($user, $batch)->assertUnprocessable()->assertJsonValidationErrors('schedule_import');
        $this->assertSame($before, $this->snapshot(['lessons', 'schedule_import_batches', 'schedule_import_rows']));
    }

    public function test_revalidation_and_mutation_share_one_transaction_and_identity_targets_are_locked(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $level = DB::transactionLevel();
        $queries = [];
        $processing = true;
        DB::listen(function ($query) use (&$queries, &$processing, $level): void {
            if (! $processing) {
                return;
            }
            $queries[] = $query->sql;
            if (preg_match('/(?:from|into|update) `(subjects|teachers|lessons|schedule_import_rows)`/', $query->sql)) {
                $this->assertSame($level + 1, DB::transactionLevel());
            }
        });
        $this->postJson($this->url($batch))->assertOk();
        $processing = false;
        $locks = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'for update')));
        foreach (['users', 'schedule_import_batches', 'academic_periods', 'schedule_import_rows'] as $index => $table) {
            $this->assertStringContainsString('`'.$table.'`', $locks[$index]);
        }
        $identityLocks = array_filter($locks, fn ($sql) => str_contains($sql, '`lessons`'));
        $this->assertCount(30, $identityLocks);
        foreach ($identityLocks as $sql) {
            $this->assertStringContainsString('`user_id`', $sql);
            $this->assertStringContainsString('`academic_period_id`', $sql);
            $this->assertStringContainsString('`import_fingerprint`', $sql);
            $this->assertStringNotContainsString('`deleted_at` is null', $sql);
        }
        $this->assertSame($level, DB::transactionLevel());
    }

    private function context(): array
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $period = AcademicPeriod::factory()->for($user)->create(['starts_on' => '2026-09-07', 'ends_on' => '2026-09-20']);
        $template = (new ScheduleWorkbookParser)->parse(self::FIXTURE);
        foreach (array_unique(array_column($template['rules'], 'subject_name')) as $name) {
            Subject::factory()->for($user)->create(['name' => $name]);
        }
        foreach (array_unique(array_column($template['rules'], 'teacher_name')) as $name) {
            Teacher::factory()->for($user)->create(['name' => $name]);
        }

        return [$user, $period];
    }

    private function upload(User $user, AcademicPeriod $period): ScheduleImportBatch
    {
        $response = $this->actingAs($user, 'web')->post('/api/schedule-imports', [
            'academic_period_id' => (string) $period->id,
            'schedule_file' => new UploadedFile(self::FIXTURE, 'Приклад розкладу.xlsx', null, null, true),
        ], ['Accept' => 'application/json'])->assertCreated();

        return ScheduleImportBatch::findOrFail($response->json('data.id'));
    }

    private function commit(User $user, ScheduleImportBatch $batch, array $body = []): TestResponse
    {
        $catalog = $this->snapshot(['users', 'academic_periods', 'subjects', 'teachers']);
        $source = DB::table('schedule_import_rows')->where('schedule_import_batch_id', $batch->id)
            ->orderBy('row_number')->get(['id', 'row_number', 'raw_data', 'created_at'])->toArray();
        $this->app['auth']->forgetGuards();
        $response = $this->actingAs($user, 'web')->postJson($this->url($batch), $body);
        $this->assertSame($catalog, $this->snapshot(['users', 'academic_periods', 'subjects', 'teachers']));
        $this->assertEquals($source, DB::table('schedule_import_rows')->where('schedule_import_batch_id', $batch->id)
            ->orderBy('row_number')->get(['id', 'row_number', 'raw_data', 'created_at'])->toArray());

        return $response;
    }

    private function history(User $user, ScheduleImportBatch $batch, ScheduleImportRow $row, array $changes = []): Lesson
    {
        $data = $row->normalized_data;

        return Lesson::factory()->for($user)->create([
            'academic_period_id' => $batch->academic_period_id, 'subject_id' => $data['subject_id'],
            'teacher_id' => $data['teacher_id'], 'schedule_import_batch_id' => null, 'type' => LessonType::Other,
            'room' => $data['room'], 'starts_at' => $this->databaseTime($data['starts_at_utc']),
            'ends_at' => $this->databaseTime($data['ends_at_utc']), 'import_fingerprint' => $row->fingerprint,
            ...$changes,
        ]);
    }

    private function assertSource(Lesson $lesson, ScheduleImportRow $row, ScheduleImportBatch $batch): void
    {
        $this->assertSame($batch->academic_period_id, $lesson->academic_period_id);
        $this->assertSame($row->normalized_data['subject_id'], $lesson->subject_id);
        $this->assertSame($row->normalized_data['teacher_id'], $lesson->teacher_id);
        $this->assertSame($row->raw_data['room'], $lesson->room);
        $this->assertSame($this->databaseTime($row->raw_data['starts_at_utc']), $lesson->getRawOriginal('starts_at'));
        $this->assertSame($this->databaseTime($row->raw_data['ends_at_utc']), $lesson->getRawOriginal('ends_at'));
        $this->assertSame($row->fingerprint, $lesson->import_fingerprint);
        $this->assertSame($batch->id, $lesson->schedule_import_batch_id);
        $this->assertSame(LessonStatus::Active, $lesson->status);
    }

    private function rowSnapshot(ScheduleImportBatch $batch): array
    {
        return DB::table('schedule_import_rows')->where('schedule_import_batch_id', $batch->id)->orderBy('row_number')
            ->get(['id', 'row_number', 'raw_data', 'normalized_data', 'validation_errors', 'status', 'fingerprint'])->map(fn ($row) => (array) $row)->all();
    }

    private function snapshotLessonsExcept(int $id): array
    {
        return DB::table('lessons')->where('id', '!=', $id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function snapshot(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    private function databaseTime(string $utc): string
    {
        return substr($utc, 0, 10).' '.substr($utc, 11, 8);
    }

    private function url(ScheduleImportBatch $batch): string
    {
        return '/api/schedule-imports/'.$batch->id.'/commit';
    }
}
