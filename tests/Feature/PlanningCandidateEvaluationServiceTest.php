<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\StudySessionStatus;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\FreeTimeService;
use App\Services\Planning\PlanningCandidateEvaluationService;
use App\Services\Planning\PlanningConstraintService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

class PlanningCandidateEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('basicCases')]
    public function test_availability_and_nullable_preferences(string $scenario, array $preferences, array $expected): void
    {
        $user = $this->user();
        if ($scenario === 'empty') {
            $user->studyAvailabilityWindows()->delete();
        } elseif (in_array($scenario, ['partial', 'gap'], true)) {
            $user->studyAvailabilityWindows()->delete();
            $this->window($user, 1, '10:00:00', '10:30:00');
            if ($scenario === 'gap') {
                $this->window($user, 1, '10:45:00', '12:00:00');
            }
        }
        if ($scenario !== 'missing') {
            PlanningPreference::factory()->for($user)->create($preferences);
        }
        $this->assertSame($expected, $this->evaluate($user));
        $this->assertDatabaseCount('planning_preferences', $scenario === 'missing' ? 0 : 1);
    }

    public static function basicCases(): array
    {
        return [
            'valid no row' => ['missing', [], []],
            'all null' => ['null', [], []],
            'empty availability' => ['empty', [], ['outside_available_study_time']],
            'partial containment' => ['partial', [], ['outside_available_study_time']],
            'gap inside candidate' => ['gap', [], ['outside_available_study_time']],
            'exact configured duration' => ['configured', ['min_session_minutes' => 60, 'max_session_minutes' => 60], []],
            'below configured duration' => ['configured', ['min_session_minutes' => 61], ['below_minimum_session_duration']],
            'above configured duration' => ['configured', ['max_session_minutes' => 59], ['above_maximum_session_duration']],
            'candidate daily and weekly' => ['configured', ['max_daily_study_minutes' => 59, 'max_weekly_study_minutes' => 59], ['daily_study_limit_exceeded', 'weekly_study_limit_exceeded']],
        ];
    }

    #[DataProvider('sessionStatuses')]
    public function test_workload_status_selection_and_scheduled_not_actual_duration(StudySessionStatus $status, bool $counts): void
    {
        $user = $this->user();
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 90, 'max_weekly_study_minutes' => 90]);
        $this->studySession($user, '2026-10-05 08:00:00', '2026-10-05 09:00:00', $status,
            $status === StudySessionStatus::Completed ? ['actual_minutes' => 1] : []);
        $this->assertSame($counts ? ['daily_study_limit_exceeded', 'weekly_study_limit_exceeded'] : [], $this->evaluate($user));
    }

    public static function sessionStatuses(): array
    {
        return array_map(fn ($status): array => [$status, in_array($status, [StudySessionStatus::Planned, StudySessionStatus::Completed], true)], StudySessionStatus::cases());
    }

    public function test_mixed_statuses_and_equal_overlapping_records_are_additive_once_per_record(): void
    {
        $user = $this->user();
        foreach (StudySessionStatus::cases() as $status) {
            $this->studySession($user, '2026-10-05 08:00:00', '2026-10-05 09:00:00', $status);
        }
        $preference = PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 180, 'max_weekly_study_minutes' => 180]);
        // Two eligible equal intervals + candidate = exactly 180 minutes. No accidental duplicate.
        $this->assertSame([], $this->evaluate($user));
        $preference->update(['max_daily_study_minutes' => 179, 'max_weekly_study_minutes' => 179]);
        $this->assertSame(['daily_study_limit_exceeded', 'weekly_study_limit_exceeded'], $this->evaluate($user));
    }

    #[DataProvider('workloadRanges')]
    public function test_workload_full_local_periods_and_overlap_boundaries(string $timezone, string $start, string $end, string $sourceStart, string $sourceEnd, array $limits, array $expected): void
    {
        $user = $this->user($timezone);
        PlanningPreference::factory()->for($user)->create($limits);
        $this->studySession($user, $sourceStart, $sourceEnd, StudySessionStatus::Completed, ['actual_minutes' => 1]);
        $this->assertSame($expected, $this->evaluate($user, $this->candidate($start, $end, $timezone)));
    }

    public static function workloadRanges(): array
    {
        $weekly = ['max_weekly_study_minutes' => 90];
        $both = ['max_daily_study_minutes' => 90, 'max_weekly_study_minutes' => 90];

        return [
            'earlier same local day' => ['Europe/Kyiv', '2026-10-07 10:00:00', '2026-10-07 11:00:00', '2026-10-07 03:00:00', '2026-10-07 04:00:00', $both, ['daily_study_limit_exceeded', 'weekly_study_limit_exceeded']],
            'later same local day' => ['Europe/Kyiv', '2026-10-07 10:00:00', '2026-10-07 11:00:00', '2026-10-07 18:00:00', '2026-10-07 19:00:00', $both, ['daily_study_limit_exceeded', 'weekly_study_limit_exceeded']],
            'Monday for Wednesday' => ['UTC', '2026-10-07 10:00:00', '2026-10-07 11:00:00', '2026-10-05 10:00:00', '2026-10-05 11:00:00', $both, ['weekly_study_limit_exceeded']],
            'later Sunday for Wednesday' => ['UTC', '2026-10-07 10:00:00', '2026-10-07 11:00:00', '2026-10-11 10:00:00', '2026-10-11 11:00:00', $weekly, ['weekly_study_limit_exceeded']],
            'source spans preceding week boundary' => ['Europe/Kyiv', '2026-10-07 10:00:00', '2026-10-07 11:00:00', '2026-10-04 20:00:00', '2026-10-04 22:00:00', $weekly, ['weekly_study_limit_exceeded']],
            'end exactly query start excluded' => ['Europe/Kyiv', '2026-10-07 10:00:00', '2026-10-07 11:00:00', '2026-10-04 20:00:00', '2026-10-04 21:00:00', $weekly, []],
            'start exactly query end excluded' => ['Europe/Kyiv', '2026-10-07 10:00:00', '2026-10-07 11:00:00', '2026-10-11 21:00:00', '2026-10-11 22:00:00', $weekly, []],
            'cross local midnight daily split' => ['Europe/Kyiv', '2026-10-07 23:30:00', '2026-10-08 00:30:00', '2026-10-08 07:00:00', '2026-10-08 08:00:00', ['max_daily_study_minutes' => 60], ['daily_study_limit_exceeded']],
            'candidate touches two ISO weeks' => ['Europe/Kyiv', '2026-10-11 23:30:00', '2026-10-12 00:30:00', '2026-10-18 07:00:00', '2026-10-18 08:00:00', ['max_weekly_study_minutes' => 60], ['weekly_study_limit_exceeded']],
            'ISO week-year' => ['UTC', '2027-01-01 10:00:00', '2027-01-01 11:00:00', '2026-12-28 10:00:00', '2026-12-28 11:00:00', $weekly, ['weekly_study_limit_exceeded']],
            'Kyiv spring full week before offset change' => ['Europe/Kyiv', '2026-03-29 10:00:00', '2026-03-29 11:00:00', '2026-03-22 22:00:00', '2026-03-22 23:00:00', $weekly, ['weekly_study_limit_exceeded']],
            'Kyiv fall full week before offset change' => ['Europe/Kyiv', '2026-10-25 10:00:00', '2026-10-25 11:00:00', '2026-10-18 21:00:00', '2026-10-18 22:00:00', $weekly, ['weekly_study_limit_exceeded']],
            'historical diagnostic' => ['Europe/Kyiv', '2020-10-07 10:00:00', '2020-10-07 11:00:00', '2020-10-05 07:00:00', '2020-10-05 08:00:00', $weekly, ['weekly_study_limit_exceeded']],
            'spring missing hour scheduled elapsed' => ['Europe/Kyiv', '2026-03-29 10:00:00', '2026-03-29 11:00:00', '2026-03-29 00:30:00', '2026-03-29 01:30:00', ['max_daily_study_minutes' => 120], []],
            'fall repeated hour scheduled elapsed' => ['Europe/Kyiv', '2026-10-25 10:00:00', '2026-10-25 11:00:00', '2026-10-25 00:30:00', '2026-10-25 01:30:00', ['max_daily_study_minutes' => 119], ['daily_study_limit_exceeded']],
        ];
    }

    #[DataProvider('anchorStatuses')]
    public function test_only_effective_lessons_and_planned_sessions_are_break_anchors(string $kind, string $status, bool $deleted, bool $included): void
    {
        $user = $this->user();
        PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => 15]);
        if ($kind === 'lesson') {
            $event = Lesson::factory()->for($user)->create(['starts_at' => '2026-10-05 09:00:00', 'ends_at' => '2026-10-05 09:50:00', 'status' => $status]);
            if ($deleted) {
                $event->delete();
            }
        } else {
            $this->studySession($user, '2026-10-05 09:00:00', '2026-10-05 09:50:00', StudySessionStatus::from($status));
        }
        $this->assertSame($included ? ['insufficient_break_before'] : [], $this->evaluate($user));
    }

    public static function anchorStatuses(): array
    {
        $cases = [];
        foreach (LessonStatus::cases() as $status) {
            $cases['lesson '.$status->value] = ['lesson', $status->value, false, $status === LessonStatus::Active];
        }
        $cases['deleted active lesson'] = ['lesson', 'active', true, false];
        foreach (StudySessionStatus::cases() as $status) {
            $cases['session '.$status->value] = ['session', $status->value, false, $status === StudySessionStatus::Planned];
        }

        return $cases;
    }

    #[DataProvider('breakCases')]
    public function test_break_envelope_and_exact_elapsed_seconds(?int $minutes, string $start, string $end, array $expected): void
    {
        $user = $this->user();
        PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => $minutes]);
        Lesson::factory()->for($user)->create(['starts_at' => $start, 'ends_at' => $end]);
        $this->assertSame($expected, $this->evaluate($user));
    }

    public static function breakCases(): array
    {
        return [
            'before equality' => [15, '2026-10-05 09:00:00', '2026-10-05 09:45:00', []],
            'before one second short' => [15, '2026-10-05 09:00:00', '2026-10-05 09:45:01', ['insufficient_break_before']],
            'after equality' => [15, '2026-10-05 11:15:00', '2026-10-05 12:00:00', []],
            'after one second short' => [15, '2026-10-05 11:14:59', '2026-10-05 12:00:00', ['insufficient_break_after']],
            'adjacent before' => [15, '2026-10-05 09:00:00', '2026-10-05 10:00:00', ['insufficient_break_before']],
            'adjacent after' => [15, '2026-10-05 11:00:00', '2026-10-05 12:00:00', ['insufficient_break_after']],
            'null has no default' => [null, '2026-10-05 09:00:00', '2026-10-05 10:00:00', []],
            'null still checks overlap' => [null, '2026-10-05 10:30:00', '2026-10-05 11:30:00', ['outside_available_study_time', 'overlapping_break_anchor']],
            'maximum configured lookbehind' => [65535, '2026-08-21 21:45:00', '2026-08-21 21:45:01', ['insufficient_break_before']],
            'maximum configured lookahead' => [65535, '2026-11-19 23:14:59', '2026-11-19 23:15:00', ['insufficient_break_after']],
            'outside lookbehind' => [15, '2026-10-04 09:00:00', '2026-10-04 10:00:00', []],
        ];
    }

    #[DataProvider('lessonTypes')]
    public function test_no_lesson_type_exemptions_and_active_replacements_count(LessonType $type): void
    {
        $user = $this->user();
        PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => 15]);
        $original = Lesson::factory()->for($user)->create(['status' => LessonStatus::Replaced]);
        Lesson::factory()->for($user)->create(['type' => $type, 'replaces_lesson_id' => $original->id,
            'starts_at' => '2026-10-05 09:00:00', 'ends_at' => '2026-10-05 09:50:00']);
        $this->assertSame(['insufficient_break_before'], $this->evaluate($user));
    }

    public static function lessonTypes(): array
    {
        return array_map(fn ($type): array => [$type], LessonType::cases());
    }

    #[DataProvider('deadlines')]
    public function test_earliest_present_work_deadline(?string $parent, ?string $child, bool $withChild, array $expected): void
    {
        $user = $this->user();
        $task = Task::factory()->for($user)->create(['deadline_at' => $parent]);
        $subtask = $withChild ? Subtask::factory()->for($task)->create(['deadline_at' => $child]) : null;
        $this->assertSame($expected, $this->evaluate($user, null, $task->id, $subtask?->id));
    }

    public static function deadlines(): array
    {
        $late = ['deadline_exceeded'];

        return [
            'task no deadline' => [null, null, false, []],
            'task equality' => ['2026-10-05 11:00:00', null, false, []],
            'task one second earlier' => ['2026-10-05 10:59:59', null, false, $late],
            'task later' => ['2026-10-05 12:00:00', null, false, []],
            'child earlier' => ['2026-10-05 12:00:00', '2026-10-05 10:59:59', true, $late],
            'parent earlier' => ['2026-10-05 10:59:59', '2026-10-05 12:00:00', true, $late],
            'only parent' => ['2026-10-05 10:59:59', null, true, $late],
            'only child' => [null, '2026-10-05 10:59:59', true, $late],
            'neither deadline' => [null, null, true, []],
            'both equality' => ['2026-10-05 11:00:00', '2026-10-05 11:00:00', true, []],
        ];
    }

    #[DataProvider('invalidReferences')]
    public function test_invalid_targets_fail_as_not_found_without_foreign_details(string $scenario): void
    {
        $user = $this->user();
        $task = Task::factory()->for($user)->create();
        $subtaskId = null;
        $taskId = $task->id;
        if ($scenario === 'missing task') {
            $taskId = PHP_INT_MAX;
        } elseif ($scenario === 'foreign task') {
            $taskId = Task::factory()->create(['title' => 'private foreign title'])->id;
        } elseif ($scenario === 'deleted task') {
            $task->delete();
        } elseif ($scenario === 'missing child') {
            $subtaskId = PHP_INT_MAX;
        } elseif (in_array($scenario, ['foreign child', 'different owned parent'], true)) {
            $other = Task::factory()->create($scenario === 'different owned parent' ? ['user_id' => $user->id] : []);
            $subtaskId = Subtask::factory()->for($other)->create(['title' => 'private foreign title'])->id;
        } else {
            $child = Subtask::factory()->for($task)->create();
            $child->delete();
            $subtaskId = $child->id;
        }
        try {
            $this->evaluate($user, null, $taskId, $subtaskId);
            $this->fail('An invalid target must fail.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame([], $exception->getIds());
            $this->assertStringNotContainsString('private foreign title', $exception->getMessage());
        }
    }

    public static function invalidReferences(): array
    {
        return array_map(fn ($case): array => [$case], ['missing task', 'foreign task', 'deleted task', 'missing child', 'foreign child', 'different owned parent', 'deleted child']);
    }

    public function test_foreign_sources_authentication_and_cached_attributes_do_not_override_trusted_persisted_user(): void
    {
        $user = $this->user('Europe/Kyiv');
        $foreign = $this->user('America/New_York');
        PlanningPreference::factory()->for($foreign)->create(['min_session_minutes' => 120, 'max_daily_study_minutes' => 1]);
        Lesson::factory()->for($foreign)->create(['starts_at' => '2026-10-05 07:00:00', 'ends_at' => '2026-10-05 08:00:00']);
        $this->studySession($foreign, '2026-10-05 07:00:00', '2026-10-05 08:00:00');
        $this->actingAs($foreign);
        foreach (['planningPreference', 'studyAvailabilityWindows', 'lessons', 'studySessions', 'tasks'] as $relation) {
            $user->setRelation($relation, $foreign->$relation);
        }
        $user->timezone = 'invalid cached timezone';
        $this->assertSame([], $this->evaluate($user));
        $user->studyAvailabilityWindows()->delete();
        $this->assertSame(['outside_available_study_time'], $this->evaluate($user));
    }

    #[DataProvider('corruptSources')]
    public function test_invalid_selected_session_references_fail_closed(string $scenario): void
    {
        $user = $this->user();
        $task = Task::factory()->for($user)->create();
        $foreign = Task::factory()->create();
        $values = ['user_id' => $user->id, 'task_id' => $task->id];
        if ($scenario === 'foreign task') {
            $values['task_id'] = $foreign->id;
        } else {
            $other = $scenario === 'foreign child' ? $foreign : Task::factory()->for($user)->create();
            $values['subtask_id'] = Subtask::factory()->for($other)->create()->id;
        }
        $this->studySession($user, '2026-10-05 08:00:00', '2026-10-05 09:00:00', StudySessionStatus::Completed, $values);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid planning source relationships.');
        $this->evaluate($user);
    }

    public static function corruptSources(): array
    {
        return [['foreign task'], ['foreign child'], ['different owned parent']];
    }

    public function test_corrupted_planned_anchor_outside_workload_week_also_fails_closed(): void
    {
        $user = $this->user();
        PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => 65535]);
        $this->studySession($user, '2026-09-20 08:00:00', '2026-09-20 09:00:00', StudySessionStatus::Planned,
            ['task_id' => Task::factory()->create()->id, 'user_id' => $user->id]);
        $this->expectException(UnexpectedValueException::class);
        $this->evaluate($user);
    }

    public function test_valid_soft_deleted_task_and_child_history_still_contributes(): void
    {
        $user = $this->user();
        $task = Task::factory()->for($user)->create();
        $child = Subtask::factory()->for($task)->create();
        foreach ([StudySessionStatus::Planned, StudySessionStatus::Completed] as $status) {
            $this->studySession($user, '2020-10-05 08:00:00', '2020-10-05 09:00:00', $status, ['task_id' => $task->id, 'subtask_id' => $child->id]);
        }
        $task->delete();
        $child->delete();
        PlanningPreference::factory()->for($user)->create(['max_weekly_study_minutes' => 179]);
        $this->assertSame(['weekly_study_limit_exceeded'], $this->evaluate($user, $this->candidate('2020-10-07 10:00:00', '2020-10-07 11:00:00')));
    }

    #[DataProvider('calendarCases')]
    public function test_inclusive_local_date_horizon_and_historical_membership(string $zone, string $start, string $end, bool $valid): void
    {
        $user = $this->user($zone);
        if (! $valid) {
            $this->expectException(InvalidArgumentException::class);
        }
        $this->assertSame([], $this->evaluate($user, $this->candidate($start, $end, $zone)));
    }

    public static function calendarCases(): array
    {
        return [
            'one date' => ['Europe/Kyiv', '2026-10-05 00:00:00', '2026-10-06 00:00:00', true],
            '31 dates' => ['Europe/Kyiv', '2026-10-01 00:00:00', '2026-11-01 00:00:00', true],
            '32 dates' => ['Europe/Kyiv', '2026-10-01 00:00:00', '2026-11-01 00:00:01', false],
            'spring 31 nominal dates' => ['Europe/Kyiv', '2026-03-01 00:00:00', '2026-04-01 00:00:00', true],
            'fall 31 nominal dates longer elapsed' => ['Europe/Kyiv', '2026-10-01 00:00:00', '2026-11-01 00:00:00', true],
            'spring 32 despite shorter elapsed' => ['Europe/Kyiv', '2026-03-01 00:00:00', '2026-04-01 00:00:01', false],
            'cross local midnight' => ['Europe/Kyiv', '2026-10-05 23:30:00', '2026-10-06 00:30:00', true],
            'past diagnostic' => ['Europe/Kyiv', '2000-01-01 10:00:00', '2000-01-01 11:00:00', true],
            'Apia skipped label 31 dates' => ['Pacific/Apia', '2011-12-01 00:00:00', '2012-01-01 00:00:00', true],
            'Apia skipped label 32 dates' => ['Pacific/Apia', '2011-12-01 00:00:00', '2012-01-02 00:00:00', false],
            'non-hour offset' => ['Asia/Kathmandu', '2026-10-05 23:30:00', '2026-10-06 00:30:00', true],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function test_malformed_direct_arguments_are_not_coerced(array $candidate, mixed $taskId, mixed $subtaskId): void
    {
        $user = $this->user();
        $this->expectException(InvalidArgumentException::class);
        $this->evaluate($user, $candidate, $taskId, $subtaskId);
    }

    public static function invalidArguments(): array
    {
        $good = ['start' => 1791194400, 'end' => 1791198000];
        $cases = [
            [[], null, null], [['start' => 1], null, null], [['start' => 1, 'end' => 2, 'extra' => 3], null, null],
            [['start' => 2, 'end' => 1], null, null], [['start' => 1, 'end' => 1], null, null],
            [['start' => PHP_INT_MIN, 'end' => PHP_INT_MAX], null, null],
            [['start' => PHP_INT_MAX - 1, 'end' => PHP_INT_MAX], null, null],
            [['start' => -30610224001, 'end' => -30610224000], null, null],
            [$good, null, 1],
        ];
        foreach (['1', 1.0, true, null] as $bad) {
            $cases[] = [['start' => $bad, 'end' => 1791198000], null, null];
            $cases[] = [['start' => 1791194400, 'end' => $bad], null, null];
        }
        foreach (['1', 1.0, true, false, 0, -1, []] as $bad) {
            $cases[] = [$good, $bad, null];
            $cases[] = [$good, 1, $bad];
        }

        return $cases;
    }

    public function test_unsafe_expanded_query_boundaries_are_rejected(): void
    {
        $user = $this->user();
        PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => 65535]);
        $this->expectException(InvalidArgumentException::class);
        $this->evaluate($user, $this->candidate('1000-01-15 10:00:00', '1000-01-15 11:00:00'));
    }

    #[DataProvider('malformedTimestampSources')]
    public function test_malformed_persisted_timestamps_fail_without_silent_normalization(string $kind): void
    {
        $user = $this->user();
        $task = Task::factory()->for($user)->create();
        $child = Subtask::factory()->for($task)->create();
        $mode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        // MySQL can contain incomplete legacy dates when these strict checks were disabled.
        $relaxed = implode(',', array_diff(explode(',', $mode), ['NO_ZERO_IN_DATE', 'NO_ZERO_DATE']));
        try {
            DB::statement('SET SESSION sql_mode = ?', [$relaxed]);
            if ($kind === 'task' || $kind === 'subtask') {
                DB::table($kind === 'task' ? 'tasks' : 'subtasks')->where('id', $kind === 'task' ? $task->id : $child->id)
                    ->update(['deadline_at' => '2026-10-00 08:00:00']);
            } elseif ($kind === 'lesson') {
                $lesson = Lesson::factory()->for($user)->create();
                DB::table('lessons')->where('id', $lesson->id)->update(['starts_at' => '2026-10-00 08:00:00']);
            } else {
                $session = $this->studySession($user, '2026-10-05 08:00:00', '2026-10-05 09:00:00', StudySessionStatus::Completed);
                DB::table('study_sessions')->where('id', $session->id)->update(['starts_at' => '2026-10-00 08:00:00']);
            }
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$mode]);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->evaluate($user, null, $task->id, $child->id);
    }

    public static function malformedTimestampSources(): array
    {
        return [['task'], ['subtask'], ['lesson'], ['session']];
    }

    #[DataProvider('dstMembership')]
    public function test_pf1_gap_and_fold_membership_is_preserved(string $date, string $utcStart, string $utcEnd, array $expected): void
    {
        $user = $this->user('Europe/Kyiv');
        $user->studyAvailabilityWindows()->delete();
        $this->window($user, 7, '03:15:00', '03:45:00');
        $this->assertSame($expected, $this->evaluate($user, $this->candidate($date.' '.$utcStart, $date.' '.$utcEnd)));
    }

    public static function dstMembership(): array
    {
        return [
            'spring gap has no availability' => ['2026-03-29', '01:15:00', '01:45:00', ['outside_available_study_time']],
            'first fold occurrence' => ['2026-10-25', '00:15:00', '00:45:00', []],
            'second fold occurrence' => ['2026-10-25', '01:15:00', '01:45:00', []],
            'fold gap cannot be bridged' => ['2026-10-25', '00:15:00', '01:45:00', ['outside_available_study_time']],
        ];
    }

    public function test_historical_planned_workload_is_not_inferred_missed(): void
    {
        $user = $this->user();
        PlanningPreference::factory()->for($user)->create(['max_weekly_study_minutes' => 90]);
        $session = $this->studySession($user, '2000-01-03 08:00:00', '2000-01-03 09:00:00');
        $this->assertSame(['weekly_study_limit_exceeded'], $this->evaluate($user, $this->candidate('2000-01-05 10:00:00', '2000-01-05 11:00:00')));
        $this->assertSame(StudySessionStatus::Planned, $session->refresh()->status);
    }

    public function test_invalid_persisted_timezone_is_rejected_without_using_the_server_timezone(): void
    {
        $user = $this->user();
        $user->update(['timezone' => '+03:00']);
        $this->expectException(InvalidArgumentException::class);
        $this->evaluate($user);
    }

    public function test_raw_utc_and_user_local_workload_are_server_timezone_independent(): void
    {
        $user = $this->user('Europe/Kyiv');
        $task = Task::factory()->for($user)->create(['deadline_at' => '2026-10-05 11:00:00']);
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 90, 'preferred_break_minutes' => 15]);
        $this->studySession($user, '2026-10-04 22:00:00', '2026-10-04 23:00:00', StudySessionStatus::Completed);
        Lesson::factory()->for($user)->create(['starts_at' => '2026-10-05 09:00:00', 'ends_at' => '2026-10-05 09:45:01']);
        $original = date_default_timezone_get();
        try {
            foreach (['UTC', 'Pacific/Apia', 'America/Los_Angeles', 'Asia/Kathmandu'] as $timezone) {
                date_default_timezone_set($timezone);
                $this->assertSame(['insufficient_break_before', 'daily_study_limit_exceeded'], $this->evaluate($user, null, $task->id));
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_delegation_passes_five_preferences_null_pf1_filter_and_original_source_intervals(): void
    {
        $user = $this->user();
        $source = $this->studySession($user, '2026-10-04 23:00:00', '2026-10-05 01:00:00', StudySessionStatus::Completed);
        $this->mock(FreeTimeService::class, function ($mock) use ($user): void {
            $mock->shouldReceive('calculate')->once()->withArgs(fn ($owner, $start, $end, $minimum): bool => $owner->is($user) && $start === '2026-10-05' && $end === '2026-10-05' && $minimum === null)->andReturn([]);
        });
        $this->mock(PlanningConstraintService::class, function ($mock) use ($source): void {
            $mock->shouldReceive('evaluate')->once()->withArgs(function ($candidate, $free, $anchors, $workload, $timezone, $preferences, $deadline) use ($source): bool {
                $this->assertSame($this->candidate('2026-10-05 10:00:00', '2026-10-05 11:00:00'), $candidate);
                $this->assertSame([], $free);
                $this->assertSame([], $anchors);
                $this->assertSame([$this->candidate($source->getRawOriginal('starts_at'), $source->getRawOriginal('ends_at'))], $workload);
                $this->assertSame('UTC', $timezone);
                $this->assertSame($this->nullPreferences(), $preferences);
                $this->assertNull($deadline);

                return true;
            })->andReturn(['outside_available_study_time']);
        });
        $this->assertSame(['outside_available_study_time'], $this->evaluate($user));
    }

    public function test_multiple_violations_keep_c8_order_and_candidate_input_unchanged(): void
    {
        $user = $this->user();
        $preferences = ['min_session_minutes' => 120, 'max_session_minutes' => null, 'preferred_break_minutes' => 15,
            'max_daily_study_minutes' => 90, 'max_weekly_study_minutes' => 90];
        PlanningPreference::factory()->for($user)->create($preferences);
        $task = Task::factory()->for($user)->create(['deadline_at' => '2026-10-05 10:59:59']);
        foreach ([['09:00:00', '09:50:00'], ['10:30:00', '10:40:00'], ['11:10:00', '12:00:00']] as [$start, $end]) {
            Lesson::factory()->for($user)->create(['starts_at' => '2026-10-05 '.$start, 'ends_at' => '2026-10-05 '.$end]);
        }
        $this->studySession($user, '2026-10-05 07:00:00', '2026-10-05 08:00:00', StudySessionStatus::Completed);
        $candidate = $this->candidate('2026-10-05 10:00:00', '2026-10-05 11:00:00');
        $before = $candidate;
        $expected = ['below_minimum_session_duration', 'outside_available_study_time', 'overlapping_break_anchor',
            'insufficient_break_before', 'insufficient_break_after', 'daily_study_limit_exceeded', 'weekly_study_limit_exceeded', 'deadline_exceeded'];
        $this->assertSame($expected, $this->evaluate($user, $candidate, $task->id));
        $this->assertSame($before, $candidate);
    }

    public function test_repeated_evaluation_preserves_all_tables_and_executes_selects_only(): void
    {
        $user = $this->user();
        $task = Task::factory()->for($user)->create();
        $child = Subtask::factory()->for($task)->create();
        $this->studySession($user, '2026-10-05 07:00:00', '2026-10-05 08:00:00', StudySessionStatus::Completed, ['task_id' => $task->id, 'subtask_id' => $child->id]);
        Lesson::factory()->for($user)->create();
        Reminder::factory()->for($user)->create(['task_id' => $task->id]);
        $before = $this->snapshot();
        $level = DB::transactionLevel();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            for ($i = 0; $i < 3; $i++) {
                $this->assertSame([], $this->evaluate($user, null, $task->id, $child->id));
            }
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
            $this->assertStringNotContainsString('for update', $query['query']);
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('planning_preferences', 0);
    }

    public function test_queries_remain_bounded_and_use_overlap_predicates_with_large_collections(): void
    {
        $user = $this->user();
        PlanningPreference::factory()->for($user)->create(['max_weekly_study_minutes' => 65535]);
        $task = Task::factory()->for($user)->create();
        $child = Subtask::factory()->for($task)->create();
        $this->studySession($user, '2026-10-05 07:00:00', '2026-10-05 08:00:00', StudySessionStatus::Completed, ['task_id' => $task->id, 'subtask_id' => $child->id]);
        $small = $this->evaluationQueries($user);
        // Different parents/children make a lazy per-record validation visibly grow.
        for ($i = 0; $i < 40; $i++) {
            $other = Task::factory()->for($user)->create();
            $otherChild = Subtask::factory()->for($other)->create();
            $this->studySession($user, '2026-10-05 07:00:00', '2026-10-05 08:00:00', StudySessionStatus::Completed,
                ['task_id' => $other->id, 'subtask_id' => $otherChild->id]);
        }
        $this->studySession($user, '2000-01-01 07:00:00', '2000-01-01 08:00:00', StudySessionStatus::Completed);
        $large = $this->evaluationQueries($user);
        $this->assertCount(count($small), $large);
        $this->assertLessThanOrEqual(11, count($large));
        foreach ($large as $query) {
            $this->assertStringNotContainsString('select *', $query['query']);
            if (str_contains($query['query'], 'from `study_sessions`') || str_contains($query['query'], 'from `lessons`')) {
                $this->assertStringContainsString('`starts_at` < ?', $query['query']);
                $this->assertStringContainsString('`ends_at` > ?', $query['query']);
                $this->assertStringContainsString('`user_id` = ?', $query['query']);
                $this->assertSame($user->id, $query['bindings'][0]);
                $this->assertStringNotContainsString('actual_minutes', $query['query']);
            }
        }
        $weekly = collect($large)->first(fn ($query): bool => str_contains($query['query'], '`status` in'));
        $this->assertSame([$user->id, 'planned', 'completed', '2026-10-12 00:00:00', '2026-10-05 00:00:00'], $weekly['bindings']);
        $this->assertTrue(collect(Schema::getIndexes('study_sessions'))->contains(fn ($index): bool => $index['columns'] === ['user_id', 'status', 'starts_at']));
    }

    private function user(string $timezone = 'UTC'): User
    {
        $user = User::factory()->create(['timezone' => $timezone]);
        foreach (range(1, 7) as $day) {
            $this->window($user, $day, '00:00:00', '24:00:00');
        }

        return $user;
    }

    private function window(User $user, int $day, string $start, string $end): void
    {
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $day, 'starts_at' => $start, 'ends_at' => $end]);
    }

    private function studySession(User $user, string $start, string $end, StudySessionStatus $status = StudySessionStatus::Planned, array $values = []): StudySession
    {
        $taskId = $values['task_id'] ?? Task::factory()->for($user)->create()->id;

        return StudySession::factory()->create(array_replace([
            'user_id' => $user->id, 'task_id' => $taskId, 'starts_at' => $start, 'ends_at' => $end, 'status' => $status,
            'completed_at' => $status === StudySessionStatus::Completed ? $end : null,
        ], $values));
    }

    private function candidate(string $start, string $end, string $timezone = 'UTC'): array
    {
        return ['start' => (new DateTimeImmutable($start, new DateTimeZone($timezone)))->getTimestamp(),
            'end' => (new DateTimeImmutable($end, new DateTimeZone($timezone)))->getTimestamp()];
    }

    private function evaluate(User $user, ?array $candidate = null, mixed $taskId = null, mixed $subtaskId = null): array
    {
        return app(PlanningCandidateEvaluationService::class)->evaluate($user,
            $candidate ?? $this->candidate('2026-10-05 10:00:00', '2026-10-05 11:00:00'), $taskId, $subtaskId);
    }

    private function nullPreferences(): array
    {
        return array_fill_keys(['min_session_minutes', 'max_session_minutes', 'preferred_break_minutes', 'max_daily_study_minutes', 'max_weekly_study_minutes'], null);
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

    private function evaluationQueries(User $user): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->assertSame([], $this->evaluate($user));

            return DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
    }
}
