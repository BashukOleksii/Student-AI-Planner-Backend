<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\AiConversation;
use App\Models\EducationInstitution;
use App\Models\Lesson;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subject;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AI\LlmProvider;
use App\Services\Reminders\ReminderDeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('smoke')]
#[Group('mysql')]
class CompactSmokeTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(DB::selectOne('SELECT UTC_TIMESTAMP(6) AS clock')->clock, 'UTC'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function fixture(): array
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->actingAs($user, 'web');
        $day = now('UTC')->addDays(3)->startOfDay();
        $institution = EducationInstitution::factory()->for($user)->create(['name' => 'University']);
        $period = AcademicPeriod::factory()->for($user)->create(['name' => 'Semester', 'starts_on' => $day->copy()->subMonth()->toDateString(), 'ends_on' => $day->copy()->addMonth()->toDateString()]);
        $subject = Subject::factory()->for($user)->create(['name' => 'Calculus']);
        $teacher = Teacher::factory()->for($user)->create(['name' => 'Teacher']);
        $task = Task::factory()->for($user)->create(['title' => 'Read calculus', 'estimated_minutes' => 60, 'deadline_at' => $day->copy()->setHour(20)]);
        $child = Subtask::factory()->for($task)->create(['title' => 'Differentiation', 'estimated_minutes' => 40]);
        $lesson = Lesson::factory()->for($user)->for($subject)->create(['room' => '301', 'starts_at' => $day->copy()->setHour(8), 'ends_at' => $day->copy()->setHour(9)]);
        PlanningPreference::factory()->for($user)->create(['min_session_minutes' => 15, 'max_session_minutes' => 30]);
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $day->isoWeekday(), 'starts_at' => '10:00:00', 'ends_at' => '18:00:00']);
        StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => $day->copy()->setHour(15), 'ends_at' => $day->copy()->setHour(15)->addMinutes(20)]);
        Reminder::factory()->for($user)->create(['message' => 'Study', 'trigger_at' => $day->copy()->setHour(7)]);
        AiConversation::factory()->for($user)->create();

        return compact('user', 'day', 'institution', 'period', 'subject', 'teacher', 'task', 'lesson');
    }

    #[DataProvider('reads')]
    public function test_current_domain_resource_is_visible_through_its_rest_contract(string $area): void
    {
        $f = $this->fixture();
        $date = $f['day']->toDateString();
        [$url, $path, $expected] = match ($area) {
            'tasks' => ['/api/tasks', 'data.0.title', 'Read calculus'],
            'task' => ['/api/tasks/'.$f['task']->id, 'data.title', 'Read calculus'],
            'subtasks' => ['/api/tasks/'.$f['task']->id.'/subtasks', 'data.0.title', 'Differentiation'],
            'progress' => ['/api/tasks/'.$f['task']->id.'/progress', 'data.total_subtasks', 1],
            'subjects' => ['/api/subjects', 'data.0.name', 'Calculus'],
            'teachers' => ['/api/teachers', 'data.0.name', 'Teacher'],
            'institutions' => ['/api/education-institutions', 'data.0.name', 'University'],
            'periods' => ['/api/academic-periods', 'data.0.name', 'Semester'],
            'lesson' => ['/api/lessons/'.$f['lesson']->id, 'data.room', '301'],
            'schedule' => ['/api/schedule/date/'.$date, 'data.0.room', '301'],
            'availability' => ['/api/study-availability-windows', 'data.0.starts_at', '10:00:00'],
            'preferences' => ['/api/planning-preferences', 'data.min_session_minutes', 15],
            'sessions' => ['/api/study-sessions?start_date='.$date.'&end_date='.$date, 'data.0.status', 'planned'],
            'reminders' => ['/api/reminders', 'data.0.message', 'Study'],
            'conversations' => ['/api/ai/conversations', 'data.0.id', AiConversation::sole()->id],
            'notifications' => ['/api/notifications', 'data', []],
        };
        $this->getJson($url)->assertOk()->assertJsonPath($path, $expected);
        $this->assertSame('pending', $f['task']->fresh()->status->value);
        $this->assertDatabaseCount('study_sessions', 1);
    }

    public static function reads(): array
    {
        $names = ['tasks', 'task', 'subtasks', 'progress', 'subjects', 'teachers', 'institutions', 'periods', 'lesson', 'schedule', 'availability', 'preferences', 'sessions', 'reminders', 'conversations', 'notifications'];

        return array_combine($names, array_map(fn ($name) => [$name], $names));
    }

    public function test_normal_login_profile_and_logout_use_the_session(): void
    {
        $user = User::factory()->create(['password' => 'study-password']);
        $this->withHeader('Origin', 'http://localhost:5173')->withCredentials();
        config(['sanctum.stateful' => ['localhost:5173']]);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'study-password'])->assertOk()->assertJsonPath('data.id', $user->id);
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('data.email', $user->email);
        $this->postJson('/api/auth/logout')->assertNoContent();
    }

    public function test_confirmed_plan_reserves_remaining_child_effort(): void
    {
        $f = $this->fixture();
        $date = $f['day']->toDateString();
        $preview = $this->postJson('/api/planning/study-plans/preview', ['start_date' => $date, 'end_date' => $date])->assertOk()->json('data');
        $this->assertDatabaseCount('study_sessions', 1);
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $preview['preview_token']])->assertCreated();
        $this->assertSame(40, (int) StudySession::sum(DB::raw('TIMESTAMPDIFF(MINUTE, starts_at, ends_at)')));
        $this->getJson('/api/tasks/'.$f['task']->id.'/progress')->assertOk()->assertJsonPath('data.completed_subtasks', 0);
    }

    public function test_due_reminder_delivers_once_and_can_be_marked_read(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $reminder = Reminder::factory()->for($user)->create(['message' => 'Study now', 'trigger_at' => now('UTC')->subSecond()]);
        $service = app(ReminderDeliveryService::class);
        $this->assertSame(['status' => 'sent'], $service->process($user->id, $reminder->id));
        $service->dispatchDue();
        $notification = $user->notifications()->sole();
        $this->postJson('/api/notifications/'.$notification->id.'/read')->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame('sent', $reminder->fresh()->status->value);
    }

    public function test_scripted_provider_uses_real_task_tool_and_confirmation_service(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $conversation = AiConversation::factory()->for($user)->create();
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->exactly(2))->method('respond')->willReturnOnConsecutiveCalls(['text' => null, 'calls' => [['id' => 'create-task', 'name' => 'propose_task_create', 'arguments' => ['title' => 'Read analysis', 'estimated_minutes' => 45]]], 'state' => []], ['text' => 'Review the task.', 'calls' => [], 'state' => []]);
        $this->app->instance(LlmProvider::class, $provider);
        $base = '/api/ai/conversations/'.$conversation->id;
        $turn = $this->postJson($base.'/messages', ['content' => 'Create a task to read analysis.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
        $this->assertDatabaseCount('tasks', 0);
        $action = $base.'/actions/'.$turn['action']['id'].'/confirm';
        $receipt = $this->json('POST', $action, [], [], JSON_FORCE_OBJECT)->assertOk()->assertJsonPath('data.status', 'executed')->json('data.receipt');
        $this->assertSame('Read analysis', Task::sole()->title);
        $this->assertSame(Task::sole()->id, $receipt['task']['id']);
        $this->json('POST', $action, [], [], JSON_FORCE_OBJECT)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.receipt', $receipt);
        $this->assertDatabaseCount('tasks', 1);
    }
}
