<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\FreeTimeService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FreeTimeServiceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('emptyAvailabilityCases')]
    public function test_empty_expansion_skips_blocker_queries_and_does_not_materialize_preferences(string $scenario): void
    {
        $user = User::factory()->create(['timezone' => $scenario === 'skipped date' ? 'Pacific/Apia' : 'Europe/Kyiv']);
        $date = '2026-10-05';
        if ($scenario === 'nonmatching') {
            $this->window($user, 2);
        } elseif ($scenario === 'gap') {
            $date = '2026-03-29';
            $this->window($user, 7, '03:15:00', '03:45:00');
        } elseif ($scenario === 'skipped date') {
            $date = '2011-12-30';
            $this->window($user, 5, '00:00:00', '24:00:00');
        }
        Lesson::factory()->for($user)->create();
        $task = Task::factory()->for($user)->create();
        StudySession::factory()->for($task)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame([], app(FreeTimeService::class)->calculate($user, $date, $date));
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('from `study_availability_windows`', $queries[0]['query']);
        $this->assertStringStartsWith('select ', $queries[0]['query']);
        $this->assertDatabaseCount('planning_preferences', 0);
    }

    public static function emptyAvailabilityCases(): array
    {
        return [['missing'], ['nonmatching'], ['gap'], ['skipped date']];
    }

    #[DataProvider('preferenceCases')]
    public function test_preferences_never_supply_defaults_limits_or_breaks(string $preferences): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->window($user);
        if ($preferences !== 'missing') {
            PlanningPreference::factory()->for($user)->create($preferences === 'configured' ? [
                'min_session_minutes' => 60, 'max_session_minutes' => 120, 'preferred_break_minutes' => 30,
                'max_daily_study_minutes' => 1, 'max_weekly_study_minutes' => 1,
            ] : []);
        }
        $this->assertSame($this->utcIntervals([['2026-10-05T09:00:00Z', '2026-10-05T12:00:00Z']]),
            app(FreeTimeService::class)->calculate($user, '2026-10-05', '2026-10-05'));
        $this->assertDatabaseCount('planning_preferences', $preferences === 'missing' ? 0 : 1);
    }

    public static function preferenceCases(): array
    {
        return [['missing'], ['all null'], ['configured']];
    }

    #[DataProvider('availabilityCases')]
    public function test_matching_owned_windows_expand_and_normalize_across_inclusive_dates(array $windows, string $start, string $end, string $timezone, array $expected): void
    {
        $user = User::factory()->create(['timezone' => $timezone]);
        foreach ($windows as [$weekday, $from, $to]) {
            $this->window($user, $weekday, $from, $to);
        }
        $actual = app(FreeTimeService::class)->calculate($user, $start, $end);
        $this->assertSame($this->utcIntervals($expected), $actual);
        $this->assertNormalized($actual);
    }

    public static function availabilityCases(): array
    {
        return [
            'multiple windows sorted' => [[[1, '14:00:00', '16:00:00'], [1, '09:00:00', '12:00:00']], '2026-10-05', '2026-10-05', 'UTC', [
                ['2026-10-05T09:00:00Z', '2026-10-05T12:00:00Z'], ['2026-10-05T14:00:00Z', '2026-10-05T16:00:00Z'],
            ]],
            'multiple weekdays inclusive' => [[[2, '09:00:00', '12:00:00'], [1, '09:00:00', '12:00:00']], '2026-10-05', '2026-10-06', 'UTC', [
                ['2026-10-05T09:00:00Z', '2026-10-05T12:00:00Z'], ['2026-10-06T09:00:00Z', '2026-10-06T12:00:00Z'],
            ]],
            'adjacent windows' => [[[1, '12:00:00', '13:00:00'], [1, '09:00:00', '12:00:00']], '2026-10-05', '2026-10-05', 'UTC', [['2026-10-05T09:00:00Z', '2026-10-05T13:00:00Z']]],
            'Kyiv timezone' => [[[1, '09:00:00', '12:00:00']], '2026-10-05', '2026-10-05', 'Europe/Kyiv', [['2026-10-05T06:00:00Z', '2026-10-05T09:00:00Z']]],
            'non-hour timezone' => [[[1, '09:00:00', '12:00:00']], '2026-10-05', '2026-10-05', 'Asia/Kathmandu', [['2026-10-05T03:15:00Z', '2026-10-05T06:15:00Z']]],
        ];
    }

    #[DataProvider('lessonLifecycleCases')]
    public function test_only_non_deleted_active_lessons_block(string $status, bool $deleted, bool $blocks): void
    {
        $user = $this->availableUser();
        $lesson = $this->lesson($user, '2026-10-05 10:00:00', '2026-10-05 11:00:00', ['status' => $status]);
        if ($deleted) {
            $lesson->delete();
        }
        $before = $lesson->refresh()->getAttributes();
        $expected = $blocks ? [['2026-10-05T09:00:00Z', '2026-10-05T10:00:00Z'], ['2026-10-05T11:00:00Z', '2026-10-05T12:00:00Z']]
            : [['2026-10-05T09:00:00Z', '2026-10-05T12:00:00Z']];
        $this->assertSame($this->utcIntervals($expected), $this->calculate($user));
        $this->assertSame($before, $lesson->refresh()->getAttributes());
    }

    public static function lessonLifecycleCases(): array
    {
        return [['active', false, true], ['cancelled', false, false], ['replaced', false, false], ['active', true, false]];
    }

    public function test_effective_active_replacement_blocks_while_replaced_original_remains_ineffective(): void
    {
        $user = $this->availableUser();
        $original = $this->lesson($user, '2026-10-05 09:00:00', '2026-10-05 12:00:00', ['status' => LessonStatus::Replaced]);
        $replacement = $this->lesson($user, '2026-10-05 10:00:00', '2026-10-05 11:00:00', ['replaces_lesson_id' => $original->id]);
        $this->assertSame($this->utcIntervals([['2026-10-05T09:00:00Z', '2026-10-05T10:00:00Z'], ['2026-10-05T11:00:00Z', '2026-10-05T12:00:00Z']]), $this->calculate($user));
        $this->assertSame(LessonStatus::Replaced, $original->refresh()->status);
        $this->assertSame($original->id, $replacement->refresh()->replaces_lesson_id);
    }

    #[DataProvider('blockingIntervalCases')]
    public function test_lesson_and_session_overlap_queries_include_spanning_events_and_exclude_exact_adjacency(string $kind, string $start, string $end, array $expected): void
    {
        $user = $this->availableUser();
        $event = $this->event($user, $kind, $start, $end);
        $before = $event->refresh()->getAttributes();
        $this->assertSame($this->utcIntervals($expected), $this->calculate($user));
        $this->assertSame($before, $event->refresh()->getAttributes());
    }

    public static function blockingIntervalCases(): array
    {
        $cases = [
            'inside' => ['2026-10-05 10:00:00', '2026-10-05 11:00:00', [['2026-10-05T09:00:00Z', '2026-10-05T10:00:00Z'], ['2026-10-05T11:00:00Z', '2026-10-05T12:00:00Z']]],
            'cover' => ['2026-10-05 08:00:00', '2026-10-05 13:00:00', []],
            'begin before availability' => ['2026-10-05 08:00:00', '2026-10-05 10:00:00', [['2026-10-05T10:00:00Z', '2026-10-05T12:00:00Z']]],
            'end after availability' => ['2026-10-05 11:00:00', '2026-10-05 13:00:00', [['2026-10-05T09:00:00Z', '2026-10-05T11:00:00Z']]],
            'begin before requested date' => ['2026-10-04 23:00:00', '2026-10-05 10:00:00', [['2026-10-05T10:00:00Z', '2026-10-05T12:00:00Z']]],
            'end at left boundary' => ['2026-10-05 08:00:00', '2026-10-05 09:00:00', [['2026-10-05T09:00:00Z', '2026-10-05T12:00:00Z']]],
            'start at right boundary' => ['2026-10-05 12:00:00', '2026-10-05 13:00:00', [['2026-10-05T09:00:00Z', '2026-10-05T12:00:00Z']]],
        ];
        $result = [];
        foreach (['lesson', 'session'] as $kind) {
            foreach ($cases as $name => $case) {
                $result[$kind.' '.$name] = [$kind, ...$case];
            }
        }

        return $result;
    }

    #[DataProvider('sessionLifecycleCases')]
    public function test_only_planned_sessions_block_even_when_the_requested_date_is_historical(string $status, bool $blocks): void
    {
        $user = $this->availableUser();
        $task = Task::factory()->for($user)->create();
        $session = StudySession::factory()->for($task)->create([
            'starts_at' => '2026-10-05 10:00:00', 'ends_at' => '2026-10-05 11:00:00', 'status' => $status,
            'completed_at' => $status === 'completed' ? '2026-10-05 11:00:00' : null,
            'actual_minutes' => $status === 'completed' ? 45 : null,
        ]);
        $before = $session->refresh()->getAttributes();
        $this->travelTo(CarbonImmutable::parse('2027-01-01T12:00:00Z'));
        $expected = $blocks ? [['2026-10-05T09:00:00Z', '2026-10-05T10:00:00Z'], ['2026-10-05T11:00:00Z', '2026-10-05T12:00:00Z']]
            : [['2026-10-05T09:00:00Z', '2026-10-05T12:00:00Z']];
        $this->assertSame($this->utcIntervals($expected), $this->calculate($user));
        $this->assertSame($before, $session->refresh()->getAttributes());
    }

    public static function sessionLifecycleCases(): array
    {
        return [['planned', true], ['completed', false], ['missed', false], ['rescheduled', false], ['cancelled', false]];
    }

    #[DataProvider('combinedBlockerCases')]
    public function test_blocker_unions_preserve_exact_sorted_results_independent_of_insertion_order(array $windows, array $events, array $expected): void
    {
        foreach ([false, true] as $reverse) {
            $user = User::factory()->create(['timezone' => 'UTC']);
            foreach ($reverse ? array_reverse($windows) : $windows as [$weekday, $start, $end]) {
                $this->window($user, $weekday, $start, $end);
            }
            foreach ($reverse ? array_reverse($events) : $events as [$kind, $start, $end]) {
                $this->event($user, $kind, '2026-10-05 '.$start, '2026-10-05 '.$end);
            }
            $actual = $this->calculate($user);
            $this->assertSame($this->utcIntervals($expected), $actual);
            $this->assertNormalized($actual);
        }
    }

    public static function combinedBlockerCases(): array
    {
        $windows = [[1, '09:00:00', '12:00:00']];
        $overlap = [['2026-10-05T09:00:00Z', '2026-10-05T09:30:00Z'], ['2026-10-05T11:00:00Z', '2026-10-05T12:00:00Z']];

        return [
            'overlapping lessons' => [$windows, [['lesson', '09:30:00', '10:30:00'], ['lesson', '10:00:00', '11:00:00']], $overlap],
            'overlapping sessions' => [$windows, [['session', '09:30:00', '10:30:00'], ['session', '10:00:00', '11:00:00']], $overlap],
            'overlap and duplicate equivalent cross-table blockers' => [$windows, [['lesson', '09:30:00', '10:30:00'], ['session', '09:30:00', '10:30:00'], ['session', '10:00:00', '11:00:00']], $overlap],
            'adjacent blockers' => [$windows, [['session', '09:30:00', '10:00:00'], ['lesson', '10:00:00', '10:30:00']], [['2026-10-05T09:00:00Z', '2026-10-05T09:30:00Z'], ['2026-10-05T10:30:00Z', '2026-10-05T12:00:00Z']]],
            'one-second gap' => [$windows, [['lesson', '09:00:00', '10:00:00'], ['session', '10:00:01', '12:00:00']], [['2026-10-05T10:00:00Z', '2026-10-05T10:00:01Z']]],
            'complete union coverage' => [$windows, [['lesson', '09:00:00', '10:00:00'], ['session', '10:00:00', '12:00:00']], []],
            'blocker spanning separated windows' => [[[1, '09:00:00', '10:00:00'], [1, '11:00:00', '12:00:00']], [['session', '09:30:00', '11:30:00']], [['2026-10-05T09:00:00Z', '2026-10-05T09:30:00Z'], ['2026-10-05T11:30:00Z', '2026-10-05T12:00:00Z']]],
        ];
    }

    public function test_optional_minimum_filters_after_subtraction_with_inclusive_exact_second_boundaries(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->window($user, 1, '09:00:00', '09:02:00');
        $this->lesson($user, '2026-10-05 09:00:30', '2026-10-05 09:01:31');
        $both = [['2026-10-05T09:00:00Z', '2026-10-05T09:00:30Z'], ['2026-10-05T09:01:31Z', '2026-10-05T09:02:00Z']];
        $service = app(FreeTimeService::class);
        $this->assertSame($this->utcIntervals($both), $service->calculate($user, '2026-10-05', '2026-10-05'));
        $this->assertSame($this->utcIntervals($both), $service->calculate($user, '2026-10-05', '2026-10-05', 29));
        $this->assertSame($this->utcIntervals([$both[0]]), $service->calculate($user, '2026-10-05', '2026-10-05', 30));
        $this->assertSame([], $service->calculate($user, '2026-10-05', '2026-10-05', 31));
    }

    #[DataProvider('dstCases')]
    public function test_dst_and_midnight_date_membership_is_preserved_after_utc_subtraction(array $windows, string $start, string $end, array $events, array $expected, ?int $duration = null): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        foreach ($windows as [$weekday, $from, $to]) {
            $this->window($user, $weekday, $from, $to);
        }
        foreach ($events as [$kind, $from, $to]) {
            $this->event($user, $kind, $from, $to);
        }
        $actual = app(FreeTimeService::class)->calculate($user, $start, $end);
        $this->assertSame($this->utcIntervals($expected), $actual);
        $this->assertNormalized($actual);
        if ($duration !== null) {
            $this->assertSame($duration, array_sum(array_map(fn ($slot) => $slot['end'] - $slot['start'], $actual)));
        }
    }

    public static function dstCases(): array
    {
        return [
            'spring day 23 hours' => [[[7, '00:00:00', '24:00:00']], '2026-03-29', '2026-03-29', [], [['2026-03-28T22:00:00Z', '2026-03-29T21:00:00Z']], 82800],
            'autumn day 25 hours' => [[[7, '00:00:00', '24:00:00']], '2026-10-25', '2026-10-25', [], [['2026-10-24T21:00:00Z', '2026-10-25T22:00:00Z']], 90000],
            'spring transition plus reservation' => [[[7, '02:30:00', '04:30:00']], '2026-03-29', '2026-03-29', [['session', '2026-03-29 01:00:00', '2026-03-29 01:15:00']], [['2026-03-29T00:30:00Z', '2026-03-29T01:00:00Z'], ['2026-03-29T01:15:00Z', '2026-03-29T01:30:00Z']]],
            'one fold occurrence blocked by lesson' => [[[7, '03:15:00', '03:45:00']], '2026-10-25', '2026-10-25', [['lesson', '2026-10-25 00:15:00', '2026-10-25 00:45:00']], [['2026-10-25T01:15:00Z', '2026-10-25T01:45:00Z']]],
            'part of second fold occurrence blocked by session' => [[[7, '03:15:00', '03:45:00']], '2026-10-25', '2026-10-25', [['session', '2026-10-25 01:30:00', '2026-10-25 01:35:00']], [
                ['2026-10-25T00:15:00Z', '2026-10-25T00:45:00Z'], ['2026-10-25T01:15:00Z', '2026-10-25T01:30:00Z'], ['2026-10-25T01:35:00Z', '2026-10-25T01:45:00Z'],
            ]],
            'explicit overnight windows and spanning event' => [[[7, '23:00:00', '24:00:00'], [1, '00:00:00', '02:00:00']], '2026-10-04', '2026-10-05', [['lesson', '2026-10-04 20:30:00', '2026-10-04 21:30:00']], [['2026-10-04T20:00:00Z', '2026-10-04T20:30:00Z'], ['2026-10-04T21:30:00Z', '2026-10-04T23:00:00Z']]],
        ];
    }

    public function test_only_passed_owner_relationships_are_used_regardless_of_authentication_or_cached_foreign_collections(): void
    {
        $owner = $this->availableUser();
        $foreign = User::factory()->create(['timezone' => 'Asia/Kathmandu']);
        $this->window($foreign, 1, '00:00:00', '24:00:00');
        $this->event($foreign, 'lesson', '2026-10-05 09:00:00', '2026-10-05 12:00:00');
        $this->event($foreign, 'session', '2026-10-05 09:00:00', '2026-10-05 12:00:00');
        $owner->setRelation('studyAvailabilityWindows', $foreign->studyAvailabilityWindows()->get());
        $owner->setRelation('lessons', $foreign->lessons()->get());
        $owner->setRelation('studySessions', $foreign->studySessions()->get());
        $this->actingAs($foreign, 'web');
        $this->assertSame($this->utcIntervals([['2026-10-05T09:00:00Z', '2026-10-05T12:00:00Z']]), $this->calculate($owner));
    }

    public function test_raw_utc_database_values_are_independent_of_server_timezone_and_model_presentation_casts(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $this->window($user, 1, '08:00:00', '10:00:00');
        $this->lesson($user, '2026-10-05 05:15:00', '2026-10-05 05:45:00');
        $this->event($user, 'session', '2026-10-05 06:00:00', '2026-10-05 06:30:00');
        $expected = $this->utcIntervals([
            ['2026-10-05T05:00:00Z', '2026-10-05T05:15:00Z'], ['2026-10-05T05:45:00Z', '2026-10-05T06:00:00Z'], ['2026-10-05T06:30:00Z', '2026-10-05T07:00:00Z'],
        ]);
        $original = date_default_timezone_get();
        try {
            foreach (['Pacific/Apia', 'America/Los_Angeles', 'Asia/Kathmandu'] as $timezone) {
                date_default_timezone_set($timezone);
                $this->assertSame($expected, $this->calculate($user));
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_repeated_calculation_executes_only_scoped_selects_and_preserves_every_database_table(): void
    {
        $user = $this->availableUser();
        PlanningPreference::factory()->for($user)->create();
        $this->lesson($user, '2026-10-05 10:00:00', '2026-10-05 11:00:00');
        $task = Task::factory()->for($user)->create();
        $child = Subtask::factory()->for($task)->create();
        StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-10-05 10:30:00', 'ends_at' => '2026-10-05 11:30:00']);
        Reminder::factory()->for($user)->create(['task_id' => $task->id]);
        $before = $this->snapshot();
        $level = DB::transactionLevel();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            for ($i = 0; $i < 3; $i++) {
                $this->assertSame($this->utcIntervals([['2026-10-05T09:00:00Z', '2026-10-05T10:00:00Z'], ['2026-10-05T11:30:00Z', '2026-10-05T12:00:00Z']]), $this->calculate($user));
            }
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(9, $queries);
        foreach ($queries as $index => $query) {
            $table = ['study_availability_windows', 'lessons', 'study_sessions'][$index % 3];
            $this->assertStringStartsWith('select ', $query['query']);
            $this->assertStringContainsString('from `'.$table.'`', $query['query']);
            $this->assertStringContainsString('`user_id` = ?', $query['query']);
            $this->assertSame($user->id, $query['bindings'][0]);
            $this->assertStringNotContainsString('for update', $query['query']);
            if ($table !== 'study_availability_windows') {
                $this->assertStringContainsString('`starts_at` < ?', $query['query']);
                $this->assertStringContainsString('`ends_at` > ?', $query['query']);
            }
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('invalidDateRanges')]
    public function test_invalid_range_fails_before_any_database_reads_or_writes(string $start, string $end): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(FreeTimeService::class)->calculate($user, $start, $end);
            $this->fail('Invalid range must fail explicitly.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public static function invalidDateRanges(): array
    {
        return [
            ['2026-10-06', '2026-10-05'], ['2026-02-29', '2026-03-01'], ['2026-10-05', '2026-10-32'],
            ['2026-10-5', '2026-10-05'], [' 2026-10-05', '2026-10-05'], ['2026-10-05', "2026-10-05\n"],
            ['', '2026-10-05'], ['2026-10-05', 'not-a-date'], ['0000-01-01', '0000-01-01'],
            ['2026-10-05', '2026-11-05'],
        ];
    }

    #[DataProvider('invalidMinimums')]
    public function test_invalid_minimum_fails_explicitly_even_without_availability(mixed $minimum): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(FreeTimeService::class)->calculate($user, '2026-10-05', '2026-10-05', $minimum);
            $this->fail('Invalid minimum must fail explicitly.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public static function invalidMinimums(): array
    {
        return [[0], [-1], ['60'], [60.0], [true], [false], [[]]];
    }

    public function test_31_inclusive_calendar_dates_are_allowed_and_the_32nd_is_rejected(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $this->window($user, 7, '00:00:00', '24:00:00');
        $expected = [
            ['2026-10-24T21:00:00Z', '2026-10-25T22:00:00Z'], ['2026-10-31T22:00:00Z', '2026-11-01T22:00:00Z'],
            ['2026-11-07T22:00:00Z', '2026-11-08T22:00:00Z'], ['2026-11-14T22:00:00Z', '2026-11-15T22:00:00Z'],
            ['2026-11-21T22:00:00Z', '2026-11-22T22:00:00Z'],
        ];
        // This 31-date range contains more than 31 * 86400 real seconds due to the fold.
        $this->assertSame($this->utcIntervals($expected), app(FreeTimeService::class)->calculate($user, '2026-10-25', '2026-11-24'));
        $this->expectException(InvalidArgumentException::class);
        app(FreeTimeService::class)->calculate($user, '2026-10-25', '2026-11-25');
    }

    public function test_invalid_persisted_timezone_is_rejected_without_fallback_even_without_windows(): void
    {
        $user = User::factory()->create(['timezone' => 'Invalid/Zone']);
        $this->expectException(InvalidArgumentException::class);
        $this->calculate($user);
    }

    private function calculate(User $user): array
    {
        return app(FreeTimeService::class)->calculate($user, '2026-10-05', '2026-10-05');
    }

    private function availableUser(): User
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->window($user);

        return $user;
    }

    private function window(User $user, int $weekday = 1, string $start = '09:00:00', string $end = '12:00:00'): void
    {
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $weekday, 'starts_at' => $start, 'ends_at' => $end]);
    }

    private function lesson(User $user, string $start, string $end, array $values = []): Lesson
    {
        return Lesson::factory()->for($user)->create(array_replace(['starts_at' => $start, 'ends_at' => $end], $values));
    }

    private function event(User $user, string $kind, string $start, string $end): Lesson|StudySession
    {
        if ($kind === 'lesson') {
            return $this->lesson($user, $start, $end);
        }
        $task = Task::factory()->for($user)->create();

        return StudySession::factory()->for($task)->create(['starts_at' => $start, 'ends_at' => $end]);
    }

    private function utcIntervals(array $pairs): array
    {
        return array_map(fn (array $pair): array => ['start' => (new DateTimeImmutable($pair[0]))->getTimestamp(), 'end' => (new DateTimeImmutable($pair[1]))->getTimestamp()], $pairs);
    }

    private function assertNormalized(array $intervals): void
    {
        $this->assertTrue(array_is_list($intervals));
        $previousEnd = null;
        foreach ($intervals as $interval) {
            $this->assertIsInt($interval['start']);
            $this->assertIsInt($interval['end']);
            $this->assertLessThan($interval['end'], $interval['start']);
            if ($previousEnd !== null) {
                $this->assertGreaterThan($previousEnd, $interval['start']);
            }
            $previousEnd = $interval['end'];
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
