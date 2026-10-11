<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Subject;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskIndexTest extends TestCase
{
    use RefreshDatabase;

    private function url(array $filters = []): string
    {
        return '/api/tasks'.($filters === [] ? '' : '?'.http_build_query($filters));
    }

    public function test_authentication_is_required_even_for_invalid_filters(): void
    {
        $this->getJson('/api/tasks')->assertUnauthorized();
        $this->getJson($this->url(['status' => 'overdue']))->assertUnauthorized();
    }

    public function test_empty_collection_has_standard_pagination(): void
    {
        Task::factory()->create();
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/tasks')->assertOk()
            ->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('links.prev', null)->assertJsonPath('links.next', null)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total']]);
    }

    public function test_listing_is_owned_non_deleted_and_read_only_with_subject_masking(): void
    {
        $ownedSubject = Subject::factory()->create();
        $user = $ownedSubject->user;
        $foreignSubject = Subject::factory()->create(['name' => 'Private foreign subject']);
        $owned = Task::factory()->for($user)->for($ownedSubject)->create();
        $legacy = Task::factory()->for($user)->create(['subject_id' => $foreignSubject->id]);
        $noSubject = Task::factory()->for($user)->create();
        $foreign = Task::factory()->for($foreignSubject->user)->for($foreignSubject)->create();
        $deleted = Task::factory()->for($user)->create();
        $deleted->delete();
        $subtask = Subtask::factory()->for($owned)->create();
        $records = [$owned, $legacy, $noSubject, $foreign, $deleted, $ownedSubject, $foreignSubject, $subtask];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $response = $this->actingAs($user, 'web')->getJson($this->url([
            'user_id' => $foreign->user_id, 'owner_id' => $foreign->user_id, 'with_trashed' => 1,
        ]))->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame([$owned->id, $legacy->id, $noSubject->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.subject_id', $ownedSubject->id)->assertJsonPath('data.1.subject_id', null)
            ->assertJsonPath('data.2.subject_id', null)->assertJsonMissing(['name' => 'Private foreign subject']);
        foreach ($records as $index => $record) {
            $this->assertSame($before[$index], $record->refresh()->getAttributes());
        }
    }

    #[DataProvider('statusFilters')]
    public function test_status_filters(string $status): void
    {
        $user = User::factory()->create();
        $pending = Task::factory()->for($user)->create();
        $completed = Task::factory()->for($user)->create(['status' => TaskStatus::Completed, 'completed_at' => '2026-10-10 12:00:00']);
        $response = $this->actingAs($user, 'web')->getJson($this->url(['status' => $status]))->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame([$status === 'pending' ? $pending->id : $completed->id], array_column($response->json('data'), 'id'));
    }

    public static function statusFilters(): array
    {
        return [['pending'], ['completed']];
    }

    #[DataProvider('priorityFilters')]
    public function test_canonical_string_priority_filters(int $priority): void
    {
        $user = User::factory()->create();
        $tasks = [];
        foreach ([1, 2, 3] as $value) {
            $tasks[$value] = Task::factory()->for($user)->create(['priority' => $value]);
        }
        $response = $this->actingAs($user, 'web')->getJson($this->url(['priority' => (string) $priority]))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.priority', $priority);
        $this->assertSame([$tasks[$priority]->id], array_column($response->json('data'), 'id'));
    }

    public static function priorityFilters(): array
    {
        return [[1], [2], [3]];
    }

    public function test_subject_filter_is_owned_and_zero_matches_are_valid(): void
    {
        $subject = Subject::factory()->create();
        $emptySubject = Subject::factory()->for($subject->user)->create();
        $task = Task::factory()->for($subject->user)->for($subject)->create();
        Task::factory()->for($subject->user)->create();
        Task::factory()->create(['subject_id' => $subject->id]);
        $response = $this->actingAs($subject->user, 'web')->getJson($this->url(['subject_id' => (string) $subject->id]))
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame([$task->id], array_column($response->json('data'), 'id'));
        $this->getJson($this->url(['subject_id' => $emptySubject->id]))->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_foreign_and_nonexistent_subject_errors_are_identical_and_do_not_write(): void
    {
        $task = Task::factory()->create()->refresh();
        $foreign = Subject::factory()->create()->refresh();
        $before = $task->getAttributes();
        $foreignBefore = $foreign->getAttributes();
        $this->actingAs($task->user, 'web');
        $first = $this->getJson($this->url(['subject_id' => $foreign->id]))->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $second = $this->getJson($this->url(['subject_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->assertSame($first->json(), $second->json());
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertSame($foreignBefore, $foreign->refresh()->getAttributes());
    }

    #[DataProvider('deadlineFilters')]
    public function test_deadline_filters_are_inclusive_and_exclude_null_deadlines(array $filters, array $positions): void
    {
        $user = User::factory()->create();
        $tasks = [];
        foreach (['2026-10-01 12:00:00', '2026-10-02 12:00:00', '2026-10-03 12:00:00', null] as $deadline) {
            $tasks[] = Task::factory()->for($user)->create(['deadline_at' => $deadline]);
        }
        $response = $this->actingAs($user, 'web')->getJson($this->url($filters))->assertOk();
        $this->assertSame(array_map(fn ($position) => $tasks[$position]->id, $positions), array_column($response->json('data'), 'id'));
        $response->assertJsonPath('meta.total', count($positions));
    }

    public static function deadlineFilters(): array
    {
        return [
            'lower bound' => [['deadline_from' => '2026-10-02T12:00:00Z'], [1, 2]],
            'upper bound' => [['deadline_to' => '2026-10-02T12:00:00Z'], [0, 1]],
            'range' => [['deadline_from' => '2026-10-01T12:00:00Z', 'deadline_to' => '2026-10-02T12:00:00Z'], [0, 1]],
            'equal bounds' => [['deadline_from' => '2026-10-02T12:00:00Z', 'deadline_to' => '2026-10-02T12:00:00Z'], [1]],
            'no match' => [['deadline_from' => '2026-10-04T12:00:00Z'], []],
            'mysql bounds' => [['deadline_from' => '1000-01-01T00:00:00Z', 'deadline_to' => '9999-12-31T23:59:59Z'], [0, 1, 2]],
        ];
    }

    public function test_combined_filters_apply_and_semantics_in_stable_order(): void
    {
        $subject = Subject::factory()->create();
        $other = Subject::factory()->for($subject->user)->create();
        $values = ['subject_id' => $subject->id, 'priority' => 3, 'deadline_at' => '2026-10-02 12:00:00'];
        $first = Task::factory()->for($subject->user)->create($values);
        $second = Task::factory()->for($subject->user)->create($values);
        foreach ([['subject_id' => $other->id], ['priority' => 1], ['deadline_at' => null],
            ['deadline_at' => '2026-10-04 12:00:00'], ['status' => TaskStatus::Completed, 'completed_at' => '2026-10-02 12:00:00']] as $changes) {
            Task::factory()->for($subject->user)->create(array_replace($values, $changes));
        }
        $response = $this->actingAs($subject->user, 'web')->getJson($this->url([
            'status' => 'pending', 'priority' => '3', 'subject_id' => $subject->id,
            'deadline_from' => '2026-10-01T00:00:00Z', 'deadline_to' => '2026-10-03T23:59:59Z',
        ]))->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([$first->id, $second->id], array_column($response->json('data'), 'id'));
    }

    public function test_deadline_then_id_order_is_stable_across_pages_with_nulls_last(): void
    {
        $user = User::factory()->create();
        $nullFirst = Task::factory()->for($user)->create(['priority' => 3]);
        $late = Task::factory()->for($user)->create(['deadline_at' => '2026-10-10 12:00:00']);
        $early = Task::factory()->for($user)->create(['deadline_at' => '2026-10-01 12:00:00']);
        $ties = Task::factory()->count(4)->for($user)->create(['deadline_at' => '2026-10-05 12:00:00']);
        $nullLast = Task::factory()->for($user)->create(['priority' => 1]);
        $expected = [$early->id, ...$ties->modelKeys(), $late->id, $nullFirst->id, $nullLast->id];
        $this->actingAs($user, 'web');
        $ids = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson($this->url(['per_page' => 3, 'page' => $page]))->assertOk()->assertJsonPath('meta.total', 8)
                ->assertJsonPath('meta.current_page', $page)->assertJsonPath('meta.last_page', 3);
            array_push($ids, ...array_column($response->json('data'), 'id'));
        }
        $this->assertSame($expected, $ids);
        $this->assertCount(8, array_unique($ids));
    }

    public function test_default_page_size_links_and_out_of_range_page(): void
    {
        $user = User::factory()->create();
        $tasks = Task::factory()->count(17)->for($user)->create();
        $this->actingAs($user, 'web');
        $first = $this->getJson('/api/tasks')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.total', 17)
            ->assertJsonPath('meta.per_page', 15)->assertJsonPath('meta.from', 1)->assertJsonPath('meta.to', 15);
        $this->assertSame(array_slice($tasks->modelKeys(), 0, 15), array_column($first->json('data'), 'id'));
        $this->assertSame($first->json('meta.path').'?page=2', $first->json('links.next'));
        $second = $this->getJson($first->json('links.next'))->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.from', 16)->assertJsonPath('meta.to', 17)->assertJsonPath('links.next', null);
        $this->assertSame(array_slice($tasks->modelKeys(), 15), array_column($second->json('data'), 'id'));
        $this->getJson($this->url(['page' => 3]))->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 17)
            ->assertJsonPath('meta.current_page', 3)->assertJsonPath('meta.from', null)->assertJsonPath('meta.to', null);
    }

    public function test_maximum_page_size_and_links_keep_only_approved_filters(): void
    {
        $user = User::factory()->create();
        Task::factory()->count(101)->for($user)->create();
        $first = $this->actingAs($user, 'web')->getJson($this->url(['per_page' => 100, 'status' => 'pending', 'user_id' => 999999]))
            ->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.total', 101)->assertJsonPath('meta.per_page', 100);
        $link = $first->json('links.next');
        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        ksort($query);
        $this->assertSame(['page' => '2', 'per_page' => '100', 'status' => 'pending'], $query);
        $this->getJson($link)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 101);
    }

    public function test_minimum_page_size_is_accepted(): void
    {
        $user = User::factory()->create();
        $tasks = Task::factory()->count(2)->for($user)->create();
        $response = $this->actingAs($user, 'web')->getJson($this->url(['per_page' => '1']))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tasks[0]->id)->assertJsonPath('meta.per_page', 1);
        $this->getJson($response->json('links.next'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tasks[1]->id);
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_422_and_never_mutate(string $field, mixed $value): void
    {
        $task = Task::factory()->create()->refresh();
        $before = $task->getAttributes();
        $this->actingAs($task->user, 'web')->getJson($this->url([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $task->refresh()->getAttributes());
        $this->assertDatabaseCount('tasks', 1);
    }

    public static function invalidFilters(): array
    {
        $cases = [];
        foreach (['status' => ['', 'overdue', 'in_progress', 'PENDING', ' pending ', ['pending']],
            'priority' => ['', '0', '4', 'normal'], 'subject_id' => ['', '0'], 'page' => ['', '0', (string) PHP_INT_MAX],
            'per_page' => ['', '0', '101']] as $field => $values) {
            foreach ($values as $i => $value) {
                $cases[$field.' '.$i] = [$field, $value];
            }
        }
        foreach (['priority', 'subject_id', 'page', 'per_page'] as $field) {
            foreach (['-1', '01', '+1', '1.0', '1e0', ' 1 ', 'true', ['1'], '999999999999999999999999999'] as $i => $value) {
                $cases[$field.' malformed '.$i] = [$field, $value];
            }
        }
        foreach (['deadline_from', 'deadline_to'] as $field) {
            foreach (['', '2026-02-29T00:00:00Z', '2026-10-01T24:00:00Z', '2026-10-01T12:00:00+00:00',
                '2026-10-01T12:00:00.000Z', '2026-10-01 12:00:00', '2026-10-01T12:00:00',
                ' 2026-10-01T12:00:00Z', "2026-10-01T12:00:00Z\n", '0999-12-31T23:59:59Z', '10000-01-01T00:00:00Z', ['invalid']] as $i => $value) {
                $cases[$field.' '.$i] = [$field, $value];
            }
        }

        return $cases;
    }

    public function test_reversed_deadline_range_is_a_controlled_error(): void
    {
        $task = Task::factory()->create()->refresh();
        $before = $task->getAttributes();
        $this->actingAs($task->user, 'web')->getJson($this->url([
            'deadline_from' => '2026-10-03T12:00:00Z', 'deadline_to' => '2026-10-02T12:00:00Z',
        ]))->assertUnprocessable()->assertJsonValidationErrors('deadline_to');
        $this->assertSame($before, $task->refresh()->getAttributes());
    }

    public function test_query_filters_do_not_use_a_get_request_body(): void
    {
        $task = Task::factory()->create();
        $this->actingAs($task->user, 'web')->json('GET', '/api/tasks', ['status' => 'completed', 'user_id' => 999999])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $task->id);
    }

    public function test_resource_is_exact_and_utc_output_and_filtering_ignore_server_and_user_zones(): void
    {
        $user = User::factory()->create(['timezone' => 'America/Los_Angeles']);
        $subject = Subject::factory()->for($user)->create();
        $task = Task::factory()->for($user)->for($subject)->create(['title' => 'Test', 'description' => 'Keep',
            'priority' => 1, 'estimated_minutes' => 30, 'deadline_at' => '2026-10-02 12:00:00',
            'status' => TaskStatus::Completed, 'completed_at' => '2026-10-02 11:00:00']);
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $response = $this->actingAs($user, 'web')->getJson($this->url([
                'deadline_from' => '2026-10-02T12:00:00Z', 'deadline_to' => '2026-10-02T12:00:00Z',
            ]))->assertOk()->assertJsonCount(1, 'data');
            $this->assertSame([
                'id' => $task->id, 'subject_id' => $subject->id, 'title' => 'Test', 'description' => 'Keep',
                'status' => 'completed', 'priority' => 1, 'estimated_minutes' => 30,
                'deadline_at' => '2026-10-02T12:00:00Z', 'completed_at' => '2026-10-02T11:00:00Z',
            ], $response->json('data.0'));
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_subject_queries_are_batched_scoped_and_paginated_with_no_writes(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 10) as $number) {
            $subject = Subject::factory()->for($user)->create();
            Task::factory()->for($user)->for($subject)->create();
        }
        $this->actingAs($user, 'web');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->url(['per_page' => 5]))->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 10);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $subjects = array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'from `subjects`')));
        $this->assertCount(1, $subjects);
        $this->assertStringContainsString('`user_id` =', $subjects[0]['query']);
        $this->assertStringContainsString(' in (', $subjects[0]['query']);
        $taskQueries = array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'from `tasks`')));
        $this->assertCount(2, $taskQueries);
        $this->assertStringContainsString('limit 5 offset 0', $taskQueries[1]['query']);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
        }
    }
}
