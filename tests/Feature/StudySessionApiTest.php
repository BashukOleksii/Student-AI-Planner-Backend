<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudySessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_uses_half_open_overlap_status_independence_and_stable_start_id_order_without_writes(): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $task = Task::factory()->for($owner)->create();
        $child = Subtask::factory()->for($task)->create();
        $before = $this->persistedSession($task, '2026-10-18 23:00:00', '2026-10-19 00:00:00');
        $after = $this->persistedSession($task, '2026-10-20 00:00:00', '2026-10-20 01:00:00');
        $spanning = $this->persistedSession($task, '2026-10-18 23:59:59', '2026-10-19 00:00:01');
        $expected = [$spanning->id];
        foreach (StudySessionStatus::cases() as $status) {
            $record = $this->persistedSession($task, '2026-10-19 09:00:00', '2026-10-19 09:30:01', ['status' => $status,
                'subtask_id' => $child->id, 'completed_at' => $status === StudySessionStatus::Completed ? '2026-10-19 09:30:01' : null,
                'actual_minutes' => $status === StudySessionStatus::Completed ? 1 : null]);
            $expected[] = $record->id;
        }
        $this->persistedSession(Task::factory()->create(), '2026-10-19 09:00:00', '2026-10-19 10:00:00');
        $snapshot = StudySession::orderBy('id')->get()->toArray();
        $this->actingAs($owner, 'web');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->getJson($this->url())->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($expected, array_column($response->json('data'), 'id'));
        $this->assertSame(['start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'timezone' => 'UTC'], $response->json('meta'));
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
            $this->assertStringNotContainsString('for update', $query['query']);
        }
        $this->assertSame($snapshot, StudySession::orderBy('id')->get()->toArray());
        foreach ($response->json('data') as $record) {
            $this->assertSame(['id', 'task_id', 'subtask_id', 'starts_at', 'ends_at', 'duration_seconds', 'status', 'completed_at', 'actual_minutes'], array_keys($record));
        }
        $this->assertSame(1801, $response->json('data.1.duration_seconds'));
        $child->delete();
        $task->delete();
        $this->assertSame($expected, array_column($this->getJson($this->url())->assertOk()->json('data'), 'id'));
    }

    public function test_detail_scopes_before_policy_and_foreign_or_missing_ids_are_generic(): void
    {
        $owner = User::factory()->create();
        $foreign = $this->persistedSession(Task::factory()->create(), '2026-10-19 09:00:00', '2026-10-19 10:00:00');
        $owned = $this->persistedSession(Task::factory()->for($owner)->create(), '2026-10-19 09:00:00', '2026-10-19 10:00:00');
        $this->actingAs($owner, 'web');
        $this->assertTrue(Gate::allows('view', $owned));
        $this->assertFalse(Gate::allows('view', $foreign));
        foreach ([$foreign->id, PHP_INT_MAX, 0, 'invalid'] as $id) {
            $this->getJson('/api/study-sessions/'.$id)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        }
        $this->getJson('/api/study-sessions/'.$owned->id)->assertOk()->assertJsonPath('data.id', $owned->id);
    }

    #[DataProvider('calendarCases')]
    public function test_list_date_range_uses_exact_local_membership_across_dst_and_skipped_dates(string $zone, string $date, string $start, string $end, bool $included): void
    {
        $user = User::factory()->create(['timezone' => $zone]);
        $session = $this->persistedSession(Task::factory()->for($user)->create(), $start, $end);
        $this->actingAs($user, 'web');
        $data = $this->getJson($this->url(['start_date' => $date, 'end_date' => $date]))->assertOk()->json('data');
        $this->assertSame($included ? [$session->id] : [], array_column($data, 'id'));
    }

    public static function calendarCases(): array
    {
        return [
            ['Europe/Kyiv', '2026-03-29', '2026-03-28 21:59:59', '2026-03-28 22:00:01', true],
            ['Europe/Kyiv', '2026-03-29', '2026-03-29 21:00:00', '2026-03-29 22:00:00', false],
            ['Europe/Kyiv', '2026-10-25', '2026-10-25 21:59:59', '2026-10-25 22:00:01', true],
            ['Europe/Kyiv', '2026-10-25', '2026-10-25 22:00:00', '2026-10-25 23:00:00', false],
            ['Pacific/Apia', '2011-12-30', '2011-12-30 09:00:00', '2011-12-30 11:00:00', false],
            ['America/Juneau', '1867-10-18', '1867-10-18 10:00:00', '1867-10-18 11:00:00', false],
            ['America/Juneau', '1867-10-18', '1867-10-19 01:00:00', '1867-10-19 02:00:00', true],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_list_requires_canonical_bounded_query_dates(array $parameters, string $field): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url($parameters))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidRanges(): array
    {
        return [[['start_date' => ' 2026-10-19'], 'start_date'], [['start_date' => '2026-10-19 '], 'start_date'],
            [['end_date' => '2026-02-29'], 'end_date'], [['end_date' => '2026-10-18'], 'end_date'],
            [['end_date' => '2026-11-19'], 'end_date'], [['user_id' => 999], 'user_id']];
    }

    public function test_get_body_cannot_supply_dates_and_31_dates_are_accepted(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->json('GET', '/api/study-sessions', ['start_date' => '2026-10-19', 'end_date' => '2026-10-19'])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        $this->getJson($this->url(['end_date' => '2026-11-18']))->assertOk()->assertJsonPath('data', []);
    }

    public function test_corrupt_owned_work_references_do_not_expose_foreign_identifiers_on_reads(): void
    {
        $owner = User::factory()->create();
        $session = $this->persistedSession(Task::factory()->create(), '2026-10-19 09:00:00', '2026-10-19 10:00:00', ['user_id' => $owner->id]);
        $this->actingAs($owner, 'web');
        foreach ([$this->url(), '/api/study-sessions/'.$session->id] as $url) {
            $this->getJson($url)->assertStatus(500)->assertExactJson(['message' => 'The study plan could not be processed.']);
        }
    }

    private function url(array $parameters = []): string
    {
        return '/api/study-sessions?'.http_build_query(array_replace(['start_date' => '2026-10-19', 'end_date' => '2026-10-19'], $parameters));
    }

    private function persistedSession(Task $task, string $start, string $end, array $values = []): StudySession
    {
        return StudySession::factory()->for($task)->create(['starts_at' => $start, 'ends_at' => $end, ...$values]);
    }
}
