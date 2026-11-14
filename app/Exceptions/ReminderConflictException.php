<?php

namespace App\Exceptions;

use RuntimeException;

class ReminderConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Нагадування не можна змінити в його поточному стані.');
    }
}
