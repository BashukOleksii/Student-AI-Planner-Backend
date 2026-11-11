<?php

namespace Tests\Unit;

use App\Services\AI\GeminiToolSchema;
use App\Services\AI\OpenAiToolSchema;
use App\Services\AI\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AiCapabilities;

class AiAvailabilityToolSchemaTest extends TestCase
{
    public function test_current_availability_schema_is_closed_and_registered_without_name_collisions(): void
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
        $this->assertContains($definition, AiCapabilities::definitions());
        $this->assertCount(count(AiCapabilities::names()), array_unique(AiCapabilities::names()));
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
