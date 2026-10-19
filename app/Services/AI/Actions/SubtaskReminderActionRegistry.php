<?php

namespace App\Services\AI\Actions;

use App\Enums\ReminderAnchor;
use App\Enums\ReminderStatus;

/** H3b's fixed additions; H1 and H3a registries remain unchanged. */
class SubtaskReminderActionRegistry
{
    public const NAMES = ['propose_subtask_create', 'propose_subtask_update', 'propose_subtask_complete', 'propose_subtask_reopen',
        'propose_reminder_create', 'propose_reminder_update', 'propose_reminder_cancel'];

    public function definitions(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX];
        $time = ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$',
            'description' => 'Real whole-second UTC instant, years 1000–9999. Clarify ambiguous dates and missing clock times.'];
        $fields = ['title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'description' => ['type' => ['string', 'null'], 'description' => 'At most 65535 UTF-8 bytes. Null clears; omission preserves.'],
            'estimated_minutes' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 65535],
            'deadline_at' => [...$time, 'type' => ['string', 'null']]];
        $parent = ['task_id' => $id];
        $child = [...$parent, 'subtask_id' => $id];
        $message = ['message' => ['type' => ['string', 'null'], 'maxLength' => 500]];
        $offset = ['offset_minutes' => ['type' => 'integer', 'minimum' => -32768, 'maximum' => 32767]];

        return [
            $this->definition(self::NAMES[0], 'Propose appending one pending subtask to an identified task. Position is assigned only at confirmation. Never invent IDs. Requires confirmation.', [...$parent, ...$fields], ['task_id', 'title']),
            $this->definition(self::NAMES[1], 'Propose editing one subtask. Include at least one editable field. Null clears nullable fields; omission preserves. Requires confirmation.', [...$child, ...$fields], ['task_id', 'subtask_id']),
            $this->definition(self::NAMES[2], 'Propose completing one pending subtask and cancelling dependent scheduled reminders. Parent and session statuses are preserved. Requires confirmation.', $child, ['task_id', 'subtask_id']),
            $this->definition(self::NAMES[3], 'Propose reopening one completed subtask. Cancelled reminders and retired sessions are not recreated. Requires confirmation.', $child, ['task_id', 'subtask_id']),
            $this->definition(self::NAMES[4], 'Propose scheduling one reminder, not delivery. Absolute: trigger_at and at most one target; a general reminder needs a meaningful message. Relative: exactly one target, matching anchor and offset_minutes; omit trigger_at. Server resolves the trigger. Requires confirmation.', [
                ...$message, 'trigger_at' => $time, 'task_id' => [...$id, 'type' => ['integer', 'null']],
                'subtask_id' => [...$id, 'type' => ['integer', 'null']], 'study_session_id' => [...$id, 'type' => ['integer', 'null']],
                'anchor' => ['type' => 'string', 'enum' => array_column(ReminderAnchor::cases(), 'value')], ...$offset,
            ], []),
            $this->definition(self::NAMES[5], 'Propose editing one scheduled reminder. Include message and/or trigger_at for absolute mode, message and/or offset_minutes for relative mode. Cannot change targets or mode. Requires confirmation.',
                ['reminder_id' => $id, ...$message, 'trigger_at' => $time, ...$offset], ['reminder_id']),
            $this->definition(self::NAMES[6], 'Propose cancelling one scheduled reminder. Keep its history and inbox notifications; change no work or sessions. Requires confirmation.', ['reminder_id' => $id], ['reminder_id']),
        ];
    }

    public function readDefinitions(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX];

        return [
            $this->definition('list_subtasks_for_task', 'Read active subtasks of one owned task ordered by position then ID. At most 100 rows; explicit completeness metadata.', ['task_id' => $id], ['task_id'], true),
            $this->definition('list_reminders', 'Read owned reminder history without recalculation or delivery. Ordered by stored trigger then ID; bounded pagination.', [
                'status' => ['type' => 'string', 'enum' => array_column(ReminderStatus::cases(), 'value')],
                'page' => [...$id, 'maximum' => 2147483647], 'per_page' => [...$id, 'maximum' => 50],
            ], [], true),
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

    public function findRead(string $name): ?array
    {
        foreach ($this->readDefinitions() as $definition) {
            if ($definition['name'] === $name) {
                return $definition;
            }
        }

        return null;
    }

    private function definition(string $name, string $description, array $properties, array $required, bool $strict = false): array
    {
        return compact('name', 'description', 'strict') + ['parameters' => [
            'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false,
        ]];
    }
}
