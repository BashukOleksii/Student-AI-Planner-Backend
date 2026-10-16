<?php

namespace Tests\Unit;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\User;
use App\Services\AI\Tools\ReadOnlyTools;
use App\Services\AI\Tools\ToolArgumentValidator;
use App\Services\AI\Tools\ToolExecutor;
use App\Services\AI\Tools\ToolRegistry;
use App\Services\Planning\AvailabilityOccurrenceService;
use App\Services\Planning\FreeTimeService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\TimeIntervalService;
use App\Services\Progress\ProgressComparisonService;
use App\Services\Progress\ProgressStatisticsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class AiToolFoundationTest extends TestCase
{
    private function validator(): ToolArgumentValidator
    {
        $intervals = new TimeIntervalService;
        $availability = new AvailabilityOccurrenceService($intervals);
        $context = new PlanningContextService($intervals, $availability, new FreeTimeService($availability, $intervals));

        return new ToolArgumentValidator($context, new ProgressComparisonService(new ProgressStatisticsService($context, $intervals), $context, $intervals));
    }

    private function owner(): User
    {
        $user = new User;
        $user->id = 123;
        $user->exists = true;

        return $user;
    }

    public static function validCalls(): array
    {
        $period = ['start_date' => '2026-10-01', 'end_date' => '2026-10-31'];

        return [
            ['get_schedule_for_date', ['date' => '2026-10-20']],
            ['list_tasks', []],
            ['find_free_study_slots', [...$period, 'min_slot_minutes' => 65535]],
            ['list_study_sessions', $period],
            ['get_progress_statistics', $period],
            ['get_daily_progress_trends', $period],
            ['compare_progress_periods', ['baseline_start_date' => '2026-09-01', 'baseline_end_date' => '2026-09-07',
                'current_start_date' => '2026-10-01', 'current_end_date' => '2026-10-07']],
        ];
    }

    public function test_registry_has_unique_deterministic_json_schema_definitions_and_no_mutable_registration(): void
    {
        $registry = new ToolRegistry;
        $definitions = $registry->definitions();
        $this->assertSame(array_column(self::validCalls(), 0), array_column($definitions, 'name'));
        $this->assertCount(7, array_unique(array_column($definitions, 'name')));
        $this->assertSame($definitions, json_decode(json_encode($definitions, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        foreach ($definitions as $definition) {
            $this->assertNotEmpty($definition['description']);
            $this->assertSame($definition, $registry->find($definition['name']));
            $schema = $definition['parameters'];
            $this->assertSame('object', $schema['type']);
            $this->assertFalse($schema['additionalProperties']);
            $this->assertIsArray($schema['properties']);
            $this->assertTrue(array_is_list($schema['required']));
            $this->assertSame([], array_diff($schema['required'], array_keys($schema['properties'])));
            $this->assertSame($definition['name'] === 'list_tasks' ? [] : array_values(array_filter(array_keys($schema['properties']),
                fn ($field) => $field !== 'min_slot_minutes')), $schema['required']);
            foreach ($schema['properties'] as $rule) {
                $this->assertContains($rule['type'], ['string', 'integer']);
                if ($rule['type'] === 'integer') {
                    $this->assertSame(1, $rule['minimum']);
                    $this->assertIsInt($rule['maximum']);
                } elseif (! isset($rule['enum'])) {
                    $this->assertSame('date', $rule['format']);
                    $this->assertSame('^[0-9]{4}-[0-9]{2}-[0-9]{2}$', $rule['pattern']);
                }
            }
        }
        $task = $registry->find('list_tasks')['parameters']['properties'];
        $this->assertSame(array_column(TaskStatus::cases(), 'value'), $task['status']['enum']);
        $this->assertSame(array_column(TaskPriority::cases(), 'value'), $task['priority']['enum']);
        $this->assertSame(50, $task['per_page']['maximum']);
        $definitions[0]['parameters']['properties']['user_id'] = ['type' => 'integer'];
        $this->assertSame((new ToolRegistry)->definitions(), $registry->definitions());
        $this->assertNull($registry->find('TaskService::delete'));
    }

    #[DataProvider('validCalls')]
    public function test_success_envelope_passes_trusted_owner_and_immutable_arguments(string $name, array $arguments): void
    {
        $owner = $this->owner();
        $before = $arguments;
        $attributes = $owner->getAttributes();
        $tools = $this->createMock(ReadOnlyTools::class);
        $tools->expects($this->once())->method('execute')->with($this->identicalTo($owner), $name, $arguments)->willReturn(['value' => 1]);
        $executor = new ToolExecutor(new ToolRegistry, $this->validator(), $tools);
        $this->assertSame(['ok' => true, 'tool' => $name, 'data' => ['value' => 1]], $executor->execute($owner, $name, $arguments));
        $this->assertSame($before, $arguments);
        $this->assertSame($attributes, $owner->getAttributes());
    }

    public function test_unknown_names_cannot_dispatch_arbitrary_classes_or_services(): void
    {
        $tools = $this->createMock(ReadOnlyTools::class);
        $tools->expects($this->never())->method('execute');
        $executor = new ToolExecutor(new ToolRegistry, $this->validator(), $tools);
        foreach (['', 'GET_SCHEDULE_FOR_DATE', 'list_tasks ', 'delete_tasks', ReadOnlyTools::class, 'App\\Services\\Tasks\\TaskService::delete', 'SELECT * FROM users'] as $name) {
            $this->assertSame(['ok' => false, 'tool' => $name, 'error' => ['code' => 'unknown_tool', 'message' => 'Unknown tool.']],
                $executor->execute($this->owner(), $name, []));
        }
    }

    public static function invalidCalls(): iterable
    {
        foreach (self::validCalls() as [$name, $arguments]) {
            foreach (['user_id', 'owner_id', 'timezone', 'class', 'service', 'unknown'] as $field) {
                yield $name.' unknown '.$field => [$name, [...$arguments, $field => 999]];
            }
            foreach ((new ToolRegistry)->find($name)['parameters']['properties'] as $field => $rule) {
                foreach ([null, [], ['nested' => 'value'], new \stdClass, true] as $index => $value) {
                    yield $name.' invalid '.$field.' '.$index => [$name, [...$arguments, $field => $value]];
                }
                if (in_array($field, (new ToolRegistry)->find($name)['parameters']['required'], true)) {
                    $missing = $arguments;
                    unset($missing[$field]);
                    yield $name.' missing '.$field => [$name, $missing];
                }
                if ($rule['type'] === 'integer') {
                    foreach (['1', 1.0, 0, -1, PHP_INT_MAX + 1] as $index => $value) {
                        yield $name.' integer '.$field.' '.$index => [$name, [...$arguments, $field => $value]];
                    }
                } elseif (($rule['format'] ?? null) === 'date') {
                    foreach (['2026-02-29', '0000-01-01', '2026-13-01', '2026-1-01', '2026-10-01 ', ' 2026-10-01', '2026-10-01T00:00:00Z', "2026-10-01\n", 20261001] as $index => $value) {
                        yield $name.' date '.$field.' '.$index => [$name, [...$arguments, $field => $value]];
                    }
                }
            }
        }
        foreach ([['status' => 'cancelled'], ['priority' => 4], ['priority' => '2'], ['per_page' => 51]] as $arguments) {
            yield ['list_tasks', $arguments];
        }
        yield ['find_free_study_slots', ['start_date' => '2026-10-01', 'end_date' => '2026-10-01', 'min_slot_minutes' => 65536]];
        yield ['list_tasks', [1]];
        foreach (['list_study_sessions', 'find_free_study_slots', 'get_progress_statistics', 'get_daily_progress_trends'] as $name) {
            yield [$name, ['start_date' => '2026-10-02', 'end_date' => '2026-10-01']];
            yield [$name, ['start_date' => '2026-10-01', 'end_date' => '2026-11-01']];
        }
        foreach ([['2026-09-01', '2026-09-07', '2026-09-07', '2026-09-13'],
            ['2026-10-01', '2026-10-07', '2026-09-01', '2026-09-07'],
            ['2026-09-01', '2026-09-07', '2026-10-01', '2026-10-08'],
            ['2026-09-01', '2026-09-30', '2026-10-01', '2026-11-01']] as $dates) {
            yield ['compare_progress_periods', array_combine(['baseline_start_date', 'baseline_end_date', 'current_start_date', 'current_end_date'], $dates)];
        }
    }

    #[DataProvider('invalidCalls')]
    public function test_invalid_arguments_never_reach_adapters_and_remain_unchanged(string $name, array $arguments): void
    {
        $before = $arguments;
        $tools = $this->createMock(ReadOnlyTools::class);
        $tools->expects($this->never())->method('execute');
        $executor = new ToolExecutor(new ToolRegistry, $this->validator(), $tools);
        $this->assertSame(['ok' => false, 'tool' => $name, 'error' => ['code' => 'invalid_arguments', 'message' => 'Invalid tool arguments.']],
            $executor->execute($this->owner(), $name, $arguments));
        $this->assertSame($before, $arguments);
    }

    public function test_valid_enums_pagination_edges_and_real_leap_date_are_accepted(): void
    {
        $registry = new ToolRegistry;
        $validator = $this->validator();
        foreach (TaskStatus::cases() as $status) {
            foreach (TaskPriority::cases() as $priority) {
                $validator->validate($registry->find('list_tasks'), ['status' => $status->value, 'priority' => $priority->value,
                    'page' => PHP_INT_MAX, 'per_page' => 50, 'subject_id' => PHP_INT_MAX]);
            }
        }
        $validator->validate($registry->find('get_schedule_for_date'), ['date' => '2024-02-29']);
        $this->addToAssertionCount(7);
    }

    public static function failures(): array
    {
        $validator = new Validator(new Translator(new ArrayLoader, 'en'), [], []);
        $validator->errors()->add('subject_id', 'Foreign identifier 999');

        return [
            [new RuntimeException('SQL secret foreign_id=999'), 'execution_failed', 'Tool execution failed.'],
            [new InvalidArgumentException('Corrupt persisted source 999'), 'execution_failed', 'Tool execution failed.'],
            [new \TypeError('Internal stack and credentials'), 'execution_failed', 'Tool execution failed.'],
            [(new ModelNotFoundException)->setModel('ForeignTask', [999]), 'not_found', 'Resource not found.'],
            [new AuthorizationException('Foreign task name'), 'access_denied', 'Access denied.'],
            [(new AuthorizationException('Foreign task name'))->withStatus(404), 'not_found', 'Resource not found.'],
            [new ValidationException($validator), 'invalid_arguments', 'Invalid tool arguments.'],
        ];
    }

    #[DataProvider('failures')]
    public function test_exceptions_have_safe_stable_errors(Throwable $exception, string $code, string $message): void
    {
        $tools = $this->createMock(ReadOnlyTools::class);
        $tools->expects($this->once())->method('execute')->willThrowException($exception);
        $executor = new ToolExecutor(new ToolRegistry, $this->validator(), $tools);
        $this->assertSame(['ok' => false, 'tool' => 'list_tasks', 'error' => compact('code', 'message')],
            $executor->execute($this->owner(), 'list_tasks', []));
    }

    public function test_unpersisted_user_is_rejected_without_adapter_execution(): void
    {
        $tools = $this->createMock(ReadOnlyTools::class);
        $tools->expects($this->never())->method('execute');
        $executor = new ToolExecutor(new ToolRegistry, $this->validator(), $tools);
        $this->assertSame(['ok' => false, 'tool' => 'list_tasks', 'error' => ['code' => 'access_denied', 'message' => 'Access denied.']],
            $executor->execute(new User, 'list_tasks', []));
    }
}
