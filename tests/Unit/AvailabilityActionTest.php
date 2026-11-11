<?php

namespace Tests\Unit;

use App\Exceptions\AiAgentException;
use App\Models\User;
use App\Services\AI\Actions\AvailabilityActionArgumentValidator;
use App\Services\AI\Actions\AvailabilityActionRegistry;
use App\Services\AI\Actions\AvailabilityActionService;
use App\Services\AI\Actions\LessonActionRegistry;
use App\Services\AI\Actions\LessonActionService;
use App\Services\AI\Actions\PlanningActionRegistry;
use App\Services\AI\Actions\PlanningActionService;
use App\Services\AI\Actions\SubtaskReminderActionRegistry;
use App\Services\AI\Actions\SubtaskReminderActionService;
use App\Services\AI\Actions\TaskActionProposalService;
use App\Services\AI\Actions\TaskActionRegistry;
use App\Services\AI\AgentLimits;
use App\Services\AI\AgentOrchestrator;
use App\Services\AI\GeminiToolSchema;
use App\Services\AI\LlmProvider;
use App\Services\AI\OpenAiToolSchema;
use App\Services\AI\Tools\ManagementReadTools;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\AI\Tools\ToolRegistry;
use Illuminate\Container\Container;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AvailabilityActionTest extends TestCase
{
    private function validator(): AvailabilityActionArgumentValidator
    {
        $factory = new Factory(new Translator(new ArrayLoader, 'en'));
        $factory->setContainer(new Container);

        return new AvailabilityActionArgumentValidator(new AvailabilityActionRegistry, $factory);
    }

    public function test_exact_schemas_preserve_current_other_definitions_and_both_provider_contracts(): void
    {
        $old = [...(new ToolRegistry)->definitions(), ...(new TaskActionRegistry)->definitions(),
            ...(new SubtaskReminderActionRegistry)->readDefinitions(), ...(new SubtaskReminderActionRegistry)->definitions(),
            ...(new PlanningActionRegistry)->definitions(), ...(new LessonActionRegistry)->definitions()];
        $this->assertSame(json_decode(file_get_contents(__DIR__.'/../Fixtures/AI/tool-definitions-lifecycle-before-availability-actions.json'), true, flags: JSON_THROW_ON_ERROR), $old);
        $definitions = (new AvailabilityActionRegistry)->definitions();
        $this->assertSame(AvailabilityActionRegistry::NAMES, array_column($definitions, 'name'));
        $this->assertCount(34, array_unique(array_column([...$old, ...$definitions], 'name')));
        $fields = [
            'day_of_week' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
            'starts_at' => ['type' => 'string', 'pattern' => '^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$'],
            'ends_at' => ['type' => 'string', 'pattern' => '^(?:(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]|24:00:00)$'],
        ];
        $target = ['availability_window_id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX]];
        foreach ($definitions as $i => $definition) {
            $this->assertLessThanOrEqual(32, strlen($definition['name']));
            $this->assertFalse($definition['strict']);
            $this->assertSame(['type' => 'object', 'properties' => match ($i) {
                0 => $fields, 1 => [...$target, ...$fields], 2 => $target,
            }, 'required' => $i === 0 ? array_keys($fields) : array_keys($target), 'additionalProperties' => false], $definition['parameters']);
            $this->assertSame($definition['parameters'], (new OpenAiToolSchema)->definitions([$definition])[0]['parameters']);
            $this->assertSame($definition['parameters'], (new GeminiToolSchema)->definitions([$definition])[0]['parametersJsonSchema']);
            $this->assertSame($definition, (new AvailabilityActionRegistry)->find($definition['name']));
        }
        $this->assertNull((new AvailabilityActionRegistry)->find('StudyAvailabilityService::delete'));
        $this->assertSame(['availability_window_id' => 1, 'starts_at' => null], (new OpenAiToolSchema)->arguments(
            AvailabilityActionRegistry::NAMES[1], ['availability_window_id' => 1, 'starts_at' => null], $definitions));
    }

    public static function validArguments(): array
    {
        return [
            [AvailabilityActionRegistry::NAMES[0], ['day_of_week' => 1, 'starts_at' => '00:00:00', 'ends_at' => '24:00:00']],
            [AvailabilityActionRegistry::NAMES[0], ['day_of_week' => 7, 'starts_at' => '23:59:58', 'ends_at' => '23:59:59']],
            [AvailabilityActionRegistry::NAMES[1], ['availability_window_id' => PHP_INT_MAX, 'day_of_week' => 7]],
            [AvailabilityActionRegistry::NAMES[1], ['availability_window_id' => 1, 'starts_at' => '16:00:01']],
            [AvailabilityActionRegistry::NAMES[1], ['availability_window_id' => 1, 'ends_at' => '24:00:00']],
            [AvailabilityActionRegistry::NAMES[2], ['availability_window_id' => 1]],
        ];
    }

    #[DataProvider('validArguments')]
    public function test_valid_arguments_preserve_exact_values_and_omission(string $name, array $arguments): void
    {
        $before = $arguments;
        $this->assertSame($arguments, $this->validator()->validate($name, $arguments));
        $this->assertSame($before, $arguments);
    }

    public static function invalidArguments(): iterable
    {
        foreach (self::validArguments() as $case => [$name, $arguments]) {
            foreach (['user_id', 'owner_id', 'timezone', 'class', 'method', 'unknown', 'accept_partial'] as $field) {
                yield $case.' unknown '.$field => [$name, [...$arguments, $field => 1]];
            }
            foreach ($arguments as $field => $valid) {
                foreach ([null, false, 1.0, [], new \stdClass] as $i => $bad) {
                    yield $case.' type '.$field.' '.$i => [$name, [...$arguments, $field => $bad]];
                }
                if (is_int($valid)) {
                    foreach ([0, -1, '1', PHP_INT_MAX + 1] as $i => $bad) {
                        yield $case.' int '.$field.' '.$i => [$name, [...$arguments, $field => $bad]];
                    }
                } else {
                    foreach (['16:00', '1:00:00', ' 16:00:00', '16:00:00 ', "16:00:00\n", '16:60:00', '16:00:60',
                        '2026-11-08T16:00:00Z', '16:00:00+02:00', '24:00:01', '25:00:00', ''] as $i => $bad) {
                        yield $case.' time '.$field.' '.$i => [$name, [...$arguments, $field => $bad]];
                    }
                }
            }
            foreach ((new AvailabilityActionRegistry)->find($name)['parameters']['required'] as $field) {
                $missing = $arguments;
                unset($missing[$field]);
                yield $case.' missing '.$field => [$name, $missing];
            }
        }
        yield ['propose_availability_create', ['day_of_week' => 8, 'starts_at' => '16:00:00', 'ends_at' => '20:00:00']];
        yield ['propose_availability_update', ['availability_window_id' => 1, 'day_of_week' => 8]];
        yield ['propose_availability_update', ['availability_window_id' => 1]];
        yield ['propose_availability_update', ['availability_window_id' => 1, 'starts_at' => '24:00:00']];
        yield ['propose_availability_delete', ['availability_window_id' => 1, 'day_of_week' => 1]];
        yield ['App\\Services\\Planning\\StudyAvailabilityService::delete', ['availability_window_id' => 1]];
        yield ['propose_availability_create', [1]];
    }

    #[DataProvider('invalidArguments')]
    public function test_invalid_arguments_fail_without_coercion_or_business_dispatch(string $name, array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator()->validate($name, $arguments);
    }

    public static function mixedMutations(): iterable
    {
        $all = [...TaskActionRegistry::NAMES, ...SubtaskReminderActionRegistry::NAMES,
            ...PlanningActionRegistry::NAMES, ...LessonActionRegistry::NAMES, ...AvailabilityActionRegistry::NAMES];
        foreach (AvailabilityActionRegistry::NAMES as $first) {
            foreach ($all as $second) {
                foreach ([false, true] as $separate) {
                    yield [$first, $second, $separate];
                }
            }
        }
    }

    #[DataProvider('mixedMutations')]
    public function test_global_one_proposal_limit_includes_availability_and_invalid_attempts(string $first, string $second, bool $separate): void
    {
        $calls = [['id' => 'one', 'name' => $first, 'arguments' => []], ['id' => 'two', 'name' => $second, 'arguments' => []]];
        $provider = $this->createMock(LlmProvider::class);
        $provider->method('respond')->willReturnOnConsecutiveCalls(...($separate
            ? [['text' => null, 'calls' => [$calls[0]], 'state' => []], ['text' => null, 'calls' => [$calls[1]], 'state' => []]]
            : [['text' => null, 'calls' => $calls, 'state' => []]]));
        $availability = $this->createMock(AvailabilityActionService::class);
        $availability->expects($separate ? $this->once() : $this->never())->method('prepare')
            ->willReturn(['proposal' => null, 'result' => ['ok' => false, 'error' => ['code' => 'invalid_arguments']]]);
        $reads = $this->createMock(ToolExecutor::class);
        $reads->expects($this->never())->method('execute');
        $tasks = $this->createMock(TaskActionProposalService::class);
        $management = $this->createMock(SubtaskReminderActionService::class);
        $planning = $this->createMock(PlanningActionService::class);
        $lessons = $this->createMock(LessonActionService::class);
        foreach ([$tasks, $management, $planning, $lessons] as $adapter) {
            $adapter->expects($this->never())->method('prepare');
        }
        $agent = new AgentOrchestrator($provider, new ToolRegistry, $reads,
            new AgentLimits(['max_tool_rounds' => 5, 'max_tool_calls' => 8, 'max_context_bytes' => 131072, 'max_tool_result_bytes' => 65536, 'max_turn_seconds' => 180]),
            new TaskActionRegistry, $tasks, new SubtaskReminderActionRegistry, $management, $this->createMock(ManagementReadTools::class),
            new PlanningActionRegistry, $planning, new LessonActionRegistry, $lessons, new AvailabilityActionRegistry, $availability);
        $owner = new User;
        $owner->timezone = 'UTC';
        $this->expectException(AiAgentException::class);
        $this->expectExceptionMessage('Only one mutation action may be proposed per turn.');
        $agent->run($owner, [], 'Do both.');
    }
}
