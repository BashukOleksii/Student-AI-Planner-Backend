<?php

namespace App\Services\AI\Actions;

use Illuminate\Database\Eloquent\Model;

/** Canonical persisted attributes, including actual values rather than second-resolution timestamps alone. */
class ActionTargetFingerprint
{
    public function hash(array $records): string
    {
        $values = [];
        foreach ($records as $name => $record) {
            $attributes = $record instanceof Model ? $record->getRawOriginal() : null;
            if ($attributes !== null) {
                ksort($attributes);
            }
            $values[$name] = $attributes;
        }
        ksort($values);

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
}
