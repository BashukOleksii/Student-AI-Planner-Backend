<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\EducationInstitution;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AI\Actions\SemesterActionRegistry;
use App\Services\Schedule\EffectiveScheduleService;
use App\Services\Schedule\SemesterScheduleManagementService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\TestCase;

/** Deferred execution: synthetic MySQL + offline provider regression cases for semester H3 adapters. */
class AiSemesterActionsTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 10, 16));
        config(['ai.provider' => 'openai', 'ai.openai_key' => 'offline', 'ai.model' => 'offline', 'ai.requests_per_minute' => 60]);
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $institution = EducationInstitution::factory()->for($owner)->create();
        $period = AcademicPeriod::factory()->for($owner)->for($institution)->create([
            'starts_on' => '2026-10-19', 'ends_on' => '2026-11-01',
        ]);
        $schedule = $owner->semesterSchedules()->create(['academic_period_id' => $period->id, 'group_name' => 'Calendar',
            'parity_anchor_on' => '2026-10-19', 'first_week_parity' => 'numerator']);
        $teacher = Teacher::factory()->for($owner)->create();
        $subject = Subject::factory()->for($owner)->create();
        $manager = app(SemesterScheduleManagementService::class);
        $fields = ['weekday' => 2, 'week_pattern' => 'all', 'pair_number' => 1,
            'starts_at_local' => '08:00:00', 'ends_at_local' => '09:00:00', 'subject_id' => $subject->id,
            'teacher_id' => $teacher->id, 'lesson_type' => null];
        $manager->createRule($owner, $schedule, [...$fields, 'schedule_token' => $manager->read($owner, $schedule)['meta']['schedule_token']]);
        $manager->createRule($owner, $schedule, [...$fields, 'pair_number' => 2,
            'starts_at_local' => '10:00:00', 'ends_at_local' => '11:00:00',
            'schedule_token' => $manager->read($owner, $schedule)['meta']['schedule_token']]);
        $rule = $schedule->weeklyLessonRules()->orderBy('id')->first();
        $this->actingAs($owner, 'web');
        $conversation = $this->postJson('/api/ai/conversations')->assertCreated()->json('data.id');
        $base = '/api/ai/conversations/'.$conversation;

        return compact('owner', 'schedule', 'manager', 'fields', 'rule', 'base');
    }

    private function snapshot(): array
    {
        return collect(['semester_schedules', 'weekly_lesson_rules', 'schedule_date_exceptions', 'schedule_date_exception_lessons',
            'semester_schedule_suppressed_slots', 'lessons', 'study_sessions', 'tasks', 'reminders', 'teachers', 'subjects'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function propose(array $f, string $name, array $arguments): string
    {
        $responses = [[$this->aiCall($name, json_encode($arguments), 'semester_action')], [$this->aiText('Changed everything.')]];
        // Capture by reference because provider rounds consume distinct responses.
        Http::fake(function () use (&$responses) {
            return Http::response($this->aiResponse(array_shift($responses)));
        });
        $response = $this->postJson($f['base'].'/messages', ['content' => 'Propose the semester change.'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.action.state', 'confirmation_required');
        $this->assertStringContainsString('Confirmation is required', $response->json('data.content'));

        return $response->json('data.action.id');
    }

    public static function operations(): array
    {
        return array_map(fn ($name) => [$name], SemesterActionRegistry::NAMES);
    }

    #[DataProvider('operations')]
    public function test_proposal_is_read_only_confirmation_is_owned_atomic_and_replayable(string $name): void
    {
        $f = $this->fixture();
        $target = ['semester_schedule_id' => $f['schedule']->id];
        $day = [...$target, 'date' => '2026-10-20'];
        $occurrence = [...$day, 'occurrence_id' => 'semester:'.$f['schedule']->id.':2026-10-20:rule:'.$f['rule']->id];
        if ($name === 'propose_semester_date_delete') {
            $f['manager']->replaceDate($f['owner'], $f['schedule'], '2026-10-20', ['mode' => 'replace', 'lessons' => [],
                'schedule_token' => $f['manager']->read($f['owner'], $f['schedule'])['meta']['schedule_token']]);
        }
        $arguments = match ($name) {
            'propose_semester_rule_create' => [...$target, ...$f['fields'], 'weekday' => 3],
            'propose_semester_rule_update' => [...$target, 'weekly_lesson_rule_id' => $f['rule']->id, 'room' => 'Reviewed'],
            'propose_semester_rule_delete' => [...$target, 'weekly_lesson_rule_id' => $f['rule']->id],
            'propose_semester_date_replace' => [...$day, 'mode' => 'copy_base', 'changes' => [['weekly_lesson_rule_id' => $f['rule']->id, 'room' => 'One date']]],
            'propose_semester_date_delete' => $day,
            'propose_semester_working_saturday' => [...$target, 'occurs_on' => '2026-10-24', 'copied_weekday' => 2],
            'propose_semester_occurrence_update' => [...$occurrence, 'room' => 'One occurrence'],
            'propose_semester_occurrence_cancel' => $occurrence,
            'propose_semester_settings_update' => [...$target, 'group_name' => 'New group'],
        };
        $before = $this->snapshot();
        $id = $this->propose($f, $name, $arguments);
        $this->assertSame($before, $this->snapshot());
        $path = $f['base'].'/actions/'.$id.'/confirm';
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web')->postJson($path)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs($f['owner'], 'web');
        AiMessage::creating(fn () => throw new RuntimeException('history failure'));
        try {
            $this->postJson($path)->assertStatus(500);
        } finally {
            AiMessage::flushEventListeners();
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('pending', AiPendingAction::findOrFail($id)->status);
        $receipt = $this->postJson($path)->assertOk()->assertJsonPath('data.status', 'executed')->json('data.receipt');
        $this->assertSame($receipt, $this->postJson($path)->assertOk()->json('data.receipt'));
        $this->assertStringNotContainsString('schedule_token', json_encode($receipt));
        $after = $this->snapshot();
        foreach (['lessons', 'study_sessions', 'tasks', 'reminders'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
        if (str_starts_with($name, 'propose_semester_occurrence_')) {
            $lessons = app(EffectiveScheduleService::class)->date($f['owner'], $f['schedule'], '2026-10-20');
            $this->assertSame($name === 'propose_semester_occurrence_cancel' ? 1 : 2, count($lessons));
            $this->assertSame('10:00:00', substr($lessons[array_key_last($lessons)]['starts_at'], 11, 8));
            $this->assertSame(2, $f['schedule']->weeklyLessonRules()->count());
        }
    }

    public function test_changed_graph_cannot_be_confirmed(): void
    {
        $f = $this->fixture();
        $arguments = ['semester_schedule_id' => $f['schedule']->id, 'weekly_lesson_rule_id' => $f['rule']->id, 'room' => 'AI room'];
        $id = $this->propose($f, 'propose_semester_rule_update', $arguments);
        $f['manager']->updateRule($f['owner'], $f['schedule'], $f['rule']->id, ['room' => 'Concurrent edit',
            'schedule_token' => $f['manager']->read($f['owner'], $f['schedule'])['meta']['schedule_token']]);
        $before = $this->snapshot();
        $this->postJson($f['base'].'/actions/'.$id.'/confirm')->assertConflict()->assertJsonPath('code', 'action_stale');
        $this->assertSame($before, $this->snapshot());
        $this->assertNull(AiPendingAction::findOrFail($id)->receipt);
    }

    public function test_rejection_preserves_the_calendar(): void
    {
        $f = $this->fixture();
        $id = $this->propose($f, 'propose_semester_rule_delete', ['semester_schedule_id' => $f['schedule']->id, 'weekly_lesson_rule_id' => $f['rule']->id]);
        $before = $this->snapshot();
        $this->postJson($f['base'].'/actions/'.$id.'/reject')->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson($f['base'].'/actions/'.$id.'/confirm')->assertConflict();
        $this->assertSame($before, $this->snapshot());
    }
    public function test_expired_confirmation_preserves_the_calendar(): void
    {
        $f = $this->fixture();
        $id = $this->propose($f, 'propose_semester_rule_delete', ['semester_schedule_id' => $f['schedule']->id, 'weekly_lesson_rule_id' => $f['rule']->id]);
        AiPendingAction::whereKey($id)->update(['expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        $before = $this->snapshot();
        $this->postJson($f['base'].'/actions/'.$id.'/confirm')->assertStatus(410);
        $this->assertSame($before, $this->snapshot());
        $this->assertNull(AiPendingAction::findOrFail($id)->receipt);
    }

    public function test_foreign_rule_and_legacy_identity_cannot_prepare_a_semester_mutation(): void
    {
        $f = $this->fixture();
        $service = app(\App\Services\AI\Actions\SemesterActionService::class);
        $other = User::factory()->create();
        $before = $this->snapshot();
        $foreign = $service->prepare($other, 'propose_semester_rule_delete', [
            'semester_schedule_id' => $f['schedule']->id, 'weekly_lesson_rule_id' => $f['rule']->id,
        ], 'foreign');
        $this->assertNull($foreign['proposal']);
        $this->assertSame('not_found', $foreign['result']['error']['code']);
        $invalid = $service->prepare($f['owner'], 'propose_semester_occurrence_cancel', [
            'semester_schedule_id' => $f['schedule']->id, 'date' => '2026-10-20', 'occurrence_id' => 'legacy_lesson:1',
        ], 'legacy');
        $this->assertNull($invalid['proposal']);
        $this->assertSame('not_found', $invalid['result']['error']['code']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_discovery_reads_are_owned_bounded_and_do_not_mutate_calendar_data(): void
    {
        $f = $this->fixture();
        $foreign = User::factory()->create();
        Subject::factory()->for($foreign)->create(['name' => 'FOREIGN SUBJECT']);
        $before = $this->snapshot();
        $reads = app(\App\Services\AI\Tools\SemesterReadTools::class);
        $catalog = $reads->execute($f['owner'], 'search_academic_catalog', ['kind' => 'subject', 'per_page' => 1]);
        $this->assertTrue($catalog['ok']);
        $this->assertCount(1, $catalog['data']['items']);
        $this->assertStringNotContainsString('FOREIGN', json_encode($catalog));
        $context = $reads->execute($f['owner'], 'get_semester_schedule_context', ['semester_schedule_id' => $f['schedule']->id, 'per_page' => 1]);
        $this->assertTrue($context['ok']);
        $this->assertTrue($context['data']['meta']['truncated']);
        $this->assertCount(1, $context['data']['weekly_rules']);
        $denied = $reads->execute($foreign, 'get_semester_schedule_context', ['semester_schedule_id' => $f['schedule']->id]);
        $this->assertSame('not_found', $denied['error']['code']);
        $this->assertSame($before, $this->snapshot());
    }

}
