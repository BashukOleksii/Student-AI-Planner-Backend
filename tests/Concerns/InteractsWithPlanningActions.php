<?php

namespace Tests\Concerns;

use App\Models\AiConversation;
use App\Models\Lesson;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait InteractsWithPlanningActions
{
    use InteractsWithAiResponses;

    protected function planningFixture(string $name = 'propose_study_plan_create', ?User $owner = null): array
    {
        $owner ??= User::factory()->create(['timezone' => 'UTC']);
        $conversation = AiConversation::factory()->for($owner)->create();
        $date = CarbonImmutable::now('UTC')->addDays(2)->startOfDay();
        $task = Task::factory()->for($owner)->create(['estimated_minutes' => 240, 'deadline_at' => $date->setHour(23)]);
        $window = StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => $date->isoWeekday(), 'starts_at' => '09:00:00', 'ends_at' => '18:00:00']);
        $source = $lesson = $reminder = null;
        if ($name !== 'propose_study_plan_create') {
            $source = StudySession::factory()->for($task)->create(['starts_at' => $date->setHour(10), 'ends_at' => $date->setHour(11)]);
            $reminder = app(ReminderService::class)->create($owner, ['study_session_id' => $source->id, 'trigger_at' => $date->setHour(9)->format('Y-m-d\TH:i:s\Z')]);
            if ($name === 'propose_adaptive_replanning') {
                $lesson = Lesson::factory()->for($owner)->create(['starts_at' => $date->setHour(10), 'ends_at' => $date->setHour(11)]);
            }
        }
        $arguments = $name === 'propose_session_reschedule'
            ? ['study_session_id' => $source->id, 'starts_at' => $date->setHour(14)->format('Y-m-d\TH:i:s\Z'), 'ends_at' => $date->setHour(15)->format('Y-m-d\TH:i:s\Z')]
            : ['start_date' => $date->toDateString(), 'end_date' => $date->toDateString()];
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner, 'web');

        return compact('owner', 'conversation', 'date', 'task', 'window', 'source', 'lesson', 'reminder', 'arguments', 'name');
    }

    protected function proposePlanning(array $f, ?array $arguments = null, ?string $requestId = null)
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push($this->aiResponse([$this->aiText('Already applied!'), $this->aiCall($f['name'], json_encode($arguments ?? $f['arguments'], JSON_THROW_ON_ERROR), 'call_'.str_replace('-', '', (string) Str::uuid()))]))
            ->push($this->aiResponse([$this->aiText('Successfully scheduled and delivered!')]));

        return $this->postJson('/api/ai/conversations/'.$f['conversation']->id.'/messages', ['content' => 'Review one planning change.'],
            ['Idempotency-Key' => $requestId ?? (string) Str::uuid()]);
    }

    protected function planningUrl(array $f, string $id, string $operation = ''): string
    {
        return '/api/ai/conversations/'.$f['conversation']->id.'/actions/'.$id.($operation === '' ? '' : '/'.$operation);
    }

    protected function confirmPlanning(array $f, string $id, array $consent = [], string $operation = 'confirm')
    {
        return $this->call('POST', $this->planningUrl($f, $id, $operation), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($consent === [] ? (object) [] : $consent, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    protected function planningSnapshot(): array
    {
        $snapshot = [];
        foreach (['tasks', 'subtasks', 'planning_preferences', 'study_availability_windows', 'lessons', 'study_sessions', 'reminders', 'notifications'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy($table === 'planning_preferences' ? 'user_id' : 'id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}
