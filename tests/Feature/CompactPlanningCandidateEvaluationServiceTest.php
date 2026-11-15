<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Enums\StudySessionStatus;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\PlanningCandidateEvaluationService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\AssertsBatchedReads;
use Tests\TestCase;

#[Group('integration')]
#[Group('mysql')]
class CompactPlanningCandidateEvaluationServiceTest extends TestCase
{
    use AssertsBatchedReads;
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
        return ['valid no row' => ['missing', [], []]];
    }

    #[DataProvider('sessionStatuses')]
    public function test_workload_status_selection_and_scheduled_not_actual_duration(StudySessionStatus $status, bool $counts): void
    {
        $user = $this->user();
        PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 90, 'max_weekly_study_minutes' => 90]);
        $this->studySession($user, '2026-10-05 08:00:00', '2026-10-05 09:00:00', $status, $status === StudySessionStatus::Completed ? ['actual_minutes' => 1] : []);
        $this->assertSame($counts ? ['daily_study_limit_exceeded', 'weekly_study_limit_exceeded'] : [], $this->evaluate($user));
    }

    public static function sessionStatuses(): array
    {
        return [0 => [StudySessionStatus::Planned, true]];
    }

    public function test_mixed_statuses_and_equal_overlapping_records_are_additive_once_per_record(): void
    {
        $user = $this->user();
        foreach (StudySessionStatus::cases() as $status) {
            $this->studySession($user, '2026-10-05 08:00:00', '2026-10-05 09:00:00', $status);
        }
        $preference = PlanningPreference::factory()->for($user)->create(['max_daily_study_minutes' => 180, 'max_weekly_study_minutes' => 180]);
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
        return ['earlier same local day' => ['Europe/Kyiv', '2026-10-07 10:00:00', '2026-10-07 11:00:00', '2026-10-07 03:00:00', '2026-10-07 04:00:00', ['max_daily_study_minutes' => 90, 'max_weekly_study_minutes' => 90], [0 => 'daily_study_limit_exceeded', 1 => 'weekly_study_limit_exceeded']]];
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
        return ['lesson active' => ['lesson', 'active', false, true]];
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
        return ['before equality' => [15, '2026-10-05 09:00:00', '2026-10-05 09:45:00', []]];
    }

    #[DataProvider('lessonTypes')]
    public function test_no_lesson_type_exemptions_and_active_replacements_count(LessonType $type): void
    {
        $user = $this->user();
        PlanningPreference::factory()->for($user)->create(['preferred_break_minutes' => 15]);
        $original = Lesson::factory()->for($user)->create(['status' => LessonStatus::Replaced]);
        Lesson::factory()->for($user)->create(['type' => $type, 'replaces_lesson_id' => $original->id, 'starts_at' => '2026-10-05 09:00:00', 'ends_at' => '2026-10-05 09:50:00']);
        $this->assertSame(['insufficient_break_before'], $this->evaluate($user));
    }

    public static function lessonTypes(): array
    {
        return [0 => [LessonType::Lecture]];
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

        return StudySession::factory()->create(array_replace(['user_id' => $user->id, 'task_id' => $taskId, 'starts_at' => $start, 'ends_at' => $end, 'status' => $status, 'completed_at' => $status === StudySessionStatus::Completed ? $end : null], $values));
    }

    private function candidate(string $start, string $end, string $timezone = 'UTC'): array
    {
        return ['start' => (new DateTimeImmutable($start, new DateTimeZone($timezone)))->getTimestamp(), 'end' => (new DateTimeImmutable($end, new DateTimeZone($timezone)))->getTimestamp()];
    }

    private function evaluate(User $user, ?array $candidate = null, mixed $taskId = null, mixed $subtaskId = null): array
    {
        return app(PlanningCandidateEvaluationService::class)->evaluate($user, $candidate ?? $this->candidate('2026-10-05 10:00:00', '2026-10-05 11:00:00'), $taskId, $subtaskId);
    }
}
