<?php

namespace App\Exceptions;

use Throwable;
use UnexpectedValueException;

class ReminderIntegrityException extends UnexpectedValueException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The reminder dependencies could not be processed.', 0, $previous);
    }
}
