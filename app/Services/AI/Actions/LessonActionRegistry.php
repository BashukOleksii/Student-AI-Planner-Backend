<?php

namespace App\Services\AI\Actions;

use App\Enums\LessonType;

/** Proposal-only allowlist. No model-selected method names or execution capability. */
class LessonActionRegistry
{
    public const NAMES = ['propose_lesson_create', 'propose_lesson_update', 'propose_lesson_cancel', 'propose_lesson_replacement'];

    public function definitions(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX];
        $instant = ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$',
            'description' => 'Exact whole-second UTC instant, years 1000–9999. Convert an unambiguous local time using the persisted timezone; clarify missing/ambiguous dates and times.'];
        $fields = ['subject_id' => $id, 'teacher_id' => [...$id, 'type' => ['integer', 'null']],
            'academic_period_id' => [...$id, 'type' => ['integer', 'null']],
            'type' => ['type' => 'string', 'enum' => array_column(LessonType::cases(), 'value')],
            'room' => ['type' => ['string', 'null'], 'maxLength' => 100], 'starts_at' => $instant, 'ends_at' => $instant];
        $target = ['lesson_id' => $id];
        $required = ['subject_id', 'type', 'starts_at', 'ends_at'];

        return [
            $this->definition(self::NAMES[0], 'Propose one new manual Lesson; no write until explicit confirmation. Use exact owned identifiers from successful reads or explicit user input; never invent or silently choose IDs. Clarify unknown Subject/Teacher/Academic Period IDs.', $fields, $required),
            $this->definition(self::NAMES[1], 'Propose editing one exact owned Lesson. Include at least one editable field; omission preserves and null clears optional fields. Import identity and replacement links cannot be edited. Requires confirmation.', [...$target, ...$fields], ['lesson_id']),
            $this->definition(self::NAMES[2], 'Propose cancelling one active owned Lesson, retaining its history. Cancelling a replacement does not restore its original. Requires confirmation.', $target, ['lesson_id']),
            $this->definition(self::NAMES[3], 'Propose a complete replacement for one active original Lesson. Supply Subject, type and both UTC instants explicitly; optional omissions become null, never inherit identifiers. The original becomes replaced only on confirmation.', [...$target, ...$fields], ['lesson_id', ...$required]),
        ];
    }

    public function find(string $name): ?array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['name'] === $name) {
                return $definition;
            }
        }

        return null;
    }

    private function definition(string $name, string $description, array $properties, array $required): array
    {
        return ['name' => $name, 'description' => $description, 'strict' => false, 'parameters' => [
            'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false,
        ]];
    }
}
