<?php

namespace App\Services\AI\Actions;

use App\Enums\TaskPriority;

/** Fixed proposal allowlist. These definitions never authorize business writes. */
class TaskActionRegistry
{
    public const NAMES = ['propose_task_create', 'propose_task_update', 'propose_task_complete', 'propose_task_reopen'];

    public function definitions(): array
    {
        $fields = [
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'description' => ['type' => ['string', 'null'], 'description' => 'At most 65535 UTF-8 bytes. Null clears; omission preserves on update.'],
            'subject_id' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => PHP_INT_MAX],
            'priority' => ['type' => 'integer', 'enum' => array_column(TaskPriority::cases(), 'value')],
            'estimated_minutes' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 65535],
            'deadline_at' => ['type' => ['string', 'null'], 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$',
                'description' => 'Exact UTC instant, years 1000–9999. Clarify ambiguous dates or missing local clock time. Null clears.'],
        ];
        $target = ['task_id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX]];

        return [
            $this->definition(self::NAMES[0], 'Propose creating one task. Required explicit confirmation; no task is created. Never invent Subject IDs or ambiguous deadlines.', $fields, ['title']),
            $this->definition(self::NAMES[1], 'Propose editing one task. Include at least one editable field. Omit unchanged fields; null explicitly clears nullable fields. Requires confirmation.', [...$target, ...$fields], ['task_id']),
            $this->definition(self::NAMES[2], 'Propose completing one pending task, cancelling dependent scheduled reminders. Subtasks and study sessions retain their statuses. Requires confirmation.', $target, ['task_id']),
            $this->definition(self::NAMES[3], 'Propose reopening one completed task. Does not recreate reminders or retired sessions. Requires confirmation.', $target, ['task_id']),
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

    private function definition(string $name, string $description, array $fields, array $required): array
    {
        // Responses strict mode makes every property required and loses absent versus clearing-null semantics.
        // Explicit non-strict provider schemas preserve that distinction; server validation is always strict.
        return ['name' => $name, 'description' => $description, 'strict' => false, 'parameters' => [
            'type' => 'object', 'properties' => $fields, 'required' => $required, 'additionalProperties' => false,
        ]];
    }
}
