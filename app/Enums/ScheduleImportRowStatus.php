<?php

namespace App\Enums;

enum ScheduleImportRowStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Duplicate = 'duplicate';
}
