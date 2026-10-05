<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AcademicContextPersistenceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('academicModels')]
    public function test_user_relationships_and_cascade_deletes_are_scoped_to_the_owner(string $model, string $relationship): void
    {
        $first = $model::factory()->create();
        $second = $model::factory()->create();
        $firstUser = $first->user;
        $secondUser = $second->user;

        $this->assertNotSame($firstUser->id, $secondUser->id);
        $this->assertSame([$first->id], $firstUser->$relationship->modelKeys());
        $this->assertSame([$second->id], $secondUser->$relationship->modelKeys());
        $this->assertTrue($first->user->is($firstUser));
        $this->assertTrue($second->user->is($secondUser));

        DB::table('users')->where('id', $firstUser->id)->delete();

        $this->assertModelMissing($first);
        $this->assertModelExists($second);
    }

    public static function academicModels(): array
    {
        return [
            'institution' => [EducationInstitution::class, 'educationInstitutions'],
            ...self::institutionChildren(),
        ];
    }

    public static function institutionChildren(): array
    {
        return [
            'period' => [AcademicPeriod::class, 'academicPeriods'],
            'subject' => [Subject::class, 'subjects'],
            'teacher' => [Teacher::class, 'teachers'],
        ];
    }

    #[DataProvider('institutionChildren')]
    public function test_optional_institution_relationships_and_set_null_delete(string $model, string $relationship): void
    {
        $institution = EducationInstitution::factory()->create();
        $otherInstitution = EducationInstitution::factory()->for($institution->user)->create();
        $record = $model::factory()->for($institution->user)->for($institution)->create();
        $other = $model::factory()->for($institution->user)->for($otherInstitution)->create();
        $unlinked = $model::factory()->for($institution->user)->create();

        $this->assertSame([$record->id], $institution->$relationship->modelKeys());
        $this->assertSame([$other->id], $otherInstitution->$relationship->modelKeys());
        $this->assertTrue($record->educationInstitution->is($institution));
        $this->assertSame($institution->user_id, $record->user_id);
        $this->assertNull($unlinked->refresh()->education_institution_id);
        $this->assertNull($unlinked->educationInstitution);

        DB::table('education_institutions')->where('id', $institution->id)->delete();

        $this->assertModelExists($record);
        $this->assertNull($record->refresh()->education_institution_id);
        $this->assertNull($record->educationInstitution);
        $this->assertSame($otherInstitution->id, $other->refresh()->education_institution_id);
        $this->assertModelExists($institution->user);
    }

    public function test_user_deletion_cascades_through_linked_academic_records(): void
    {
        $institution = EducationInstitution::factory()->create();
        $records = [];
        foreach (self::institutionChildren() as [$model]) {
            $records[] = $model::factory()->for($institution->user)->for($institution)->create();
        }

        DB::table('users')->where('id', $institution->user_id)->delete();

        $this->assertModelMissing($institution);
        foreach ($records as $record) {
            $this->assertModelMissing($record);
        }
    }

    #[DataProvider('uniqueModels')]
    public function test_approved_unique_keys_are_scoped_to_one_user(string $model, array $values, string $constraint): void
    {
        $record = $model::factory()->create($values);
        $anotherOwner = $model::factory()->create($values);

        $this->assertNotSame($record->user_id, $anotherOwner->user_id);
        $this->assertModelExists($anotherOwner);
        $this->assertMysqlRejects(
            fn () => DB::table($record->getTable())->insert(['user_id' => $record->user_id, ...$values]),
            1062,
            $constraint,
        );
    }

    public static function uniqueModels(): array
    {
        return [
            'institution' => [EducationInstitution::class, ['name' => 'Test institution'], 'education_institutions_user_id_name_unique'],
            'period' => [AcademicPeriod::class, ['name' => 'Test semester', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31'], 'academic_periods_user_id_name_starts_on_unique'],
            'subject' => [Subject::class, ['name' => 'Test subject'], 'subjects_user_id_name_unique'],
        ];
    }

    public function test_period_name_can_be_reused_by_the_same_user_with_a_different_start_date(): void
    {
        $period = AcademicPeriod::factory()->create();
        $next = AcademicPeriod::factory()->for($period->user)->create([
            'name' => $period->name, 'starts_on' => '2027-09-01', 'ends_on' => '2027-12-31',
        ]);

        $this->assertModelExists($next);
    }

    #[DataProvider('validDateRanges')]
    public function test_periods_accept_ordered_and_equal_dates_and_cast_them(string $start, string $end): void
    {
        $period = AcademicPeriod::factory()->create(['starts_on' => $start, 'ends_on' => $end])->refresh();

        $this->assertInstanceOf(Carbon::class, $period->starts_on);
        $this->assertInstanceOf(Carbon::class, $period->ends_on);
        $this->assertSame($start, $period->starts_on->toDateString());
        $this->assertSame($end, $period->ends_on->toDateString());
    }

    public static function validDateRanges(): array
    {
        return [
            'ordered' => ['2026-09-01', '2026-12-31'],
            'equal' => ['2026-09-01', '2026-09-01'],
        ];
    }

    public function test_mysql_rejects_reversed_period_dates_on_insert_and_update(): void
    {
        $period = AcademicPeriod::factory()->create();

        $this->assertMysqlRejects(
            fn () => DB::table('academic_periods')->insert([
                'user_id' => $period->user_id, 'name' => 'Invalid period',
                'starts_on' => '2026-12-31', 'ends_on' => '2026-09-01',
            ]),
            3819,
            'academic_periods_valid_date_range',
        );
        $this->assertMysqlRejects(
            fn () => DB::table('academic_periods')->where('id', $period->id)->update(['ends_on' => '2026-08-31']),
            3819,
            'academic_periods_valid_date_range',
        );
    }

    #[DataProvider('validColors')]
    public function test_subjects_accept_hex_colors_and_null(?string $color): void
    {
        $subject = Subject::factory()->create(['color' => $color, 'code' => 'TEST-101'])->refresh();

        $this->assertSame($color, $subject->color);
        $this->assertSame('TEST-101', $subject->code);
    }

    public static function validColors(): array
    {
        return ['uppercase' => ['#A1B2C3'], 'lowercase' => ['#a1b2c3'], 'mixed' => ['#a1B2c3'], 'null' => [null]];
    }

    #[DataProvider('invalidColors')]
    public function test_mysql_rejects_malformed_colors_on_insert_and_update(string $color): void
    {
        $subject = Subject::factory()->create();

        $this->assertMysqlRejects(
            fn () => DB::table('subjects')->insert(['user_id' => $subject->user_id, 'name' => 'Invalid color', 'color' => $color]),
            3819,
            'subjects_valid_color',
        );
        $this->assertMysqlRejects(
            fn () => DB::table('subjects')->where('id', $subject->id)->update(['color' => $color]),
            3819,
            'subjects_valid_color',
        );
    }

    public static function invalidColors(): array
    {
        return [
            'empty' => [''],
            'missing hash' => ['A1B2C3'],
            'short hex' => ['#ABC'],
            'nonhex' => ['#G1B2C3'],
            'wrong prefix' => ['!A1B2C3'],
            'embedded space' => ['#A1 B2C'],
        ];
    }

    public function test_duplicate_teacher_names_are_allowed_for_the_same_user(): void
    {
        $teacher = Teacher::factory()->create();
        $duplicate = Teacher::factory()->for($teacher->user)->create(['name' => $teacher->name]);

        $this->assertNotSame($teacher->id, $duplicate->id);
        $this->assertSame(2, $teacher->user->teachers()->where('name', $teacher->name)->count());
    }

    #[DataProvider('academicModelClasses')]
    public function test_nonexistent_users_are_rejected_by_mysql(string $model): void
    {
        $record = $model::factory()->create();
        $values = $record->getAttributes();
        unset($values['id']);
        $record->user->delete();

        $this->assertMysqlRejects(
            fn () => DB::table($record->getTable())->insert($values),
            1452,
            $record->getTable().'_user_id_foreign',
        );
    }

    #[DataProvider('institutionChildClasses')]
    public function test_nonexistent_institutions_are_rejected_by_mysql(string $model): void
    {
        $institution = EducationInstitution::factory()->create();
        $record = $model::factory()->for($institution->user)->make();
        $institution->delete();

        $this->assertMysqlRejects(
            fn () => DB::table($record->getTable())->insert([
                ...$record->getAttributes(), 'education_institution_id' => $institution->id,
            ]),
            1452,
            $record->getTable().'_education_institution_id_foreign',
        );
    }

    #[DataProvider('institutionChildClasses')]
    public function test_simple_institution_fk_does_not_enforce_same_owner(string $model): void
    {
        $institution = EducationInstitution::factory()->create();
        // BR-GEN-001 requires same-owner validation in the future application layer.
        // The approved simple FK checks existence only; this is not a valid domain operation.
        $record = $model::factory()->for($institution)->create();

        $this->assertNotSame($institution->user_id, $record->user_id);
        $this->assertModelExists($record);
    }

    #[DataProvider('tableDefinitions')]
    public function test_columns_match_the_approved_types_and_nullability(string $table, array $expected): void
    {
        $columns = collect(Schema::getColumns($table));

        $this->assertSame($expected, $columns->mapWithKeys(fn (array $column) => [
            $column['name'] => [$column['type'], $column['nullable']],
        ])->all());
        $this->assertTrue($columns->firstWhere('name', 'id')['auto_increment']);
        $this->assertTrue(collect(Schema::getIndexes($table))->contains(
            fn (array $index) => $index['primary'] && $index['columns'] === ['id'],
        ));
    }

    public static function tableDefinitions(): array
    {
        $identity = ['id' => ['bigint unsigned', false], 'user_id' => ['bigint unsigned', false]];
        $institution = ['education_institution_id' => ['bigint unsigned', true]];
        $name = ['name' => ['varchar(255)', false]];
        $timestamps = ['created_at' => ['timestamp', true], 'updated_at' => ['timestamp', true]];

        return [
            'institutions' => ['education_institutions', [...$identity, ...$name, ...$timestamps]],
            'periods' => ['academic_periods', [...$identity, ...$institution, ...$name, 'starts_on' => ['date', false], 'ends_on' => ['date', false], ...$timestamps]],
            'subjects' => ['subjects', [...$identity, ...$institution, ...$name, 'code' => ['varchar(50)', true], 'color' => ['char(7)', true], ...$timestamps]],
            'teachers' => ['teachers', [...$identity, ...$institution, ...$name, ...$timestamps]],
        ];
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
            'institution names' => ['education_institutions', 'education_institutions_user_id_name_unique', ['user_id', 'name'], true],
            'period dates' => ['academic_periods', 'academic_periods_user_id_starts_on_ends_on_index', ['user_id', 'starts_on', 'ends_on'], false],
            'period names' => ['academic_periods', 'academic_periods_user_id_name_starts_on_unique', ['user_id', 'name', 'starts_on'], true],
            'subject names' => ['subjects', 'subjects_user_id_name_unique', ['user_id', 'name'], true],
            'teacher names' => ['teachers', 'teachers_user_id_name_index', ['user_id', 'name'], false],
        ];
    }

    #[DataProvider('academicModelClasses')]
    public function test_foreign_keys_match_approved_targets_and_delete_actions(string $model): void
    {
        $table = (new $model)->getTable();
        $keys = collect(Schema::getForeignKeys($table))->keyBy('name');
        $expected = ['user_id' => ['users', 'cascade']];
        if ($model !== EducationInstitution::class) {
            $expected['education_institution_id'] = ['education_institutions', 'set null'];
        }

        $this->assertCount(count($expected), $keys);
        foreach ($expected as $column => [$target, $action]) {
            $key = $keys->get($table.'_'.$column.'_foreign');
            $this->assertNotNull($key);
            $this->assertSame([$column], $key['columns']);
            $this->assertSame($target, $key['foreign_table']);
            $this->assertSame(['id'], $key['foreign_columns']);
            $this->assertSame($action, $key['on_delete']);
        }
    }

    public static function academicModelClasses(): array
    {
        return array_map(fn (array $case) => [$case[0]], self::academicModels());
    }

    public static function institutionChildClasses(): array
    {
        return array_map(fn (array $case) => [$case[0]], self::institutionChildren());
    }

    public function test_both_named_checks_are_enforced(): void
    {
        $checks = DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS name, ENFORCED AS enforced
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'
                AND TABLE_NAME IN ('education_institutions', 'academic_periods', 'subjects', 'teachers')
            ORDER BY CONSTRAINT_NAME
            SQL);

        $this->assertSame([
            'academic_periods_valid_date_range' => 'YES',
            'subjects_valid_color' => 'YES',
        ], collect($checks)->pluck('enforced', 'name')->all());
    }

    private function assertMysqlRejects(callable $operation, int $error, string $constraint): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertSame($error, $exception->errorInfo[1]);
            $this->assertStringContainsString($constraint, $exception->getMessage());

            return;
        }

        $this->fail('MySQL accepted a record that should violate a database constraint.');
    }
}
