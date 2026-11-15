<?php

namespace App\Services\Tasks;

class SubtaskInputRules
{
    public static function rules(bool $create, array $values = []): array
    {
        return array_intersect_key(TaskInputRules::rules($create, $values),
            array_flip(['title', 'description', 'estimated_minutes', 'deadline_at']));
    }
}
