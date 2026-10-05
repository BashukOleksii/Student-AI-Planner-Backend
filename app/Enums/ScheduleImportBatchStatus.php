<?php

namespace App\Enums;

enum ScheduleImportBatchStatus: string
{
    case Uploaded = 'uploaded';
    case Validated = 'validated';
    case Committed = 'committed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
