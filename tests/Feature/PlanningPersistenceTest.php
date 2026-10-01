<?php

namespace Tests\Feature;

use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanningPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_timezone_has_the_approved_database_definition_and_default(): void
    {
        $column = DB::selectOne(<<<'SQL'
            SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS default_value
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'timezone'
            SQL);

        $this->assertNotNull($column);
        $this->assertSame('varchar(64)', $column->type);
        $this->assertSame('NO', $column->nullable);
        $this->assertSame('UTC', $column->default_value);
        $this->assertSame('UTC', User::factory()->create()->refresh()->timezone);
    }

    public function test_timezone_can_be_mass_assigned(): void
    {
        $user = User::create([
            'name' => 'Student',
            'email' => 'student@example.test',
            'password' => 'password',
            'timezone' => 'Europe/Kyiv',
        ]);

        $this->assertSame('Europe/Kyiv', $user->refresh()->timezone);
        $this->assertNull($user->planningPreference);
    }

    public function test_preferences_use_the_user_primary_key_and_both_relationships(): void
    {
        $preference = PlanningPreference::factory()->create();
        $user = $preference->user;

        $this->assertFalse(Schema::hasColumn('planning_preferences', 'id'));
        $this->assertFalse($preference->getIncrementing());
        $this->assertSame('user_id', $preference->getKeyName());
        $this->assertSame($user->id, $preference->getKey());
        $this->assertTrue($user->planningPreference->is($preference));
        $this->assertTrue($preference->user->is($user));

        $preference->update(['preferred_break_minutes' => 5]);
        $this->assertSame(5, $user->planningPreference()->first()->preferred_break_minutes);
    }

    public function test_preferences_allow_null_values_without_product_defaults(): void
    {
        $preference = PlanningPreference::factory()->create()->refresh();

        foreach (self::minuteFields() as $field) {
            $this->assertNull($preference->$field);
        }
    }

    public function test_preferences_accept_positive_values_and_equal_bounds(): void
    {
        $preference = PlanningPreference::factory()->create(array_fill_keys(self::minuteFields(), 1))->refresh();

        foreach (self::minuteFields() as $field) {
            $this->assertSame(1, $preference->$field);
        }
    }

    #[DataProvider('partiallyConfiguredBounds')]
    public function test_preferences_allow_either_bound_to_be_null(array $values): void
    {
        $preference = PlanningPreference::factory()->create($values)->refresh();

        foreach ($values as $field => $value) {
            $this->assertSame($value, $preference->$field);
        }
    }

    public static function partiallyConfiguredBounds(): array
    {
        return [
            'minimum only' => [['min_session_minutes' => 30]],
            'maximum only' => [['max_session_minutes' => 60]],
            'daily only' => [['max_daily_study_minutes' => 120]],
            'weekly only' => [['max_weekly_study_minutes' => 600]],
        ];
    }

    public function test_duplicate_preferences_are_rejected_by_the_primary_key(): void
    {
        $preference = PlanningPreference::factory()->create();

        $this->assertMysqlRejects(
            fn () => DB::table('planning_preferences')->insert(['user_id' => $preference->user_id]),
            1062,
        );
    }

    #[DataProvider('invalidMinutes')]
    public function test_mysql_rejects_nonpositive_preference_values(string $field, int $value, int $error): void
    {
        $user = User::factory()->create();

        $this->assertMysqlRejects(
            fn () => DB::table('planning_preferences')->insert(['user_id' => $user->id, $field => $value]),
            $error,
        );
    }

    public static function invalidMinutes(): array
    {
        $cases = [];
        foreach (self::minuteFields() as $field) {
            $cases[$field.' zero'] = [$field, 0, 3819];
            $cases[$field.' negative'] = [$field, -1, 1264];
        }

        return $cases;
    }

    private static function minuteFields(): array
    {
        return ['max_daily_study_minutes', 'max_weekly_study_minutes', 'preferred_break_minutes', 'min_session_minutes', 'max_session_minutes'];
    }

    #[DataProvider('reversedPreferenceBounds')]
    public function test_mysql_rejects_reversed_bounds_on_update(array $values, string $constraint): void
    {
        $preference = PlanningPreference::factory()->create();

        $this->assertMysqlRejects(
            fn () => DB::table('planning_preferences')->where('user_id', $preference->user_id)->update($values),
            3819,
            $constraint,
        );
    }

    public static function reversedPreferenceBounds(): array
    {
        return [
            'sessions' => [['min_session_minutes' => 60, 'max_session_minutes' => 30], 'planning_preferences_session_bounds'],
            'workload' => [['max_daily_study_minutes' => 120, 'max_weekly_study_minutes' => 60], 'planning_preferences_workload_bounds'],
        ];
    }

    public function test_deleting_a_user_cascades_to_both_planning_tables(): void
    {
        $user = User::factory()->create();
        PlanningPreference::factory()->for($user)->create();
        $window = StudyAvailabilityWindow::factory()->for($user)->create();

        $user->delete();

        $this->assertDatabaseMissing('planning_preferences', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('study_availability_windows', ['id' => $window->id]);
    }

    #[DataProvider('planningTables')]
    public function test_planning_records_require_an_existing_user(string $table, array $values): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->assertMysqlRejects(
            fn () => DB::table($table)->insert(['user_id' => $user->id, ...$values]),
            1452,
        );
    }

    public static function planningTables(): array
    {
        return [
            'preferences' => ['planning_preferences', []],
            'windows' => ['study_availability_windows', ['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '10:00:00']],
        ];
    }

    public function test_relationships_keep_two_users_planning_records_separate(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $firstPreference = PlanningPreference::factory()->for($first)->create();
        $secondPreference = PlanningPreference::factory()->for($second)->create();
        $firstWindow = StudyAvailabilityWindow::factory()->for($first)->create();
        $secondWindow = StudyAvailabilityWindow::factory()->for($second)->create();

        $this->assertTrue($first->planningPreference->is($firstPreference));
        $this->assertTrue($second->planningPreference->is($secondPreference));
        $this->assertSame([$firstWindow->id], $first->studyAvailabilityWindows->modelKeys());
        $this->assertSame([$secondWindow->id], $second->studyAvailabilityWindows->modelKeys());
        $this->assertTrue($firstWindow->user->is($first));
        $this->assertTrue($secondWindow->user->is($second));

        $first->delete();
        $this->assertModelExists($secondPreference);
        $this->assertModelExists($secondWindow);
    }

    #[DataProvider('validWeekdays')]
    public function test_windows_accept_iso_weekday_boundaries(int $day): void
    {
        $window = StudyAvailabilityWindow::factory()->create(['day_of_week' => $day])->refresh();

        $this->assertSame($day, $window->day_of_week);
        $this->assertSame('09:00:00', $window->starts_at);
        $this->assertSame('10:00:00', $window->ends_at);
    }

    public static function validWeekdays(): array
    {
        return ['Monday' => [1], 'Sunday' => [7]];
    }

    #[DataProvider('invalidWindows')]
    public function test_mysql_rejects_invalid_windows(array $values, string $constraint): void
    {
        $user = User::factory()->create();

        $this->assertMysqlRejects(
            fn () => DB::table('study_availability_windows')->insert([
                'user_id' => $user->id, 'day_of_week' => 1,
                'starts_at' => '09:00:00', 'ends_at' => '10:00:00', ...$values,
            ]),
            3819,
            $constraint,
        );
    }

    public static function invalidWindows(): array
    {
        return [
            'weekday zero' => [['day_of_week' => 0], 'study_availability_windows_iso_weekday'],
            'weekday eight' => [['day_of_week' => 8], 'study_availability_windows_iso_weekday'],
            'equal times' => [['ends_at' => '09:00:00'], 'study_availability_windows_valid_interval'],
            'reversed times' => [['ends_at' => '08:00:00'], 'study_availability_windows_valid_interval'],
            'overnight range' => [['starts_at' => '23:00:00', 'ends_at' => '01:00:00'], 'study_availability_windows_valid_interval'],
        ];
    }

    public function test_identical_windows_are_unique_only_within_the_same_user_and_day(): void
    {
        $window = StudyAvailabilityWindow::factory()->create();
        $values = $window->only(['user_id', 'day_of_week', 'starts_at', 'ends_at']);
        StudyAvailabilityWindow::create([...$values, 'day_of_week' => 2]);
        StudyAvailabilityWindow::create([...$values, 'user_id' => User::factory()->create()->id]);

        $this->assertMysqlRejects(fn () => DB::table('study_availability_windows')->insert($values), 1062);
    }

    public function test_nonidentical_overlapping_windows_are_allowed_by_the_database(): void
    {
        $window = StudyAvailabilityWindow::factory()->create();
        $overlapping = StudyAvailabilityWindow::factory()->create([
            'user_id' => $window->user_id, 'starts_at' => '09:30:00', 'ends_at' => '10:30:00',
        ]);

        $this->assertModelExists($overlapping);
    }

    public function test_window_indexes_match_the_approved_columns(): void
    {
        $indexes = collect(Schema::getIndexes('study_availability_windows'));

        $this->assertTrue($indexes->contains(fn (array $index) => $index['columns'] === ['user_id', 'day_of_week'] && ! $index['unique']));
        $this->assertTrue($indexes->contains(fn (array $index) => $index['columns'] === ['user_id', 'day_of_week', 'starts_at', 'ends_at'] && $index['unique']));
    }

    public function test_all_nine_named_checks_are_enforced(): void
    {
        $checks = DB::select(<<<'SQL'
            SELECT CONSTRAINT_NAME AS name, ENFORCED AS enforced
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'
                AND TABLE_NAME IN ('planning_preferences', 'study_availability_windows')
            SQL);

        $this->assertCount(9, $checks);
        foreach ($checks as $check) {
            $this->assertSame('YES', $check->enforced);
            $this->assertMatchesRegularExpression('/^(planning_preferences|study_availability_windows)_/', $check->name);
        }
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

        $this->fail('MySQL accepted a record that should violate a database constraint.');
    }
}
