<?php

namespace Tests\Feature;

use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminders\ReminderDeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\AssertsProgressComparison;
use Tests\TestCase;

#[Group('workflow')]
#[Group('mysql')]
class CompactProgressComparisonIntegrationTest extends TestCase
{
    use AssertsProgressComparison, DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14T08:00:00.123456Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_real_lifecycle_fixture_rescheduling_known_actuals_and_reminder_inbox_state_reconcile(): void
    {
        $user = User::factory()->create();
        $task = Task::factory()->for($user)->create(['estimated_minutes' => 240, 'deadline_at' => '2026-10-18 12:00:00']);
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 240, 'deadline_at' => '2026-10-17 12:00:00']);
        foreach (range(1, 7) as $day) {
            StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => $day, 'starts_at' => '09:00:00', 'ends_at' => '18:00:00']);
        }
        $original = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-10-15 09:00:00', 'ends_at' => '2026-10-15 10:00:00']);
        $completed = StudySession::factory()->for($task)->create(['subtask_id' => $child->id, 'starts_at' => '2026-10-15 10:00:00', 'ends_at' => '2026-10-15 11:00:00']);
        $this->actingAs($user, 'web');
        $sourceReminder = $this->postJson('/api/reminders', ['study_session_id' => $original->id, 'trigger_at' => '2026-10-15T08:45:00Z'])->assertCreated()->json('data.id');
        $sent = $this->postJson('/api/reminders', ['message' => 'Persist inbox state', 'trigger_at' => '2026-10-14T08:00:01Z'])->assertCreated()->json('data.id');
        $this->travelTo(CarbonImmutable::parse('2026-10-14T08:00:01.500000Z'));
        $this->assertSame(['status' => 'sent'], app(ReminderDeliveryService::class)->process($user->id, $sent));
        $this->postJson('/api/study-sessions/'.$original->id.'/reschedule', ['starts_at' => '2026-10-16T09:00:00Z', 'ends_at' => '2026-10-16T10:00:00Z'])->assertCreated();
        $this->getJson('/api/reminders/'.$sourceReminder)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00.123456Z'));
        $this->postJson('/api/study-sessions/'.$completed->id.'/complete', ['actual_minutes' => 25])->assertOk();
        $this->postJson('/api/tasks/'.$task->id.'/complete', $this->getJson('/api/tasks/'.$task->id.'/completion-review')->assertOk()->json())->assertOk();
        $this->postJson('/api/tasks/'.$task->id.'/subtasks/'.$child->id.'/complete')->assertOk();
        $baseline = $this->emptyMetrics();
        $baseline['tasks_completed'] = $baseline['subtasks_completed'] = ['total' => 1, 'on_time' => 1, 'late' => 0, 'without_deadline' => 0];
        $baseline['study_sessions']['completed'] = $baseline['study_sessions']['rescheduled'] = ['count' => 1, 'scheduled_seconds' => 3600];
        $baseline['study_sessions']['completed_actual_minutes'] = 25;
        $baseline['study_sessions']['completed_with_actual_minutes'] = 1;
        $current = $this->emptyMetrics();
        $current['study_sessions']['cancelled'] = ['count' => 1, 'scheduled_seconds' => 3600];
        Bus::fake();
        Notification::fake();
        Http::fake();
        $snapshot = $this->snapshot();
        $url = $this->url(['baseline_start_date' => '2026-10-15', 'baseline_end_date' => '2026-10-15', 'current_start_date' => '2026-10-16', 'current_end_date' => '2026-10-16']);
        $data = $this->getJson($url)->assertOk()->json('data');
        $this->assertSame($baseline, $data['baseline']['totals']);
        $this->assertSame($current, $data['current']['totals']);
        $this->assertSame(-25, $data['delta']['study_sessions']['completed_actual_minutes']);
        $this->assertSame('decreased', $data['observations']['known_actual_minutes']);
        $this->assertSame($data, $this->getJson($url)->assertOk()->json('data'));
        $this->reconcile($data);
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertNull($user->notifications()->sole()->read_at);
        Bus::assertNothingDispatched();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }
}
