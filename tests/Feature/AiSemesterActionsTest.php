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
use App\Services\AI\Actions\SemesterActionService;
use App\Services\AI\LlmProvider;
use App\Services\AI\Tools\SemesterReadTools;
use App\Services\Schedule\EffectiveScheduleService;
use App\Services\Schedule\SemesterScheduleManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\Support\AiCapabilities;
use Tests\TestCase;

/** Deferred execution: synthetic MySQL + offline provider regression cases for semester H3 adapters. */
#[Group('mysql')]
#[Group('ai')]
class AiSemesterActionsTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-16T08:00:00Z'));
        config(['ai.provider' => getenv('AI_TEST_PROVIDER') ?: 'openai', 'ai.openai_key' => 'offline', 'ai.model' => 'offline',
            'ai.gemini_key' => 'offline-gemini-key', 'ai.gemini_model' => 'gemini-offline-fixture', 'ai.requests_per_minute' => 60]);
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
        $conversation = $this->json('POST', '/api/ai/conversations', [], [], JSON_FORCE_OBJECT)->assertCreated()->json('data.id');
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
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $responses = [[$this->aiCall($name, json_encode($arguments), 'semester_'.Str::uuid())], [$this->aiText('Changed everything.')]];
        // Capture by reference because provider rounds consume distinct responses.
        Http::fake(function () use (&$responses) {
            return Http::response($this->aiResponse(array_shift($responses)));
        });
        $response = $this->postJson($f['base'].'/messages', ['content' => 'Propose the semester change.'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.action.state', 'confirmation_required');
        $this->assertStringContainsString('підготовлено до підтвердження', $response->json('data.content'));

        return $response->json('data.action.id');
    }

    private function addition(array $f, string $date = '2026-10-20'): array
    {
        return ['semester_schedule_id' => $f['schedule']->id, 'date' => $date, 'pair_number' => 3,
            'starts_at_local' => '12:00:00', 'ends_at_local' => '13:00:00',
            'subject_id' => $f['fields']['subject_id'], 'teacher_id' => $f['fields']['teacher_id'], 'room' => '301'];
    }

    public static function operations(): array
    {
        return array_map(fn ($name) => [$name], SemesterActionRegistry::NAMES);
    }

    #[DataProvider('operations')]
    #[Group('smoke')]
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
            'propose_semester_occurrence_create' => $this->addition($f),
        };
        $before = $this->snapshot();
        $id = $this->propose($f, $name, $arguments);
        $this->assertSame($before, $this->snapshot());
        $path = $f['base'].'/actions/'.$id.'/confirm';
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web')->json('POST', $path, [], [], JSON_FORCE_OBJECT)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs($f['owner'], 'web');
        AiMessage::creating(fn () => throw new RuntimeException('history failure'));
        try {
            $this->json('POST', $path, [], [], JSON_FORCE_OBJECT)->assertStatus(500);
        } finally {
            AiMessage::flushEventListeners();
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('pending', AiPendingAction::findOrFail($id)->status);
        $receipt = $this->json('POST', $path, [], [], JSON_FORCE_OBJECT)->assertOk()->assertJsonPath('data.status', 'executed')->json('data.receipt');
        $this->assertSame($receipt, $this->json('POST', $path, [], [], JSON_FORCE_OBJECT)->assertOk()->json('data.receipt'));
        $this->assertStringNotContainsString('schedule_token', json_encode($receipt));
        $after = $this->snapshot();
        foreach (['lessons', 'study_sessions', 'tasks', 'reminders'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
        if (str_starts_with($name, 'propose_semester_occurrence_')) {
            $lessons = app(EffectiveScheduleService::class)->date($f['owner'], $f['schedule'], '2026-10-20');
            $this->assertSame(match ($name) {
                'propose_semester_occurrence_cancel' => 1, 'propose_semester_occurrence_create' => 3, default => 2,
            }, count($lessons));
            $this->assertSame($name === 'propose_semester_occurrence_create' ? '12:00:00' : '10:00:00', substr($lessons[array_key_last($lessons)]['starts_at'], 11, 8));
            $this->assertSame(2, $f['schedule']->weeklyLessonRules()->count());
        }
    }

    public function test_addition_to_working_saturday_preserves_saved_lessons_identities_provenance_and_rules(): void
    {
        $f = $this->fixture();
        $f['manager']->workingSaturday($f['owner'], $f['schedule'], ['occurs_on' => '2026-10-24', 'copied_weekday' => 2,
            'source_week_parity' => 'numerator', 'selected_weekly_rule_ids' => $f['schedule']->weeklyLessonRules()->pluck('id')->all(),
            'schedule_token' => $f['manager']->read($f['owner'], $f['schedule'])['meta']['schedule_token']]);
        // A saved modification must survive: the effective Saturday is not the base Tuesday.
        $graph = $f['manager']->read($f['owner'], $f['schedule']);
        $first = $graph['lessons']->first();
        $f['manager']->changeOccurrence($f['owner'], $f['schedule'], '2026-10-24',
            'semester:'.$f['schedule']->id.':2026-10-24:snapshot:'.$first->id,
            ['room' => 'Frozen Saturday room'], false, ['schedule_token' => $graph['meta']['schedule_token']]);
        $before = $this->snapshot();
        $links = DB::table('schedule_date_exception_lesson_teacher')->orderBy('schedule_date_exception_lesson_id')->get()->all();
        $effective = app(EffectiveScheduleService::class)->date($f['owner'], $f['schedule'], '2026-10-24');
        $revision = $f['schedule']->fresh()->management_revision;
        $id = $this->propose($f, 'propose_semester_occurrence_create', $this->addition($f, '2026-10-24'));
        $this->assertSame($before, $this->snapshot());
        $preview = AiPendingAction::findOrFail($id)->preview;
        $this->assertSame('2026-10-24', $preview['after']['date']);
        $this->assertSame('working_saturday', $preview['after']['kind']);
        $this->assertSame('numerator', $preview['after']['source_week_parity']);
        $new = collect($preview['after']['lessons'])->firstWhere('pair_number', 3);
        $this->assertSame('301', $new['room']);
        $this->assertSame($f['owner']->subjects()->findOrFail($new['subject_id'])->name, $new['subject']['name']);
        $this->assertSame($f['owner']->teachers()->findOrFail($new['teacher_id'])->name, $new['teacher']['name']);
        $this->travelTo(CarbonImmutable::parse('2026-10-16T08:05:00Z'));
        $path = $f['base'].'/actions/'.$id.'/confirm';
        $receipt = $this->json('POST', $path, [], [], JSON_FORCE_OBJECT)->assertOk()->assertJsonPath('data.status', 'executed')->json('data.receipt');
        $after = $this->snapshot();
        $this->assertSame($before['weekly_lesson_rules'], $after['weekly_lesson_rules']);
        $this->assertSame($before['schedule_date_exceptions'], $after['schedule_date_exceptions']);
        $this->assertSame($before['schedule_date_exception_lessons'], array_slice($after['schedule_date_exception_lessons'], 0, 2));
        $this->assertEquals($links, DB::table('schedule_date_exception_lesson_teacher')->whereIn('schedule_date_exception_lesson_id', array_column($before['schedule_date_exception_lessons'], 'id'))->orderBy('schedule_date_exception_lesson_id')->get()->all());
        $lessons = app(EffectiveScheduleService::class)->date($f['owner'], $f['schedule'], '2026-10-24');
        $this->assertSame([1, 2, 3], array_column($lessons, 'pair_number'));
        $this->assertSame($effective, array_slice($lessons, 0, 2));
        $this->assertSame('added', $lessons[2]['change_kind']);
        $this->assertSame($revision + 1, $f['schedule']->fresh()->management_revision);
        $this->assertSame($receipt, $this->json('POST', $path, [], [], JSON_FORCE_OBJECT)->assertOk()->json('data.receipt'));
        $this->assertSame($after, $this->snapshot());
    }

    public function test_base_day_addition_freezes_only_selected_date(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $other = app(EffectiveScheduleService::class)->date($f['owner'], $f['schedule'], '2026-10-27');
        $id = $this->propose($f, 'propose_semester_occurrence_create', $this->addition($f));
        $this->assertSame($before, $this->snapshot());
        $this->json('POST', $f['base'].'/actions/'.$id.'/confirm', [], [], JSON_FORCE_OBJECT)->assertOk();
        $lessons = app(EffectiveScheduleService::class)->date($f['owner'], $f['schedule'], '2026-10-20');
        $this->assertSame([1, 2, 3], array_column($lessons, 'pair_number'));
        $this->assertSame(['base', 'base', 'added'], array_column($lessons, 'change_kind'));
        $this->assertSame($other, app(EffectiveScheduleService::class)->date($f['owner'], $f['schedule'], '2026-10-27'));
        $this->assertSame($before['weekly_lesson_rules'], $this->snapshot()['weekly_lesson_rules']);
        $this->assertDatabaseCount('schedule_date_exceptions', 1);
        $rows = $f['manager']->read($f['owner'], $f['schedule'])['lessons'];
        $this->assertSame($f['schedule']->weeklyLessonRules()->pluck('id')->all(), $rows->where('is_added', false)->pluck('source_weekly_rule_id')->all());
        $this->assertNull($rows->firstWhere('pair_number', 3)->source_weekly_rule_id);
    }

    public static function invalidAdditions(): array
    {
        return array_map(fn ($case) => [$case], ['occupied pair', 'overlap', 'occupied pair and overlap', 'foreign subject', 'foreign teacher', 'foreign schedule',
            'wrong teacher', 'outside semester', 'invalid time', 'ambiguous local time', 'cancelled date']);
    }

    #[DataProvider('invalidAdditions')]
    public function test_invalid_single_additions_fail_without_proposal_or_calendar_changes(string $case): void
    {
        $f = $this->fixture();
        $arguments = $this->addition($f);
        switch ($case) {
            case 'occupied pair': $arguments['pair_number'] = 1;
                break;
            case 'overlap': $arguments['starts_at_local'] = '08:30:00';
                break;
            case 'occupied pair and overlap': $arguments = [...$arguments, 'pair_number' => 2, 'starts_at_local' => '08:30:00'];
                break;
            case 'foreign subject': $arguments['subject_id'] = Subject::factory()->create()->id;
                break;
            case 'foreign teacher': $arguments['teacher_id'] = Teacher::factory()->create()->id;
                break;
            case 'foreign schedule': $f['owner'] = User::factory()->create();
                break;
            case 'wrong teacher': $arguments['teacher_id'] = Teacher::factory()->for($f['owner'])->create()->id;
                break;
            case 'outside semester': $arguments['date'] = '2026-11-02';
                break;
            case 'invalid time': $arguments['ends_at_local'] = '11:00:00';
                break;
            case 'ambiguous local time':
                $arguments = [...$arguments, 'date' => '2026-10-25', 'starts_at_local' => '03:30:00', 'ends_at_local' => '04:30:00'];
                break;
            case 'cancelled date':
                $f['manager']->replaceDate($f['owner'], $f['schedule'], $arguments['date'], ['mode' => 'replace', 'lessons' => [],
                    'schedule_token' => $f['manager']->read($f['owner'], $f['schedule'])['meta']['schedule_token']]);
                break;
        }
        $before = $this->snapshot();
        $prepared = app(SemesterActionService::class)->prepare($f['owner'], 'propose_semester_occurrence_create', $arguments, 'invalid_addition');
        $this->assertNull($prepared['proposal']);
        $this->assertSame($case === 'foreign schedule' ? 'not_found' : 'invalid_arguments', $prepared['result']['error']['code']);
        $this->assertSame(match ($case) {
            'occupied pair', 'occupied pair and overlap' => 'pair_number_taken',
            'overlap' => 'time_overlap', 'cancelled date' => 'date_cancelled', default => null,
        }, $prepared['result']['error']['reason'] ?? null);
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('ai_pending_actions', 0);
    }

    public static function additionFailureResponses(): array
    {
        return [
            ['occupied pair', 'pair_number_taken', 'invalid_arguments'],
            ['overlap', 'time_overlap', 'invalid_arguments'],
            ['occupied pair and overlap', 'pair_number_taken', 'invalid_arguments'],
            ['cancelled date', 'date_cancelled', 'invalid_arguments'],
            ['invalid arguments', null, 'invalid_arguments'],
            ['invalid interval with occupied pair', null, 'invalid_arguments'],
            ['unavailable schedule', null, 'not_found'],
            ['stale state', null, 'action_stale'],
            ['internal failure', null, 'execution_failed'],
        ];
    }

    #[DataProvider('additionFailureResponses')]
    public function test_addition_failure_reason_reaches_chat_replay_and_history_without_calendar_changes(string $case, ?string $reason, string $code): void
    {
        $f = $this->fixture();
        // A saved Saturday must be checked rather than the empty Saturday base rules.
        $f['manager']->workingSaturday($f['owner'], $f['schedule'], ['occurs_on' => '2026-10-24', 'copied_weekday' => 2,
            'schedule_token' => $f['manager']->read($f['owner'], $f['schedule'])['meta']['schedule_token']]);
        $arguments = $this->addition($f, '2026-10-24');
        $arguments = match ($case) {
            'occupied pair' => [...$arguments, 'pair_number' => 1],
            'overlap' => [...$arguments, 'starts_at_local' => '08:30:00'],
            // Pair 2 is later than the overlapping pair 1; pair priority is global.
            'occupied pair and overlap' => [...$arguments, 'pair_number' => 2, 'starts_at_local' => '08:30:00'],
            'invalid arguments' => [...$arguments, 'pair_number' => '3'],
            'invalid interval with occupied pair' => [...$arguments, 'pair_number' => 1, 'ends_at_local' => '11:00:00'],
            'unavailable schedule' => [...$arguments, 'semester_schedule_id' => $f['schedule']->id + 1000],
            default => $arguments,
        };
        if ($case === 'cancelled date') {
            $f['manager']->replaceDate($f['owner'], $f['schedule'], $arguments['date'], ['mode' => 'replace', 'lessons' => [],
                'schedule_token' => $f['manager']->read($f['owner'], $f['schedule'])['meta']['schedule_token']]);
        }
        if (in_array($case, ['stale state', 'internal failure'], true)) {
            $manager = $this->createMock(SemesterScheduleManagementService::class);
            $manager->expects($this->once())->method('createOccurrence')->willThrowException($case === 'stale state'
                ? new ConflictHttpException('time_overlap: private stale state')
                : new RuntimeException('SQL: private pair_number_taken debug data'));
            $this->app->instance(SemesterScheduleManagementService::class, $manager);
        }
        $before = $this->snapshot();
        $name = 'propose_semester_occurrence_create';
        $expected = ['ok' => false, 'tool' => $name, 'error' => ['code' => $code,
            ...($reason === null ? [] : ['reason' => $reason]),
            'message' => match ($code) {
                'not_found' => 'Resource not found.', 'action_stale' => 'The semester schedule changed. Read it again.',
                'execution_failed' => 'Semester proposal could not be prepared.', default => 'Invalid semester action arguments.',
            }]];
        $summary = ['call_id' => 'failed_addition', 'name' => $name, 'ok' => false, 'error_code' => $code,
            ...($reason === null ? [] : ['error_reason' => $reason])];
        $round = 0;
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->exactly(3))->method('respond')->willReturnCallback(function ($instructions, $history) use (&$round, $arguments, $name, $expected): array {
            if ($round++ === 0) {
                return ['text' => null, 'calls' => [['id' => 'failed_addition', 'name' => $name, 'arguments' => $arguments]], 'state' => []];
            }
            $tool = collect($history)->firstWhere('role', 'tool');
            $this->assertSame($expected, json_decode($tool['content'], true, flags: JSON_THROW_ON_ERROR));

            return ['text' => 'The lesson was added successfully.', 'calls' => [], 'state' => []];
        });
        $this->app->instance(LlmProvider::class, $provider);
        $key = (string) Str::uuid();
        $body = ['content' => 'Додай пару на робочу суботу.'];
        $response = $this->postJson($f['base'].'/messages', $body, ['Idempotency-Key' => $key])->assertOk();
        $this->assertSame([$summary], $response->json('data.tools'));
        $this->assertArrayNotHasKey('action', $response->json('data'));
        $this->assertSame('Пропозицію не створено. Зміни не виконано.', $response->json('data.content'));
        $replay = $this->postJson($f['base'].'/messages', $body, ['Idempotency-Key' => $key])->assertOk();
        $this->assertSame($response->json('data'), $replay->json('data'));
        $messages = $this->getJson($f['base'].'/messages')->assertOk()->json('data');
        $tool = collect($messages)->firstWhere('role', 'tool');
        $this->assertNull($tool['content']);
        // The existing history code allowlist does not expose action_stale.
        $this->assertSame([...$summary, 'error_code' => $code === 'action_stale' ? null : $code], $tool['tool_result']);
        $stored = AiMessage::where('role', 'tool')->sole();
        $this->assertSame($expected, json_decode($stored->content, true, flags: JSON_THROW_ON_ERROR));
        $this->postJson($f['base'].'/messages', ['content' => 'Поясни попередній результат.'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('ai_pending_actions', 0);
    }

    public function test_an_empty_base_day_is_not_a_cancelled_date_and_touching_intervals_are_allowed(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $service = app(SemesterActionService::class);
        $empty = $service->prepare($f['owner'], 'propose_semester_occurrence_create', $this->addition($f, '2026-10-21'), 'empty');
        $this->assertTrue($empty['result']['ok']);
        $this->assertSame([], $empty['proposal']['preview']['before']['lessons']);
        $touching = $service->prepare($f['owner'], 'propose_semester_occurrence_create',
            [...$this->addition($f), 'starts_at_local' => '09:00:00', 'ends_at_local' => '10:00:00'], 'touching');
        $this->assertTrue($touching['result']['ok']);
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('ai_pending_actions', 0);
    }

    public function test_single_addition_rejection_and_stale_confirmation_preserve_calendar(): void
    {
        $f = $this->fixture();
        $id = $this->propose($f, 'propose_semester_occurrence_create', $this->addition($f));
        $before = $this->snapshot();
        $path = $f['base'].'/actions/'.$id;
        $this->json('POST', $path.'/reject', [], [], JSON_FORCE_OBJECT)->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->json('POST', $path.'/confirm', [], [], JSON_FORCE_OBJECT)->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $id = $this->propose($f, 'propose_semester_occurrence_create', $this->addition($f));
        $f['manager']->updateRule($f['owner'], $f['schedule'], $f['rule']->id, ['room' => 'Concurrent edit',
            'schedule_token' => $f['manager']->read($f['owner'], $f['schedule'])['meta']['schedule_token']]);
        $before = $this->snapshot();
        $this->json('POST', $f['base'].'/actions/'.$id.'/confirm', [], [], JSON_FORCE_OBJECT)->assertConflict()->assertJsonPath('code', 'action_stale');
        $this->assertSame($before, $this->snapshot());
        $this->assertNull(AiPendingAction::findOrFail($id)->receipt);
    }

    public function test_changed_graph_cannot_be_confirmed(): void
    {
        $f = $this->fixture();
        $arguments = ['semester_schedule_id' => $f['schedule']->id, 'weekly_lesson_rule_id' => $f['rule']->id, 'room' => 'AI room'];
        $id = $this->propose($f, 'propose_semester_rule_update', $arguments);
        $f['manager']->updateRule($f['owner'], $f['schedule'], $f['rule']->id, ['room' => 'Concurrent edit',
            'schedule_token' => $f['manager']->read($f['owner'], $f['schedule'])['meta']['schedule_token']]);
        $before = $this->snapshot();
        $this->json('POST', $f['base'].'/actions/'.$id.'/confirm', [], [], JSON_FORCE_OBJECT)->assertConflict()->assertJsonPath('code', 'action_stale');
        $this->assertSame($before, $this->snapshot());
        $this->assertNull(AiPendingAction::findOrFail($id)->receipt);
    }

    public function test_rejection_preserves_the_calendar(): void
    {
        $f = $this->fixture();
        $id = $this->propose($f, 'propose_semester_rule_delete', ['semester_schedule_id' => $f['schedule']->id, 'weekly_lesson_rule_id' => $f['rule']->id]);
        $before = $this->snapshot();
        $this->json('POST', $f['base'].'/actions/'.$id.'/reject', [], [], JSON_FORCE_OBJECT)->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->json('POST', $f['base'].'/actions/'.$id.'/confirm', [], [], JSON_FORCE_OBJECT)->assertConflict();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_expired_confirmation_preserves_the_calendar(): void
    {
        $f = $this->fixture();
        $id = $this->propose($f, 'propose_semester_rule_delete', ['semester_schedule_id' => $f['schedule']->id, 'weekly_lesson_rule_id' => $f['rule']->id]);
        AiPendingAction::whereKey($id)->update(['expires_at' => DB::raw('DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)')]);
        $before = $this->snapshot();
        $this->json('POST', $f['base'].'/actions/'.$id.'/confirm', [], [], JSON_FORCE_OBJECT)->assertStatus(410);
        $this->assertSame($before, $this->snapshot());
        $this->assertNull(AiPendingAction::findOrFail($id)->receipt);
    }

    public function test_foreign_rule_and_legacy_identity_cannot_prepare_a_semester_mutation(): void
    {
        $f = $this->fixture();
        $service = app(SemesterActionService::class);
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
        $reads = app(SemesterReadTools::class);
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

    public function test_owned_semester_discovery_reads_feed_one_reviewable_proposal_without_calendar_writes(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $round = 0;
        $scheduleId = $ruleId = null;
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->exactly(5))->method('respond')->willReturnCallback(function ($instructions, $history, $definitions) use (&$round, &$scheduleId, &$ruleId) {
            $this->assertSame(AiCapabilities::names(), array_column($definitions, 'name'));
            if ($round > 0) {
                $last = json_decode($history[array_key_last($history)]['content'], true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($last['ok']);
            }
            if ($round === 1) {
                $scheduleId = $last['data']['schedules'][0]['id'];
            }
            if ($round === 2) {
                $ruleId = $last['data']['weekly_rules'][0]['id'];
            }
            $call = match ($round++) {
                0 => ['list_semester_schedules', []],
                1 => ['get_semester_schedule_context', ['semester_schedule_id' => $scheduleId]],
                2 => ['search_academic_catalog', ['kind' => 'subject']],
                3 => ['propose_semester_rule_update', ['semester_schedule_id' => $scheduleId, 'weekly_lesson_rule_id' => $ruleId, 'room' => 'Reviewed room']],
                default => null,
            };

            return $call === null ? ['text' => 'Please review the proposed change.', 'calls' => [], 'state' => []]
                : ['text' => null, 'calls' => [['id' => 'semester_'.$round, 'name' => $call[0], 'arguments' => $call[1]]], 'state' => []];
        });
        $this->app->instance(LlmProvider::class, $provider);
        $response = $this->postJson($f['base'].'/messages', ['content' => 'Inspect my semester, then propose a room change.'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.action.state', 'confirmation_required');
        $this->assertSame([...array_column((new SemesterActionRegistry)->readDefinitions(), 'name'), 'propose_semester_rule_update'], array_column($response->json('data.tools'), 'name'));
        $this->assertSame($f['schedule']->id, $scheduleId);
        $this->assertSame($f['rule']->id, $ruleId);
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('ai_pending_actions', 1);
    }

    /** Scripted provider verifies data flow and supplied instructions, not live-model language understanding. */
    public function test_names_are_resolved_from_catalog_before_a_single_saturday_addition_proposal(): void
    {
        $f = $this->fixture();
        $f['owner']->subjects()->whereKey($f['fields']['subject_id'])->update(['name' => 'Алгоритми']);
        $f['owner']->teachers()->whereKey($f['fields']['teacher_id'])->update(['name' => 'Олена Коваль']);
        $f['manager']->workingSaturday($f['owner'], $f['schedule'], ['occurs_on' => '2026-10-24', 'copied_weekday' => 2,
            'schedule_token' => $f['manager']->read($f['owner'], $f['schedule'])['meta']['schedule_token']]);
        $before = $this->snapshot();
        $round = 0;
        $scheduleId = $subjectId = $teacherId = null;
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->exactly(4))->method('respond')->willReturnCallback(function ($instructions, $history, $definitions) use (&$round, &$scheduleId, &$subjectId, &$teacherId) {
            $this->assertStringContainsString('Zero matches or multiple matches require clarification before a proposal', $instructions);
            $this->assertStringContainsString('instead of asking the student to enter database IDs', $instructions);
            $this->assertStringContainsString('a duration that contradicts those endpoints', $instructions);
            $this->assertContains('propose_semester_occurrence_create', array_column($definitions, 'name'));
            $call = fn ($name, $arguments, $id) => ['id' => $id, 'name' => $name, 'arguments' => $arguments];
            if ($round++ === 0) {
                return ['text' => null, 'calls' => [
                    $call('list_semester_schedules', [], 'schedule'),
                    $call('search_academic_catalog', ['kind' => 'subject', 'query' => 'Алгоритми'], 'subject'),
                    $call('search_academic_catalog', ['kind' => 'teacher', 'query' => 'Олена Коваль'], 'teacher'),
                ], 'state' => []];
            }
            if ($round === 2) {
                $results = array_map(fn ($event) => json_decode($event['content'], true, flags: JSON_THROW_ON_ERROR), array_slice($history, -3));
                foreach ($results as $result) {
                    $this->assertTrue($result['ok']);
                }
                $this->assertSame(1, $results[1]['data']['meta']['total']);
                $this->assertSame(1, $results[2]['data']['meta']['total']);
                $scheduleId = $results[0]['data']['schedules'][0]['id'];
                $subjectId = $results[1]['data']['items'][0]['id'];
                $teacherId = $results[2]['data']['items'][0]['id'];
                $this->assertSame($teacherId, $results[1]['data']['items'][0]['teachers'][0]['id']);

                return ['text' => null, 'calls' => [$call('get_schedule_for_date', ['date' => '2026-10-24'], 'effective')], 'state' => []];
            }
            if ($round === 3) {
                $effective = json_decode($history[array_key_last($history)]['content'], true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue($effective['ok']);

                return ['text' => null, 'calls' => [$call('propose_semester_occurrence_create', [
                    'semester_schedule_id' => $scheduleId, 'date' => '2026-10-24', 'pair_number' => 3,
                    'starts_at_local' => '12:00:00', 'ends_at_local' => '13:00:00',
                    'subject_id' => $subjectId, 'teacher_id' => $teacherId, 'room' => '301',
                ], 'append')], 'state' => []];
            }

            return ['text' => 'Review the proposed addition.', 'calls' => [], 'state' => []];
        });
        $this->app->instance(LlmProvider::class, $provider);
        $response = $this->postJson($f['base'].'/messages', [
            'content' => 'Додай третю пару на робочу суботу 24 жовтня: Алгоритми, Олена Коваль, з 12:00 до 13:00, аудиторія 301.',
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.action.preview.type', 'propose_semester_occurrence_create');
        $this->assertSame(['list_semester_schedules', 'search_academic_catalog', 'search_academic_catalog',
            'get_schedule_for_date', 'propose_semester_occurrence_create'], array_column($response->json('data.tools'), 'name'));
        $this->assertSame($f['fields']['subject_id'], $subjectId);
        $this->assertSame($f['fields']['teacher_id'], $teacherId);
        $this->assertSame($f['schedule']->id, $scheduleId);
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('ai_pending_actions', 1);
        $this->assertSame(3, count($response->json('data.action.preview.after.lessons')));
        $path = $f['base'].'/actions/'.$response->json('data.action.id').'/confirm';
        $receipt = $this->json('POST', $path, [], [], JSON_FORCE_OBJECT)->assertOk()
            ->assertJsonPath('data.receipt.type', 'propose_semester_occurrence_create')->json('data.receipt');
        $this->assertSame([1, 2, 3], array_column($receipt['after']['lessons'], 'pair_number'));
        $this->assertSame('working_saturday', $receipt['after']['kind']);
        $this->assertSame(2, $receipt['after']['copied_weekday']);
        $this->assertSame('numerator', $receipt['after']['source_week_parity']);
        $after = $this->snapshot();
        $this->assertSame($before['weekly_lesson_rules'], $after['weekly_lesson_rules']);
        $this->assertSame($before['schedule_date_exceptions'], $after['schedule_date_exceptions']);
        $this->assertSame($before['schedule_date_exception_lessons'], array_slice($after['schedule_date_exception_lessons'], 0, 2));
        $this->assertSame($receipt, $this->json('POST', $path, [], [], JSON_FORCE_OBJECT)->assertOk()->json('data.receipt'));
        $this->assertSame($after, $this->snapshot());
    }

    public static function unresolvedCatalogMatches(): array
    {
        return [['Немає такого предмета', 0], ['Неоднозначний предмет', 2]];
    }

    #[DataProvider('unresolvedCatalogMatches')]
    public function test_catalog_uncertainty_is_visible_to_the_provider_and_can_end_with_clarification(string $query, int $count): void
    {
        $f = $this->fixture();
        foreach ([1, 2] as $index) {
            Subject::factory()->for($f['owner'])->create(['name' => 'Неоднозначний предмет '.$index]);
        }
        $before = $this->snapshot();
        $round = 0;
        $provider = $this->createMock(LlmProvider::class);
        $provider->expects($this->exactly(2))->method('respond')->willReturnCallback(function ($instructions, $history) use (&$round, $query, $count) {
            $this->assertStringContainsString('Zero matches or multiple matches require clarification before a proposal', $instructions);
            if ($round++ === 0) {
                return ['text' => null, 'calls' => [['id' => 'lookup', 'name' => 'search_academic_catalog',
                    'arguments' => ['kind' => 'subject', 'query' => $query]]], 'state' => []];
            }
            $result = json_decode($history[array_key_last($history)]['content'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($result['ok']);
            $this->assertSame($count, $result['data']['meta']['total']);

            return ['text' => 'Уточніть, будь ласка, назву предмета.', 'calls' => [], 'state' => []];
        });
        $this->app->instance(LlmProvider::class, $provider);
        $response = $this->postJson($f['base'].'/messages', ['content' => 'Додай пару: '.$query],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertArrayNotHasKey('action', $response->json('data'));
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $this->assertSame($before, $this->snapshot());
    }
}
