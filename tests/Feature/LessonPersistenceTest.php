<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Models\AcademicPeriod;
use App\Models\Lesson;
use App\Models\ScheduleImportBatch;
use App\Models\ScheduleImportRow;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LessonPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const EXAMPLE_FINGERPRINT = 'aaef02b0a645f0b15b7583ce6b0f21ece2ab2f8ea3d7a8dd2f0c1e6104ed8806';

    public function test_columns_match_approved_types_nullability_and_defaults(): void
    {
        $columns = collect(Schema::getColumns('lessons'));

        $this->assertSame([
            'id' => ['bigint unsigned', false, null],
            'user_id' => ['bigint unsigned', false, null],
            'academic_period_id' => ['bigint unsigned', true, null],
            'subject_id' => ['bigint unsigned', false, null],
            'teacher_id' => ['bigint unsigned', true, null],
            'schedule_import_batch_id' => ['bigint unsigned', true, null],
            'replaces_lesson_id' => ['bigint unsigned', true, null],
            'type' => ['varchar(32)', false, 'lecture'],
            'room' => ['varchar(100)', true, null],
            'starts_at' => ['datetime', false, null],
            'ends_at' => ['datetime', false, null],
            'status' => ['varchar(20)', false, 'active'],
            'import_fingerprint' => ['char(64)', true, null],
            'created_at' => ['timestamp', true, null],
            'updated_at' => ['timestamp', true, null],
            'deleted_at' => ['timestamp', true, null],
        ], $columns->mapWithKeys(fn (array $column) => [
            $column['name'] => [$column['type'], $column['nullable'], $column['default']],
        ])->all());
        $this->assertTrue($columns->firstWhere('name', 'id')['auto_increment']);
        $this->assertTrue(collect(Schema::getIndexes('lessons'))->contains(
            fn (array $index) => $index['primary'] && $index['columns'] === ['id'],
        ));
    }

    public function test_factory_creates_an_ownership_consistent_manual_lesson_and_database_defaults(): void
    {
        $lesson = Lesson::factory()->create()->refresh();

        $this->assertTrue($lesson->user->is($lesson->subject->user));
        $this->assertSame(LessonStatus::Active, $lesson->status);
        $this->assertSame(LessonType::Lecture, $lesson->type);
        $this->assertTrue($lesson->starts_at->lt($lesson->ends_at));
        foreach (['academic_period_id', 'teacher_id', 'schedule_import_batch_id', 'replaces_lesson_id', 'room', 'import_fingerprint', 'deleted_at'] as $column) {
            $this->assertNull($lesson->$column);
        }
        foreach (['academicPeriod', 'teacher', 'scheduleImportBatch', 'replacedLesson', 'replacement'] as $relation) {
            $this->assertNull($lesson->$relation);
        }

        // Omit status/type entirely to verify MySQL defaults, independently of the factory.
        $minimal = Lesson::create($lesson->only(['user_id', 'subject_id', 'starts_at', 'ends_at']))->refresh();
        $this->assertSame(LessonStatus::Active, $minimal->status);
        $this->assertSame(LessonType::Lecture, $minimal->type);

        $existingUser = User::factory()->create();
        $owned = Lesson::factory()->for($existingUser)->create();
        $this->assertSame($existingUser->id, $owned->user_id);
        $this->assertSame($existingUser->id, $owned->subject->user_id);
    }

    public function test_datetime_and_enum_casts_and_room_round_trip(): void
    {
        $lesson = Lesson::factory()->create([
            'starts_at' => '2026-10-05 06:00:00', 'ends_at' => '2026-10-05 07:30:00',
            'type' => LessonType::Practical, 'status' => LessonStatus::Cancelled, 'room' => 'Test room 42',
        ])->refresh();

        $this->assertSame(LessonType::Practical, $lesson->type);
        $this->assertSame(LessonStatus::Cancelled, $lesson->status);
        $this->assertSame('Test room 42', $lesson->room);
        foreach (['starts_at' => '2026-10-05T06:00:00Z', 'ends_at' => '2026-10-05T07:30:00Z'] as $column => $expected) {
            $this->assertInstanceOf(Carbon::class, $lesson->$column);
            $this->assertSame('UTC', $lesson->$column->timezoneName);
            $this->assertSame($expected, $lesson->$column->format('Y-m-d\TH:i:s\Z'));
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
        $this->assertSame($enum::from($value), $lesson->$column);

        DB::table('lessons')->where('id', $lesson->id)->update([$column => $value]);
        $this->assertSame($enum::from($value), $lesson->refresh()->$column);
    }

    public static function approvedVocabulary(): array
    {
        return [
            ['status', 'active', LessonStatus::class], ['status', 'cancelled', LessonStatus::class], ['status', 'replaced', LessonStatus::class],
            ['type', 'lecture', LessonType::class], ['type', 'practical', LessonType::class], ['type', 'laboratory', LessonType::class],
            ['type', 'seminar', LessonType::class], ['type', 'consultation', LessonType::class], ['type', 'exam', LessonType::class], ['type', 'other', LessonType::class],
        ];
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
        return [
            ['status', 'unsupported', 'lessons_valid_status'], ['status', '', 'lessons_valid_status'],
            ['type', 'unsupported', 'lessons_valid_type'], ['type', '', 'lessons_valid_type'],
        ];
    }

    #[DataProvider('invalidIntervals')]
    public function test_mysql_rejects_equal_or_reversed_intervals_on_insert_and_update(string $end): void
    {
        $lesson = Lesson::factory()->create();
        $values = $lesson->getAttributes();
        unset($values['id']);

        $this->assertMysqlRejects(fn () => DB::table('lessons')->insert([...$values, 'ends_at' => $end]), 3819, 'lessons_valid_interval');
        $this->assertMysqlRejects(fn () => DB::table('lessons')->where('id', $lesson->id)->update(['ends_at' => $end]), 3819, 'lessons_valid_interval');
    }

    public static function invalidIntervals(): array
    {
        return ['equal' => ['2026-10-05 06:00:00'], 'reversed' => ['2026-10-05 05:59:59']];
    }

    public function test_parent_and_lesson_relationships_are_isolated(): void
    {
        $batch = ScheduleImportBatch::factory()->create();
        $user = $batch->user;
        $subject = Subject::factory()->for($user)->create();
        $teacher = Teacher::factory()->for($user)->create();
        $lesson = Lesson::factory()->for($user)->for($subject)->for($teacher)->for($batch)->for($batch->academicPeriod)->create();
        $another = Lesson::factory()->for($user)->create();
        $otherUserLesson = Lesson::factory()->create();

        $this->assertSame([$lesson->id, $another->id], $user->lessons()->orderBy('id')->get()->modelKeys());
        $this->assertSame([$otherUserLesson->id], $otherUserLesson->user->lessons->modelKeys());
        foreach (['academicPeriod' => $batch->academicPeriod, 'subject' => $subject, 'teacher' => $teacher, 'scheduleImportBatch' => $batch] as $relation => $parent) {
            $this->assertTrue($lesson->$relation->is($parent));
            $this->assertSame([$lesson->id], $parent->lessons->modelKeys());
        }
        $this->assertTrue($lesson->user->is($user));
        $this->assertSame([$another->id], $another->subject->lessons->modelKeys());
    }

    #[DataProvider('foreignKeyTargets')]
    public function test_mysql_rejects_nonexistent_foreign_keys_on_insert_and_update(string $column, string $model): void
    {
        $missing = $model::factory()->create();
        $missing instanceof Lesson ? $missing->forceDelete() : $missing->delete();
        $lesson = Lesson::factory()->create();
        $values = $lesson->getAttributes();
        unset($values['id']);

        $this->assertMysqlRejects(fn () => DB::table('lessons')->insert([...$values, $column => $missing->id]), 1452, 'lessons_'.$column.'_foreign');
        $this->assertMysqlRejects(fn () => DB::table('lessons')->where('id', $lesson->id)->update([$column => $missing->id]), 1452, 'lessons_'.$column.'_foreign');
    }

    public static function foreignKeyTargets(): array
    {
        return [
            ['user_id', User::class], ['academic_period_id', AcademicPeriod::class], ['subject_id', Subject::class],
            ['teacher_id', Teacher::class], ['schedule_import_batch_id', ScheduleImportBatch::class], ['replaces_lesson_id', Lesson::class],
        ];
    }

    #[DataProvider('restrictedParents')]
    public function test_restrict_parents_remain_protected_even_after_lesson_soft_deletion(string $column, string $model, string $table): void
    {
        $parent = $model::factory()->create();
        $lesson = Lesson::factory()->for($parent->user)->create([$column => $parent->id]);

        $this->assertMysqlRejects(fn () => DB::table($table)->where('id', $parent->id)->delete(), 1451, 'lessons_'.$column.'_foreign');
        $lesson->delete();
        $this->assertMysqlRejects(fn () => DB::table($table)->where('id', $parent->id)->delete(), 1451, 'lessons_'.$column.'_foreign');
        $this->assertModelExists($parent);

        $lesson->forceDelete();
        $parent->delete();
        $this->assertModelMissing($parent);
    }

    public static function restrictedParents(): array
    {
        return [['subject_id', Subject::class, 'subjects'], ['academic_period_id', AcademicPeriod::class, 'academic_periods']];
    }

    public function test_deleting_teacher_clears_optional_reference_and_preserves_lesson(): void
    {
        $teacher = Teacher::factory()->create();
        $lesson = Lesson::factory()->for($teacher->user)->for($teacher)->create();
        $teacher->delete();

        $this->assertModelExists($lesson);
        $this->assertNull($lesson->refresh()->teacher_id);
        $this->assertNull($lesson->teacher);
    }

    public function test_deleting_import_batch_cascades_rows_but_preserves_lesson_and_fingerprint(): void
    {
        $row = ScheduleImportRow::factory()->create();
        $batch = $row->scheduleImportBatch;
        $lesson = Lesson::factory()->for($batch->user)->for($batch)->for($batch->academicPeriod)->create(['import_fingerprint' => self::EXAMPLE_FINGERPRINT]);
        $batch->delete();

        $this->assertModelMissing($row);
        $this->assertModelMissing($batch);
        $this->assertModelExists($lesson);
        $this->assertNull($lesson->refresh()->schedule_import_batch_id);
        $this->assertNull($lesson->scheduleImportBatch);
        $this->assertSame($batch->academic_period_id, $lesson->academic_period_id);
        $this->assertSame(self::EXAMPLE_FINGERPRINT, $lesson->import_fingerprint);
    }

    public function test_replacement_relationships_and_one_replacement_per_original(): void
    {
        $original = Lesson::factory()->create();
        $replacement = Lesson::factory()->for($original->user)->for($original->subject)->create(['replaces_lesson_id' => $original->id]);
        $this->assertTrue($replacement->replacedLesson->is($original));
        $this->assertTrue($original->replacement->is($replacement));
        // Persisting the reference does not implement the future replacement lifecycle workflow.
        $this->assertSame(LessonStatus::Active, $original->refresh()->status);

        $values = $replacement->getAttributes();
        unset($values['id']);
        $this->assertMysqlRejects(fn () => DB::table('lessons')->insert($values), 1062, 'lessons_replaces_lesson_id_unique');
        $unlinked = Lesson::factory()->for($original->user)->create();
        $this->assertNull($unlinked->replaces_lesson_id);
        $this->assertMysqlRejects(fn () => DB::table('lessons')->where('id', $unlinked->id)->update(['replaces_lesson_id' => $original->id]), 1062, 'lessons_replaces_lesson_id_unique');
    }

    public function test_original_soft_delete_keeps_reference_and_hard_delete_sets_it_null(): void
    {
        $original = Lesson::factory()->create();
        $replacement = Lesson::factory()->for($original->user)->for($original->subject)->create(['replaces_lesson_id' => $original->id]);
        $original->delete();
        $this->assertSame($original->id, $replacement->refresh()->replaces_lesson_id);
        $this->assertDatabaseHas('lessons', ['id' => $original->id]);

        DB::table('lessons')->where('id', $original->id)->delete();
        $this->assertModelExists($replacement);
        $this->assertNull($replacement->refresh()->replaces_lesson_id);
        $this->assertNull($replacement->replacedLesson);
    }

    #[DataProvider('crossOwnerTargets')]
    public function test_simple_foreign_keys_do_not_enforce_association_ownership(string $column, string $model): void
    {
        $parent = $model::factory()->create();
        $lesson = Lesson::factory()->create();
        $this->assertNotSame($parent->user_id, $lesson->user_id);

        // Invalid domain operation: future deterministic Services must enforce same-owner compatibility.
        DB::table('lessons')->where('id', $lesson->id)->update([$column => $parent->id]);
        $this->assertSame($parent->id, $lesson->refresh()->$column);
    }

    public static function crossOwnerTargets(): array
    {
        return [
            ['subject_id', Subject::class], ['teacher_id', Teacher::class], ['academic_period_id', AcademicPeriod::class],
            ['schedule_import_batch_id', ScheduleImportBatch::class], ['replaces_lesson_id', Lesson::class],
        ];
    }

    public function test_mysql_permits_self_reference_which_the_future_service_must_reject(): void
    {
        $lesson = Lesson::factory()->create();
        // Invalid domain state, demonstrated only to document the physical-schema limitation.
        // MySQL errors 3818 (AUTO_INCREMENT id) and 3823 (SET NULL FK) prevent the intended CHECK.
        DB::table('lessons')->where('id', $lesson->id)->update(['replaces_lesson_id' => $lesson->id]);

        $this->assertSame($lesson->id, $lesson->refresh()->replaces_lesson_id);
        $this->assertTrue($lesson->replacedLesson->is($lesson));
        $this->assertTrue($lesson->replacement->is($lesson));
    }

    public function test_null_fingerprints_and_replacement_references_can_repeat_for_manual_lessons(): void
    {
        $period = AcademicPeriod::factory()->create();
        $subject = Subject::factory()->for($period->user)->create();
        foreach ([null, $period->id] as $periodId) {
            $lessons = Lesson::factory()->count(2)->for($period->user)->for($subject)->create(['academic_period_id' => $periodId]);
            $this->assertCount(2, $lessons);
            foreach ($lessons as $lesson) {
                $this->assertNull($lesson->refresh()->import_fingerprint);
                $this->assertNull($lesson->replaces_lesson_id);
            }
        }
    }

    public function test_nonnull_fingerprint_requires_period_on_insert_and_update(): void
    {
        $lesson = Lesson::factory()->create();
        $values = $lesson->getAttributes();
        unset($values['id']);
        $this->assertMysqlRejects(fn () => DB::table('lessons')->insert([...$values, 'import_fingerprint' => self::EXAMPLE_FINGERPRINT]), 3819, 'lessons_import_requires_period');
        $this->assertMysqlRejects(fn () => DB::table('lessons')->where('id', $lesson->id)->update(['import_fingerprint' => self::EXAMPLE_FINGERPRINT]), 3819, 'lessons_import_requires_period');

        $period = AcademicPeriod::factory()->for($lesson->user)->create();
        $lesson->update(['academic_period_id' => $period->id, 'import_fingerprint' => self::EXAMPLE_FINGERPRINT]);
        $this->assertSame(self::EXAMPLE_FINGERPRINT, $lesson->refresh()->import_fingerprint);
        $this->assertMysqlRejects(fn () => DB::table('lessons')->where('id', $lesson->id)->update(['academic_period_id' => null]), 3819, 'lessons_import_requires_period');
    }

    public function test_import_fingerprint_uniqueness_is_scoped_to_user_and_period(): void
    {
        $period = AcademicPeriod::factory()->create();
        $lesson = Lesson::factory()->for($period->user)->for($period)->create(['import_fingerprint' => self::EXAMPLE_FINGERPRINT]);
        $values = $lesson->getAttributes();
        unset($values['id']);
        $this->assertMysqlRejects(fn () => DB::table('lessons')->insert($values), 1062, 'lessons_user_id_academic_period_id_import_fingerprint_unique');

        $anotherPeriod = AcademicPeriod::factory()->for($period->user)->create();
        $sameUser = Lesson::factory()->for($period->user)->for($lesson->subject)->for($anotherPeriod)->create(['import_fingerprint' => self::EXAMPLE_FINGERPRINT]);
        $otherPeriod = AcademicPeriod::factory()->create();
        $otherUser = Lesson::factory()->for($otherPeriod->user)->for($otherPeriod)->create(['import_fingerprint' => self::EXAMPLE_FINGERPRINT]);
        $this->assertSame($lesson->user_id, $sameUser->user_id);
        $this->assertNotSame($lesson->academic_period_id, $sameUser->academic_period_id);
        $this->assertNotSame($lesson->user_id, $otherUser->user_id);
        foreach ([$lesson, $sameUser, $otherUser] as $record) {
            $this->assertSame(self::EXAMPLE_FINGERPRINT, $record->refresh()->import_fingerprint);
        }
    }

    public function test_soft_delete_hides_lesson_but_keeps_physical_row_and_reserves_import_identity(): void
    {
        $period = AcademicPeriod::factory()->create();
        $lesson = Lesson::factory()->for($period->user)->for($period)->create(['import_fingerprint' => self::EXAMPLE_FINGERPRINT]);
        $values = $lesson->getAttributes();
        unset($values['id']);
        $lesson->delete();

        $this->assertSoftDeleted($lesson);
        $this->assertNull(Lesson::find($lesson->id));
        $this->assertTrue(Lesson::withTrashed()->find($lesson->id)->is($lesson));
        $this->assertInstanceOf(Carbon::class, $lesson->deleted_at);
        $this->assertDatabaseHas('lessons', ['id' => $lesson->id, 'import_fingerprint' => self::EXAMPLE_FINGERPRINT]);
        // Future import commit must restore/update this row instead of inserting its reserved identity.
        $this->assertMysqlRejects(fn () => DB::table('lessons')->insert($values), 1062, 'lessons_user_id_academic_period_id_import_fingerprint_unique');
    }

    public function test_user_cascade_removes_lessons_in_the_isolated_subject_graph(): void
    {
        $lesson = Lesson::factory()->create();
        $subject = $lesson->subject;
        $user = $lesson->user;
        $other = Lesson::factory()->create();
        DB::table('users')->where('id', $user->id)->delete();

        foreach ([$user, $subject, $lesson] as $record) {
            $this->assertModelMissing($record);
        }
        $this->assertModelExists($other);
    }

    public function test_period_and_import_history_require_ordered_account_hard_purge(): void
    {
        $row = ScheduleImportRow::factory()->create();
        $batch = $row->scheduleImportBatch;
        $period = $batch->academicPeriod;
        $user = $batch->user;
        $lesson = Lesson::factory()->for($user)->for($period)->for($batch)->create();
        $subject = $lesson->subject;

        // RESTRICT history paths can block a User CASCADE. This is administrative hard purge,
        // distinct from normal Lesson soft deletion; approved FK actions are retained.
        $this->assertMysqlRejects(fn () => DB::table('users')->where('id', $user->id)->delete(), 1451);
        foreach ([$user, $period, $batch, $row, $subject, $lesson] as $record) {
            $this->assertModelExists($record);
        }
        $lesson->forceDelete();
        $this->assertMysqlRejects(fn () => DB::table('users')->where('id', $user->id)->delete(), 1451, 'schedule_import_batches_academic_period_id_foreign');
        $batch->delete();
        DB::table('users')->where('id', $user->id)->delete();
        foreach ([$user, $period, $batch, $row, $subject, $lesson] as $record) {
            $this->assertModelMissing($record);
        }
    }

    #[DataProvider('approvedIndexes')]
    public function test_indexes_match_approved_columns_and_uniqueness(string $name, array $columns, bool $unique): void
    {
        $index = collect(Schema::getIndexes('lessons'))->firstWhere('name', $name);
        $this->assertNotNull($index);
        $this->assertSame($columns, $index['columns']);
        $this->assertSame($unique, $index['unique']);
    }

    public static function approvedIndexes(): array
    {
        return [
            ['lessons_user_id_status_starts_at_index', ['user_id', 'status', 'starts_at'], false],
            ['lessons_academic_period_id_starts_at_index', ['academic_period_id', 'starts_at'], false],
            ['lessons_replaces_lesson_id_unique', ['replaces_lesson_id'], true],
            ['lessons_user_id_academic_period_id_import_fingerprint_unique', ['user_id', 'academic_period_id', 'import_fingerprint'], true],
        ];
    }

    public function test_foreign_keys_match_approved_targets_and_delete_actions(): void
    {
        $keys = collect(Schema::getForeignKeys('lessons'))->keyBy('name');
        $this->assertCount(6, $keys);
        foreach ([
            'user_id' => ['users', 'cascade'], 'academic_period_id' => ['academic_periods', 'restrict'],
            'subject_id' => ['subjects', 'restrict'], 'teacher_id' => ['teachers', 'set null'],
            'schedule_import_batch_id' => ['schedule_import_batches', 'set null'], 'replaces_lesson_id' => ['lessons', 'set null'],
        ] as $column => [$target, $action]) {
            $key = $keys->get('lessons_'.$column.'_foreign');
            $this->assertNotNull($key);
            $this->assertSame([$column], $key['columns']);
            $this->assertSame($target, $key['foreign_table']);
            $this->assertSame(['id'], $key['foreign_columns']);
            $this->assertSame($action, $key['on_delete']);
        }
    }

    public function test_exactly_the_four_supported_named_checks_are_enforced(): void
    {
        $checks = DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS name, ENFORCED AS enforced
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lessons' AND CONSTRAINT_TYPE = 'CHECK'
            ORDER BY CONSTRAINT_NAME
            SQL);

        $this->assertSame([
            'lessons_import_requires_period' => 'YES', 'lessons_valid_interval' => 'YES',
            'lessons_valid_status' => 'YES', 'lessons_valid_type' => 'YES',
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
