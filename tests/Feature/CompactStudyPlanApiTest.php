<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Models\PlanningPreference;
use App\Models\Reminder;
use App\Models\StudyAvailabilityWindow;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\FreeTimeService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\StudyPlanningService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactStudyPlanApiTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_preview_is_actual_stage_b_with_public_utc_resource_encrypted_token_and_no_writes(): void
    {
        [$user, $task] = $this->fixture();
        Reminder::factory()->for($user)->create(['task_id' => $task->id]);
        $expected = app(StudyPlanningService::class)->plan($user, '2026-10-19', '2026-10-19');
        $snapshot = $this->snapshot();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $this->preview();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
            $this->assertStringNotContainsString('for update', $query['query']);
        }
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertSame($expected['summary'], $data['summary']);
        $this->assertSame($expected['work_results'], array_map(fn ($row) => Arr::except($row, ['explanations', 'effective_deadline_at']), $data['work_results']));
        $this->assertSame([], $data['work_results'][0]['explanations']);
        $this->assertSame(['task_id' => $task->id, 'subtask_id' => null, 'starts_at' => '2026-10-19T09:00:00Z', 'ends_at' => '2026-10-19T10:00:00Z', 'duration_seconds' => 3600], $data['sessions'][0]);
        $this->assertSame('2026-10-19T08:15:00Z', $data['expires_at']);
        $this->assertIsString($data['preview_token']);
        $this->assertStringNotContainsString('2026-10-19', base64_decode($data['preview_token']));
        $this->assertArrayNotHasKey('user_id', $data);
    }

    public function test_full_plan_is_created_with_exact_fields_and_no_other_application_mutations(): void
    {
        [$user, $task] = $this->fixture();
        $child = Subtask::factory()->for($task)->create(['estimated_minutes' => 60]);
        PlanningPreference::factory()->for($user)->create(['max_session_minutes' => 30, 'preferred_break_minutes' => 15]);
        Reminder::factory()->for($user)->create(['subtask_id' => $child->id]);
        $data = $this->preview(['task_id' => $task->id, 'subtask_id' => $child->id]);
        $snapshot = $this->snapshot(['study_sessions', 'recovery_scan_states', 'jobs']);
        $scanVersion = DB::table('recovery_scan_states')->where('user_id', $user->id)->value('requested_version');
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:01:00.250000Z'));
        $saved = $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token']])->assertCreated()->assertJsonPath('data.summary.fully_allocated', true)->json('data');
        $this->assertArrayNotHasKey('preview_token', $saved);
        $this->assertSame($data['work_results'], $saved['work_results']);
        $this->assertSame($snapshot, $this->snapshot(['study_sessions', 'recovery_scan_states', 'jobs']));
        $this->assertSame($scanVersion + 1, DB::table('recovery_scan_states')->where('user_id', $user->id)->value('requested_version'));
        $this->assertCount(2, $saved['sessions']);
        foreach (StudySession::orderBy('id')->get() as $index => $session) {
            $this->assertSame($user->id, $session->user_id);
            $this->assertSame($task->id, $session->task_id);
            $this->assertSame($child->id, $session->subtask_id);
            $this->assertSame(StudySessionStatus::Planned, $session->status);
            $this->assertNull($session->completed_at);
            $this->assertNull($session->actual_minutes);
            $this->assertNull($session->rescheduled_from_session_id);
            $this->assertSame($saved['sessions'][$index]['id'], $session->id);
            $this->assertSame($data['sessions'][$index]['starts_at'], $saved['sessions'][$index]['starts_at']);
            $this->getJson('/api/study-sessions/'.$session->id)->assertOk()->assertExactJson(['data' => $saved['sessions'][$index]]);
        }
        $context = app(PlanningContextService::class)->load($user, '2026-10-19', '2026-10-19');
        $free = app(FreeTimeService::class)->calculate($user, '2026-10-19', '2026-10-19');
        $this->assertSame($free, $context['free_intervals']);
        $this->assertSame([['2026-10-19T09:30:00Z', '2026-10-19T09:45:00Z'], ['2026-10-19T10:15:00Z', '2026-10-19T12:00:00Z']], array_map(fn ($interval) => [gmdate('Y-m-d\TH:i:s\Z', $interval['start']), gmdate('Y-m-d\TH:i:s\Z', $interval['end'])], $free));
        $this->assertSame([], app(StudyPlanningService::class)->plan($user, '2026-10-19', '2026-10-19')['sessions']);
        $this->assertSame(0, app(StudyPlanningService::class)->plan($user, '2026-10-19', '2026-10-19')['summary']['requested_seconds']);
    }

    public function test_partial_plan_requires_explicit_true_and_persists_only_the_reviewed_allocation(): void
    {
        [$user] = $this->fixture(240);
        $data = $this->preview();
        $this->assertFalse($data['summary']['fully_allocated']);
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token']])->assertUnprocessable()->assertJsonValidationErrors('accept_partial');
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token'], 'accept_partial' => false])->assertUnprocessable()->assertJsonValidationErrors('accept_partial');
        $this->assertDatabaseCount('study_sessions', 0);
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token'], 'accept_partial' => true])->assertCreated()->assertJsonPath('data.summary.unscheduled_seconds', 3600)->assertJsonPath('data.summary.allocated_seconds', 10800)->assertJsonPath('data.summary.fully_allocated', false);
        $this->assertDatabaseCount('study_sessions', 1);
        $this->assertSame(3600, app(StudyPlanningService::class)->plan($user, '2026-10-19', '2026-10-19')['summary']['requested_seconds']);
    }

    #[DataProvider('emptyCases')]
    public function test_empty_plan_is_a_valid_preview_but_cannot_be_confirmed(string $case): void
    {
        [$user, $task] = $this->fixture();
        match ($case) {
            'no availability' => $user->studyAvailabilityWindows()->delete(),
            'no work' => $task->delete(),
            'missing estimate' => $task->update(['estimated_minutes' => null]),
        };
        $data = $this->preview();
        $this->assertSame([], $data['sessions']);
        $this->postJson('/api/planning/study-plans/confirm', ['preview_token' => $data['preview_token'], 'accept_partial' => true])->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public static function emptyCases(): array
    {
        return [0 => ['no availability']];
    }

    #[DataProvider('invalidPreviewInput')]
    public function test_preview_input_is_exact_body_only_and_rejects_server_fields(array $values, string $field): void
    {
        $this->fixture();
        $this->call('POST', '/api/planning/study-plans/preview', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode(array_replace($this->parameters(), $values), JSON_PRESERVE_ZERO_FRACTION))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('study_sessions', 0);
    }

    public static function invalidPreviewInput(): array
    {
        return [0 => [['start_date' => ' 2026-10-19'], 'start_date']];
    }

    public function test_post_query_parameters_never_override_or_supply_body_input(): void
    {
        $this->fixture();
        $this->postJson('/api/planning/study-plans/preview?start_date=2026-10-19&end_date=2026-10-19', [])->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        $data = $this->postJson('/api/planning/study-plans/preview?task_id=999&start_date=bad', $this->parameters())->assertOk()->json('data');
        $this->postJson('/api/planning/study-plans/confirm?preview_token='.$data['preview_token'], [])->assertUnprocessable();
        $this->postJson('/api/planning/study-plans/confirm?accept_partial=bad', ['preview_token' => $data['preview_token']])->assertCreated();
    }

    private function fixture(int $estimate = 60): array
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        StudyAvailabilityWindow::factory()->for($user)->create(['day_of_week' => 1, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00']);
        $task = Task::factory()->for($user)->create(['estimated_minutes' => $estimate]);
        $this->actingAs($user, 'web');

        return [$user, $task];
    }

    private function parameters(): array
    {
        return ['start_date' => '2026-10-19', 'end_date' => '2026-10-19'];
    }

    private function preview(array $values = []): array
    {
        return $this->postJson('/api/planning/study-plans/preview', array_replace($this->parameters(), $values))->assertOk()->json('data');
    }

    private function snapshot(array $except = []): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            if (! in_array($table, $except, true)) {
                $rows = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
                usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
                $snapshot[$table] = $rows;
            }
        }
        ksort($snapshot);

        return $snapshot;
    }
}
