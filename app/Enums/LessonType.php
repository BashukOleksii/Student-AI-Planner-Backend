<?php

namespace App\Enums;

enum LessonType: string
{
    case Lecture = 'lecture';
    case Practical = 'practical';
    case Laboratory = 'laboratory';
    case Seminar = 'seminar';
    case Consultation = 'consultation';
    case Exam = 'exam';
    case Other = 'other';
}
