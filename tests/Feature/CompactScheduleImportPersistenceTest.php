<?php

namespace Tests\Feature;

use App\Enums\ScheduleImportBatchStatus;
use App\Models\AcademicPeriod;
use App\Models\ScheduleImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('migration')]
#[Group('mysql')]
class CompactScheduleImportPersistenceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('tableDefinitions')]
    public function test_columns_match_approved_types_nullability_and_defaults(string $table, array $expected): void
    {
        $columns = collect(Schema::getColumns($table));
        $this->assertSame($expected, $columns->mapWithKeys(fn (array $column) => [$column['name'] => [$column['type'], $column['nullable'], $column['default']]])->all());
        $this->assertTrue($columns->firstWhere('name', 'id')['auto_increment']);
        $this->assertTrue(collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['primary'] && $index['columns'] === ['id']));
    }

    public static function tableDefinitions(): array
    {
        return ['batches' => ['schedule_import_batches', ['id' => [0 => 'bigint unsigned', 1 => false, 2 => null], 'user_id' => [0 => 'bigint unsigned', 1 => false, 2 => null], 'academic_period_id' => [0 => 'bigint unsigned', 1 => false, 2 => null], 'status' => [0 => 'varchar(20)', 1 => false, 2 => 'uploaded'], 'original_filename' => [0 => 'varchar(255)', 1 => false, 2 => null], 'file_hash' => [0 => 'char(64)', 1 => false, 2 => null], 'total_rows' => [0 => 'int unsigned', 1 => false, 2 => '0'], 'valid_rows' => [0 => 'int unsigned', 1 => false, 2 => '0'], 'invalid_rows' => [0 => 'int unsigned', 1 => false, 2 => '0'], 'duplicate_rows' => [0 => 'int unsigned', 1 => false, 2 => '0'], 'error_message' => [0 => 'text', 1 => true, 2 => null], 'committed_at' => [0 => 'datetime', 1 => true, 2 => null], 'created_at' => [0 => 'timestamp', 1 => true, 2 => null], 'updated_at' => [0 => 'timestamp', 1 => true, 2 => null]]]];
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
        $batch = ScheduleImportBatch::factory()->create(['status' => ScheduleImportBatchStatus::Committed, 'total_rows' => 10, 'valid_rows' => 5, 'invalid_rows' => 3, 'duplicate_rows' => 2, 'committed_at' => '2026-10-05 12:30:00', 'error_message' => 'Test diagnostic'])->refresh();
        $this->assertSame(ScheduleImportBatchStatus::Committed, $batch->status);
        $this->assertInstanceOf(Carbon::class, $batch->committed_at);
        $this->assertSame('2026-10-05 12:30:00', $batch->committed_at->format('Y-m-d H:i:s'));
        $this->assertSame('Test diagnostic', $batch->error_message);
        foreach (['total_rows' => 10, 'valid_rows' => 5, 'invalid_rows' => 3, 'duplicate_rows' => 2] as $counter => $value) {
            $this->assertSame($value, $batch->{$counter});
        }
    }
}
