<?php

namespace Tests\Feature;

use App\Models\PlanningPreference;
use App\Models\StudyAvailabilityWindow;
use App\Models\Task;
use App\Models\User;
use App\Services\Demo\PlanningDemoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class PlanningDemoCommandTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp(); // verifies disposable MySQL before switching the app environment
        $this->app['env'] = 'local';
        $this->travelTo(CarbonImmutable::parse('2026-11-13T06:00:00Z'));
        $this->beforeApplicationDestroyed(function (): void {
            $this->app['env'] = 'testing';
            $this->truncateTablesForAllConnections();
        });
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['name' => 'o', 'timezone' => 'Europe/Kyiv']);
        PlanningPreference::factory()->for($owner)->create(['min_session_minutes' => 30, 'max_session_minutes' => 120,
            'preferred_break_minutes' => 15, 'max_daily_study_minutes' => 540, 'max_weekly_study_minutes' => 4000]);
        foreach (range(1, 7) as $day) {
            StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => $day, 'starts_at' => '08:00:00', 'ends_at' => '22:00:00']);
        }

        return $owner;
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['users', 'planning_preferences', 'study_availability_windows', 'tasks', 'subtasks', 'study_sessions'] as $table) {
            $result[$table] = DB::table($table)->orderByRaw('1')->get()->toJson();
        }

        return $result;
    }

    public function test_dry_run_and_repeated_seed_preserve_settings_other_owners_and_history(): void
    {
        $owner = $this->owner();
        $real = Task::factory()->for($owner)->create(['title' => 'Real work']);
        $foreign = Task::factory()->create(['title' => 'Foreign work']);
        $real->refresh();
        $foreign->refresh();
        $before = $this->snapshot();
        $service = app(PlanningDemoService::class);
        $this->assertSame($owner->id, $service->resolve('o')->id);
        $this->assertSame($owner->id, $service->resolve('id:'.$owner->id)->id);
        $dry = $service->run($owner, true);
        $this->assertCount(9, $dry['records']);
        $this->assertSame($before, $this->snapshot());
        $result = $service->run($owner, false);
        $this->assertSame(9, $result['created_tasks']);
        $this->assertSame(3, $result['created_subtasks']);
        $this->assertSame(3, $result['created_sessions']);
        $this->assertSame($real->getAttributes(), $real->fresh()->getAttributes());
        $this->assertSame($foreign->getAttributes(), $foreign->fresh()->getAttributes());
        foreach (['users', 'planning_preferences', 'study_availability_windows'] as $table) {
            $this->assertSame($before[$table], $this->snapshot()[$table]);
        }
        $rows = collect($result['records'])->keyBy('key');
        $this->assertNull($rows['A']['deadline_at']);
        $this->assertGreaterThan(0, $rows['D']['preview_summary']['unscheduled_seconds']);
        $this->assertGreaterThan(0, $rows['E']['preview_summary']['unscheduled_seconds']);
        $this->assertSame(135, Task::findOrFail($rows['C']['task_id'])->estimated_minutes);
        $after = $this->snapshot();
        $again = $service->run($owner, false);
        $this->assertSame([0, 0, 0], [$again['created_tasks'], $again['created_subtasks'], $again['created_sessions']]);
        $this->assertSame($after, $this->snapshot());
        $task = Task::findOrFail($rows['A']['task_id']);
        $task->delete();
        $service->run($owner, false);
        $this->assertTrue($task->fresh()->trashed());
        $this->assertSame(11, Task::withTrashed()->count());
    }

    public function test_long_source_uses_later_feasible_slot_without_overwriting_preferences(): void
    {
        $owner = $this->owner();
        $owner->studyAvailabilityWindows()->delete();
        foreach ([[1, '09:00:00', '10:00:00'], [1, '14:00:00', '17:00:00'], [2, '14:00:00', '17:00:00']] as [$day, $start, $end]) {
            StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => $day, 'starts_at' => $start, 'ends_at' => $end]);
        }
        $result = app(PlanningDemoService::class)->run($owner, false);
        $row = collect($result['records'])->firstWhere('key', 'H');
        $this->assertCount(1, $row['sessions']);
        $session = $owner->studySessions()->findOrFail($row['sessions'][0]['id']);
        $this->assertSame(7200, $session->ends_at->timestamp - $session->starts_at->timestamp);
        $this->assertSame(120, $owner->planningPreference->max_session_minutes);
        $this->assertSame('2026-11-17', $session->starts_at->format('Y-m-d'));
    }

    public function test_confirmed_command_prints_actual_ids_and_refuses_marker_collisions(): void
    {
        $owner = $this->owner();
        $tester = new CommandTester(Artisan::all()['planning:demo-seed']);
        $tester->setInputs(['yes']);
        $this->assertSame(0, $tester->execute(['--user' => 'id:'.$owner->id], ['interactive' => true]));
        $this->assertStringContainsString('"created_tasks": 9', $tester->getDisplay());
        $this->assertStringContainsString('"task_id":', $tester->getDisplay());
        $this->assertDatabaseCount('study_sessions', 3);
        Task::factory()->for($owner)->create(['title' => '[DEMO-PLANNING] A — Без дедлайну']);
        $before = $this->snapshot();
        $tester->setInputs(['yes']);
        $this->assertSame(1, $tester->execute(['--user' => 'o'], ['interactive' => true]));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_missing_and_ambiguous_owner_never_creates_demo_records(): void
    {
        $owner = $this->owner();
        $tester = new CommandTester(Artisan::all()['planning:demo-seed']);
        $this->assertSame(1, $tester->execute(['--user' => 'missing', '--dry-run' => true]));
        User::factory()->create(['name' => 'o']);
        $this->assertSame(1, $tester->execute(['--user' => 'o', '--dry-run' => true]));
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_command_requires_local_environment_and_explicit_interactive_consent(): void
    {
        $owner = $this->owner();
        $tester = new CommandTester(Artisan::all()['planning:demo-seed']);
        $this->assertSame(1, $tester->execute(['--user' => 'o'], ['interactive' => false]));
        $tester->setInputs(['no']);
        $this->assertSame(1, $tester->execute(['--user' => 'o'], ['interactive' => true]));
        $this->assertDatabaseCount('tasks', 0);
        $this->assertSame(0, $tester->execute(['--user' => 'o', '--dry-run' => true], ['interactive' => false]));
        $this->assertDatabaseCount('tasks', 0);
        $this->app['env'] = 'production';
        $this->assertSame(1, $tester->execute(['--user' => 'o', '--dry-run' => true]));
        $this->assertDatabaseCount('tasks', 0);
    }
}
