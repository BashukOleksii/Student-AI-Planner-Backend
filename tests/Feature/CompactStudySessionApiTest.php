<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactStudySessionApiTest extends TestCase
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
            $record = $this->persistedSession($task, '2026-10-19 09:00:00', '2026-10-19 09:30:01', ['status' => $status, 'subtask_id' => $child->id, 'completed_at' => $status === StudySessionStatus::Completed ? '2026-10-19 09:30:01' : null, 'actual_minutes' => $status === StudySessionStatus::Completed ? 1 : null]);
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
            $this->assertSame(['id', 'task_id', 'subtask_id', 'starts_at', 'ends_at', 'duration_seconds', 'status', 'completed_at', 'status_label', 'actual_minutes'], array_keys($record));
        }
        $this->assertSame(1801, $response->json('data.1.duration_seconds'));
        $child->delete();
        $task->delete();
        $this->assertSame($expected, array_column($this->getJson($this->url())->assertOk()->json('data'), 'id'));
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
        return [0 => ['Europe/Kyiv', '2026-03-29', '2026-03-28 21:59:59', '2026-03-28 22:00:01', true], 6 => ['America/Juneau', '1867-10-18', '1867-10-19 01:00:00', '1867-10-19 02:00:00', true]];
    }

    #[DataProvider('invalidRanges')]
    public function test_list_requires_canonical_bounded_query_dates(array $parameters, string $field): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url($parameters))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidRanges(): array
    {
        return [0 => [['start_date' => ' 2026-10-19'], 'start_date'], 5 => [['user_id' => 999], 'user_id']];
    }

    public function test_get_body_cannot_supply_dates_and_31_dates_are_accepted(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->json('GET', '/api/study-sessions', ['start_date' => '2026-10-19', 'end_date' => '2026-10-19'])->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);
        $this->getJson($this->url(['end_date' => '2026-11-18']))->assertOk()->assertJsonPath('data', []);
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
