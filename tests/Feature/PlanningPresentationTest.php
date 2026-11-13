<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\PlanningExplanationPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

class PlanningPresentationTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13T08:00:00Z'));
    }

    public function test_options_are_owned_searchable_paginated_and_use_existing_eligibility(): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        Task::factory()->for($owner)->count(23)->create(['title' => 'Курсова', 'estimated_minutes' => 30]);
        Task::factory()->for($owner)->create(['title' => 'Завершене', 'estimated_minutes' => 30, 'status' => 'completed', 'completed_at' => now()]);
        Task::factory()->for($owner)->create(['title' => 'Прострочене', 'estimated_minutes' => 30, 'deadline_at' => now()->subDay()]);
        Task::factory()->create(['title' => 'Чуже', 'estimated_minutes' => 30]);
        $this->actingAs($owner);
        $this->getJson('/api/planning/work-options')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/planning/work-options?page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/planning/work-options?search='.urlencode('Курсова'))->assertJsonPath('meta.total', 23);
        $this->getJson('/api/planning/work-options?search=missing')->assertJsonPath('meta.total', 0);
        $literal = Task::factory()->for($owner)->create(['title' => '100%_готово', 'estimated_minutes' => 30]);
        $this->getJson('/api/planning/work-options?search=%25_')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $literal->id);
    }

    public function test_step_choices_exclude_completed_and_foreign_steps_and_report_empty_states(): void
    {
        $task = Task::factory()->create(['estimated_minutes' => 60]);
        $open = Subtask::factory()->for($task)->create(['estimated_minutes' => 30]);
        $done = Subtask::factory()->for($task)->create(['estimated_minutes' => 30, 'status' => 'completed', 'completed_at' => now()]);
        $other = Subtask::factory()->create(['estimated_minutes' => 30]);
        $this->actingAs($task->user);
        $url = '/api/planning/work-options?task_id='.$task->id;
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);
        $this->getJson($url.'&subtask_id='.$other->id)->assertNotFound();
        $this->getJson($url.'&subtask_id='.$done->id)->assertJsonPath('meta.task_available', false)->assertJsonCount(0, 'data');
        $open->update(['status' => 'completed', 'completed_at' => now()]);
        $this->getJson($url)->assertJsonPath('meta.all_subtasks_completed', true)->assertJsonCount(0, 'data');
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
        $this->getJson('/api/planning/work-options?subtask_id='.$open->id)->assertUnprocessable();
    }

    public function test_preview_and_replanning_add_ukrainian_text_without_changing_internal_reasons_or_writing_sessions(): void
    {
        $task = Task::factory()->create(['estimated_minutes' => 60]);
        $this->actingAs($task->user);
        $body = ['task_id' => $task->id, 'start_date' => '2026-11-14', 'end_date' => '2026-11-15'];
        $preview = $this->postJson('/api/planning/study-plans/preview', $body)->assertOk();
        $preview->assertJsonPath('data.user_summary.title', 'Нових сесій не запропоновано');
        $row = $preview->json('data.work_results.0');
        $this->assertNotEmpty($row['reason_codes']);
        $this->assertNotEmpty($row['explanations']);
        $this->assertMatchesRegularExpression('/[А-Яа-яІіЇїЄє]/u', $row['explanations'][0]);
        $this->assertDatabaseCount('study_sessions', 0);
        StudySession::factory()->for($task)->create(['starts_at' => '2026-11-14 10:00:00', 'ends_at' => '2026-11-14 11:00:00']);
        $replan = $this->postJson('/api/planning/replanning/preview', $body)->assertOk();
        $this->assertArrayHasKey('explanations', $replan->json('data.session_results.0'));
        $this->assertDatabaseCount('study_sessions', 1);
    }

    public function test_unknown_codes_are_never_used_as_user_text(): void
    {
        $presenter = app(PlanningExplanationPresenter::class);
        $row = $presenter->row(['reason_codes' => ['future_internal_code']]);
        $this->assertSame(['future_internal_code'], $row['reason_codes']);
        $this->assertStringNotContainsString('future_internal_code', $row['explanations'][0]);
    }

    public function test_session_details_add_a_ukrainian_result_label_and_preserve_actual_time(): void
    {
        $session = StudySession::factory()->create(['status' => 'completed', 'actual_minutes' => 17, 'completed_at' => now()]);
        $before = $session->refresh()->getAttributes();
        $this->actingAs($session->user)->getJson('/api/study-sessions/'.$session->id)->assertOk()
            ->assertJsonPath('data.status_label', 'Сесію завершено')->assertJsonPath('data.actual_minutes', 17);
        $this->assertSame($before, $session->refresh()->getAttributes());
    }
}
