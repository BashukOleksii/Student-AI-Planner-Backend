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
use App\Services\Imports\ScheduleImportPreviewRevalidator;
use App\Services\Imports\ScheduleOccurrenceMaterializer;
use App\Services\Imports\ScheduleWorkbookParser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ScheduleImportPreviewRevalidationTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../Fixtures/ScheduleImports/Приклад розкладу.xlsx';

    public function test_real_preview_revalidates_thirty_occurrences_in_place_and_get_returns_snapshot(): void
    {
        $this->freezeTime();
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $before = $this->snapshot(['schedule_import_batches', 'schedule_import_rows']);
        $response = $this->refreshPreview($user, $batch)->assertOk()
            ->assertJsonPath('data.status', 'validated')->assertJsonPath('data.total_rows', 30)
            ->assertJsonPath('data.valid_rows', 30)->assertJsonPath('data.invalid_rows', 0)
            ->assertJsonPath('data.duplicate_rows', 0)->assertJsonPath('data.error_message', null)
            ->assertJsonPath('data.committed_at', null)->assertJsonCount(30, 'data.rows');
        $this->assertSame(range(1, 30), array_column($response->json('data.rows'), 'row_number'));
        $this->assertSame($before, $this->snapshot(['schedule_import_batches', 'schedule_import_rows']));
        $this->getJson('/api/schedule-imports/'.$batch->id)->assertOk()->assertExactJson($response->json());
        $this->assertDatabaseCount('lessons', 0);
    }

    #[DataProvider('referenceKinds')]
    public function test_missing_reference_can_be_created_through_api_and_preview_becomes_validated(string $kind): void
    {
        [$user, $period, $template] = $this->context();
        $name = $template['rules'][0][$kind.'_name'];
        $relation = $kind === 'subject' ? 'subjects' : 'teachers';
        $entity = $user->$relation()->where('name', $name)->sole();
        $this->actingAs($user, 'web')->deleteJson('/api/'.$relation.'/'.$entity->id)->assertNoContent();
        $batch = $this->upload($user, $period);
        $this->assertSame(ScheduleImportBatchStatus::Uploaded, $batch->status);
        $affected = $batch->rows()->get()->filter(fn ($row) => $row->raw_data[$kind.'_name'] === $name);
        foreach ($affected as $row) {
            $this->assertSame(ScheduleImportRowStatus::Invalid, $row->status);
            $this->assertNull($row->normalized_data[$kind.'_id']);
            $this->assertSame($kind === 'subject', $row->fingerprint === null);
        }
        $created = $this->postJson('/api/'.$relation, ['name' => $name])->assertCreated()->json('data.id');
        $response = $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.valid_rows', 30)->assertJsonPath('data.invalid_rows', 0);
        $this->getJson('/api/schedule-imports/'.$batch->id)->assertOk()->assertExactJson($response->json());
        foreach ($affected as $row) {
            $row->refresh();
            $this->assertSame($created, $row->normalized_data[$kind.'_id']);
            $this->assertNotNull($row->fingerprint);
            $this->assertSame([], $row->validation_errors);
        }
    }

    public static function referenceKinds(): array
    {
        return [['subject'], ['teacher']];
    }

    #[DataProvider('referenceKinds')]
    public function test_owned_mapping_survives_rename_even_when_original_name_now_maps_elsewhere(string $kind): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $id = $row->normalized_data[$kind.'_id'];
        $originalName = $row->raw_data[$kind.'_name'];
        $fingerprint = $row->fingerprint;
        $relation = $kind === 'subject' ? 'subjects' : 'teachers';
        $this->patchJson('/api/'.$relation.'/'.$id, ['name' => 'Renamed academic display name'])->assertOk();
        $this->postJson('/api/'.$relation, ['name' => $originalName])->assertCreated();
        if ($kind === 'teacher') {
            $this->postJson('/api/teachers', ['name' => $originalName])->assertCreated();
        }
        $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.status', 'validated');
        $row->refresh();
        $this->assertSame($id, $row->normalized_data[$kind.'_id']);
        $this->assertSame($originalName, $row->raw_data[$kind.'_name']);
        $this->assertSame($fingerprint, $row->fingerprint);
        $this->assertSame([], $row->validation_errors);
    }

    #[DataProvider('referenceKinds')]
    public function test_deleted_mapping_becomes_unresolved_without_silently_retaining_dangling_id(string $kind): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $fingerprint = $row->fingerprint;
        $relation = $kind === 'subject' ? 'subjects' : 'teachers';
        $this->deleteJson('/api/'.$relation.'/'.$row->normalized_data[$kind.'_id'])->assertNoContent();
        $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.status', 'uploaded');
        $row->refresh();
        $this->assertNull($row->normalized_data[$kind.'_id']);
        $this->assertSame(ScheduleImportRowStatus::Invalid, $row->status);
        $this->assertSame([$kind.'_name' => ['No owned '.$kind.' exactly matches this imported name.']], $row->validation_errors);
        $this->assertSame($kind === 'subject' ? null : $fingerprint, $row->fingerprint);
    }

    #[DataProvider('referenceKinds')]
    public function test_deleted_mapping_can_resolve_to_a_new_exact_owned_entity(string $kind): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $oldId = $row->normalized_data[$kind.'_id'];
        $fingerprint = $row->fingerprint;
        $relation = $kind === 'subject' ? 'subjects' : 'teachers';
        $this->deleteJson('/api/'.$relation.'/'.$oldId)->assertNoContent();
        $newId = $this->postJson('/api/'.$relation, ['name' => $row->raw_data[$kind.'_name']])->assertCreated()->json('data.id');
        $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.status', 'validated');
        $row->refresh();
        $this->assertNotSame($oldId, $newId);
        $this->assertSame($newId, $row->normalized_data[$kind.'_id']);
        $this->assertSame($kind === 'teacher', $fingerprint === $row->fingerprint);
    }

    public function test_ambiguous_unmapped_teacher_resolves_after_duplicate_is_removed(): void
    {
        [$user, $period, $template] = $this->context();
        $name = $template['rules'][0]['teacher_name'];
        $original = $user->teachers()->where('name', $name)->sole();
        $duplicate = Teacher::factory()->for($user)->create(['name' => $name]);
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $this->assertSame(['teacher_name' => ['Multiple owned teachers exactly match this imported name.']], $row->validation_errors);
        $fingerprint = $row->fingerprint;
        $this->deleteJson('/api/teachers/'.$duplicate->id)->assertNoContent();
        $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.status', 'validated');
        $row->refresh();
        $this->assertSame($original->id, $row->normalized_data['teacher_id']);
        $this->assertSame($fingerprint, $row->fingerprint);
    }

    #[DataProvider('referenceKinds')]
    public function test_invalid_foreign_mapping_and_similar_owned_name_cannot_resolve_foreign_exact_name(string $kind): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $relation = $kind === 'subject' ? 'subjects' : 'teachers';
        $this->deleteJson('/api/'.$relation.'/'.$row->normalized_data[$kind.'_id'])->assertNoContent();
        $name = $row->raw_data[$kind.'_name'];
        $foreignUser = User::factory()->create();
        $factory = $kind === 'subject' ? Subject::factory() : Teacher::factory();
        $foreign = $factory->for($foreignUser)->create(['name' => $name]);
        $factory->for($user)->create(['name' => mb_strtolower($name)]);
        $row->update(['normalized_data' => [...$row->normalized_data, $kind.'_id' => $foreign->id]]);
        $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.status', 'uploaded');
        $row->refresh();
        $this->assertNull($row->normalized_data[$kind.'_id']);
        $this->assertSame([$kind.'_name' => ['No owned '.$kind.' exactly matches this imported name.']], $row->validation_errors);
    }

    public function test_stale_classification_fingerprint_and_normalized_source_fields_are_recomputed(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $expected = $row->normalized_data;
        $fingerprint = $row->fingerprint;
        $foreign = Lesson::factory()->create();
        $row->update([
            'status' => ScheduleImportRowStatus::Invalid, 'fingerprint' => str_repeat('f', 64),
            'validation_errors' => ['obsolete' => ['Old error.']],
            'normalized_data' => [
                'academic_period_id' => 999999, 'subject_id' => $foreign->subject_id,
                'teacher_id' => 'not an integer', 'room' => 'stale room', 'type' => 'lecture',
                'starts_at_utc' => 'bad cached instant', 'ends_at_utc' => 'bad cached instant',
                'operation' => 'restore', 'matched_lesson_id' => $foreign->id, 'extra' => 'discard',
            ],
        ]);
        $batch->update(['status' => ScheduleImportBatchStatus::Uploaded, 'valid_rows' => 0, 'invalid_rows' => 30, 'duplicate_rows' => 0, 'error_message' => 'Obsolete message']);
        $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.valid_rows', 30)->assertJsonPath('data.invalid_rows', 0)
            ->assertJsonPath('data.error_message', null);
        $row->refresh();
        $this->assertEquals($expected, $row->normalized_data);
        $this->assertSame($fingerprint, $row->fingerprint);
        $this->assertSame([], $row->validation_errors);
    }

    #[DataProvider('scheduleChanges')]
    public function test_revalidation_refreshes_classification_against_current_lesson_state(string $change, string $status, ?string $operation): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $lesson = $this->lesson($user, $batch, $row, $change);
        $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.rows.0.status', $status)
            ->assertJsonPath('data.rows.0.operation', $operation)->assertJsonPath('data.rows.0.type', 'other');
        $row->refresh();
        $this->assertSame($change === 'manual' ? null : $lesson->id, $row->normalized_data['matched_lesson_id']);
        $this->assertSame(LessonType::Laboratory, $lesson->refresh()->type);
        $batch->refresh();
        $this->assertSame($status === 'invalid' ? ScheduleImportBatchStatus::Uploaded : ScheduleImportBatchStatus::Validated, $batch->status);
        $this->assertSame($status === 'invalid' ? 1 : 0, $batch->invalid_rows);
        $this->assertSame($status === 'duplicate' ? 1 : 0, $batch->duplicate_rows);
        $this->assertSame($status === 'valid' ? 30 : 29, $batch->valid_rows);
        $this->assertSame(30, $batch->valid_rows + $batch->invalid_rows + $batch->duplicate_rows);
    }

    public static function scheduleChanges(): array
    {
        return [
            ['manual', 'invalid', null], ['identical', 'duplicate', 'noop'], ['changed', 'valid', 'update'],
            ['cancelled', 'valid', 'preserve'], ['replaced', 'valid', 'preserve'], ['deleted', 'valid', 'restore'],
        ];
    }

    public function test_duplicate_preview_becomes_restore_after_matching_lesson_is_soft_deleted(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $lesson = $this->lesson($user, $batch, $row, 'identical');
        $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.rows.0.operation', 'noop');
        $this->deleteJson('/api/lessons/'.$lesson->id)->assertNoContent();
        $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.rows.0.operation', 'restore')
            ->assertJsonPath('data.rows.0.status', 'valid');
        $this->assertTrue($lesson->refresh()->trashed());
        $this->assertSame($batch->id, $lesson->schedule_import_batch_id);
    }

    public function test_conflicted_preview_becomes_validated_after_blocker_is_cancelled(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $lesson = $this->lesson($user, $batch, $row, 'manual');
        $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.status', 'uploaded')
            ->assertJsonPath('data.rows.0.status', 'invalid');
        $this->postJson('/api/lessons/'.$lesson->id.'/cancel')->assertOk();
        $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.rows.0.operation', 'create');
        $this->assertSame(LessonStatus::Cancelled, $lesson->refresh()->status);
    }

    #[DataProvider('changedPeriods')]
    public function test_period_and_timezone_changes_do_not_rematerialize_the_concrete_snapshot(string $start, string $end): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $period->update(['starts_on' => $start, 'ends_on' => $end]);
        $user->update(['timezone' => 'UTC']);
        $this->mock(ScheduleWorkbookParser::class)->shouldNotReceive('parse');
        $this->mock(ScheduleOccurrenceMaterializer::class)->shouldNotReceive('materialize');
        $foreignPeriod = AcademicPeriod::factory()->create();
        $this->refreshPreview($user, $batch, [
            'user_id' => $foreignPeriod->user_id, 'owner_id' => $foreignPeriod->user_id,
            'academic_period_id' => $foreignPeriod->id, 'timezone' => 'America/New_York',
        ])->assertOk()->assertJsonPath('data.total_rows', 30)->assertJsonPath('data.academic_period_id', $period->id)
            ->assertJsonPath('data.rows.0.starts_at_utc', '2026-09-07T05:30:00Z');
    }

    public static function changedPeriods(): array
    {
        return [['2026-09-01', '2026-10-31'], ['2026-09-10', '2026-09-11']];
    }

    public function test_empty_validated_preview_remains_empty(): void
    {
        [$user, $period] = $this->context();
        $period->update(['starts_on' => '2026-09-12', 'ends_on' => '2026-09-13']);
        $batch = $this->upload($user, $period);
        $period->update(['starts_on' => '2026-09-07', 'ends_on' => '2026-09-20']);
        $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.total_rows', 0)->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.invalid_rows', 0)->assertJsonPath('data.duplicate_rows', 0)
            ->assertJsonCount(0, 'data.rows');
    }

    public function test_calendar_source_validation_does_not_use_server_timezone(): void
    {
        [$user, $period] = $this->context();
        $period->update(['starts_on' => '2011-12-30', 'ends_on' => '2011-12-30']);
        $batch = $this->upload($user, $period);
        $originalTimezone = date_default_timezone_get();
        try {
            // This label exists in the source Kyiv timezone, but not in Apia.
            date_default_timezone_set('Pacific/Apia');
            $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.status', 'validated')
                ->assertJsonPath('data.total_rows', 2)->assertJsonPath('data.rows.0.local_date', '2011-12-30');
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[DataProvider('forbiddenStates')]
    public function test_forbidden_batch_states_return_422_without_any_mutation(ScheduleImportBatchStatus $status): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $batch->update(['status' => $status, 'error_message' => 'Retained message']);
        $before = $this->snapshot(['schedule_import_batches', 'schedule_import_rows']);
        $this->refreshPreview($user, $batch)->assertUnprocessable()
            ->assertJsonValidationErrors('schedule_import')
            ->assertJsonPath('errors.schedule_import', ['This import preview cannot be revalidated.']);
        $this->assertSame($before, $this->snapshot(['schedule_import_batches', 'schedule_import_rows']));
    }

    public static function forbiddenStates(): array
    {
        return [[ScheduleImportBatchStatus::Failed], [ScheduleImportBatchStatus::Committed], [ScheduleImportBatchStatus::Cancelled]];
    }

    public function test_authentication_and_foreign_batch_are_protected(): void
    {
        $batch = ScheduleImportBatch::factory()->create();
        $this->postJson($this->url($batch))->assertUnauthorized();
        $this->refreshPreview(User::factory()->create(), $batch)->assertNotFound();
        $this->assertSame(ScheduleImportBatchStatus::Uploaded, $batch->refresh()->status);
    }

    public function test_service_reloads_batch_ownership_even_if_in_memory_owner_is_forged(): void
    {
        $batch = ScheduleImportBatch::factory()->create();
        $other = User::factory()->create();
        $batch->user_id = $other->id;
        $before = $this->snapshot(['schedule_import_batches', 'schedule_import_rows', 'lessons']);
        try {
            app(ScheduleImportPreviewRevalidator::class)->revalidate($other, $batch);
            $this->fail('Foreign batches must not revalidate.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $this->snapshot(['schedule_import_batches', 'schedule_import_rows', 'lessons']));
        }
    }

    public function test_malformed_foreign_period_fails_generically_without_partial_updates(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $batch->update(['academic_period_id' => AcademicPeriod::factory()->create()->id]);
        $before = $this->snapshot(['schedule_import_batches', 'schedule_import_rows']);
        $this->refreshPreview($user, $batch)->assertUnprocessable()->assertJsonValidationErrors('academic_period_id')
            ->assertJsonPath('errors.academic_period_id', ['The selected academic period is invalid.']);
        $this->assertSame($before, $this->snapshot(['schedule_import_batches', 'schedule_import_rows']));
    }

    #[DataProvider('missingRawFields')]
    public function test_missing_required_source_field_rejects_entire_preview_without_partial_updates(string $field): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 30)->sole();
        $raw = $row->raw_data;
        unset($raw[$field]);
        $row->update(['raw_data' => $raw]);
        $this->assertIntegrityRejection($user, $batch);
    }

    public static function missingRawFields(): array
    {
        return array_map(fn ($field) => [$field], [
            'group', 'workbook_source', 'local_date', 'weekday', 'pair_label', 'parity',
            'subject_name', 'teacher_name', 'room', 'starts_at_local', 'ends_at_local',
            'starts_at_utc', 'ends_at_utc', 'source',
        ]);
    }

    #[DataProvider('structuralCorruptions')]
    public function test_corrupt_core_structure_is_not_guessed_or_partially_updated(string $corruption): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 30)->sole();
        $raw = $row->raw_data;
        switch ($corruption) {
            case 'row count': $batch->update(['total_rows' => 31]);
                break;
            case 'ordinal gap': $row->update(['row_number' => 31]);
                break;
            case 'non-object source': $row->update(['raw_data' => 'invalid']);
                break;
            case 'date': $row->update(['raw_data' => [...$raw, 'local_date' => '2026-02-30']]);
                break;
            case 'weekday': $row->update(['raw_data' => [...$raw, 'weekday' => 8]]);
                break;
            case 'calendar weekday': $row->update(['raw_data' => [...$raw, 'weekday' => 1]]);
                break;
            case 'parity': $row->update(['raw_data' => [...$raw, 'parity' => 'guessed']]);
                break;
            case 'local time': $row->update(['raw_data' => [...$raw, 'starts_at_local' => '8:30']]);
                break;
            case 'local interval': $row->update(['raw_data' => [...$raw, 'ends_at_local' => $raw['starts_at_local']]]);
                break;
            case 'source rows': $row->update(['raw_data' => [...$raw, 'source' => [...$raw['source'], 'rows' => [9, 8]]]]);
                break;
            case 'source worksheet': $row->update(['raw_data' => [...$raw, 'source' => [...$raw['source'], 'worksheet' => 'Other']]]);
                break;
            case 'workbook context': $row->update(['raw_data' => [...$raw, 'group' => 'Other group']]);
                break;
        }
        $this->assertIntegrityRejection($user, $batch);
    }

    public static function structuralCorruptions(): array
    {
        return array_map(fn ($value) => [$value], ['row count', 'ordinal gap', 'non-object source', 'date', 'weekday', 'calendar weekday', 'parity', 'local time', 'local interval', 'source rows', 'source worksheet', 'workbook context']);
    }

    #[DataProvider('invalidUtcIntervals')]
    public function test_invalid_stored_utc_interval_is_invalidated_without_normalization(string $start, string $end): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $row->update(['raw_data' => [...$row->raw_data, 'starts_at_utc' => $start, 'ends_at_utc' => $end]]);
        $this->refreshPreview($user, $batch)->assertOk()->assertJsonPath('data.status', 'uploaded')
            ->assertJsonPath('data.invalid_rows', 1)->assertJsonPath('data.valid_rows', 29);
        $row->refresh();
        $this->assertSame(['preview_integrity' => ['The stored occurrence UTC interval is invalid. Create a new import preview.']], $row->validation_errors);
        $this->assertNull($row->fingerprint);
        $this->assertNull($row->normalized_data['operation']);
        $this->assertNull($row->normalized_data['matched_lesson_id']);
        $this->assertSame($start, $row->normalized_data['starts_at_utc']);
        $this->assertSame($end, $row->normalized_data['ends_at_utc']);
    }

    public static function invalidUtcIntervals(): array
    {
        $start = '2026-09-07T05:30:00Z';
        $end = '2026-09-07T06:50:00Z';

        return [
            ['not a timestamp', $end], ['2026-09-07T05:30:00+00:00', $end],
            ['2026-09-07T05:30:00.000Z', $end], [' '.$start, $end],
            ['2026-02-30T05:30:00Z', $end], ['2026-09-07T25:30:00Z', $end],
            [$start, $start], [$end, $start], [$start, $end.' '], [$start, 'bad end'],
        ];
    }

    public function test_invalid_utc_is_detected_even_when_subject_cannot_resolve(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $row = $batch->rows()->where('row_number', 1)->sole();
        $this->deleteJson('/api/subjects/'.$row->normalized_data['subject_id'])->assertNoContent();
        $row->update(['raw_data' => [...$row->raw_data, 'starts_at_utc' => 'not UTC']]);
        $this->refreshPreview($user, $batch)->assertOk();
        $row->refresh();
        $this->assertArrayHasKey('subject_name', $row->validation_errors);
        $this->assertArrayHasKey('preview_integrity', $row->validation_errors);
        $this->assertNull($row->fingerprint);
    }

    public function test_row_refresh_failure_rolls_back_all_rows_and_batch(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $batch->rows()->whereIn('row_number', [1, 2])->update(['status' => 'invalid']);
        $batch->update(['status' => ScheduleImportBatchStatus::Uploaded, 'valid_rows' => 28, 'invalid_rows' => 2]);
        $before = $this->snapshot(['schedule_import_batches', 'schedule_import_rows', 'lessons']);
        $dispatcher = ScheduleImportRow::getEventDispatcher();
        ScheduleImportRow::setEventDispatcher(clone $dispatcher);
        ScheduleImportRow::updating(function (ScheduleImportRow $row): void {
            if ($row->row_number === 2) {
                throw new RuntimeException('Persistence unavailable');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->refreshPreview($user, $batch);
            $this->fail('Persistence errors must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Persistence unavailable', $exception->getMessage());
        } finally {
            ScheduleImportRow::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $this->snapshot(['schedule_import_batches', 'schedule_import_rows', 'lessons']));
    }

    public function test_owner_batch_period_and_rows_lock_before_batched_reference_and_lesson_reads(): void
    {
        [$user, $period] = $this->context();
        $batch = $this->upload($user, $period);
        $level = DB::transactionLevel();
        $queries = [];
        DB::listen(function ($query) use (&$queries, $level): void {
            $queries[] = $query->sql;
            if (preg_match('/^select .* from `(subjects|teachers|lessons)`/', $query->sql)) {
                $this->assertSame($level + 1, DB::transactionLevel());
            }
        });
        $this->postJson($this->url($batch))->assertOk();
        $locks = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'for update')));
        $this->assertCount(4, $locks);
        foreach (['users', 'schedule_import_batches', 'academic_periods', 'schedule_import_rows'] as $index => $table) {
            $this->assertStringContainsString('`'.$table.'`', $locks[$index]);
        }
        $referenceQueries = array_filter($queries, fn ($sql) => preg_match('/^select .* from `(subjects|teachers)`/', $sql));
        // Two ID checks; mapped rows are never resolved again by their imported names.
        $this->assertCount(2, $referenceQueries);
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

        return [$user, $period, $template];
    }

    private function upload(User $user, AcademicPeriod $period): ScheduleImportBatch
    {
        $response = $this->actingAs($user, 'web')->post('/api/schedule-imports', [
            'academic_period_id' => (string) $period->id,
            'schedule_file' => new UploadedFile(self::FIXTURE, 'Приклад розкладу.xlsx', null, null, true),
        ], ['Accept' => 'application/json'])->assertCreated();

        return ScheduleImportBatch::findOrFail($response->json('data.id'));
    }

    /** Every HTTP revalidation asserts full Lesson/catalog snapshots and immutable row source. */
    private function refreshPreview(User $user, ScheduleImportBatch $batch, array $body = []): TestResponse
    {
        $before = $this->snapshot(['lessons', 'subjects', 'teachers']);
        $source = $this->sourceSnapshot($batch);
        $this->app['auth']->forgetGuards();
        $response = $this->actingAs($user, 'web')->postJson($this->url($batch), $body);
        $this->assertSame($before, $this->snapshot(['lessons', 'subjects', 'teachers']));
        $this->assertSame($source, $this->sourceSnapshot($batch));

        return $response;
    }

    private function lesson(User $user, ScheduleImportBatch $batch, ScheduleImportRow $row, string $change): Lesson
    {
        $candidate = $row->normalized_data;
        $lesson = Lesson::factory()->for($user)->create([
            'subject_id' => $candidate['subject_id'], 'teacher_id' => $candidate['teacher_id'],
            'academic_period_id' => $batch->academic_period_id,
            'type' => LessonType::Laboratory, 'room' => $change === 'changed' ? 'manual correction' : $candidate['room'],
            'starts_at' => substr($candidate['starts_at_utc'], 0, 10).' '.substr($candidate['starts_at_utc'], 11, 8),
            'ends_at' => substr($candidate['ends_at_utc'], 0, 10).' '.substr($candidate['ends_at_utc'], 11, 8),
            'import_fingerprint' => $change === 'manual' ? null : $row->fingerprint,
            'schedule_import_batch_id' => $change === 'manual' ? null : $batch->id,
            'status' => match ($change) {
                'cancelled' => LessonStatus::Cancelled, 'replaced' => LessonStatus::Replaced,
                default => LessonStatus::Active,
            },
        ]);
        if ($change === 'deleted') {
            $lesson->delete();
        }

        return $lesson;
    }

    private function assertIntegrityRejection(User $user, ScheduleImportBatch $batch): void
    {
        $before = $this->snapshot(['schedule_import_batches', 'schedule_import_rows']);
        $this->refreshPreview($user, $batch)->assertUnprocessable()->assertJsonValidationErrors('schedule_import')
            ->assertJsonPath('errors.schedule_import', ['This import preview is malformed. Create a new import preview.']);
        $this->assertSame($before, $this->snapshot(['schedule_import_batches', 'schedule_import_rows']));
    }

    private function sourceSnapshot(ScheduleImportBatch $batch): array
    {
        return DB::table('schedule_import_rows')->where('schedule_import_batch_id', $batch->id)
            ->orderBy('row_number')->get(['id', 'row_number', 'raw_data', 'created_at'])
            ->map(fn ($row) => (array) $row)->all();
    }

    private function snapshot(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }

    private function url(ScheduleImportBatch $batch): string
    {
        return '/api/schedule-imports/'.$batch->id.'/revalidate';
    }
}
