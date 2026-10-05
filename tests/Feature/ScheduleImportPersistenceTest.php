<?php

namespace Tests\Feature;

use App\Enums\ScheduleImportBatchStatus;
use App\Enums\ScheduleImportRowStatus;
use App\Models\AcademicPeriod;
use App\Models\ScheduleImportBatch;
use App\Models\ScheduleImportRow;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleImportPersistenceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('tableDefinitions')]
    public function test_columns_match_approved_types_nullability_and_defaults(string $table, array $expected): void
    {
        $columns = collect(Schema::getColumns($table));

        $this->assertSame($expected, $columns->mapWithKeys(fn (array $column) => [
            $column['name'] => [$column['type'], $column['nullable'], $column['default']],
        ])->all());
        $this->assertTrue($columns->firstWhere('name', 'id')['auto_increment']);
        $this->assertTrue(collect(Schema::getIndexes($table))->contains(
            fn (array $index) => $index['primary'] && $index['columns'] === ['id'],
        ));
    }

    public static function tableDefinitions(): array
    {
        $timestamps = ['created_at' => ['timestamp', true, null], 'updated_at' => ['timestamp', true, null]];

        return [
            'batches' => ['schedule_import_batches', [
                'id' => ['bigint unsigned', false, null],
                'user_id' => ['bigint unsigned', false, null],
                'academic_period_id' => ['bigint unsigned', false, null],
                'status' => ['varchar(20)', false, 'uploaded'],
                'original_filename' => ['varchar(255)', false, null],
                'file_hash' => ['char(64)', false, null],
                'total_rows' => ['int unsigned', false, '0'],
                'valid_rows' => ['int unsigned', false, '0'],
                'invalid_rows' => ['int unsigned', false, '0'],
                'duplicate_rows' => ['int unsigned', false, '0'],
                'error_message' => ['text', true, null],
                'committed_at' => ['datetime', true, null],
                ...$timestamps,
            ]],
            'rows' => ['schedule_import_rows', [
                'id' => ['bigint unsigned', false, null],
                'schedule_import_batch_id' => ['bigint unsigned', false, null],
                'row_number' => ['int unsigned', false, null],
                'status' => ['varchar(16)', false, null],
                'raw_data' => ['json', false, null],
                'normalized_data' => ['json', true, null],
                'validation_errors' => ['json', true, null],
                'fingerprint' => ['char(64)', true, null],
                ...$timestamps,
            ]],
        ];
    }

    public function test_batch_database_defaults_and_factory_ownership(): void
    {
        $batch = ScheduleImportBatch::factory()->create()->refresh();

        $this->assertSame($batch->academicPeriod->user_id, $batch->user_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $batch->file_hash);
        $this->assertSame(ScheduleImportBatchStatus::Uploaded, $batch->status);
        $this->assertNull($batch->error_message);
        $this->assertNull($batch->committed_at);
        foreach (['total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows'] as $counter) {
            $this->assertSame(0, $batch->$counter);
        }

        // Omit status and counters entirely to prove these defaults come from MySQL.
        $minimal = ScheduleImportBatch::create($batch->only(['user_id', 'academic_period_id', 'original_filename', 'file_hash']))->refresh();
        $this->assertSame(ScheduleImportBatchStatus::Uploaded, $minimal->status);
        foreach (['total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows'] as $counter) {
            $this->assertSame(0, $minimal->$counter);
        }
    }

    public function test_batch_factory_reuses_the_owner_of_an_explicit_period(): void
    {
        $period = AcademicPeriod::factory()->create();
        $batch = ScheduleImportBatch::factory()->for($period)->create();

        $this->assertSame($period->id, $batch->academic_period_id);
        $this->assertSame($period->user_id, $batch->user_id);
    }

    public function test_batch_casts_counters_and_committed_datetime(): void
    {
        $batch = ScheduleImportBatch::factory()->create([
            'status' => ScheduleImportBatchStatus::Committed,
            'total_rows' => 10, 'valid_rows' => 5, 'invalid_rows' => 3, 'duplicate_rows' => 2,
            'committed_at' => '2026-10-05 12:30:00',
            'error_message' => 'Test diagnostic',
        ])->refresh();

        $this->assertSame(ScheduleImportBatchStatus::Committed, $batch->status);
        $this->assertInstanceOf(Carbon::class, $batch->committed_at);
        $this->assertSame('2026-10-05 12:30:00', $batch->committed_at->format('Y-m-d H:i:s'));
        $this->assertSame('Test diagnostic', $batch->error_message);
        foreach (['total_rows' => 10, 'valid_rows' => 5, 'invalid_rows' => 3, 'duplicate_rows' => 2] as $counter => $value) {
            $this->assertSame($value, $batch->$counter);
        }
    }

    public function test_php_enums_have_exactly_the_approved_scalar_values(): void
    {
        $this->assertSame(['uploaded', 'validated', 'committed', 'failed', 'cancelled'], array_column(ScheduleImportBatchStatus::cases(), 'value'));
        $this->assertSame(['valid', 'invalid', 'duplicate'], array_column(ScheduleImportRowStatus::cases(), 'value'));
    }

    #[DataProvider('batchStatuses')]
    public function test_mysql_accepts_each_batch_status_and_eloquent_casts_it(string $status): void
    {
        $batch = ScheduleImportBatch::factory()->create();

        DB::table('schedule_import_batches')->where('id', $batch->id)->update(['status' => $status]);

        $this->assertSame(ScheduleImportBatchStatus::from($status), $batch->refresh()->status);
    }

    public static function batchStatuses(): array
    {
        return [['uploaded'], ['validated'], ['committed'], ['failed'], ['cancelled']];
    }

    #[DataProvider('invalidStatuses')]
    public function test_mysql_rejects_unsupported_batch_status_on_insert_and_update(string $status): void
    {
        $batch = ScheduleImportBatch::factory()->create();
        $values = $batch->getAttributes();
        unset($values['id']);

        $this->assertMysqlRejects(
            fn () => DB::table('schedule_import_batches')->insert([...$values, 'status' => $status]),
            3819,
            'schedule_import_batches_valid_status',
        );
        $this->assertMysqlRejects(
            fn () => DB::table('schedule_import_batches')->where('id', $batch->id)->update(['status' => $status]),
            3819,
            'schedule_import_batches_valid_status',
        );
    }

    public static function invalidStatuses(): array
    {
        return ['unsupported' => ['unsupported'], 'empty' => ['']];
    }

    public function test_batch_relationships_are_scoped_to_user_and_period(): void
    {
        $first = ScheduleImportBatch::factory()->create();
        $second = ScheduleImportBatch::factory()->create();
        $anotherPeriod = AcademicPeriod::factory()->for($first->user)->create();
        $anotherBatch = ScheduleImportBatch::factory()->for($anotherPeriod)->create();

        $this->assertSame([$first->id, $anotherBatch->id], $first->user->scheduleImportBatches()->orderBy('id')->get()->modelKeys());
        $this->assertSame([$second->id], $second->user->scheduleImportBatches->modelKeys());
        $this->assertSame([$first->id], $first->academicPeriod->scheduleImportBatches->modelKeys());
        $this->assertSame([$second->id], $second->academicPeriod->scheduleImportBatches->modelKeys());
        $this->assertSame([$anotherBatch->id], $anotherPeriod->scheduleImportBatches->modelKeys());
        $this->assertTrue($first->user->is($first->academicPeriod->user));
        $this->assertTrue($first->academicPeriod->is($first->user->academicPeriods()->find($first->academic_period_id)));
    }

    public function test_mysql_rejects_nonexistent_batch_user(): void
    {
        $missing = User::factory()->create();
        $missing->delete();
        $batch = ScheduleImportBatch::factory()->create();

        $this->assertMysqlRejects(
            fn () => DB::table('schedule_import_batches')->where('id', $batch->id)->update(['user_id' => $missing->id]),
            1452,
            'schedule_import_batches_user_id_foreign',
        );
    }

    public function test_mysql_rejects_nonexistent_batch_period(): void
    {
        $missing = AcademicPeriod::factory()->create();
        $missing->delete();
        $batch = ScheduleImportBatch::factory()->create();

        $this->assertMysqlRejects(
            fn () => DB::table('schedule_import_batches')->where('id', $batch->id)->update(['academic_period_id' => $missing->id]),
            1452,
            'schedule_import_batches_academic_period_id_foreign',
        );
    }

    public function test_referenced_period_cannot_be_deleted_until_its_batch_is_removed(): void
    {
        $batch = ScheduleImportBatch::factory()->create();
        $period = $batch->academicPeriod;

        $this->assertMysqlRejects(
            fn () => DB::table('academic_periods')->where('id', $period->id)->delete(),
            1451,
            'schedule_import_batches_academic_period_id_foreign',
        );
        $this->assertModelExists($period);
        $this->assertModelExists($batch);

        $batch->delete();
        $period->delete();

        $this->assertModelMissing($period);
    }

    public function test_direct_user_hard_delete_is_restricted_until_owned_batches_are_removed(): void
    {
        $row = ScheduleImportRow::factory()->create();
        $batch = $row->scheduleImportBatch;
        $period = $batch->academicPeriod;
        $user = $batch->user;

        // MySQL encounters the period CASCADE before the batch CASCADE; the period's
        // RESTRICT path requires ordered account purging in the future application layer.
        $this->assertMysqlRejects(
            fn () => DB::table('users')->where('id', $user->id)->delete(),
            1451,
            'schedule_import_batches_academic_period_id_foreign',
        );
        foreach ([$user, $period, $batch, $row] as $record) {
            $this->assertModelExists($record);
        }

        $batch->delete();
        DB::table('users')->where('id', $user->id)->delete();

        foreach ([$user, $period, $batch, $row] as $record) {
            $this->assertModelMissing($record);
        }
    }

    public function test_simple_period_fk_checks_existence_without_enforcing_same_owner(): void
    {
        $period = AcademicPeriod::factory()->create();
        $otherUser = User::factory()->create();
        // BR-IMP-005 must be enforced by the future import Service/validation layer.
        $batch = ScheduleImportBatch::factory()->for($period)->for($otherUser)->create();

        $this->assertNotSame($period->user_id, $batch->user_id);
        $this->assertModelExists($batch);

        // Isolate the user->batch CASCADE from the same-owner period RESTRICT path.
        DB::table('users')->where('id', $otherUser->id)->delete();

        $this->assertModelMissing($batch);
        $this->assertModelExists($period);
    }

    public function test_same_file_hash_can_be_reused_within_one_user_and_period(): void
    {
        $batch = ScheduleImportBatch::factory()->create();
        $repeated = ScheduleImportBatch::factory()->for($batch->academicPeriod)->create(['file_hash' => $batch->file_hash]);

        $this->assertSame($batch->user_id, $repeated->user_id);
        $this->assertSame($batch->academic_period_id, $repeated->academic_period_id);
        $this->assertSame($batch->file_hash, $repeated->refresh()->file_hash);
        $this->assertNotSame($batch->id, $repeated->id);
        $this->assertFalse(collect(Schema::getIndexes('schedule_import_batches'))->contains(
            fn (array $index) => $index['unique'] && in_array('file_hash', $index['columns'], true),
        ));
    }

    public function test_rows_relationship_and_batch_cascade_are_isolated(): void
    {
        $rows = ScheduleImportRow::factory()->count(2)->for(ScheduleImportBatch::factory())->create();
        $batch = $rows->first()->scheduleImportBatch;
        $other = ScheduleImportRow::factory()->create();

        $this->assertSame($rows->modelKeys(), $batch->rows()->orderBy('id')->get()->modelKeys());
        foreach ($rows as $row) {
            $this->assertTrue($row->scheduleImportBatch->is($batch));
        }

        DB::table('schedule_import_batches')->where('id', $batch->id)->delete();

        foreach ($rows as $row) {
            $this->assertModelMissing($row);
        }
        $this->assertModelExists($other);
    }

    public function test_mysql_rejects_nonexistent_row_batch(): void
    {
        $row = ScheduleImportRow::factory()->create();
        $values = $row->getAttributes();
        unset($values['id']);
        $row->scheduleImportBatch->delete();

        $this->assertMysqlRejects(fn () => DB::table('schedule_import_rows')->insert($values), 1452, 'schedule_import_rows_schedule_import_batch_id_foreign');
    }

    #[DataProvider('rowStatuses')]
    public function test_mysql_accepts_each_row_status_and_eloquent_casts_it(string $status): void
    {
        $row = ScheduleImportRow::factory()->create();

        DB::table('schedule_import_rows')->where('id', $row->id)->update(['status' => $status]);

        $this->assertSame(ScheduleImportRowStatus::from($status), $row->refresh()->status);
    }

    public static function rowStatuses(): array
    {
        return [['valid'], ['invalid'], ['duplicate']];
    }

    #[DataProvider('invalidStatuses')]
    public function test_mysql_rejects_unsupported_row_status_on_insert_and_update(string $status): void
    {
        $row = ScheduleImportRow::factory()->create();
        $values = $row->getAttributes();
        unset($values['id']);

        $this->assertMysqlRejects(
            fn () => DB::table('schedule_import_rows')->insert([...$values, 'row_number' => $row->row_number + 1, 'status' => $status]),
            3819,
            'schedule_import_rows_valid_status',
        );
        $this->assertMysqlRejects(
            fn () => DB::table('schedule_import_rows')->where('id', $row->id)->update(['status' => $status]),
            3819,
            'schedule_import_rows_valid_status',
        );
    }

    #[DataProvider('positiveRowNumbers')]
    public function test_positive_row_numbers_are_accepted(int $number): void
    {
        $row = ScheduleImportRow::factory()->create(['row_number' => $number])->refresh();

        $this->assertSame($number, $row->row_number);
    }

    public static function positiveRowNumbers(): array
    {
        return ['minimum' => [1], 'another row' => [42], 'unsigned maximum' => [4294967295]];
    }

    #[DataProvider('invalidRowNumbers')]
    public function test_mysql_rejects_nonpositive_row_numbers(int $number, int $error, ?string $constraint): void
    {
        $row = ScheduleImportRow::factory()->create();
        $values = $row->getAttributes();
        unset($values['id']);

        $this->assertMysqlRejects(fn () => DB::table('schedule_import_rows')->insert([...$values, 'row_number' => $number]), $error, $constraint);
        $this->assertMysqlRejects(fn () => DB::table('schedule_import_rows')->where('id', $row->id)->update(['row_number' => $number]), $error, $constraint);
    }

    public static function invalidRowNumbers(): array
    {
        return [
            'zero CHECK' => [0, 3819, 'schedule_import_rows_positive_row_number'],
            'negative UNSIGNED' => [-1, 1264, null],
        ];
    }

    public function test_row_numbers_are_unique_within_a_batch_only(): void
    {
        $row = ScheduleImportRow::factory()->create(['row_number' => 1]);
        $other = ScheduleImportRow::factory()->create(['row_number' => 1]);
        $values = $row->getAttributes();
        unset($values['id']);

        $this->assertNotSame($row->schedule_import_batch_id, $other->schedule_import_batch_id);
        $this->assertModelExists($other);
        $this->assertMysqlRejects(fn () => DB::table('schedule_import_rows')->insert($values), 1062, 'schedule_import_rows_schedule_import_batch_id_row_number_unique');
    }

    public function test_row_factory_and_nullable_preview_metadata(): void
    {
        $row = ScheduleImportRow::factory()->create()->refresh();

        $this->assertGreaterThan(0, $row->row_number);
        $this->assertSame(ScheduleImportRowStatus::Valid, $row->status);
        $this->assertIsArray($row->raw_data);
        $this->assertNull($row->normalized_data);
        $this->assertNull($row->validation_errors);
        $this->assertNull($row->fingerprint);
        $this->assertSame($row->scheduleImportBatch->user_id, $row->scheduleImportBatch->academicPeriod->user_id);
    }

    public function test_json_preview_fields_round_trip_as_arrays(): void
    {
        // Representative fixtures only; these do not define the importer JSON contract.
        $raw = ['cells' => ['Test subject', 'Українська'], 'metadata' => ['source_row' => 2]];
        $normalized = ['test_value' => 'Example', 'flags' => [true, false], 'optional' => null];
        $errors = [['field' => 'test_value', 'message' => 'Test diagnostic']];
        $row = ScheduleImportRow::factory()->create([
            'raw_data' => $raw, 'normalized_data' => $normalized, 'validation_errors' => $errors,
        ])->refresh();

        $this->assertEquals($raw, $row->raw_data);
        $this->assertEquals($normalized, $row->normalized_data);
        $this->assertEquals($errors, $row->validation_errors);
        foreach (['raw_data' => $raw, 'normalized_data' => $normalized, 'validation_errors' => $errors] as $column => $expected) {
            $this->assertIsArray($row->$column);
            $this->assertEquals($expected, json_decode($row->getRawOriginal($column), true, flags: JSON_THROW_ON_ERROR));
        }

        $row->update(['normalized_data' => null, 'validation_errors' => null]);
        $this->assertNull($row->refresh()->normalized_data);
        $this->assertNull($row->validation_errors);
    }

    public function test_mysql_requires_raw_json_data(): void
    {
        $row = ScheduleImportRow::factory()->create();
        $values = $row->getAttributes();
        unset($values['id'], $values['raw_data']);
        $values['row_number'] = $row->row_number + 1;

        $this->assertMysqlRejects(fn () => DB::table('schedule_import_rows')->insert($values), 1364);
        $this->assertMysqlRejects(fn () => DB::table('schedule_import_rows')->insert([...$values, 'raw_data' => null]), 1048);
    }

    #[DataProvider('jsonColumns')]
    public function test_mysql_rejects_malformed_json_in_each_preview_column(string $column): void
    {
        $row = ScheduleImportRow::factory()->create();

        $this->assertMysqlRejects(
            fn () => DB::table('schedule_import_rows')->where('id', $row->id)->update([$column => '{invalid json']),
            3140,
        );
    }

    public static function jsonColumns(): array
    {
        return [['raw_data'], ['normalized_data'], ['validation_errors']];
    }

    public function test_fingerprints_are_nullable_and_not_unique_within_or_across_batches(): void
    {
        $fingerprint = str_repeat('a1', 32);
        $row = ScheduleImportRow::factory()->create(['row_number' => 1, 'fingerprint' => $fingerprint]);
        $sameBatch = ScheduleImportRow::factory()->for($row->scheduleImportBatch)->create(['row_number' => 2, 'fingerprint' => $fingerprint]);
        $otherBatch = ScheduleImportRow::factory()->create(['row_number' => 1, 'fingerprint' => $fingerprint]);

        foreach ([$row, $sameBatch, $otherBatch] as $record) {
            $this->assertSame($fingerprint, $record->refresh()->fingerprint);
        }
        $row->update(['fingerprint' => null]);
        $this->assertNull($row->refresh()->fingerprint);
        $this->assertFalse(collect(Schema::getIndexes('schedule_import_rows'))->contains(
            fn (array $index) => $index['unique'] && in_array('fingerprint', $index['columns'], true),
        ));
    }

    #[DataProvider('approvedIndexes')]
    public function test_indexes_match_approved_columns_and_uniqueness(string $table, string $name, array $columns, bool $unique): void
    {
        $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);

        $this->assertNotNull($index);
        $this->assertSame($columns, $index['columns']);
        $this->assertSame($unique, $index['unique']);
    }

    public static function approvedIndexes(): array
    {
        return [
            'batch period' => ['schedule_import_batches', 'schedule_import_batches_user_period_created_index', ['user_id', 'academic_period_id', 'created_at'], false],
            'batch hash' => ['schedule_import_batches', 'schedule_import_batches_user_id_file_hash_index', ['user_id', 'file_hash'], false],
            'row number' => ['schedule_import_rows', 'schedule_import_rows_schedule_import_batch_id_row_number_unique', ['schedule_import_batch_id', 'row_number'], true],
            'row status' => ['schedule_import_rows', 'schedule_import_rows_schedule_import_batch_id_status_index', ['schedule_import_batch_id', 'status'], false],
        ];
    }

    public function test_foreign_keys_match_approved_targets_and_delete_actions(): void
    {
        $expected = [
            'schedule_import_batches' => [
                'user_id' => ['users', 'cascade'],
                'academic_period_id' => ['academic_periods', 'restrict'],
            ],
            'schedule_import_rows' => [
                'schedule_import_batch_id' => ['schedule_import_batches', 'cascade'],
            ],
        ];

        foreach ($expected as $table => $definitions) {
            $keys = collect(Schema::getForeignKeys($table))->keyBy('name');
            $this->assertCount(count($definitions), $keys);
            foreach ($definitions as $column => [$target, $action]) {
                $key = $keys->get($table.'_'.$column.'_foreign');
                $this->assertNotNull($key);
                $this->assertSame([$column], $key['columns']);
                $this->assertSame($target, $key['foreign_table']);
                $this->assertSame(['id'], $key['foreign_columns']);
                $this->assertSame($action, $key['on_delete']);
            }
        }
    }

    public function test_all_three_named_checks_are_enforced(): void
    {
        $checks = DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS name, ENFORCED AS enforced
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'
                AND TABLE_NAME IN ('schedule_import_batches', 'schedule_import_rows')
            ORDER BY CONSTRAINT_NAME
            SQL);

        $this->assertSame([
            'schedule_import_batches_valid_status' => 'YES',
            'schedule_import_rows_positive_row_number' => 'YES',
            'schedule_import_rows_valid_status' => 'YES',
        ], collect($checks)->pluck('enforced', 'name')->all());
    }

    private function assertMysqlRejects(callable $operation, int $error, ?string $constraint = null): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertSame($error, $exception->errorInfo[1]);
            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $exception->getMessage());
            }

            return;
        }

        $this->fail('MySQL accepted an operation that should violate a database constraint.');
    }
}
