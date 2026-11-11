<?php

namespace Tests\Concerns;

use App\Models\PlanningPreference;
use App\Models\RecoverySuggestion;
use App\Models\StudyAvailabilityWindow;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\RecoverySuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

trait InteractsWithLearningRecovery
{
    private function initializeRecovery(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        PlanningPreference::factory()->for($owner)->create(['min_session_minutes' => 30, 'max_session_minutes' => 120,
            'preferred_break_minutes' => 15, 'max_daily_study_minutes' => 240, 'max_weekly_study_minutes' => 1200]);
        foreach (range(1, 7) as $day) {
            StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => $day, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00']);
        }
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 60, 'deadline_at' => '2026-10-21 17:00:00']);
        $service = app(RecoverySuggestionService::class);
        $this->actingAs($owner, 'web');

        return compact('owner', 'task', 'service');
    }

    private function business(): array
    {
        return collect(['tasks', 'subtasks', 'lessons', 'study_sessions', 'reminders', 'notifications', 'planning_preferences', 'study_availability_windows'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy($table === 'planning_preferences' ? 'user_id' : 'id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function pending(User $owner): RecoverySuggestion
    {
        return $owner->recoverySuggestions()->where('status', 'pending')->firstOrFail();
    }
}
