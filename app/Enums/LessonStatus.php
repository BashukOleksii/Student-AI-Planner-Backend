<?php

namespace App\Enums;

enum LessonStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';
    case Replaced = 'replaced';
}
