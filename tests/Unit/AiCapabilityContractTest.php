<?php

namespace Tests\Unit;

use App\Services\AI\Actions\LessonActionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AiCapabilities;
use Tests\Support\TestDatabase;

class AiCapabilityContractTest extends TestCase
{
    public function test_current_lesson_discovery_is_distinct_from_historical_dispatch(): void
    {
        $registry = new LessonActionRegistry;
        $this->assertSame(['propose_lesson_create', 'propose_lesson_update', 'propose_lesson_cancel'], array_column($registry->definitions(), 'name'));
        $this->assertNotContains('propose_lesson_delete', AiCapabilities::names());
        $this->assertNotContains('propose_lesson_replacement', AiCapabilities::names());
        $this->assertNotNull($registry->find('propose_lesson_replacement'));
        $this->assertContains('propose_semester_occurrence_create', AiCapabilities::names());
        foreach (['propose_lesson_update', 'propose_lesson_cancel'] as $name) {
            $this->assertSame('integer', $registry->find($name)['parameters']['properties']['lesson_id']['type']);
        }
    }

    public function test_all_current_registered_tools_have_unique_explicit_closed_schemas(): void
    {
        $names = AiCapabilities::names();
        $this->assertNotEmpty($names);
        $this->assertCount(count($names), array_unique($names));
        foreach (AiCapabilities::definitions() as $definition) {
            $this->assertMatchesRegularExpression('/\A[a-z][a-z0-9_]+\z/', $definition['name']);
            $this->assertNotEmpty($definition['description']);
            $schema = $definition['parameters'];
            $this->assertSame('object', $schema['type']);
            $this->assertFalse($schema['additionalProperties']);
            $this->assertSame([], array_diff($schema['required'], array_keys($schema['properties'])));
        }
        foreach (['list_recovery_suggestions', 'get_recovery_suggestion', 'get_recovery_diagnostics'] as $name) {
            $this->assertContains($name, $names);
        }
    }

    public function test_database_selector_rejects_development_and_production_names(): void
    {
        $previous = getenv('PLANNER_TEST_DATABASE');
        try {
            foreach (['student_planner', 'production', 'student_planner_isolated_../prod', 'student_planner_isolated_'] as $name) {
                putenv('PLANNER_TEST_DATABASE='.$name);
                try {
                    TestDatabase::name();
                    $this->fail('Unsafe database selector was accepted.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame('Unsafe isolated test database name.', $exception->getMessage());
                }
            }
        } finally {
            putenv($previous === false ? 'PLANNER_TEST_DATABASE' : 'PLANNER_TEST_DATABASE='.$previous);
        }
    }
}
