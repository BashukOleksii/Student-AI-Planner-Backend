<?php

namespace App\Services\Schedule;

use App\Models\ScheduleDateExceptionLesson;
use App\Models\WeeklyLessonRule;

/** Called only inside the owner-locked semester write transaction, after reference validation. */
class SemesterLessonTeacherService
{
    public function sync(WeeklyLessonRule|ScheduleDateExceptionLesson $lesson, array $ids): void
    {
        $links = array_fill_keys($ids, ['user_id' => $lesson->user_id]);
        $lesson->teachers()->sync($links);
        // A subject's teaching catalog is additive: edits never detach existing associations.
        $lesson->subject()->firstOrFail()->teachers()->syncWithoutDetaching($links);
        $lesson->unsetRelation('teachers');
    }
}
