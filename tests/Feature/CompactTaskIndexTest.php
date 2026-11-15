<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Subject;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactTaskIndexTest extends TestCase
{
    use RefreshDatabase;

    private function url(array $filters = []): string
    {
        return '/api/tasks'.($filters === [] ? '' : '?'.http_build_query($filters));
    }

    public function test_empty_collection_has_standard_pagination(): void
    {
        Task::factory()->create();
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/tasks')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0)->assertJsonPath('meta.per_page', 15)->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.last_page', 1)->assertJsonPath('links.prev', null)->assertJsonPath('links.next', null)->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total']]);
    }

    public function test_listing_is_non_deleted_read_only_and_preserves_optional_subjects(): void
    {
        $ownedSubject = Subject::factory()->create();
        $user = $ownedSubject->user;
        $secondSubject = Subject::factory()->for($user)->create(['name' => 'Algebra']);
        $owned = Task::factory()->for($user)->for($ownedSubject)->create();
        $legacy = Task::factory()->for($user)->create(['subject_id' => $secondSubject->id]);
        $noSubject = Task::factory()->for($user)->create();
        $deleted = Task::factory()->for($user)->create();
        $deleted->delete();
        $subtask = Subtask::factory()->for($owned)->create();
        $records = [$owned, $legacy, $noSubject, $deleted, $ownedSubject, $secondSubject, $subtask];
        $before = array_map(fn ($record) => $record->refresh()->getAttributes(), $records);
        $response = $this->actingAs($user, 'web')->getJson($this->url())->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame([$owned->id, $legacy->id, $noSubject->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.subject_id', $ownedSubject->id)->assertJsonPath('data.1.subject_id', $secondSubject->id)->assertJsonPath('data.2.subject_id', null);
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
        return [0 => ['pending']];
    }

    #[DataProvider('priorityFilters')]
    public function test_canonical_string_priority_filters(int $priority): void
    {
        $user = User::factory()->create();
        $tasks = [];
        foreach ([1, 2, 3] as $value) {
            $tasks[$value] = Task::factory()->for($user)->create(['priority' => $value]);
        }
        $response = $this->actingAs($user, 'web')->getJson($this->url(['priority' => (string) $priority]))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.priority', $priority);
        $this->assertSame([$tasks[$priority]->id], array_column($response->json('data'), 'id'));
    }

    public static function priorityFilters(): array
    {
        return [0 => [1]];
    }
}
