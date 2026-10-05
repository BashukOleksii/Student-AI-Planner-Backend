<?php

namespace App\Enums;

enum StudySessionStatus: string
{
    case Planned = 'planned';
    case Completed = 'completed';
    case Missed = 'missed';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
}
