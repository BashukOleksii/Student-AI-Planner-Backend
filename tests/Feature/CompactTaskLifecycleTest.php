<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactTaskLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12T10:20:30.987654Z'));
    }

    public static function operations(): array
    {
        return [0 => ['complete']];
    }

    private function taskForOperation(string $operation): Task
    {
        return Task::factory()->create(['status' => $operation === 'reopen' ? TaskStatus::Completed : TaskStatus::Pending, 'completed_at' => $operation === 'reopen' ? '2026-10-10 09:00:00' : null])->refresh();
    }

    public function test_complete_preserves_unrelated_fields_and_returns_exact_resource_with_actual_late_completion(): void
    {
        $subject = Subject::factory()->create();
        $task = Task::factory()->for($subject->user)->for($subject)->create(['title' => 'Курсова робота', 'description' => 'Prepare report', 'priority' => 3, 'estimated_minutes' => 60, 'deadline_at' => '2026-10-10 12:00:00'])->refresh();
        $before = $task->getAttributes();
        $other = User::factory()->create();
        $this->actingAs($subject->user, 'web')->postJson('/api/tasks/'.$task->id.'/complete', [])->assertOk()->assertExactJson(['data' => ['id' => $task->id, 'subject_id' => $subject->id, 'title' => 'Курсова робота', 'description' => 'Prepare report', 'status' => 'completed', 'priority' => 3, 'estimated_minutes' => 60, 'deadline_at' => '2026-10-10T12:00:00Z', 'completed_at' => '2026-10-12T10:20:30Z']]);
        $task->refresh();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame('2026-10-12 10:20:30', $task->getRawOriginal('completed_at'));
        $this->assertSame(Arr::except($before, ['status', 'completed_at', 'updated_at']), Arr::except($task->getAttributes(), ['status', 'completed_at', 'updated_at']));
    }

    public function test_task_without_deadline_can_complete(): void
    {
        $task = Task::factory()->create();
        $this->actingAs($task->user, 'web')->postJson('/api/tasks/'.$task->id.'/complete')->assertOk()->assertJsonPath('data.deadline_at', null)->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_at', '2026-10-12T10:20:30Z');
    }

    public function test_reopen_preserves_unrelated_fields_and_recompletion_uses_a_new_instant(): void
    {
        $subject = Subject::factory()->create();
        $task = Task::factory()->for($subject->user)->for($subject)->create(['title' => 'Original', 'description' => 'Keep', 'priority' => 1, 'estimated_minutes' => 50, 'deadline_at' => '2026-10-10 12:00:00', 'status' => TaskStatus::Completed, 'completed_at' => '2026-10-10 09:00:00'])->refresh();
        $before = $task->getAttributes();
        $this->actingAs($subject->user, 'web')->postJson('/api/tasks/'.$task->id.'/reopen', [])->assertOk()->assertExactJson(['data' => ['id' => $task->id, 'subject_id' => $subject->id, 'title' => 'Original', 'description' => 'Keep', 'status' => 'pending', 'priority' => 1, 'estimated_minutes' => 50, 'deadline_at' => '2026-10-10T12:00:00Z', 'completed_at' => null]]);
        $task->refresh();
        $this->assertSame(Arr::except($before, ['status', 'completed_at', 'updated_at']), Arr::except($task->getAttributes(), ['status', 'completed_at', 'updated_at']));
        $this->travelTo(CarbonImmutable::parse('2026-10-13T11:22:33.654321Z'));
        $this->postJson('/api/tasks/'.$task->id.'/complete')->assertOk()->assertJsonPath('data.completed_at', '2026-10-13T11:22:33Z');
        $this->assertSame('2026-10-13 11:22:33', $task->refresh()->getRawOriginal('completed_at'));
    }

    #[DataProvider('operations')]
    public function test_repeated_operation_is_idempotent_without_any_update_query(string $operation): void
    {
        $task = $this->taskForOperation($operation);
        $this->actingAs($task->user, 'web')->postJson('/api/tasks/'.$task->id.'/'.$operation)->assertOk();
        $before = $task->refresh()->getAttributes();
        $this->travelTo(CarbonImmutable::parse('2026-10-15T15:00:00Z'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson('/api/tasks/'.$task->id.'/'.$operation)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame([], array_values(array_filter($queries, fn ($query) => str_starts_with(strtolower($query['query']), 'update '))));
    }

    public function test_completion_clock_is_utc_even_with_different_server_and_user_timezones(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Los_Angeles']);
        $task = Task::factory()->for($user)->create();
        $originalTimezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $this->travelTo(CarbonImmutable::parse('2026-10-12T13:20:30.999999+03:00'));
            $this->actingAs($user, 'web')->postJson('/api/tasks/'.$task->id.'/complete')->assertOk()->assertJsonPath('data.completed_at', '2026-10-12T10:20:30Z');
            $this->assertSame('2026-10-12 10:20:30', $task->refresh()->getRawOriginal('completed_at'));
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[DataProvider('operations')]
    public function test_direct_service_calls_do_not_mutate_soft_deleted_tasks(string $operation): void
    {
        $task = $this->taskForOperation($operation);
        $user = $task->user;
        $task->delete();
        $before = $task->refresh()->getAttributes();
        try {
            app(TaskService::class)->{$operation}($user, $task);
            $this->fail('A deleted task must be excluded from scoped reloading.');
        } catch (ModelNotFoundException) {
            $this->assertSame($before, $task->refresh()->getAttributes());
        }
    }
}
