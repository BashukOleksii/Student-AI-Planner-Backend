<?php

namespace App\Services\AI\Actions;

/** Fixed planning and study-session lifecycle proposal adapters. */
class PlanningActionRegistry
{
    public const NAMES = ['propose_study_plan_create', 'propose_adaptive_replanning', 'propose_session_reschedule',
        'propose_session_mark_missed', 'propose_session_complete'];

    public function definitions(): array
    {
        $date = ['type' => 'string', 'format' => 'date', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'];
        $id = ['type' => 'integer', 'minimum' => 1, 'maximum' => PHP_INT_MAX];
        $scope = ['start_date' => $date, 'end_date' => $date, 'task_id' => $id, 'subtask_id' => $id];
        $actualMinutes = ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 65535,
            'description' => 'Optional actual minutes spent. Null or omission preserves unknown actual effort; never estimate it.'];
        $time = ['type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$',
            'description' => 'Real whole-second UTC instant in years 1000–9999. Clarify missing or ambiguous local clock times.'];

        return [
            $this->definition(self::NAMES[0], 'Propose a server-calculated study plan over 1–31 inclusive local dates in the persisted timezone. Use get_study_recovery_overview first for recovery requests. Optional owned task/subtask scope; a subtask requires its parent. Never invent IDs or intervals. Partial plans require explicit client acceptance. Requires authenticated confirmation before saving.', $scope, ['start_date', 'end_date']),
            $this->definition(self::NAMES[1], 'Propose deterministic adaptive replanning over 1–31 inclusive local dates. Preserve valid/cannot-replan sessions; review all affected sources, shorter replacements and unplaced cancellations. Separate partial and cancellation consent comes only from the client confirmation request. Requires confirmation.', $scope, ['start_date', 'end_date']),
            $this->definition(self::NAMES[2], 'Propose moving one identified future planned session to an explicit UTC interval. Only preliminary lifecycle/duration checks are available; feasibility is validated by the server at confirmation. Never invent a clock time. Requires confirmation.',
                ['study_session_id' => $id, 'starts_at' => $time, 'ends_at' => $time], ['study_session_id', 'starts_at', 'ends_at']),
            $this->definition(self::NAMES[3], 'Propose marking one identified owned planned study session missed. It must already have ended. Never infer that it was missed from its date. The existing session is updated only after authenticated confirmation; dependent scheduled reminders are cancelled then.',
                ['study_session_id' => $id], ['study_session_id']),
            $this->definition(self::NAMES[4], 'Propose marking one identified owned planned study session completed. It must have started. Supply actual_minutes only when the student states the value; otherwise leave it null. The existing session is updated only after authenticated confirmation; dependent scheduled reminders are cancelled then.',
                ['study_session_id' => $id, 'actual_minutes' => $actualMinutes], ['study_session_id']),
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
        return compact('name', 'description') + ['strict' => false, 'parameters' => [
            'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false,
        ]];
    }
}
