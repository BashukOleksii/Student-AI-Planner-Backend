<?php

namespace App\Exceptions;

use RuntimeException;

class StudyPlanConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The study plan preview is no longer current. Obtain a fresh preview and review it before confirming.');
    }
}
