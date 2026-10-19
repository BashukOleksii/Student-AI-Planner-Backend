<?php

namespace App\Services\Tasks;

/** Subtasks share Task text, effort and UTC wire constraints, without Task-only fields. */
class SubtaskInputRules
{
    public static function rules(bool $create, array $values = []): array
    {
        return array_intersect_key(TaskInputRules::rules($create, $values),
            array_flip(['title', 'description', 'estimated_minutes', 'deadline_at']));
    }
}
