<?php

namespace App\Services\Planning;

/** Shared field rules for REST, read-only preparation and confirmed availability writes. */
class StudyAvailabilityInputRules
{
    public const START_TIME_PATTERN = '(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]';

    public const END_TIME_PATTERN = '(?:'.self::START_TIME_PATTERN.'|24:00:00)';

    public static function rules(bool $creating): array
    {
        $presence = $creating ? ['required'] : ['sometimes', 'required'];

        return [
            'day_of_week' => [...$presence, 'integer:strict', 'between:1,7'],
            'starts_at' => [...$presence, 'string', 'regex:/\A'.self::START_TIME_PATTERN.'\z/'],
            'ends_at' => [...$presence, 'string', 'regex:/\A'.self::END_TIME_PATTERN.'\z/'],
        ];
    }
}
