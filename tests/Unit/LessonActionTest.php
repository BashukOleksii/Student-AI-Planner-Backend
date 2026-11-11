<?php

namespace Tests\Unit;

use App\Exceptions\AiAgentException;
use App\Models\User;
use App\Services\AI\Actions\AvailabilityActionRegistry;
use App\Services\AI\Actions\AvailabilityActionService;
use App\Services\AI\Actions\LessonActionArgumentValidator;
use App\Services\AI\Actions\LessonActionRegistry;
use App\Services\AI\Actions\LessonActionService;
use App\Services\AI\Actions\PlanningActionRegistry;
use App\Services\AI\Actions\PlanningActionService;
use App\Services\AI\Actions\SemesterActionRegistry;
use App\Services\AI\Actions\SemesterActionService;
use App\Services\AI\Actions\SubtaskReminderActionRegistry;
use App\Services\AI\Actions\SubtaskReminderActionService;
use App\Services\AI\Actions\TaskActionProposalService;
use App\Services\AI\Actions\TaskActionRegistry;
use App\Services\AI\AgentLimits;
use App\Services\AI\AgentOrchestrator;
use App\Services\AI\LlmProvider;
use App\Services\AI\OpenAiToolSchema;
use App\Services\AI\Tools\ManagementReadTools;
use App\Services\AI\Tools\RecoveryReadTools;
use App\Services\AI\Tools\SemesterReadTools;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\AI\Tools\ToolRegistry;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LessonActionTest extends TestCase
{
    private function validator(): LessonActionArgumentValidator
    {
        return new LessonActionArgumentValidator(new LessonActionRegistry, new Factory(new Translator(new ArrayLoader, 'en')));
    }

    private static function createArgs(): array
    {
        return ['subject_id' => 1, 'type' => 'lecture', 'starts_at' => '2026-11-04T07:00:00Z', 'ends_at' => '2026-11-04T08:00:00Z'];
    }

    public function test_allowlist_and_provider_schemas_preserve_all_previous_definitions_and_optional_nulls(): void
    {
        $old = [...(new ToolRegistry)->definitions(), ...(new TaskActionRegistry)->definitions(),
            ...(new SubtaskReminderActionRegistry)->readDefinitions(), ...(new SubtaskReminderActionRegistry)->definitions(),
            ...(new PlanningActionRegistry)->definitions()];
        $definitions = (new LessonActionRegistry)->definitions();
        $this->assertSame(LessonActionRegistry::NAMES, array_column($definitions, 'name'));
        $this->assertCount(31, array_unique(array_column([...$old, ...$definitions], 'name')));
        foreach ((new OpenAiToolSchema)->definitions($definitions) as $i => $definition) {
            $this->assertFalse($definition['strict']);
            $this->assertFalse($definition['parameters']['additionalProperties']);
            $this->assertSame($definitions[$i]['parameters'], $definition['parameters']);
            $this->assertLessThanOrEqual(32, strlen($definition['name']));
        }
        $args = ['lesson_id' => 1, 'teacher_id' => null];
        $this->assertSame($args, $this->validator()->validate('propose_lesson_update', $args));
        $this->assertSame($args, (new OpenAiToolSchema)->arguments('propose_lesson_update', $args, $definitions));
        $this->assertSame(self::createArgs(), $this->validator()->validate('propose_lesson_create', self::createArgs()));
    }

    public static function invalidArguments(): array
    {
        $cases = [['unknown', []], ['propose_lesson_cancel', []], ['propose_lesson_update', ['lesson_id' => 1]],
            ['propose_lesson_replacement', ['lesson_id' => 1]], ['propose_lesson_cancel', ['lesson_id' => 1, 'status' => 'cancelled']]];
        foreach (array_keys(self::createArgs()) as $field) {
            $args = self::createArgs();
            unset($args[$field]);
            $cases[] = ['propose_lesson_create', $args];
        }
        foreach (['subject_id' => [null, 0, -1, '1', 1.0, true, []], 'teacher_id' => [0, '1', true, []],
            'academic_period_id' => [0, '1', 1.0], 'type' => [null, 'invalid', 'LECTURE', []],
            'room' => [str_repeat('a', 101), 1, [], "\xFF"],
            'starts_at' => ['2026-02-30T07:00:00Z', '2026-11-04T24:00:00Z', '2026-11-04T07:00:00+00:00',
                '2026-11-04T07:00:00.000Z', '2026-11-04 07:00:00', '2026-11-04T07:00:00Z ', '0999-11-04T07:00:00Z', null]] as $field => $values) {
            foreach ($values as $value) {
                $cases[] = ['propose_lesson_create', array_replace(self::createArgs(), [$field => $value])];
            }
        }
        foreach (['user_id', 'status', 'schedule_import_batch_id', 'import_fingerprint', 'replaces_lesson_id', 'replacement_id', 'timezone', 'accept_partial'] as $field) {
            $cases[] = ['propose_lesson_create', [...self::createArgs(), $field => 1]];
        }

        return $cases;
    }

    #[DataProvider('invalidArguments')]
    public function test_strict_arguments_reject_missing_unknown_coerced_and_inexact_values(string $name, array $args): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator()->validate($name, $args);
    }

    public static function mixedActions(): array
    {
        $cases = [];
        foreach (LessonActionRegistry::NAMES as $name) {
            foreach (['propose_task_create', 'propose_reminder_cancel', 'propose_subtask_update', 'propose_study_plan_create', 'propose_lesson_create'] as $other) {
                foreach ([false, true] as $separate) {
                    $cases[] = [$name, $other, $separate];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('mixedActions')]
    public function test_one_proposal_limit_includes_invalid_lesson_attempts_across_batches_and_rounds(string $first, string $second, bool $separate): void
    {
        $calls = [['id' => 'one', 'name' => $first, 'arguments' => []], ['id' => 'two', 'name' => $second, 'arguments' => []]];
        $provider = $this->createMock(LlmProvider::class);
        $provider->method('respond')->willReturnOnConsecutiveCalls(...($separate
            ? [['text' => null, 'calls' => [$calls[0]], 'state' => []], ['text' => null, 'calls' => [$calls[1]], 'state' => []]]
            : [['text' => null, 'calls' => $calls, 'state' => []]]));
        $lessons = $this->createMock(LessonActionService::class);
        $lessons->expects($separate ? $this->once() : $this->never())->method('prepare')
            ->willReturn(['proposal' => null, 'result' => ['ok' => false, 'error' => ['code' => 'invalid_arguments']]]);
        $executor = $this->createMock(ToolExecutor::class);
        $executor->expects($this->never())->method('execute');
        $agent = new AgentOrchestrator($provider, new ToolRegistry, $executor,
            new AgentLimits(['max_tool_rounds' => 5, 'max_tool_calls' => 8, 'max_context_bytes' => 131072, 'max_tool_result_bytes' => 65536, 'max_turn_seconds' => 180]),
            new TaskActionRegistry, $this->createMock(TaskActionProposalService::class), new SubtaskReminderActionRegistry,
            $this->createMock(SubtaskReminderActionService::class), $this->createMock(ManagementReadTools::class),
            new PlanningActionRegistry, $this->createMock(PlanningActionService::class), new LessonActionRegistry, $lessons, new AvailabilityActionRegistry, $this->createMock(AvailabilityActionService::class), new SemesterActionRegistry, $this->createMock(SemesterActionService::class), $this->createMock(SemesterReadTools::class), $this->createMock(RecoveryReadTools::class));
        $owner = new User;
        $owner->timezone = 'UTC';
        $this->expectException(AiAgentException::class);
        $this->expectExceptionMessage('Only one mutation action may be proposed per turn.');
        $agent->run($owner, [], 'Do both.');
    }
}
