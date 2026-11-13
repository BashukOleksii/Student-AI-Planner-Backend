<?php

namespace App\Exceptions;

use InvalidArgumentException;

/** Fixed reasons for a rejected single-occurrence addition; no schedule data. */
class SemesterOccurrenceConflictException extends InvalidArgumentException
{
    public const REASONS = ['pair_number_taken', 'time_overlap', 'date_cancelled'];

    public function __construct(public readonly string $reason)
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException;
        }
        parent::__construct('The lesson cannot be added to this date.');
    }
}
