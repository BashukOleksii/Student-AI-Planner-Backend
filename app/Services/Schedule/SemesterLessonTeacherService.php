<?php

namespace App\Services\Schedule;

use App\Models\ScheduleDateExceptionLesson;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Shared single-teacher validation; pivot history is never automatically repaired. */
class SemesterLessonTeacherService
{
    public function teacherId(array $row): int
    {
        $id = $row['teacher_id'] ?? null;
        if (! is_int($id) || $id < 1) {
            $this->invalid('teacher_id', 'Exactly one valid owned teacher_id is required for each semester lesson.');
        }
        if (array_key_exists('teacher_ids', $row) && $row['teacher_ids'] !== [$id]) {
            $this->invalid('teacher_ids', 'Use one teacher_id. A compatibility teacher_ids list must contain only that same ID.');
        }

        return $id;
    }

    public function assertHistoricalLesson(WeeklyLessonRule|ScheduleDateExceptionLesson $lesson): void
    {
        if (count($lesson->teacher_ids) > 1 || ($lesson->teachers->isNotEmpty() && $lesson->teacher_id === null)) {
            $this->repair($lesson->getTable().' '.$lesson->id.' has incompatible scalar/pivot teacher relationships.');
        }
    }

    /** Include scalar references in every semester, even if a historical catalog pivot is absent. */
    public function catalogAssignments(User $owner, Collection $subjects, bool $lock = false): array
    {
        $assignments = [];
        foreach ($subjects as $subject) {
            if ($subject->teachers->contains(fn ($teacher) => $teacher->user_id !== $owner->id || $teacher->pivot->user_id !== $owner->id)) {
                $this->repair('Subject '.$subject->id.' has invalid teacher ownership.');
            }
            $assignments[$subject->id] = $subject->teachers->modelKeys();
        }
        foreach ([WeeklyLessonRule::class, ScheduleDateExceptionLesson::class] as $model) {
            $references = $model::where('user_id', $owner->id)->whereNotNull('teacher_id')->orderBy('id')
                ->when($lock, fn ($query) => $query->lockForUpdate())->get(['id', 'subject_id', 'teacher_id']);
            foreach ($references as $reference) {
                if (array_key_exists($reference->subject_id, $assignments)) {
                    $assignments[$reference->subject_id][] = $reference->teacher_id;
                }
            }
        }
        foreach ($assignments as $subjectId => &$ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) > 1) {
                $this->repair('Subject '.$subjectId.' has multiple historical teacher identities.');
            }
            $ids = $ids[0] ?? null;
        }
        unset($ids);

        return $assignments;
    }

    /** Return a conflict on every affected row, including both sides of an incoming disagreement. */
    public function assignmentErrors(array $rows, array $assignments): array
    {
        $bySubject = [];
        foreach ($rows as $row) {
            if (isset($row['subject_id'], $row['teacher_id'])) {
                $bySubject[$row['subject_id']][] = $row['teacher_id'];
            }
        }
        $errors = [];
        foreach ($rows as $index => $row) {
            if (! isset($row['subject_id'], $row['teacher_id'])) {
                continue;
            }
            $ids = $bySubject[$row['subject_id']];
            if (isset($assignments[$row['subject_id']])) {
                $ids[] = $assignments[$row['subject_id']];
            }
            if (count(array_unique($ids)) > 1) {
                $errors[$index] = 'The subject must use its single assigned teacher. Conflicting teacher assignments are not allowed.';
            }
        }

        return $errors;
    }

    /** Called after owner-locked preflight, inside the same transaction as the lesson write. */
    public function sync(WeeklyLessonRule|ScheduleDateExceptionLesson $lesson, int $teacherId): void
    {
        $this->teacherId(['teacher_id' => $lesson->teacher_id]);
        if ($lesson->teacher_id !== $teacherId) {
            $this->invalid('teacher_id', 'The scalar lesson teacher must match its compatibility link.');
        }
        if ($lesson->teachers()->count() > 1) {
            $this->repair($lesson->getTable().' '.$lesson->id.' has multiple historical teachers.');
        }
        $subject = $lesson->subject()->with('teachers')->firstOrFail();
        $ids = $subject->teachers->modelKeys();
        if (count($ids) > 1) {
            $this->repair('Subject '.$subject->id.' has multiple historical teachers.');
        }
        if ($ids !== [] && $ids !== [$teacherId]) {
            $this->invalid('teacher_id', 'The subject is already assigned to another teacher.');
        }
        $links = [$teacherId => ['user_id' => $lesson->user_id]];
        $subject->teachers()->syncWithoutDetaching($links);
        $lesson->teachers()->sync($links);
        $lesson->unsetRelation('teachers');
    }

    private function repair(string $detail): never
    {
        $this->invalid('semester_teacher_repair', 'Manual repair required: '.$detail.' No teacher relationships were changed.');
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
