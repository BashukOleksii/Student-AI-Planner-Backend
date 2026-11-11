<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AI\Tools\RecoveryReadTools;
use App\Services\Planning\RecoverySuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithSpaBrowser;
use Tests\TestCase;

#[Group('mysql')]
#[Group('workflow')]
#[Group('recovery')]
class AcademicRecoveryWorkflowTest extends TestCase
{
    use DatabaseTruncation, InteractsWithSpaBrowser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T04:00:00Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    #[Group('smoke')]
    public function test_registered_students_plan_recover_academic_conflicts_and_read_confirmed_state_without_inventing_completion(): void
    {
        $student = $this->registerBrowser();
        $owner = User::findOrFail($student['id']);
        $this->browser('GET', '/api/profile')->assertOk()->assertJsonPath('data.id', $owner->id);
        $subject = $this->browser('POST', '/api/subjects', ['name' => 'Mathematics'])->assertCreated()->json('data.id');
        $teacher = $this->browser('POST', '/api/teachers', ['name' => 'Professor'])->assertCreated()->json('data.id');
        $institution = $this->browser('POST', '/api/education-institutions', ['name' => 'University'])->assertCreated()->json('data.id');
        $period = $this->browser('POST', '/api/academic-periods', ['education_institution_id' => $institution, 'name' => 'Autumn', 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-25'])
            ->assertCreated()->json('data.id');
        $schedule = $this->browser('POST', '/api/semester-schedules', ['academic_period_id' => $period, 'group_name' => 'Student',
            'parity_anchor_on' => '2026-10-19', 'first_week_parity' => 'numerator'])->assertCreated()->json('data.schedule.id');
        $scheduleBase = '/api/semester-schedules/'.$schedule;
        $token = fn () => $this->browser('GET', $scheduleBase.'/management')->assertOk()->json('meta.schedule_token');
        $rule = ['weekday' => 1, 'week_pattern' => 'all', 'pair_number' => 1, 'starts_at_local' => '12:00:00', 'ends_at_local' => '13:00:00',
            'subject_id' => $subject, 'teacher_id' => $teacher, 'lesson_type' => null];
        $rules = $this->browser('POST', $scheduleBase.'/weekly-rules', [...$rule, 'schedule_token' => $token()])
            ->assertCreated()->json('data.weekly_rules');
        $ruleId = $rules[0]['id'];
        $this->browser('PUT', '/api/planning-preferences', ['min_session_minutes' => 30, 'max_session_minutes' => 120,
            'preferred_break_minutes' => 15, 'max_daily_study_minutes' => 240, 'max_weekly_study_minutes' => 1200])->assertOk();
        $this->browser('POST', '/api/study-availability-windows', ['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '17:00:00'])->assertCreated();
        $task = $this->browser('POST', '/api/tasks', ['title' => 'Prepare exercises', 'subject_id' => $subject, 'priority' => 3,
            'estimated_minutes' => 60, 'deadline_at' => '2026-10-19T14:00:00Z'])->assertCreated()->json('data.id');
        $range = ['start_date' => '2026-10-19', 'end_date' => '2026-10-19'];
        $this->browser('GET', '/api/planning/free-slots?'.http_build_query($range))->assertOk()->assertJsonPath('data.0.starts_at', '2026-10-19T06:00:00Z');
        $preview = $this->browser('POST', '/api/planning/study-plans/preview', [...$range, 'task_id' => $task])->assertOk()->json('data');
        $this->assertDatabaseCount('study_sessions', 0);
        $source = $this->browser('POST', '/api/planning/study-plans/confirm', ['preview_token' => $preview['preview_token']])
            ->assertCreated()->json('data.sessions.0');
        $this->assertSame('2026-10-19T06:00:00Z', $source['starts_at']);
        $reminder = $this->browser('POST', '/api/reminders', ['study_session_id' => $source['id'],
            'trigger_at' => '2026-10-19T05:45:00Z', 'message' => 'Prepare'])->assertCreated()->json('data.id');
        $this->browser('PATCH', $scheduleBase.'/weekly-rules/'.$ruleId, ['starts_at_local' => '09:00:00', 'ends_at_local' => '10:00:00',
            'schedule_token' => $token()])->assertOk();
        $this->assertDatabaseHas('study_sessions', ['id' => $source['id'], 'status' => 'planned']);
        $calendar = $this->browser('GET', '/api/academic-calendar/today')->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertSame('semester_occurrence', $calendar[0]['source_type']);
        $this->assertSame('created', app(RecoverySuggestionService::class)->detect($owner->id));
        $row = $owner->recoverySuggestions()->where('status', 'pending')->sole();
        $this->assertSame('replan', $row->kind);
        $path = '/api/planning/recovery-suggestions/'.$row->id;
        $this->browser('GET', $path)->assertOk()->assertJsonPath('data.actionable', true);
        $this->assertDatabaseCount('study_sessions', 1);
        $receipt = $this->browser('POST', $path.'/confirm')->assertOk()->assertJsonPath('data.status', 'applied')->json('data.receipt');
        $this->assertSame($receipt, $this->browser('POST', $path.'/confirm')->assertOk()->assertJsonPath('replayed', true)->json('data.receipt'));
        $this->assertDatabaseCount('study_sessions', 2);
        $this->assertDatabaseHas('study_sessions', ['id' => $source['id'], 'status' => 'rescheduled']);
        $this->browser('GET', '/api/reminders/'.$reminder)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $statistics = $this->browser('GET', '/api/statistics/progress?'.http_build_query($range))->assertOk()->json('data');
        $this->assertSame(0, $statistics['tasks']['completed_in_period']['total']);
        $this->assertSame(0, $statistics['study_sessions']['started_in_period']['completed_actual_minutes']);
        $this->assertSame(0, DB::table('study_sessions')->whereNotNull('actual_minutes')->count());
        $before = DB::table('study_sessions')->orderBy('id')->get()->toJson();
        $read = app(RecoveryReadTools::class)->execute($owner, 'get_recovery_suggestion', ['suggestion_id' => $row->id]);
        $this->assertTrue($read['ok']);
        $this->assertSame('applied', $read['data']['status']);
        $this->assertSame($receipt, $read['data']['receipt']);
        $this->assertSame($before, DB::table('study_sessions')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('notifications', 0);
    }
}
