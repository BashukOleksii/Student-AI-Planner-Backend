<?php

namespace App\Enums;

enum ReminderAnchor: string
{
    case TaskDeadline = 'task_deadline';
    case SubtaskDeadline = 'subtask_deadline';
    case SessionStart = 'session_start';
}
