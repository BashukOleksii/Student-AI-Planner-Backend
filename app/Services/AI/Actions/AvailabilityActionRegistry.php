<?php

namespace App\Services\AI\Actions;

use App\Services\Planning\StudyAvailabilityInputRules;

/** Fixed proposal allowlist. Only authenticated REST confirmation can execute a writer. */
class AvailabilityActionRegistry
{
    public const NAMES = ['propose_availability_create', 'propose_availability_update', 'propose_availability_delete'];

    public function definitions(): array
    {
        $fields = [
            'day_of_week' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 7],
            'starts_at' => ['type' => 'string', 'pattern' => '^'.StudyAvailabilityInputRules::START_TIME_PATTERN.'$'],
            'ends_at' => ['type' => 'string', 'pattern' => '^'.StudyAvailabilityInputRules::END_TIME_PATTERN.'$'],
        ];
        $target = ['availability_window_id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX]];

        return [
            $this->definition(self::NAMES[0], 'Propose one recurring weekly study availability window. Supply the ISO weekday and exact local HH:MM:SS times explicitly; only the end may be 24:00:00. Requires confirmation; does not reschedule existing sessions.', $fields, array_keys($fields)),
            $this->definition(self::NAMES[1], 'Propose editing one exact owned recurring availability window. Discover IDs with list_study_availability_windows. Include at least one editable field; omission preserves values, null is invalid. Requires confirmation; does not reschedule existing sessions.', [...$target, ...$fields], array_keys($target)),
            $this->definition(self::NAMES[2], 'Propose deleting exactly one owned availability configuration record identified by list_study_availability_windows. Requires confirmation. Does not delete Tasks, Lessons, StudySessions or Reminders, or reschedule sessions. No bulk deletion.', $target, array_keys($target)),
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
        // Preserve true omission for partial edits; unlike read schemas, null has no omission meaning.
        return ['name' => $name, 'description' => $description, 'strict' => false, 'parameters' => [
            'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false,
        ]];
    }
}
