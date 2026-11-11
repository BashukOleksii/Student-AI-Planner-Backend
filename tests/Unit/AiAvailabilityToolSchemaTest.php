<?php

namespace Tests\Unit;

use App\Services\AI\Actions\LessonActionRegistry;
use App\Services\AI\Actions\PlanningActionRegistry;
use App\Services\AI\Actions\SubtaskReminderActionRegistry;
use App\Services\AI\Actions\TaskActionRegistry;
use App\Services\AI\GeminiToolSchema;
use App\Services\AI\OpenAiToolSchema;
use App\Services\AI\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

class AiAvailabilityToolSchemaTest extends TestCase
{
    public function test_exact_schema_and_all_previous_definitions_are_preserved(): void
    {
        $registry = new ToolRegistry;
        $definition = $registry->find('list_study_availability_windows');
        $this->assertSame([
            'name' => 'list_study_availability_windows',
            'description' => 'Read configured recurring weekly study availability windows, not calculated free slots; ordered by weekday, start time and ID with bounded pagination.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'day_of_week' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
                    'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ],
                'required' => [],
                'additionalProperties' => false,
            ],
        ], $definition);
        $definitions = [...$registry->definitions(), ...(new TaskActionRegistry)->definitions(),
            ...(new SubtaskReminderActionRegistry)->readDefinitions(), ...(new SubtaskReminderActionRegistry)->definitions(),
            ...(new PlanningActionRegistry)->definitions(), ...(new LessonActionRegistry)->definitions()];
        $this->assertCount(31, array_unique(array_column($definitions, 'name')));
        $baseline = json_decode(file_get_contents(__DIR__.'/../Fixtures/AI/tool-definitions-lifecycle-before-availability-actions.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(31, $baseline);
        $baseline = array_values(array_filter($baseline, fn ($item) => $item['name'] !== $definition['name']));
        $this->assertCount(30, $baseline);
        $this->assertSame($baseline, array_values(array_filter($definitions, fn ($item) => $item['name'] !== $definition['name'])));
    }

    public function test_both_provider_schemas_preserve_bounds_and_optional_argument_semantics(): void
    {
        $definition = (new ToolRegistry)->find('list_study_availability_windows');
        $this->assertSame([['name' => $definition['name'], 'description' => $definition['description'],
            'parametersJsonSchema' => $definition['parameters']]], (new GeminiToolSchema)->definitions([$definition]));
        $openai = new OpenAiToolSchema;
        $wire = $openai->definitions([$definition])[0];
        $this->assertTrue($wire['strict']);
        $this->assertSame(['day_of_week', 'page', 'per_page'], $wire['parameters']['required']);
        $this->assertFalse($wire['parameters']['additionalProperties']);
        foreach ($definition['parameters']['properties'] as $field => $rule) {
            $this->assertSame([...$rule, 'type' => ['integer', 'null']], $wire['parameters']['properties'][$field]);
        }
        $this->assertSame([], $openai->arguments($definition['name'], ['day_of_week' => null, 'page' => null, 'per_page' => null], [$definition]));
        $this->assertSame(['day_of_week' => 7, 'per_page' => 50, 'user_id' => null], $openai->arguments($definition['name'],
            ['day_of_week' => 7, 'page' => null, 'per_page' => 50, 'user_id' => null], [$definition]));
    }
}
