<?php

namespace Tests\Unit;

use App\Services\AI\Actions\SemesterActionArgumentValidator;
use App\Services\AI\Actions\SemesterActionRegistry;
use App\Services\AI\GeminiToolSchema;
use App\Services\AI\OpenAiToolSchema;
use App\Services\Schedule\EffectiveScheduleCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SemesterActionSchemaTest extends TestCase
{
    public function test_both_provider_schemas_preserve_optional_fields_and_null_classification(): void
    {
        $registry = new SemesterActionRegistry;
        $definitions = [...$registry->readDefinitions(), ...$registry->definitions()];
        $this->assertCount(count($definitions), array_unique(array_column($definitions, 'name')));
        $this->assertCount(count($definitions), (new GeminiToolSchema)->definitions($definitions));
        foreach ((new OpenAiToolSchema)->definitions($definitions) as $tool) {
            $this->assertFalse($tool['strict']);
            $this->assertFalse($tool['parameters']['additionalProperties']);
        }
        $arguments = ['semester_schedule_id' => 1, 'weekly_lesson_rule_id' => 2, 'lesson_type' => null];
        $validator = new SemesterActionArgumentValidator($registry, new EffectiveScheduleCalculator);
        $this->assertSame($arguments, $validator->validate('propose_semester_rule_update', $arguments));
    }

    public static function invalidArguments(): array
    {
        return [
            ['propose_semester_occurrence_create', ['semester_schedule_id' => 1, 'date' => '2026-10-24', 'lessons' => []]],
            ['propose_semester_occurrence_create', ['semester_schedule_id' => 1, 'date' => '2026-10-24',
                'pair_number' => 3, 'starts_at_local' => '12:00:00', 'ends_at_local' => '13:00:00', 'subject_id' => 2, 'teacher_id' => '3']],
            ['propose_semester_rule_delete', ['semester_schedule_id' => '1', 'weekly_lesson_rule_id' => 2]],
            ['propose_semester_rule_delete', ['semester_schedule_id' => 1, 'weekly_lesson_rule_id' => 2, 'user_id' => 3]],
            ['propose_semester_rule_update', ['semester_schedule_id' => 1, 'weekly_lesson_rule_id' => 2]],
            ['propose_semester_date_replace', ['semester_schedule_id' => 1, 'date' => '2026-02-30', 'mode' => 'replace', 'lessons' => []]],
            ['propose_semester_date_replace', ['semester_schedule_id' => 1, 'date' => '2026-10-20', 'mode' => 'replace']],
            ['propose_semester_date_replace', ['semester_schedule_id' => 1, 'date' => '2026-10-20', 'mode' => 'copy_base',
                'changes' => [['weekly_lesson_rule_id' => 2, 'cancelled' => 'true']]]],
            ['propose_semester_occurrence_update', ['semester_schedule_id' => 1, 'date' => '2026-10-20', 'occurrence_id' => 'semester:1:2026-10-20:rule:2', 'room' => ['unsafe']]],
            ['propose_semester_working_saturday', ['semester_schedule_id' => 1, 'occurs_on' => '2026-10-24', 'copied_weekday' => 2, 'source_week_parity' => 'numerator']],
        ];
    }

    public function test_occurrence_creation_accepts_one_complete_lesson_and_no_day_copy_controls(): void
    {
        $registry = new SemesterActionRegistry;
        $validator = new SemesterActionArgumentValidator($registry, new EffectiveScheduleCalculator);
        $arguments = ['semester_schedule_id' => 1, 'date' => '2026-10-24', 'pair_number' => 3,
            'starts_at_local' => '12:00:00', 'ends_at_local' => '13:00:00', 'subject_id' => 2, 'teacher_id' => 3,
            'pair_label' => '3', 'room' => '301', 'lesson_type' => null];
        $this->assertSame($arguments, $validator->validate('propose_semester_occurrence_create', $arguments));
        $properties = $registry->find('propose_semester_occurrence_create')['parameters']['properties'];
        foreach (['lessons', 'changes', 'mode', 'occurrence_id', 'copied_weekday', 'duration_minutes'] as $field) {
            $this->assertArrayNotHasKey($field, $properties);
        }
        foreach (['lessons' => [], 'mode' => 'copy_base', 'occurrence_id' => 'guessed', 'duration_minutes' => 90] as $key => $value) {
            try {
                $validator->validate('propose_semester_occurrence_create', [...$arguments, $key => $value]);
                $this->fail('Unexpected occurrence creation field accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('invalidArguments')]
    public function test_transport_does_not_coerce_or_accept_unapproved_fields(string $name, array $arguments): void
    {
        $validator = new SemesterActionArgumentValidator(new SemesterActionRegistry, new EffectiveScheduleCalculator);
        $this->expectException(InvalidArgumentException::class);
        $validator->validate($name, $arguments);
    }
}
