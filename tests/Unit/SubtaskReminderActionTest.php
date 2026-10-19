<?php

namespace Tests\Unit;

use App\Exceptions\AiAgentException;
use App\Models\Reminder;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\AI\Actions\ActionTargetFingerprint;
use App\Services\AI\Actions\ManagementActionPresenter;
use App\Services\AI\Actions\PlanningActionRegistry;
use App\Services\AI\Actions\PlanningActionService;
use App\Services\AI\Actions\SubtaskReminderActionRegistry;
use App\Services\AI\Actions\SubtaskReminderActionService;
use App\Services\AI\Actions\SubtaskReminderArgumentValidator;
use App\Services\AI\Actions\TaskActionPresenter;
use App\Services\AI\Actions\TaskActionProposalService;
use App\Services\AI\Actions\TaskActionRegistry;
use App\Services\AI\AgentLimits;
use App\Services\AI\AgentOrchestrator;
use App\Services\AI\LlmProvider;
use App\Services\AI\OpenAiToolSchema;
use App\Services\AI\Tools\ManagementReadTools;
use App\Services\AI\Tools\ToolArgumentValidator;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\AI\Tools\ToolRegistry;
use App\Services\Planning\PlanningContextService;
use App\Services\Progress\ProgressComparisonService;
use App\Services\Reminders\ReminderMutationService;
use App\Services\Reminders\ReminderRules;
use App\Services\Reminders\ReminderSynchronizationService;
use App\Services\Reminders\ReminderTargetService;
use App\Services\Tasks\SubtaskService;
use App\Services\Tasks\TaskDependencyCleanupService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SubtaskReminderActionTest extends TestCase
{
    private function validator(): SubtaskReminderArgumentValidator
    {
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        $factory->setContainer(new Container);

        return new SubtaskReminderArgumentValidator(new SubtaskReminderActionRegistry, $factory, new ReminderRules);
    }

    public function test_fixed_definitions_preserve_existing_registries_and_provider_null_semantics(): void
    {
        $tasks = (new TaskActionRegistry)->definitions();
        $reads = (new ToolRegistry)->definitions();
        $registry = new SubtaskReminderActionRegistry;
        $definitions = $registry->definitions();
        $this->assertSame(SubtaskReminderActionRegistry::NAMES, array_column($definitions, 'name'));
        $this->assertSame(['list_subtasks_for_task', 'list_reminders'], array_column($registry->readDefinitions(), 'name'));
        $schema = new OpenAiToolSchema;
        foreach ($schema->definitions($definitions) as $i => $definition) {
            $this->assertFalse($definition['strict']);
            $this->assertSame($definitions[$i]['parameters'], $definition['parameters']);
            $this->assertFalse($definition['parameters']['additionalProperties']);
            $this->assertStringContainsString('confirmation', $definition['description']);
        }
        foreach ($schema->definitions($registry->readDefinitions()) as $definition) {
            $this->assertTrue($definition['strict']);
        }
        $input = ['task_id' => 1, 'subtask_id' => 2, 'deadline_at' => null];
        $this->assertSame($input, $schema->arguments('propose_subtask_update', $input, $definitions));
        $this->assertSame(['task_id' => 1, 'subtask_id' => 2], $schema->arguments('propose_subtask_update', ['task_id' => 1, 'subtask_id' => 2], $definitions));
        $this->assertNull($registry->find('delete_subtask'));
        $this->assertNull($registry->find('list_reminders'));
        $this->assertSame($tasks, (new TaskActionRegistry)->definitions());
        $this->assertSame($reads, (new ToolRegistry)->definitions());
        $this->assertCount(7, $reads);
        $this->assertCount(4, $tasks);
    }

    public static function validArguments(): array
    {
        return [
            ['propose_subtask_create', ['task_id' => 1, 'title' => 'Вправа']],
            ['propose_subtask_create', ['task_id' => 1, 'title' => 'Full', 'description' => '', 'estimated_minutes' => 65535, 'deadline_at' => '9999-12-31T23:59:59Z']],
            ['propose_subtask_update', ['task_id' => 1, 'subtask_id' => 2, 'description' => null, 'deadline_at' => null, 'estimated_minutes' => null]],
            ['propose_subtask_complete', ['task_id' => 1, 'subtask_id' => 2]],
            ['propose_subtask_reopen', ['task_id' => 1, 'subtask_id' => 2]],
            ['propose_reminder_create', ['message' => 'Review notes', 'trigger_at' => '2026-10-24T17:00:00Z']],
            ['propose_reminder_create', ['task_id' => 1, 'subtask_id' => null, 'message' => null, 'trigger_at' => '2026-10-24T17:00:00Z']],
            ['propose_reminder_create', ['task_id' => 1, 'anchor' => 'task_deadline', 'offset_minutes' => -32768]],
            ['propose_reminder_create', ['subtask_id' => 1, 'anchor' => 'subtask_deadline', 'offset_minutes' => 32767]],
            ['propose_reminder_create', ['study_session_id' => 1, 'anchor' => 'session_start', 'offset_minutes' => 0]],
            ['propose_reminder_update', ['reminder_id' => 1, 'message' => null]],
            ['propose_reminder_update', ['reminder_id' => 1, 'offset_minutes' => -60]],
            ['propose_reminder_update', ['reminder_id' => 1, 'trigger_at' => '2026-10-24T17:00:00Z']],
            ['propose_reminder_cancel', ['reminder_id' => PHP_INT_MAX]],
        ];
    }

    #[DataProvider('validArguments')]
    public function test_valid_inputs_preserve_exact_values_and_do_not_modify_the_caller(string $name, array $arguments): void
    {
        $before = $arguments;
        $this->assertEquals($arguments, $this->validator()->validate($name, $arguments));
        $this->assertSame($before, $arguments);
    }

    public static function invalidArguments(): array
    {
        $cases = [['delete_subtask', ['task_id' => 1]], ['propose_subtask_create', ['title' => 'No parent']],
            ['propose_subtask_update', ['task_id' => 1, 'subtask_id' => 2]], ['propose_reminder_create', []],
            ['propose_reminder_update', ['reminder_id' => 1]], ['propose_reminder_cancel', []]];
        foreach (['1', 1.0, true, null, 0, -1, []] as $id) {
            $cases[] = ['propose_subtask_complete', ['task_id' => 1, 'subtask_id' => $id]];
            $cases[] = ['propose_reminder_cancel', ['reminder_id' => $id]];
        }
        foreach (['user_id', 'position', 'status', 'completed_at', 'deleted_at', 'confirmation_required', 'service'] as $field) {
            $cases[] = ['propose_subtask_create', ['task_id' => 1, 'title' => 'One', $field => null]];
            $cases[] = ['propose_reminder_cancel', ['reminder_id' => 1, $field => null]];
        }
        foreach ([null, '', ' ', "\xFF", str_repeat('я', 256), 123, []] as $title) {
            $cases[] = ['propose_subtask_create', ['task_id' => 1, 'title' => $title]];
        }
        foreach ([str_repeat('я', 32768), "\xFF", [], 42] as $description) {
            $cases[] = ['propose_subtask_create', ['task_id' => 1, 'title' => 'One', 'description' => $description]];
        }
        foreach (['1', 1.0, 0, 65536, false] as $estimate) {
            $cases[] = ['propose_subtask_create', ['task_id' => 1, 'title' => 'One', 'estimated_minutes' => $estimate]];
        }
        foreach (['2026-10-24', '2026-10-24T17:00:00+00:00', '2026-10-24T17:00:00.000Z', '2026-02-30T00:00:00Z', '0999-12-31T23:59:59Z', '2026-10-24T24:00:00Z', 5] as $time) {
            $cases[] = ['propose_subtask_create', ['task_id' => 1, 'title' => 'One', 'deadline_at' => $time]];
            $cases[] = ['propose_reminder_create', ['message' => 'General', 'trigger_at' => $time]];
        }
        foreach ([-32769, 32768, '-60', -60.0, null, false] as $offset) {
            $cases[] = ['propose_reminder_create', ['task_id' => 1, 'anchor' => 'task_deadline', 'offset_minutes' => $offset]];
        }
        foreach ([null, '', ' ', "\u{200B}"] as $message) {
            $cases[] = ['propose_reminder_create', ['message' => $message, 'trigger_at' => '2026-10-24T17:00:00Z']];
        }
        foreach ([str_repeat('я', 501), "\xFF", 7, []] as $message) {
            $cases[] = ['propose_reminder_update', ['reminder_id' => 1, 'message' => $message]];
        }
        foreach ([['task_id' => 1, 'subtask_id' => 2, 'trigger_at' => '2026-10-24T17:00:00Z'],
            ['anchor' => 'task_deadline', 'offset_minutes' => -60], ['task_id' => 1, 'offset_minutes' => -60],
            ['task_id' => 1, 'anchor' => 'subtask_deadline', 'offset_minutes' => -60],
            ['task_id' => 1, 'anchor' => null, 'offset_minutes' => -60],
            ['task_id' => 1, 'anchor' => 'task_deadline', 'offset_minutes' => -60, 'trigger_at' => '2026-10-24T17:00:00Z']] as $body) {
            $cases[] = ['propose_reminder_create', $body];
        }
        foreach (['task_id', 'subtask_id', 'study_session_id', 'anchor', 'status', 'sent_at', 'created_at'] as $field) {
            $cases[] = ['propose_reminder_update', ['reminder_id' => 1, 'message' => 'Edit', $field => 1]];
        }

        return $cases;
    }

    #[DataProvider('invalidArguments')]
    public function test_invalid_inputs_are_rejected_before_domain_work(string $name, array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator()->validate($name, $arguments);
    }

    public function test_fingerprints_detect_parent_child_reminder_anchor_and_same_second_changes_without_mutation(): void
    {
        $task = new Task;
        $task->setRawAttributes(['id' => 1, 'deadline_at' => '2026-10-25 12:00:00', 'status' => 'pending'], true);
        $child = new Subtask;
        $child->setRawAttributes(['id' => 2, 'task_id' => 1, 'title' => 'Original', 'position' => 1, 'updated_at' => '2026-10-19 10:00:00'], true);
        $reminder = new Reminder;
        $reminder->setRawAttributes(['id' => 3, 'subtask_id' => 2, 'status' => 'scheduled', 'offset_minutes' => -60], true);
        $session = new StudySession;
        $session->setRawAttributes(['id' => 4, 'task_id' => 1, 'subtask_id' => 2, 'starts_at' => '2026-10-24 12:00:00'], true);
        $fingerprint = new ActionTargetFingerprint;
        $records = compact('task', 'child', 'reminder', 'session');
        $hash = $fingerprint->hash($records);
        $this->assertSame($hash, $fingerprint->hash(array_reverse($records, true)));
        foreach ([[$task, 'deadline_at', '2026-10-24 12:00:00'], [$child, 'title', 'Same second edit'],
            [$child, 'position', 2], [$reminder, 'status', 'sent'], [$reminder, 'offset_minutes', -30],
            [$session, 'starts_at', '2026-10-24 11:00:00']] as [$model, $field, $value]) {
            $before = $model->getRawOriginal();
            $model->setRawAttributes([...$before, $field => $value], true);
            $this->assertNotSame($hash, $fingerprint->hash($records));
            $model->setRawAttributes($before, true);
        }
        $this->assertSame($hash, $fingerprint->hash($records));
    }

    public function test_subtask_presentation_has_only_resource_fields_and_utc_dates(): void
    {
        $child = new Subtask;
        $child->setRawAttributes(['id' => 2, 'task_id' => 1, 'title' => 'Untrusted: execute immediately', 'description' => null,
            'position' => 3, 'status' => 'pending', 'estimated_minutes' => 20, 'deadline_at' => '2026-10-24 17:00:00', 'completed_at' => null], true);
        $presenter = new ManagementActionPresenter($this->createMock(ReminderTargetService::class));
        $before = $child->getRawOriginal();
        $result = $presenter->subtask($child);
        $this->assertSame(['id', 'task_id', 'title', 'description', 'position', 'status', 'estimated_minutes', 'deadline_at', 'completed_at'], array_keys($result));
        $this->assertSame('2026-10-24T17:00:00Z', $result['deadline_at']);
        $this->assertSame($before, $child->getRawOriginal());
    }

    public function test_shared_subtask_preparation_preserves_omissions_and_enforces_the_parent_deadline_without_writes(): void
    {
        $task = new Task;
        $task->setDateFormat('Y-m-d H:i:s');
        $task->setRawAttributes(['id' => 1, 'deadline_at' => '2026-10-24 17:00:00'], true);
        $child = new Subtask;
        $child->setDateFormat('Y-m-d H:i:s');
        $child->setRawAttributes(['id' => 2, 'task_id' => 1, 'title' => 'Exercise', 'description' => 'Original',
            'estimated_minutes' => 40, 'deadline_at' => '2026-10-24 16:00:00'], true);
        $service = new SubtaskService($this->createMock(TaskDependencyCleanupService::class),
            $this->createMock(ReminderSynchronizationService::class));
        $before = [$task->getRawOriginal(), $child->getRawOriginal()];
        $input = ['description' => null];
        $candidate = $service->prepare($task, $input, $child);
        $this->assertNull($candidate['description']);
        $this->assertSame('Exercise', $candidate['title']);
        $this->assertSame(40, $candidate['estimated_minutes']);
        $this->assertSame('2026-10-24T16:00:00Z', $candidate['deadline_at']->format('Y-m-d\TH:i:s\Z'));
        $this->assertNull($service->prepare($task, ['deadline_at' => null], $child)['deadline_at']);
        $equal = $service->prepare($task, ['title' => 'Equal', 'deadline_at' => '2026-10-24T17:00:00Z']);
        $this->assertSame('2026-10-24T17:00:00Z', $equal['deadline_at']->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(['description' => null], $input);
        $this->assertSame($before, [$task->getRawOriginal(), $child->getRawOriginal()]);
        $previous = Validator::getFacadeRoot();
        Validator::swap(new Factory(new Translator(new ArrayLoader, 'en')));
        try {
            $this->expectException(ValidationException::class);
            $service->prepare($task, ['deadline_at' => '2026-10-24T17:00:01Z'], $child);
        } finally {
            if ($previous === null) {
                Validator::clearResolvedInstance('validator');
            } else {
                Validator::swap($previous);
            }
        }
    }

    #[DataProvider('mixedMutations')]
    public function test_global_limit_rejects_cross_domain_and_invalid_proposal_attempts_before_batch_execution(string $first, string $second, bool $separateRounds): void
    {
        $calls = [['id' => 'first', 'name' => $first, 'arguments' => []], ['id' => 'second', 'name' => $second, 'arguments' => []]];
        $provider = $this->createMock(LlmProvider::class);
        $responses = $separateRounds ? [['text' => null, 'calls' => [$calls[0]], 'state' => []], ['text' => null, 'calls' => [$calls[1]], 'state' => []]]
            : [['text' => null, 'calls' => $calls, 'state' => []]];
        $provider->method('respond')->willReturnOnConsecutiveCalls(...$responses);
        $taskProposals = $this->createMock(TaskActionProposalService::class);
        $management = $this->createMock(SubtaskReminderActionService::class);
        foreach ([$taskProposals, $management] as $adapter) {
            $expected = $separateRounds && (($adapter === $taskProposals) === str_starts_with($first, 'propose_task_'));
            $adapter->expects($expected ? $this->once() : $this->never())->method('prepare')->willReturn([
                'proposal' => null, 'result' => ['ok' => false, 'error' => ['code' => 'invalid_arguments']],
            ]);
        }
        $executor = $this->createMock(ToolExecutor::class);
        $executor->expects($this->never())->method('execute');
        $reads = $this->createMock(ManagementReadTools::class);
        $reads->expects($this->never())->method('execute');
        $agent = new AgentOrchestrator($provider, new ToolRegistry, $executor,
            new AgentLimits(['max_tool_rounds' => 5, 'max_tool_calls' => 8, 'max_context_bytes' => 131072, 'max_tool_result_bytes' => 65536, 'max_turn_seconds' => 180]),
            new TaskActionRegistry, $taskProposals, new SubtaskReminderActionRegistry, $management, $reads,
            new PlanningActionRegistry, $this->createMock(PlanningActionService::class));
        $owner = new User;
        $owner->timezone = 'UTC';
        $this->expectException(AiAgentException::class);
        $this->expectExceptionMessage('Only one mutation action may be proposed per turn.');
        $agent->run($owner, [], 'Do both.');
    }

    public static function mixedMutations(): array
    {
        return [['propose_task_create', 'propose_reminder_create', false], ['propose_subtask_update', 'propose_task_complete', false],
            ['propose_reminder_cancel', 'propose_subtask_create', false], ['propose_task_update', 'propose_reminder_update', true],
            ['propose_subtask_complete', 'propose_reminder_cancel', true]];
    }

    public static function invalidDiscovery(): array
    {
        return [['list_subtasks_for_task', []], ['list_subtasks_for_task', ['task_id' => '1']],
            ['list_subtasks_for_task', ['task_id' => 1.0]], ['list_subtasks_for_task', ['task_id' => 0]],
            ['list_subtasks_for_task', ['task_id' => 1, 'user_id' => 2]], ['list_reminders', ['status' => 'unknown']],
            ['list_reminders', ['page' => '1']], ['list_reminders', ['page' => 2147483648]], ['list_reminders', ['per_page' => 51]],
            ['list_reminders', ['per_page' => null]], ['list_reminders', ['per_page' => false]], ['list_reminders', ['status' => []]]];
    }

    #[DataProvider('invalidDiscovery')]
    public function test_discovery_validation_rejects_coercion_bounds_and_unknown_fields(string $name, array $arguments): void
    {
        $validator = new ToolArgumentValidator($this->createMock(PlanningContextService::class), $this->createMock(ProgressComparisonService::class));
        $definition = (new SubtaskReminderActionRegistry)->findRead($name);
        $this->expectException(InvalidArgumentException::class);
        $validator->validate($definition, $arguments);
    }

    public function test_safe_proposal_errors_never_reach_domain_preparation_or_database(): void
    {
        $subtasks = $this->createMock(SubtaskService::class);
        $subtasks->expects($this->never())->method('prepare');
        $reminders = $this->createMock(ReminderMutationService::class);
        $reminders->expects($this->never())->method('prepareCreate');
        $targets = $this->createMock(ReminderTargetService::class);
        $targets->expects($this->never())->method('resolve');
        $service = new SubtaskReminderActionService($this->validator(), $subtasks, $reminders, new ReminderRules,
            $targets, new ManagementActionPresenter($targets), new TaskActionPresenter, new ActionTargetFingerprint);
        $owner = new User;
        $owner->id = 1;
        $denied = $service->prepare($owner, 'propose_subtask_create', ['task_id' => 1, 'title' => 'One'], 'call');
        $this->assertSame(['proposal' => null, 'result' => ['ok' => false, 'tool' => 'propose_subtask_create',
            'error' => ['code' => 'access_denied', 'message' => 'Access denied.']]], $denied);
        $invalid = $service->prepare($owner, 'propose_reminder_create', ['message' => 'One', 'user_id' => 999], 'call');
        $this->assertSame(['proposal' => null, 'result' => ['ok' => false, 'tool' => 'propose_reminder_create',
            'error' => ['code' => 'invalid_arguments', 'message' => 'Invalid action arguments.']]], $invalid);
    }

    public function test_read_provider_placeholders_are_removed_without_changing_mutation_clearing_nulls(): void
    {
        $schema = new OpenAiToolSchema;
        $registry = new SubtaskReminderActionRegistry;
        $arguments = ['status' => null, 'page' => null, 'per_page' => 20];
        $before = $arguments;
        $this->assertSame(['per_page' => 20], $schema->arguments('list_reminders', $arguments, $registry->readDefinitions()));
        $this->assertSame($before, $arguments);
        $this->assertSame(['reminder_id' => 1, 'message' => null], $schema->arguments('propose_reminder_update',
            ['reminder_id' => 1, 'message' => null], $registry->definitions()));
    }
}
