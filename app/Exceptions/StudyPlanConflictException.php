<?php

namespace App\Exceptions;

use RuntimeException;

class StudyPlanConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Огляд плану більше не актуальний. Створіть і перегляньте новий огляд перед підтвердженням.');
    }
}
