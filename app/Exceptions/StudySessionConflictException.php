<?php

namespace App\Exceptions;

use RuntimeException;

class StudySessionConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Дія із сесією конфліктує з її станом або обмеженнями планування. Оновіть сесію та за потреби перегляньте інший проміжок.');
    }
}
