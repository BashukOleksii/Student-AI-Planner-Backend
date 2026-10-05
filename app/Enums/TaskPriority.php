<?php

namespace App\Enums;

enum TaskPriority: int
{
    case Low = 1;
    case Normal = 2;
    case High = 3;
}
