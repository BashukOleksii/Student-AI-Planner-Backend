<?php

namespace App\Exceptions;

use RuntimeException;

class StudySessionConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This session action conflicts with the current state or planning constraints. Refresh the session and review a new interval if needed.');
    }
}
