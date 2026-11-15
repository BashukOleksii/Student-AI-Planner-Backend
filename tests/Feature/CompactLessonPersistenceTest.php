<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Models\Lesson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('migration')]
#[Group('mysql')]
class CompactLessonPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const EXAMPLE_FINGERPRINT = 'aaef02b0a645f0b15b7583ce6b0f21ece2ab2f8ea3d7a8dd2f0c1e6104ed8806';

    public function test_columns_match_approved_types_nullability_and_defaults(): void
    {
        $columns = collect(Schema::getColumns('lessons'));
        $this->assertSame(['id' => ['bigint unsigned', false, null], 'user_id' => ['bigint unsigned', false, null], 'academic_period_id' => ['bigint unsigned', true, null], 'subject_id' => ['bigint unsigned', false, null], 'teacher_id' => ['bigint unsigned', true, null], 'schedule_import_batch_id' => ['bigint unsigned', true, null], 'replaces_lesson_id' => ['bigint unsigned', true, null], 'type' => ['varchar(32)', false, 'lecture'], 'room' => ['varchar(100)', true, null], 'starts_at' => ['datetime', false, null], 'ends_at' => ['datetime', false, null], 'status' => ['varchar(20)', false, 'active'], 'import_fingerprint' => ['char(64)', true, null], 'created_at' => ['timestamp', true, null], 'updated_at' => ['timestamp', true, null], 'deleted_at' => ['timestamp', true, null]], $columns->mapWithKeys(fn (array $column) => [$column['name'] => [$column['type'], $column['nullable'], $column['default']]])->all());
        $this->assertTrue($columns->firstWhere('name', 'id')['auto_increment']);
        $this->assertTrue(collect(Schema::getIndexes('lessons'))->contains(fn (array $index) => $index['primary'] && $index['columns'] === ['id']));
    }

    public function test_datetime_and_enum_casts_and_room_round_trip(): void
    {
        $lesson = Lesson::factory()->create(['starts_at' => '2026-10-05 06:00:00', 'ends_at' => '2026-10-05 07:30:00', 'type' => LessonType::Practical, 'status' => LessonStatus::Cancelled, 'room' => 'Test room 42'])->refresh();
        $this->assertSame(LessonType::Practical, $lesson->type);
        $this->assertSame(LessonStatus::Cancelled, $lesson->status);
        $this->assertSame('Test room 42', $lesson->room);
        foreach (['starts_at' => '2026-10-05T06:00:00Z', 'ends_at' => '2026-10-05T07:30:00Z'] as $column => $expected) {
            $this->assertInstanceOf(Carbon::class, $lesson->{$column});
            $this->assertSame('UTC', $lesson->{$column}->timezoneName);
            $this->assertSame($expected, $lesson->{$column}->format('Y-m-d\TH:i:s\Z'));
        }
    }

    public function test_php_enums_have_exactly_the_approved_scalar_values(): void
    {
        $this->assertSame(['active', 'cancelled', 'replaced'], array_column(LessonStatus::cases(), 'value'));
        $this->assertSame(['lecture', 'practical', 'laboratory', 'seminar', 'consultation', 'exam', 'other'], array_column(LessonType::cases(), 'value'));
    }

    #[DataProvider('approvedVocabulary')]
    public function test_mysql_accepts_approved_vocabulary_and_eloquent_casts_it(string $column, string $value, string $enum): void
    {
        $lesson = Lesson::factory()->create([$column => $value])->refresh();
        $this->assertSame($enum::from($value), $lesson->{$column});
        DB::table('lessons')->where('id', $lesson->id)->update([$column => $value]);
        $this->assertSame($enum::from($value), $lesson->refresh()->{$column});
    }

    public static function approvedVocabulary(): array
    {
        return [0 => ['status', 'active', 'App\Enums\LessonStatus']];
    }

    #[DataProvider('unsupportedVocabulary')]
    public function test_mysql_rejects_unsupported_vocabulary_on_insert_and_update(string $column, string $value, string $constraint): void
    {
        $lesson = Lesson::factory()->create();
        $values = $lesson->getAttributes();
        unset($values['id']);
        $this->assertMysqlRejects(fn () => DB::table('lessons')->insert([...$values, $column => $value]), 3819, $constraint);
        $this->assertMysqlRejects(fn () => DB::table('lessons')->where('id', $lesson->id)->update([$column => $value]), 3819, $constraint);
    }

    public static function unsupportedVocabulary(): array
    {
        return [0 => ['status', 'unsupported', 'lessons_valid_status']];
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
