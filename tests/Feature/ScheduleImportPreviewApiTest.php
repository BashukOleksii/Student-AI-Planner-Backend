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
use App\Services\Imports\ScheduleOccurrenceMaterializer;
use App\Services\Imports\ScheduleOccurrenceResolver;
use App\Services\Imports\ScheduleWorkbookParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class ScheduleImportPreviewApiTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../Fixtures/ScheduleImports/Приклад розкладу.xlsx';

    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_real_upload_persists_thirty_ordered_scalar_preview_rows_without_lessons(): void
    {
        [$user, $period, $template] = $this->context();
        $response = $this->upload($user, $period)->assertCreated()
            ->assertJsonPath('data.status', 'validated')->assertJsonPath('data.total_rows', 30)
            ->assertJsonPath('data.valid_rows', 30)->assertJsonPath('data.invalid_rows', 0)
            ->assertJsonPath('data.duplicate_rows', 0)->assertJsonPath('data.error_message', null)
            ->assertJsonPath('data.committed_at', null)->assertJsonCount(30, 'data.rows');
        $batch = ScheduleImportBatch::sole();
        $this->assertSame($user->id, $batch->user_id);
        $this->assertSame($period->id, $batch->academic_period_id);
        $this->assertSame('Приклад розкладу.xlsx', $batch->original_filename);
        $this->assertSame(hash_file('sha256', self::FIXTURE), $batch->file_hash);
        $this->assertSame(ScheduleImportBatchStatus::Validated, $batch->status);
        $this->assertDatabaseCount('schedule_import_rows', 30);
        $this->assertDatabaseCount('lessons', 0);
        $this->assertSame(range(1, 30), array_column($response->json('data.rows'), 'row_number'));

        $materialized = app(ScheduleOccurrenceMaterializer::class)->materialize($template, $period, $user->timezone);
        $rows = $batch->rows()->orderBy('row_number')->get();
        foreach ($rows as $index => $row) {
            $occurrence = $materialized['occurrences'][$index];
            $raw = $row->raw_data;
            $normalized = $row->normalized_data;
            $this->assertSame(ScheduleImportRowStatus::Valid, $row->status);
            $this->assertSame([], $row->validation_errors);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $row->fingerprint);
            $this->assertEqualsCanonicalizing([
                'group', 'workbook_source', 'local_date', 'weekday', 'pair_label', 'parity',
                'subject_name', 'teacher_name', 'room', 'starts_at_local', 'ends_at_local',
                'starts_at_utc', 'ends_at_utc', 'source',
            ], array_keys($raw));
            $this->assertEqualsCanonicalizing([
                'academic_period_id', 'subject_id', 'teacher_id', 'room', 'type',
                'starts_at_utc', 'ends_at_utc', 'operation', 'matched_lesson_id',
            ], array_keys($normalized));
            $this->assertSame('ПІ-231', $raw['group']);
            $this->assertEquals($template['source'], $raw['workbook_source']);
            foreach (array_diff(array_keys($occurrence), ['type']) as $key) {
                $this->assertEquals($occurrence[$key], $raw[$key]);
            }
            $this->assertSame($period->id, $normalized['academic_period_id']);
            $this->assertSame($user->subjects()->where('name', $raw['subject_name'])->sole()->id, $normalized['subject_id']);
            $this->assertSame($user->teachers()->where('name', $raw['teacher_name'])->sole()->id, $normalized['teacher_id']);
            $this->assertSame('other', $normalized['type']);
            $this->assertSame('create', $normalized['operation']);
            $this->assertNull($normalized['matched_lesson_id']);
            foreach (['user_id', 'schedule_import_batch_id', 'fingerprint', 'raw_data', 'normalized_data', 'resolution_errors'] as $internal) {
                $this->assertArrayNotHasKey($internal, $response->json('data.rows.'.$index));
            }
        }
        foreach (['user_id', 'file_hash'] as $internal) {
            $this->assertArrayNotHasKey($internal, $response->json('data'));
        }
        $numerator = collect($response->json('data.rows'))->firstWhere(fn ($row) => $row['local_date'] === '2026-09-08' && $row['pair_label'] === '7-8');
        $denominator = collect($response->json('data.rows'))->firstWhere(fn ($row) => $row['local_date'] === '2026-09-15' && $row['pair_label'] === '7-8');
        $this->assertSame('Філософія', $numerator['subject_name']);
        $this->assertSame('numerator', $numerator['parity']);
        $this->assertSame('2026-09-08T10:20:00Z', $numerator['starts_at_utc']);
        $this->assertSame('Проект інтерфейсів користувача ПС', $denominator['subject_name']);
        $this->assertSame('denominator', $denominator['parity']);
        $this->assertSame('Sheet1', $numerator['source']['worksheet']);
        $this->assertNotSame($numerator['source']['rows'], $denominator['source']['rows']);
        $this->assertSame($numerator['source']['pair_range'], $denominator['source']['pair_range']);
        $this->getJson('/api/schedule-imports/'.$batch->id)->assertOk()->assertExactJson($response->json());
    }

    public function test_missing_references_persist_invalid_preview_and_exact_counters_without_creation(): void
    {
        [$user, $period, $template] = $this->context();
        $missingSubject = $template['rules'][0]['subject_name'];
        $user->subjects()->where('name', $missingSubject)->delete();
        $user->teachers()->where('name', 'Багас Л.М.')->delete();
        $before = $this->snapshot(['subjects', 'teachers', 'lessons']);
        $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'uploaded')
            ->assertJsonPath('data.total_rows', 30)->assertJsonPath('data.valid_rows', 23)
            ->assertJsonPath('data.invalid_rows', 7)->assertJsonPath('data.duplicate_rows', 0);
        $rows = ScheduleImportRow::where('status', ScheduleImportRowStatus::Invalid)->get();
        $this->assertCount(7, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row->normalized_data['operation']);
            if ($row->raw_data['subject_name'] === $missingSubject) {
                $this->assertNull($row->fingerprint);
                $this->assertSame(['subject_name' => ['No owned subject exactly matches this imported name.']], $row->validation_errors);
            } else {
                $this->assertNotNull($row->fingerprint);
                $this->assertSame(['teacher_name' => ['No owned teacher exactly matches this imported name.']], $row->validation_errors);
            }
        }
        $this->assertSame($before, $this->snapshot(['subjects', 'teachers', 'lessons']));
    }

    public function test_same_bytes_can_create_two_independent_valid_create_previews(): void
    {
        [$user, $period] = $this->context();
        $first = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.valid_rows', 30);
        $second = $this->upload($user, $period)->assertCreated()->assertJsonPath('data.valid_rows', 30);
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('schedule_import_batches', 2);
        $this->assertDatabaseCount('schedule_import_rows', 60);
        $this->assertSame(1, ScheduleImportBatch::distinct()->count('file_hash'));
        $this->assertSame(60, ScheduleImportRow::where('status', ScheduleImportRowStatus::Valid)->count());
        foreach ($second->json('data.rows') as $row) {
            $this->assertSame('create', $row['operation']);
        }
        $this->assertDatabaseCount('lessons', 0);
    }

    #[DataProvider('lessonStates')]
    public function test_existing_lessons_classify_in_preview_without_any_lifecycle_mutation(string $state, string $status, ?string $operation): void
    {
        [$user, $period, $template] = $this->context();
        $materialized = app(ScheduleOccurrenceMaterializer::class)->materialize($template, $period, $user->timezone);
        $candidate = app(ScheduleOccurrenceResolver::class)->resolve($user, $period, $materialized)['occurrences'][0];
        $lesson = Lesson::factory()->for($user)->create([
            'subject_id' => $candidate['subject_id'], 'teacher_id' => $candidate['teacher_id'],
            'academic_period_id' => $period->id, 'type' => LessonType::Laboratory,
            'room' => $state === 'changed' ? 'corrected room' : $candidate['room'],
            'starts_at' => substr($candidate['starts_at_utc'], 0, 10).' '.substr($candidate['starts_at_utc'], 11, 8),
            'ends_at' => substr($candidate['ends_at_utc'], 0, 10).' '.substr($candidate['ends_at_utc'], 11, 8),
            'import_fingerprint' => $state === 'manual' ? null : $candidate['fingerprint'],
            'status' => match ($state) {
                'cancelled' => LessonStatus::Cancelled,
                'replaced' => LessonStatus::Replaced,
                default => LessonStatus::Active,
            },
        ]);
        if ($state === 'deleted') {
            $lesson->delete();
        }
        $before = $this->snapshot(['lessons', 'subjects', 'teachers']);
        $response = $this->upload($user, $period)->assertCreated()
            ->assertJsonPath('data.rows.0.status', $status)->assertJsonPath('data.rows.0.operation', $operation)
            ->assertJsonPath('data.rows.0.type', 'other');
        $row = ScheduleImportRow::where('row_number', 1)->sole();
        $this->assertSame($state === 'manual' ? null : $lesson->id, $row->normalized_data['matched_lesson_id']);
        $this->assertSame($candidate['fingerprint'], $row->fingerprint);
        $this->assertSame($before, $this->snapshot(['lessons', 'subjects', 'teachers']));
        $this->assertDatabaseCount('lessons', 1);
        if ($status === 'invalid') {
            $response->assertJsonPath('data.status', 'uploaded')->assertJsonPath('data.invalid_rows', 1)->assertJsonPath('data.valid_rows', 29);
            $this->assertArrayHasKey('schedule', $row->validation_errors);
        } else {
            $response->assertJsonPath('data.status', 'validated')->assertJsonPath('data.invalid_rows', 0)
                ->assertJsonPath('data.duplicate_rows', $status === 'duplicate' ? 1 : 0)
                ->assertJsonPath('data.valid_rows', $status === 'duplicate' ? 29 : 30);
            $this->assertSame([], $row->validation_errors);
        }
    }

    public static function lessonStates(): array
    {
        return [
            ['identical', 'duplicate', 'noop'], ['changed', 'valid', 'update'],
            ['manual', 'invalid', null], ['deleted', 'valid', 'restore'],
            ['cancelled', 'valid', 'preserve'], ['replaced', 'valid', 'preserve'],
        ];
    }

    public function test_unsupported_workbook_leaves_safe_failed_attempt_with_zero_rows(): void
    {
        [$user, $period] = $this->context();
        Lesson::factory()->for($user)->create(['subject_id' => $user->subjects()->first()->id, 'academic_period_id' => $period->id]);
        $before = $this->snapshot(['lessons', 'subjects', 'teachers']);
        $path = $this->temporaryPath();
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->setCellValue('A1', 'Not a supported schedule');
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();
        $response = $this->upload($user, $period, new UploadedFile($path, 'unsupported.xlsx', null, null, true));
        $this->assertFailedAttempt($response, 1);
        $this->assertSame(hash_file('sha256', $path), ScheduleImportBatch::sole()->file_hash);
        $this->assertStringNotContainsString($path, $response->json('data.error_message'));
        $this->assertStringNotContainsString('Stack trace', $response->getContent());
        $this->assertSame($before, $this->snapshot(['lessons', 'subjects', 'teachers']));
    }

    public function test_materialization_failure_also_creates_failed_attempt_without_rows(): void
    {
        [$user, $period] = $this->context();
        $user->update(['timezone' => 'Pacific/Apia']);
        // This Friday never existed locally when Apia moved across the date line.
        $period->update(['starts_on' => '2011-12-30', 'ends_on' => '2011-12-30']);
        $response = $this->upload($user, $period);
        $this->assertFailedAttempt($response);
        $this->assertStringContainsString('Nonexistent local time', $response->json('data.error_message'));
    }

    public function test_request_file_validation_and_missing_file_do_not_create_attempts(): void
    {
        [$user, $period] = $this->context();
        $this->actingAs($user, 'web')->postJson('/api/schedule-imports', ['academic_period_id' => $period->id])
            ->assertUnprocessable()->assertJsonValidationErrors('schedule_file');
        foreach (['schedule.xls', 'schedule.xlsm', 'schedule.csv', 'schedule.zip'] as $filename) {
            $this->upload($user, $period, $this->fixtureUpload($filename))
                ->assertUnprocessable()->assertJsonValidationErrors('schedule_file');
        }
        $this->upload($user, $period, UploadedFile::fake()->create('schedule.xlsx', 5121, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))
            ->assertUnprocessable()->assertJsonValidationErrors('schedule_file');
        $this->assertDatabaseCount('schedule_import_batches', 0);
        $this->assertDatabaseCount('schedule_import_rows', 0);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_client_mime_and_xlsx_suffix_cannot_disguise_text_or_arbitrary_zip(): void
    {
        [$user, $period] = $this->context();
        $text = $this->temporaryPath();
        file_put_contents($text, 'subject,teacher,room');
        $zipPath = $this->temporaryPath();
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('data.txt', 'An arbitrary ZIP is not an XLSX workbook.');
        $zip->close();
        foreach ([$text, $zipPath] as $path) {
            $file = new UploadedFile($path, 'schedule.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $this->upload($user, $period, $file)->assertUnprocessable()->assertJsonValidationErrors('schedule_file');
        }
        $this->assertDatabaseCount('schedule_import_batches', 0);
    }

    public function test_valid_xlsx_content_is_accepted_despite_incorrect_client_mime(): void
    {
        [$user, $period] = $this->context();
        $this->upload($user, $period, new UploadedFile(self::FIXTURE, 'schedule.xlsx', 'text/plain', null, true))
            ->assertCreated()->assertJsonPath('data.valid_rows', 30);
    }

    #[DataProvider('invalidPeriodIds')]
    public function test_period_id_is_a_strict_positive_integer(mixed $value): void
    {
        [$user] = $this->context();
        $this->actingAs($user, 'web')->post('/api/schedule-imports', [
            'academic_period_id' => $value, 'schedule_file' => $this->fixtureUpload(),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('academic_period_id');
        $this->assertDatabaseCount('schedule_import_batches', 0);
    }

    public static function invalidPeriodIds(): array
    {
        return [[null], [0], [-1], [true], [1.0], ['1.0'], ['1e2'], ['01'], ['+1'], ['1abc'], [str_repeat('9', 40)], [['1']]];
    }

    public function test_foreign_or_nonexistent_period_is_generically_rejected_before_processing(): void
    {
        [$user] = $this->context();
        $foreign = AcademicPeriod::factory()->create();
        $this->mock(ScheduleWorkbookParser::class)->shouldNotReceive('parse');
        $bodies = [];
        foreach ([$foreign->id, PHP_INT_MAX] as $id) {
            $response = $this->actingAs($user, 'web')->post('/api/schedule-imports', [
                'academic_period_id' => $id, 'schedule_file' => $this->fixtureUpload(),
            ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('academic_period_id');
            $bodies[] = $response->json();
        }
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertDatabaseCount('schedule_import_batches', 0);
    }

    public function test_client_context_is_ignored_and_filename_is_only_safe_metadata(): void
    {
        [$user, $period] = $this->context();
        $foreign = User::factory()->create(['timezone' => 'UTC']);
        Storage::fake('local');
        Storage::fake('public');
        $response = $this->actingAs($user, 'web')->post('/api/schedule-imports', [
            'academic_period_id' => (string) $period->id,
            'schedule_file' => $this->fixtureUpload('C:\\private\\..\\evil.xlsx'),
            'user_id' => $foreign->id, 'owner_id' => $foreign->id, 'timezone' => 'UTC',
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.original_filename', 'evil.xlsx')
            ->assertJsonPath('data.rows.0.starts_at_utc', '2026-09-07T05:30:00Z');
        $batch = ScheduleImportBatch::findOrFail($response->json('data.id'));
        $this->assertSame($user->id, $batch->user_id);
        $this->assertSame(hash_file('sha256', self::FIXTURE), $batch->file_hash);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_parsing_precedes_transaction_and_fresh_locked_owner_and_period_drive_preview(): void
    {
        [$user, $period, $template] = $this->context();
        $level = DB::transactionLevel();
        $this->mock(ScheduleWorkbookParser::class)->shouldReceive('parse')->once()->andReturnUsing(function () use ($user, $period, $template, $level) {
            $this->assertSame($level, DB::transactionLevel());
            User::whereKey($user->id)->update(['timezone' => 'UTC']);
            AcademicPeriod::whereKey($period->id)->update(['ends_on' => '2026-09-13']);

            return $template;
        });
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->upload($user, $period)->assertCreated()->assertJsonPath('data.total_rows', 15)
            ->assertJsonPath('data.rows.0.starts_at_utc', '2026-09-07T08:30:00Z');
        $locks = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'for update')));
        $this->assertCount(2, $locks);
        $this->assertStringContainsString('`users`', $locks[0]);
        $this->assertStringContainsString('`academic_periods`', $locks[1]);
        $this->assertSame($level, DB::transactionLevel());
    }

    public function test_unexpected_processing_error_propagates_without_fake_failed_batch(): void
    {
        [$user, $period] = $this->context();
        $this->mock(ScheduleImportCandidateClassifier::class)->shouldReceive('classify')->once()->andThrow(new RuntimeException('Infrastructure unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->upload($user, $period);
            $this->fail('Unexpected errors must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Infrastructure unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('schedule_import_batches', 0);
        $this->assertDatabaseCount('schedule_import_rows', 0);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_period_ownership_is_revalidated_after_parsing_before_persistence(): void
    {
        [$user, $period, $template] = $this->context();
        $foreign = User::factory()->create();
        $this->mock(ScheduleWorkbookParser::class)->shouldReceive('parse')->once()->andReturnUsing(function () use ($period, $foreign, $template) {
            AcademicPeriod::whereKey($period->id)->update(['user_id' => $foreign->id]);

            return $template;
        });
        $this->upload($user, $period)->assertUnprocessable()->assertJsonValidationErrors('academic_period_id');
        $this->assertDatabaseCount('schedule_import_batches', 0);
        $this->assertDatabaseCount('schedule_import_rows', 0);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_supported_workbook_with_no_occurrences_in_period_is_an_empty_validated_preview(): void
    {
        [$user, $period] = $this->context();
        $period->update(['starts_on' => '2026-09-12', 'ends_on' => '2026-09-13']);
        $this->upload($user, $period)->assertCreated()->assertJsonPath('data.status', 'validated')
            ->assertJsonPath('data.total_rows', 0)->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.invalid_rows', 0)->assertJsonPath('data.duplicate_rows', 0)
            ->assertJsonPath('data.error_message', null)->assertJsonCount(0, 'data.rows');
        $this->assertDatabaseCount('schedule_import_batches', 1);
        $this->assertDatabaseCount('schedule_import_rows', 0);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_persistence_failure_rolls_back_batch_and_all_rows(): void
    {
        [$user, $period] = $this->context();
        $dispatcher = ScheduleImportRow::getEventDispatcher();
        ScheduleImportRow::setEventDispatcher(clone $dispatcher);
        ScheduleImportRow::creating(function (ScheduleImportRow $row): void {
            if ($row->row_number === 2) {
                throw new RuntimeException('Persistence unavailable');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->upload($user, $period);
            $this->fail('Persistence failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Persistence unavailable', $exception->getMessage());
        } finally {
            ScheduleImportRow::setEventDispatcher($dispatcher);
        }
        $this->assertDatabaseCount('schedule_import_batches', 0);
        $this->assertDatabaseCount('schedule_import_rows', 0);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_upload_and_read_require_authentication(): void
    {
        $batch = ScheduleImportBatch::factory()->create();
        $this->post('/api/schedule-imports', ['academic_period_id' => $batch->academic_period_id, 'schedule_file' => $this->fixtureUpload()], ['Accept' => 'application/json'])->assertUnauthorized();
        $this->getJson('/api/schedule-imports/'.$batch->id)->assertUnauthorized();
        $this->assertDatabaseCount('schedule_import_batches', 1);
        $this->assertDatabaseCount('schedule_import_rows', 0);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_foreign_batch_is_not_found_and_owner_reads_rows_in_ordinal_order_without_mutation(): void
    {
        [$user, $period] = $this->context();
        $batch = ScheduleImportBatch::factory()->for($period)->create();
        foreach ([3, 1, 2] as $ordinal) {
            ScheduleImportRow::factory()->for($batch)->create(['row_number' => $ordinal]);
        }
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/schedule-imports/'.$batch->id)->assertNotFound();
        $before = $this->snapshot(['schedule_import_batches', 'schedule_import_rows', 'lessons']);
        $this->mock(ScheduleWorkbookParser::class)->shouldNotReceive('parse');
        $this->mock(ScheduleImportCandidateClassifier::class)->shouldNotReceive('classify');
        $this->app['auth']->forgetGuards();
        $response = $this->actingAs($user, 'web')->getJson('/api/schedule-imports/'.$batch->id)->assertOk();
        $this->assertSame([1, 2, 3], array_column($response->json('data.rows'), 'row_number'));
        $this->assertSame($before, $this->snapshot(['schedule_import_batches', 'schedule_import_rows', 'lessons']));
    }

    public function test_read_returns_stored_snapshot_even_after_context_changes(): void
    {
        [$user, $period] = $this->context();
        $response = $this->upload($user, $period)->assertCreated();
        $user->subjects()->update(['code' => 'changed after preview']);
        $user->update(['timezone' => 'UTC']);
        $period->update(['ends_on' => '2026-10-30']);
        $before = $this->snapshot(['schedule_import_batches', 'schedule_import_rows', 'lessons']);
        $this->mock(ScheduleWorkbookParser::class)->shouldNotReceive('parse');
        $this->mock(ScheduleImportCandidateClassifier::class)->shouldNotReceive('classify');
        $this->getJson('/api/schedule-imports/'.$response->json('data.id'))->assertOk()->assertExactJson($response->json());
        $this->assertSame($before, $this->snapshot(['schedule_import_batches', 'schedule_import_rows', 'lessons']));
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

    private function fixtureUpload(string $filename = 'Приклад розкладу.xlsx'): UploadedFile
    {
        return new UploadedFile(self::FIXTURE, $filename, null, null, true);
    }

    private function upload(User $user, AcademicPeriod $period, ?UploadedFile $file = null): TestResponse
    {
        return $this->actingAs($user, 'web')->post('/api/schedule-imports', [
            'academic_period_id' => (string) $period->id, 'schedule_file' => $file ?? $this->fixtureUpload(),
        ], ['Accept' => 'application/json']);
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'schedule-preview-');
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function assertFailedAttempt(TestResponse $response, int $lessonCount = 0): void
    {
        $response->assertCreated()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.total_rows', 0)
            ->assertJsonPath('data.valid_rows', 0)->assertJsonPath('data.invalid_rows', 0)
            ->assertJsonPath('data.duplicate_rows', 0)->assertJsonPath('data.committed_at', null)
            ->assertJsonCount(0, 'data.rows');
        $this->assertNotEmpty($response->json('data.error_message'));
        $this->assertDatabaseCount('schedule_import_batches', 1);
        $this->assertDatabaseCount('schedule_import_rows', 0);
        $this->assertDatabaseCount('lessons', $lessonCount);
    }

    private function snapshot(array $tables): array
    {
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all();
        }

        return $result;
    }
}
