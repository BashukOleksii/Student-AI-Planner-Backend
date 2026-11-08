<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Missed = 'missed';

    public function isUnfinished(): bool
    {
        return $this === self::Pending || $this === self::Missed;
    }
}
