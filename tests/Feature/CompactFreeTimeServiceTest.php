<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\FreeTimeService;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('integration')]
#[Group('mysql')]
class CompactFreeTimeServiceTest extends TestCase
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
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('from `users`', $queries[0]['query']);
        $this->assertStringContainsString('from `study_availability_windows`', $queries[1]['query']);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
        }
        $this->assertDatabaseCount('planning_preferences', 0);
    }

    public static function emptyAvailabilityCases(): array
    {
        return [0 => ['missing']];
    }

    #[DataProvider('preferenceCases')]
    public function test_preferences_never_supply_defaults_limits_or_breaks(string $preferences): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->window($user);
        if ($preferences !== 'missing') {
            PlanningPreference::factory()->for($user)->create($preferences === 'configured' ? ['min_session_minutes' => 60, 'max_session_minutes' => 120, 'preferred_break_minutes' => 30, 'max_daily_study_minutes' => 1, 'max_weekly_study_minutes' => 1] : []);
        }
        $this->assertSame($this->utcIntervals([['2026-10-05T09:00:00Z', '2026-10-05T12:00:00Z']]), app(FreeTimeService::class)->calculate($user, '2026-10-05', '2026-10-05'));
        $this->assertDatabaseCount('planning_preferences', $preferences === 'missing' ? 0 : 1);
    }

    public static function preferenceCases(): array
    {
        return [0 => ['missing']];
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
        return ['multiple windows sorted' => [[0 => [0 => 1, 1 => '14:00:00', 2 => '16:00:00'], 1 => [0 => 1, 1 => '09:00:00', 2 => '12:00:00']], '2026-10-05', '2026-10-05', 'UTC', [0 => [0 => '2026-10-05T09:00:00Z', 1 => '2026-10-05T12:00:00Z'], 1 => [0 => '2026-10-05T14:00:00Z', 1 => '2026-10-05T16:00:00Z']]]];
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
        $expected = $blocks ? [['2026-10-05T09:00:00Z', '2026-10-05T10:00:00Z'], ['2026-10-05T11:00:00Z', '2026-10-05T12:00:00Z']] : [['2026-10-05T09:00:00Z', '2026-10-05T12:00:00Z']];
        $this->assertSame($this->utcIntervals($expected), $this->calculate($user));
        $this->assertSame($before, $lesson->refresh()->getAttributes());
    }

    public static function lessonLifecycleCases(): array
    {
        return [0 => ['active', false, true]];
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
        return ['lesson inside' => ['lesson', '2026-10-05 10:00:00', '2026-10-05 11:00:00', [0 => [0 => '2026-10-05T09:00:00Z', 1 => '2026-10-05T10:00:00Z'], 1 => [0 => '2026-10-05T11:00:00Z', 1 => '2026-10-05T12:00:00Z']]]];
    }

    #[DataProvider('sessionLifecycleCases')]
    public function test_only_planned_sessions_block_even_when_the_requested_date_is_historical(string $status, bool $blocks): void
    {
        $user = $this->availableUser();
        $task = Task::factory()->for($user)->create();
        $session = StudySession::factory()->for($task)->create(['starts_at' => '2026-10-05 10:00:00', 'ends_at' => '2026-10-05 11:00:00', 'status' => $status, 'completed_at' => $status === 'completed' ? '2026-10-05 11:00:00' : null, 'actual_minutes' => $status === 'completed' ? 45 : null]);
        $before = $session->refresh()->getAttributes();
        $this->travelTo(CarbonImmutable::parse('2027-01-01T12:00:00Z'));
        $expected = $blocks ? [['2026-10-05T09:00:00Z', '2026-10-05T10:00:00Z'], ['2026-10-05T11:00:00Z', '2026-10-05T12:00:00Z']] : [['2026-10-05T09:00:00Z', '2026-10-05T12:00:00Z']];
        $this->assertSame($this->utcIntervals($expected), $this->calculate($user));
        $this->assertSame($before, $session->refresh()->getAttributes());
    }

    public static function sessionLifecycleCases(): array
    {
        return [0 => ['planned', true]];
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
}
