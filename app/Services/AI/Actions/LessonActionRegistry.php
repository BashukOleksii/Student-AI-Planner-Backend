<?php

namespace App\Services\AI\Actions;

use App\Enums\LessonType;

/** Proposal-only allowlist. No model-selected method names or execution capability. */
class LessonActionRegistry
{
    public const NAMES = ['propose_lesson_create', 'propose_lesson_update', 'propose_lesson_cancel', 'propose_lesson_replacement'];

    public function definitions(bool $includeRetired = false): array
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

        $definitions = [
            $this->definition(self::NAMES[0], 'Propose one new manual legacy Lesson; no write until explicit confirmation. This does not create or edit a semester occurrence. Use exact owned identifiers from successful reads or explicit user input; never turn a semester occurrence identity into a Lesson ID or create a legacy Lesson as a workaround for a semester edit. Clarify unknown Subject/Teacher/Academic Period IDs.', $fields, $required),
            $this->definition(self::NAMES[1], 'Propose editing one exact owned numeric legacy Lesson ID. Semester occurrence string identities are read-only and cannot be targeted here. Include at least one editable field; omission preserves and null clears optional fields. Import identity and replacement links cannot be edited. Semester weekly rules require the semester management interface. Requires confirmation.', [...$target, ...$fields], ['lesson_id']),
            $this->definition(self::NAMES[2], 'Propose cancelling one active owned numeric legacy Lesson ID only. This does not cancel semester occurrences. A semester schedule edit requires the semester management interface. Retains history; cancelling a replacement does not restore its original. Requires confirmation.', $target, ['lesson_id']),
            $this->definition(self::NAMES[3], 'Propose a complete replacement for one active original numeric legacy Lesson ID only; never use a semester occurrence identity. Semester rules cannot be replaced through this legacy action. Supply Subject, type and both UTC instants explicitly; optional omissions become null, never inherit identifiers. The original becomes replaced only on confirmation.', [...$target, ...$fields], ['lesson_id', ...$required]),
        ];

        // Retain dispatch for existing pending actions/history, but do not offer new replacements.
        return $includeRetired ? $definitions : array_slice($definitions, 0, 3);
    }

    public function find(string $name): ?array
    {
        foreach ($this->definitions(includeRetired: true) as $definition) {
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
