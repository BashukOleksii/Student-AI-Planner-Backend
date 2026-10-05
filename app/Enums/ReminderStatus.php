<?php

namespace App\Enums;

enum ReminderStatus: string
{
    case Scheduled = 'scheduled';
    case Sent = 'sent';
    case Cancelled = 'cancelled';
}
