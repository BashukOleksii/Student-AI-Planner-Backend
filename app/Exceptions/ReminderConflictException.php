<?php

namespace App\Exceptions;

use RuntimeException;

class ReminderConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The reminder cannot be changed in its current state.');
    }
}
