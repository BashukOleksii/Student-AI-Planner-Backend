<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FreeSlotsApiTest extends TestCase
{
    use RefreshDatabase;

    private function url(array $parameters = []): string
    {
        return '/api/planning/free-slots?'.http_build_query(array_replace([
            'start_date' => '2026-10-12', 'end_date' => '2026-10-12',
        ], $parameters), '', '&', PHP_QUERY_RFC3986);
    }

    public function test_route_is_named_and_authentication_precedes_query_validation(): void
    {
        $this->assertSame('/api/planning/free-slots', route('planning.free-slots.index', [], false));
        $this->getJson($this->url())->assertUnauthorized();
        $this->getJson('/api/planning/free-slots')->assertUnauthorized();
        $this->getJson($this->url(['start_date' => ['bad'], 'min_slot_minutes' => '0']))->assertUnauthorized();
    }

    #[DataProvider('invalidDates')]
    public function test_individual_invalid_dates_return_standard_validation_errors_without_range_parsing(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'UTC']), 'web');
        $response = $this->getJson($this->url([$field => $value]));
        $response->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonStructure(['message', 'errors']);
        $this->assertSame([$field], array_keys($response->json('errors')));
        $this->assertDatabaseCount('planning_preferences', 0);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public static function invalidDates(): array
    {
        $values = [null, '', '2026-02-29', '2026-02-30', '2026-13-01', '0000-01-01', '2026-10-2',
            '2026-1-12', '12-10-2026', '2026-10-12T00:00:00Z', '2026-10-12 00:00:00',
            ' 2026-10-12', '2026-10-12 ', "2026-10-12\n", [], ['2026-10-12']];
        $cases = [];
        foreach (['start_date', 'end_date'] as $field) {
            foreach ($values as $index => $value) {
                $cases[$field.' '.$index] = [$field, $value];
            }
        }

        return $cases;
    }

    public function test_reversed_range_and_32_dates_fail_but_31_local_dates_across_dst_are_accepted(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $this->window($user, 7, '00:00:00', '24:00:00');
        $this->actingAs($user, 'web');
        $this->getJson($this->url(['start_date' => '2026-10-13']))->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->getJson($this->url(['start_date' => '2026-10-25', 'end_date' => '2026-11-25']))->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->assertPayload($this->getJson($this->url(['start_date' => '2026-10-25', 'end_date' => '2026-11-24'])), 'Europe/Kyiv', '2026-10-25', '2026-11-24', [
            $this->slot('2026-10-24T21:00:00Z', '2026-10-25T22:00:00Z', 90000),
            $this->slot('2026-10-31T22:00:00Z', '2026-11-01T22:00:00Z', 86400),
            $this->slot('2026-11-07T22:00:00Z', '2026-11-08T22:00:00Z', 86400),
            $this->slot('2026-11-14T22:00:00Z', '2026-11-15T22:00:00Z', 86400),
            $this->slot('2026-11-21T22:00:00Z', '2026-11-22T22:00:00Z', 86400),
        ]);
    }

    #[DataProvider('invalidMinimums')]
    public function test_noncanonical_or_out_of_range_minimum_query_values_are_rejected(mixed $minimum): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'UTC']), 'web');
        // http_build_query omits [], so encode an explicit empty query-array element.
        $url = $minimum === [] ? $this->url().'&min_slot_minutes[]=' : $this->url(['min_slot_minutes' => $minimum]);
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('min_slot_minutes');
        $this->assertDatabaseCount('study_sessions', 0);
        $this->assertDatabaseCount('planning_preferences', 0);
    }

    public static function invalidMinimums(): array
    {
        return [['0'], ['-1'], ['+1'], ['1.0'], ['1.5'], ['01'], ['00'], ['1e2'], ['1E2'],
            [' 1'], ['1 '], ["1\n"], ["\t1"], [''], [' '], ['65536'], ['999999999999999999999999999'],
            [[]], [['1']], ['null'], ['true'], ['١']];
    }

    #[DataProvider('minimumCases')]
    public function test_valid_minimum_minutes_are_converted_to_seconds_and_filter_inclusively(?string $minimum, string $start, string $end, array $expected): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->window($user, 1, $start, $end);
        $parameters = $minimum === null ? [] : ['min_slot_minutes' => $minimum];
        $this->actingAs($user, 'web');
        $this->assertPayload($this->getJson($this->url($parameters)), 'UTC', '2026-10-12', '2026-10-12', $expected);
    }

    public static function minimumCases(): array
    {
        return [
            'absent does not filter one second' => [null, '09:00:00', '09:00:01', [['starts_at' => '2026-10-12T09:00:00Z', 'ends_at' => '2026-10-12T09:00:01Z', 'duration_seconds' => 1]]],
            'one minute equal' => ['1', '09:00:00', '09:01:00', [['starts_at' => '2026-10-12T09:00:00Z', 'ends_at' => '2026-10-12T09:01:00Z', 'duration_seconds' => 60]]],
            'one minute minus a second' => ['1', '09:00:00', '09:00:59', []],
            'thirty minutes equal' => ['30', '09:00:00', '09:30:00', [['starts_at' => '2026-10-12T09:00:00Z', 'ends_at' => '2026-10-12T09:30:00Z', 'duration_seconds' => 1800]]],
            'thirty minutes minus a second' => ['30', '09:00:00', '09:29:59', []],
            // A full day exceeds 65535 seconds but not 65535 minutes: catches a missing * 60.
            'maximum accepted minimum' => ['65535', '00:00:00', '24:00:00', []],
        ];
    }

    #[DataProvider('emptyTimezones')]
    public function test_empty_availability_returns_exact_authenticated_metadata_without_creating_preferences(string $timezone): void
    {
        $user = User::factory()->create(['timezone' => $timezone]);
        $this->actingAs($user, 'web');
        $this->assertPayload($this->getJson($this->url()), $timezone, '2026-10-12', '2026-10-12', []);
        $this->assertDatabaseCount('planning_preferences', 0);
        $this->assertDatabaseCount('study_availability_windows', 0);
    }

    public static function emptyTimezones(): array
    {
        return [['UTC'], ['Europe/Kyiv']];
    }

    public function test_no_minimum_does_not_read_preference_defaults_or_apply_pf2_constraints(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->window($user, 1, '09:00:00', '09:00:01');
        PlanningPreference::factory()->for($user)->create([
            'min_session_minutes' => 60, 'max_session_minutes' => 120, 'preferred_break_minutes' => 30,
            'max_daily_study_minutes' => 1, 'max_weekly_study_minutes' => 1,
        ]);
        $this->actingAs($user, 'web');
        $this->assertPayload($this->getJson($this->url()), 'UTC', '2026-10-12', '2026-10-12', [$this->slot('2026-10-12T09:00:00Z', '2026-10-12T09:00:01Z', 1)]);
    }

    public function test_overlapping_effective_blockers_are_subtracted_and_multiple_slots_are_sorted(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $this->window($user, 1, '14:00:00', '16:00:00');
        $this->window($user);
        $this->lesson($user, '2026-10-12 06:30:00', '2026-10-12 07:30:00');
        $this->studySession($user, '2026-10-12 07:00:00', '2026-10-12 08:00:00');
        $this->actingAs($user, 'web');
        $this->assertPayload($this->getJson($this->url()), 'Europe/Kyiv', '2026-10-12', '2026-10-12', [
            $this->slot('2026-10-12T06:00:00Z', '2026-10-12T06:30:00Z', 1800),
            $this->slot('2026-10-12T08:00:00Z', '2026-10-12T09:00:00Z', 3600),
            $this->slot('2026-10-12T11:00:00Z', '2026-10-12T13:00:00Z', 7200),
        ]);
    }

    #[DataProvider('lessonStatuses')]
    public function test_lesson_lifecycle_and_soft_deletion_determine_effective_blocking(string $status, bool $deleted, bool $blocks): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->window($user);
        $lesson = $this->lesson($user, '2026-10-12 10:00:00', '2026-10-12 11:00:00', ['status' => $status]);
        if ($deleted) {
            $lesson->delete();
        }
        $before = $lesson->refresh()->getAttributes();
        $this->actingAs($user, 'web');
        $expected = $blocks ? [$this->slot('2026-10-12T09:00:00Z', '2026-10-12T10:00:00Z', 3600), $this->slot('2026-10-12T11:00:00Z', '2026-10-12T12:00:00Z', 3600)]
            : [$this->slot('2026-10-12T09:00:00Z', '2026-10-12T12:00:00Z', 10800)];
        $this->assertPayload($this->getJson($this->url()), 'UTC', '2026-10-12', '2026-10-12', $expected);
        $this->assertSame($before, $lesson->refresh()->getAttributes());
    }

    public static function lessonStatuses(): array
    {
        return [['active', false, true], ['cancelled', false, false], ['replaced', false, false], ['active', true, false]];
    }

    #[DataProvider('sessionStatuses')]
    public function test_only_planned_sessions_block_and_all_lifecycle_metadata_remains_unchanged(string $status, bool $blocks): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->window($user);
        $session = $this->studySession($user, '2026-10-12 10:00:00', '2026-10-12 11:00:00', [
            'status' => $status, 'completed_at' => $status === 'completed' ? '2026-10-12 11:00:00' : null,
            'actual_minutes' => $status === 'completed' ? 45 : null,
        ]);
        $before = $session->refresh()->getAttributes();
        $this->actingAs($user, 'web');
        $expected = $blocks ? [$this->slot('2026-10-12T09:00:00Z', '2026-10-12T10:00:00Z', 3600), $this->slot('2026-10-12T11:00:00Z', '2026-10-12T12:00:00Z', 3600)]
            : [$this->slot('2026-10-12T09:00:00Z', '2026-10-12T12:00:00Z', 10800)];
        $this->assertPayload($this->getJson($this->url()), 'UTC', '2026-10-12', '2026-10-12', $expected);
        $this->assertSame($before, $session->refresh()->getAttributes());
    }

    public static function sessionStatuses(): array
    {
        return [['planned', true], ['completed', false], ['missed', false], ['rescheduled', false], ['cancelled', false]];
    }

    public function test_second_level_duration_is_preserved_and_minimum_is_applied_after_subtraction(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->window($user, 1, '09:00:00', '10:00:00');
        $this->lesson($user, '2026-10-12 09:29:59', '2026-10-12 09:30:00');
        $this->actingAs($user, 'web');
        $last = $this->slot('2026-10-12T09:30:00Z', '2026-10-12T10:00:00Z', 1800);
        $this->assertPayload($this->getJson($this->url()), 'UTC', '2026-10-12', '2026-10-12', [$this->slot('2026-10-12T09:00:00Z', '2026-10-12T09:29:59Z', 1799), $last]);
        $this->assertPayload($this->getJson($this->url(['min_slot_minutes' => '30'])), 'UTC', '2026-10-12', '2026-10-12', [$last]);
    }

    #[DataProvider('dstCases')]
    public function test_dst_availability_and_metadata_are_serialized_as_exact_utc(string $date, string $start, string $end, bool $blockFirstFold, array $expected): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $this->window($user, 7, $start, $end);
        if ($blockFirstFold) {
            $this->lesson($user, '2026-10-25 00:15:00', '2026-10-25 00:45:00');
        }
        $this->actingAs($user, 'web');
        $this->assertPayload($this->getJson($this->url(['start_date' => $date, 'end_date' => $date])), 'Europe/Kyiv', $date, $date, $expected);
    }

    public static function dstCases(): array
    {
        return [
            'spring gap' => ['2026-03-29', '03:15:00', '03:45:00', false, []],
            'spring spanning window' => ['2026-03-29', '02:30:00', '04:30:00', false, [['starts_at' => '2026-03-29T00:30:00Z', 'ends_at' => '2026-03-29T01:30:00Z', 'duration_seconds' => 3600]]],
            '23-hour day' => ['2026-03-29', '00:00:00', '24:00:00', false, [['starts_at' => '2026-03-28T22:00:00Z', 'ends_at' => '2026-03-29T21:00:00Z', 'duration_seconds' => 82800]]],
            'fold both occurrences' => ['2026-10-25', '03:15:00', '03:45:00', false, [
                ['starts_at' => '2026-10-25T00:15:00Z', 'ends_at' => '2026-10-25T00:45:00Z', 'duration_seconds' => 1800],
                ['starts_at' => '2026-10-25T01:15:00Z', 'ends_at' => '2026-10-25T01:45:00Z', 'duration_seconds' => 1800],
            ]],
            'fold only second occurrence free' => ['2026-10-25', '03:15:00', '03:45:00', true, [['starts_at' => '2026-10-25T01:15:00Z', 'ends_at' => '2026-10-25T01:45:00Z', 'duration_seconds' => 1800]]],
            '25-hour day' => ['2026-10-25', '00:00:00', '24:00:00', false, [['starts_at' => '2026-10-24T21:00:00Z', 'ends_at' => '2026-10-25T22:00:00Z', 'duration_seconds' => 90000]]],
        ];
    }

    public function test_foreign_availability_and_blockers_and_spoofed_ownership_or_timezone_never_affect_results(): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $this->window($owner);
        $foreign = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $this->window($foreign, 1, '00:00:00', '24:00:00');
        $this->lesson($foreign, '2026-10-12 09:00:00', '2026-10-12 12:00:00');
        $this->studySession($foreign, '2026-10-12 09:00:00', '2026-10-12 12:00:00');
        $this->actingAs($owner, 'web');
        $this->assertPayload($this->getJson($this->url([
            'user_id' => $foreign->id, 'owner_id' => $foreign->id, 'timezone' => 'Europe/Kyiv',
            'min_session_minutes' => 65535, 'unrecognized' => ['private'],
        ])), 'UTC', '2026-10-12', '2026-10-12', [$this->slot('2026-10-12T09:00:00Z', '2026-10-12T12:00:00Z', 10800)]);
    }

    public function test_only_query_fields_are_validated_and_a_get_body_cannot_override_or_supply_them(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->window($user, 1, '09:00:00', '09:00:01');
        $this->actingAs($user, 'web');
        $body = ['start_date' => 'invalid', 'end_date' => 'invalid', 'min_slot_minutes' => '65535', 'timezone' => 'Europe/Kyiv'];
        $this->assertPayload($this->json('GET', $this->url(), $body), 'UTC', '2026-10-12', '2026-10-12', [$this->slot('2026-10-12T09:00:00Z', '2026-10-12T09:00:01Z', 1)]);
        $this->json('GET', '/api/planning/free-slots', ['start_date' => '2026-10-12', 'end_date' => '2026-10-12'])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
    }

    public function test_server_timezone_does_not_affect_serialization_or_calendar_validation_of_a_skipped_server_date(): void
    {
        $kyiv = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $this->window($kyiv, 7, '03:15:00', '03:45:00');
        $utc = User::factory()->create(['timezone' => 'UTC']);
        $this->window($utc, 5, '09:00:00', '10:00:00');
        $original = date_default_timezone_get();
        try {
            foreach (['Pacific/Apia', 'America/Los_Angeles', 'Asia/Kathmandu'] as $serverZone) {
                date_default_timezone_set($serverZone);
                Auth::forgetGuards();
                $this->actingAs($kyiv, 'web');
                $this->assertPayload($this->getJson($this->url(['start_date' => '2026-10-25', 'end_date' => '2026-10-25'])), 'Europe/Kyiv', '2026-10-25', '2026-10-25', [
                    $this->slot('2026-10-25T00:15:00Z', '2026-10-25T00:45:00Z', 1800), $this->slot('2026-10-25T01:15:00Z', '2026-10-25T01:45:00Z', 1800),
                ]);
                Auth::forgetGuards();
                $this->actingAs($utc, 'web');
                $this->assertPayload($this->getJson($this->url(['start_date' => '2011-12-30', 'end_date' => '2011-12-30'])), 'UTC', '2011-12-30', '2011-12-30', [$this->slot('2011-12-30T09:00:00Z', '2011-12-30T10:00:00Z', 3600)]);
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_repeated_gets_are_stable_read_only_and_preserve_all_database_tables(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->window($user);
        $this->lesson($user, '2026-10-12 10:00:00', '2026-10-12 11:00:00');
        $task = Task::factory()->for($user)->create();
        $child = Subtask::factory()->for($task)->create();
        $this->studySession($user, '2026-10-12 10:30:00', '2026-10-12 11:30:00', ['task_id' => $task->id, 'subtask_id' => $child->id]);
        Reminder::factory()->for($user)->create(['task_id' => $task->id]);
        $this->actingAs($user, 'web');
        $before = $this->snapshot();
        $level = DB::transactionLevel();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            for ($i = 0; $i < 3; $i++) {
                $this->assertPayload($this->getJson($this->url()), 'UTC', '2026-10-12', '2026-10-12', [
                    $this->slot('2026-10-12T09:00:00Z', '2026-10-12T10:00:00Z', 3600), $this->slot('2026-10-12T11:30:00Z', '2026-10-12T12:00:00Z', 1800),
                ]);
            }
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('for update', strtolower($query['query']));
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('planning_preferences', 0);
    }

    private function window(User $user, int $weekday = 1, string $start = '09:00:00', string $end = '12:00:00'): void
    {
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $weekday, 'starts_at' => $start, 'ends_at' => $end]);
    }

    private function lesson(User $user, string $start, string $end, array $extra = []): Lesson
    {
        return Lesson::factory()->for($user)->create(['starts_at' => $start, 'ends_at' => $end, ...$extra]);
    }

    private function studySession(User $user, string $start, string $end, array $extra = []): StudySession
    {
        $task = Task::factory()->for($user)->create();

        return StudySession::factory()->for($task)->create(['starts_at' => $start, 'ends_at' => $end, ...$extra]);
    }

    private function slot(string $start, string $end, int $seconds): array
    {
        return ['starts_at' => $start, 'ends_at' => $end, 'duration_seconds' => $seconds];
    }

    private function assertPayload(TestResponse $response, string $timezone, string $start, string $end, array $slots): void
    {
        $response->assertOk()->assertExactJson(['data' => $slots, 'meta' => ['timezone' => $timezone, 'start_date' => $start, 'end_date' => $end]]);
        $this->assertSame(['data', 'meta'], array_keys($response->json()));
        foreach ($response->json('data') as $slot) {
            $this->assertSame(['starts_at', 'ends_at', 'duration_seconds'], array_keys($slot));
            $this->assertIsInt($slot['duration_seconds']);
            $this->assertGreaterThan(0, $slot['duration_seconds']);
            $this->assertMatchesRegularExpression('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $slot['starts_at']);
            $this->assertMatchesRegularExpression('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $slot['ends_at']);
        }
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            $rows = DB::table($table)->get()->map(fn ($row): array => (array) $row)->all();
            usort($rows, fn ($a, $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            $result[$table] = $rows;
        }
        ksort($result);

        return $result;
    }
}
