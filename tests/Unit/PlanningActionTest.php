<?php

namespace Tests\Unit;

use App\Exceptions\AiAgentException;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\Actions\ActionTargetFingerprint;
use App\Services\AI\Actions\PlanningActionArgumentValidator;
use App\Services\AI\Actions\PlanningActionConsent;
use App\Services\AI\Actions\PlanningActionRegistry;
use App\Services\AI\Actions\PlanningActionService;
use App\Services\AI\Actions\SubtaskReminderActionRegistry;
use App\Services\AI\Actions\SubtaskReminderActionService;
use App\Services\AI\Actions\TaskActionProposalService;
use App\Services\AI\Actions\TaskActionRegistry;
use App\Services\AI\AgentLimits;
use App\Services\AI\AgentOrchestrator;
use App\Services\AI\LlmProvider;
use App\Services\AI\OpenAiToolSchema;
use App\Services\AI\Tools\ManagementReadTools;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\AI\Tools\ToolRegistry;
use App\Services\Planning\AvailabilityOccurrenceService;
use App\Services\Planning\FreeTimeService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\StudyPlanConfirmationService;
use App\Services\Planning\StudyPlanPersistenceService;
use App\Services\Planning\StudyReplanningConfirmationService;
use App\Services\Planning\StudyReplanningPersistenceService;
use App\Services\Planning\StudySessionLifecycleRules;
use App\Services\Planning\StudySessionMutationContextService;
use App\Services\Planning\StudySessionReschedulingService;
use App\Services\Planning\TimeIntervalService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlanningActionTest extends TestCase
{
    private function validator(): PlanningActionArgumentValidator
    {
        return new PlanningActionArgumentValidator(new PlanningActionRegistry,
            new PlanningContextService(new TimeIntervalService, $this->createMock(AvailabilityOccurrenceService::class), $this->createMock(FreeTimeService::class)),
            new StudySessionLifecycleRules, new TimeIntervalService);
    }

    private function limits(): AgentLimits
    {
        return new AgentLimits(['max_tool_rounds' => 5, 'max_tool_calls' => 8, 'max_context_bytes' => 131072,
            'max_tool_result_bytes' => 65536, 'max_turn_seconds' => 180]);
    }

    public function test_definitions_are_unique_ordered_bounded_and_preserve_the_previous_twenty_contracts(): void
    {
        $old = [...(new ToolRegistry)->definitions(), ...(new TaskActionRegistry)->definitions(),
            ...(new SubtaskReminderActionRegistry)->readDefinitions(), ...(new SubtaskReminderActionRegistry)->definitions()];
        $registry = new PlanningActionRegistry;
        $new = $registry->definitions();
        $this->assertSame(PlanningActionRegistry::NAMES, array_column($new, 'name'));
        $this->assertCount(23, array_unique(array_column([...$old, ...$new], 'name')));
        foreach ((new OpenAiToolSchema)->definitions($new) as $i => $definition) {
            $this->assertFalse($definition['strict']);
            $this->assertSame($new[$i]['parameters'], $definition['parameters']);
            $this->assertLessThanOrEqual(32, strlen($definition['name']));
            foreach (['preview_token', 'user_id', 'timezone', 'accept_partial', 'accept_unplaced_cancellation', 'sessions'] as $private) {
                $this->assertArrayNotHasKey($private, $definition['parameters']['properties']);
            }
        }
        $this->assertNull($registry->find('reschedule'));
        $this->assertSame(['accept_partial'], PlanningActionConsent::fields(PlanningActionRegistry::NAMES[0]));
        $this->assertSame(['accept_partial', 'accept_unplaced_cancellation'], PlanningActionConsent::fields(PlanningActionRegistry::NAMES[1]));
        $this->assertSame([], PlanningActionConsent::fields(PlanningActionRegistry::NAMES[2]));
        $this->assertSame([], PlanningActionConsent::fields('propose_reminder_create'));
    }

    public static function valid(): array
    {
        return [[PlanningActionRegistry::NAMES[0], ['start_date' => '2026-10-26', 'end_date' => '2026-11-25']],
            [PlanningActionRegistry::NAMES[1], ['start_date' => '2028-02-29', 'end_date' => '2028-02-29', 'task_id' => 1, 'subtask_id' => 2]],
            [PlanningActionRegistry::NAMES[2], ['study_session_id' => PHP_INT_MAX, 'starts_at' => '2026-10-27T14:00:00Z', 'ends_at' => '2026-10-27T14:00:01Z']]];
    }

    #[DataProvider('valid')]
    public function test_arguments_are_exact_and_immutable(string $name, array $arguments): void
    {
        $before = $arguments;
        $this->assertEquals($arguments, $this->validator()->validate($name, $arguments));
        $this->assertSame($before, $arguments);
        $this->assertSame($before, (new OpenAiToolSchema)->arguments($name, $arguments, (new PlanningActionRegistry)->definitions()));
    }

    public static function invalid(): array
    {
        $plan = ['start_date' => '2026-10-26', 'end_date' => '2026-11-01'];
        $session = ['study_session_id' => 1, 'starts_at' => '2026-10-27T14:00:00Z', 'ends_at' => '2026-10-27T15:00:00Z'];
        $cases = [['unknown', $plan]];
        foreach (array_slice(PlanningActionRegistry::NAMES, 0, 2) as $name) {
            $cases[] = [$name, []];
            $cases[] = [$name, ['start_date' => $plan['start_date']]];
            $cases[] = [$name, [...$plan, 'subtask_id' => 1]];
            $cases[] = [$name, [...$plan, 'end_date' => '2026-11-26']];
            $cases[] = [$name, [...$plan, 'end_date' => '2026-10-25']];
            foreach (['2026-02-29', '2026-10-26T00:00:00Z', '2026-1-01', ' 2026-10-26', '', null, 42, []] as $date) {
                $cases[] = [$name, [...$plan, 'start_date' => $date]];
            }
            foreach ([0, -1, '1', 1.0, true, null, []] as $id) {
                $cases[] = [$name, [...$plan, 'task_id' => $id]];
            }
            foreach (['preview_token', 'accept_partial', 'accept_unplaced_cancellation', 'owner_id', 'user_id', 'timezone', 'sessions'] as $key) {
                $cases[] = [$name, [...$plan, $key => true]];
            }
        }
        foreach ($session as $key => $_) {
            $cases[] = [PlanningActionRegistry::NAMES[2], array_diff_key($session, [$key => true])];
        }
        foreach ([0, -1, '1', 1.0, true, null, []] as $id) {
            $cases[] = [PlanningActionRegistry::NAMES[2], [...$session, 'study_session_id' => $id]];
        }
        foreach (['2026-02-30T14:00:00Z', '2026-10-27T14:00:00+00:00', '2026-10-27T14:00:00.000Z', '2026-10-27', '0999-01-01T00:00:00Z', null, 1, []] as $time) {
            $cases[] = [PlanningActionRegistry::NAMES[2], [...$session, 'starts_at' => $time]];
        }
        foreach (['2026-10-27T14:00:00Z', '2026-10-27T13:00:00Z'] as $time) {
            $cases[] = [PlanningActionRegistry::NAMES[2], [...$session, 'ends_at' => $time]];
        }
        foreach (['preview_token', 'accept_partial', 'accept_unplaced_cancellation', 'task_id', 'source_ids'] as $key) {
            $cases[] = [PlanningActionRegistry::NAMES[2], [...$session, $key => true]];
        }

        return $cases;
    }

    #[DataProvider('invalid')]
    public function test_invalid_arguments_never_reach_domain_preparation(string $name, array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator()->validate($name, $arguments);
    }

    public static function pairs(): array
    {
        $pairs = [];
        foreach (PlanningActionRegistry::NAMES as $first) {
            foreach (['propose_task_create', 'propose_subtask_update', 'propose_reminder_cancel', ...PlanningActionRegistry::NAMES] as $second) {
                $pairs[] = [$first, $second];
            }
        }

        return $pairs;
    }

    #[DataProvider('pairs')]
    public function test_global_batch_limit_covers_all_domains_before_any_call_is_prepared(string $first, string $second): void
    {
        $provider = $this->createMock(LlmProvider::class);
        $provider->method('respond')->willReturn(['text' => null, 'state' => [], 'calls' => [
            ['id' => 'one', 'name' => $first, 'arguments' => []], ['id' => 'two', 'name' => $second, 'arguments' => []]]]);
        $planning = $this->createMock(PlanningActionService::class);
        $planning->expects($this->never())->method('prepare');
        $tasks = $this->createMock(TaskActionProposalService::class);
        $tasks->expects($this->never())->method('prepare');
        $management = $this->createMock(SubtaskReminderActionService::class);
        $management->expects($this->never())->method('prepare');
        $reads = $this->createMock(ToolExecutor::class);
        $reads->expects($this->never())->method('execute');
        $agent = new AgentOrchestrator($provider, new ToolRegistry, $reads, $this->limits(), new TaskActionRegistry, $tasks,
            new SubtaskReminderActionRegistry, $management, $this->createMock(ManagementReadTools::class), new PlanningActionRegistry, $planning);
        $owner = new User;
        $owner->timezone = 'UTC';
        $this->expectException(AiAgentException::class);
        $this->expectExceptionMessage('Only one mutation action');
        $agent->run($owner, [], 'Make both changes.');
    }

    public function test_tokens_are_bound_to_stored_parameters_and_expiration_is_not_extended(): void
    {
        $tokens = $this->createMock(StudyPlanConfirmationService::class);
        $arguments = ['start_date' => '2026-10-26', 'end_date' => '2026-11-01'];
        $payload = ['parameters' => [...$arguments, 'task_id' => null, 'subtask_id' => null], 'expires_at' => 1793013300];
        $tokens->method('decode')->with('secret', 42, $this->isType('int'))->willReturn($payload);
        $tokens->method('matches')->willReturnCallback(fn ($a, $b) => $a === $b);
        $service = new PlanningActionService($this->validator(), $this->createMock(StudyPlanPersistenceService::class),
            $this->createMock(StudyReplanningPersistenceService::class), $this->createMock(StudySessionReschedulingService::class),
            $this->createMock(StudySessionMutationContextService::class), $tokens, $this->createMock(StudyReplanningConfirmationService::class),
            new ActionTargetFingerprint, $this->limits());
        $owner = new User;
        $owner->id = 42;
        $proposal = ['type' => PlanningActionRegistry::NAMES[0], 'arguments' => $arguments,
            'protected_payload' => ['preview_token' => 'secret', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $payload['expires_at'])]];
        $this->assertSame(gmdate('Y-m-d H:i:s', $payload['expires_at']), $service->expiration($owner, $proposal));
        $this->assertNull($service->expiration($owner, ['type' => PlanningActionRegistry::NAMES[2]]));
        $proposal['arguments']['task_id'] = 99;
        $this->expectException(AiAgentException::class);
        $service->expiration($owner, $proposal);
    }

    public function test_manual_fingerprints_include_source_history_and_work_changes_with_identical_timestamps(): void
    {
        $task = new Task;
        $task->setRawAttributes(['id' => 1, 'status' => 'pending', 'deadline_at' => '2026-10-28 18:00:00', 'updated_at' => '2026-10-19 08:00:00'], true);
        $child = new Subtask;
        $child->setRawAttributes(['id' => 2, 'task_id' => 1, 'status' => 'pending', 'deadline_at' => null], true);
        $ancestor = new StudySession;
        $ancestor->setRawAttributes(['id' => 3, 'task_id' => 1, 'subtask_id' => 2, 'status' => 'rescheduled', 'rescheduled_from_session_id' => null], true);
        $source = new StudySession;
        $source->setRawAttributes(['id' => 4, 'task_id' => 1, 'subtask_id' => 2, 'status' => 'planned',
            'starts_at' => '2026-10-27 14:00:00', 'ends_at' => '2026-10-27 15:00:00',
            'rescheduled_from_session_id' => 3, 'updated_at' => '2026-10-19 08:00:00'], true);
        $records = ['task' => $task, 'child' => $child, 'session_3' => $ancestor, 'session_4' => $source];
        $fingerprint = new ActionTargetFingerprint;
        $hash = $fingerprint->hash($records);
        $this->assertSame($hash, $fingerprint->hash(array_reverse($records, true)));
        foreach ([[$task, 'deadline_at', '2026-10-28 17:00:00'], [$child, 'status', 'completed'],
            [$ancestor, 'rescheduled_from_session_id', 8], [$source, 'starts_at', '2026-10-27 14:00:01'],
            [$source, 'ends_at', '2026-10-27 15:00:01'], [$source, 'status', 'cancelled'],
            [$source, 'task_id', 9], [$source, 'subtask_id', null], [$source, 'rescheduled_from_session_id', null]] as [$row, $field, $value]) {
            $before = $row->getRawOriginal();
            $row->setRawAttributes([...$before, $field => $value], true);
            $this->assertNotSame($hash, $fingerprint->hash($records));
            $row->setRawAttributes($before, true);
        }
        $this->assertSame($hash, $fingerprint->hash($records));
        $this->assertSame('2026-10-19 08:00:00', $source->getRawOriginal('updated_at'));
    }
}
