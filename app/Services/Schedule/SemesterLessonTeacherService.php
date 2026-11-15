<?php

namespace App\Services\Schedule;

use App\Models\Lesson;
use App\Models\ScheduleDateExceptionLesson;
use App\Models\User;
use App\Models\WeeklyLessonRule;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SemesterLessonTeacherService
{
    public function teacherId(array $row): int
    {
        $id = $row['teacher_id'] ?? null;
        if (! is_int($id) || $id < 1) {
            $this->invalid('teacher_id', 'Для кожного семестрового заняття виберіть одного зі своїх викладачів.');
        }
        if (array_key_exists('teacher_ids', $row) && $row['teacher_ids'] !== [$id]) {
            $this->invalid('teacher_ids', 'Використайте одного викладача. Сумісний список teacher_ids має містити лише його ідентифікатор.');
        }

        return $id;
    }

    public function assertHistoricalLesson(WeeklyLessonRule|ScheduleDateExceptionLesson $lesson): void
    {
        if (count($lesson->teacher_ids) > 1 || ($lesson->teachers->isNotEmpty() && $lesson->teacher_id === null)) {
            $this->repair('Заняття '.$lesson->id.' має неузгоджені зв’язки з викладачами.');
        }
    }

    public function catalogAssignments(User $owner, Collection $subjects, bool $lock = false, ?int $replacingScheduleId = null): array
    {
        $assignments = [];
        $replaced = $retained = [];
        foreach ($subjects as $subject) {
            if ($subject->teachers->contains(fn ($teacher) => $teacher->user_id !== $owner->id || $teacher->pivot->user_id !== $owner->id)) {
                $this->repair('Предмет '.$subject->id.' посилається на викладача іншого користувача.');
            }
            $assignments[$subject->id] = $subject->teachers->modelKeys();
        }
        foreach ([WeeklyLessonRule::class, ScheduleDateExceptionLesson::class] as $model) {
            $references = $model::with(['teachers' => fn ($query) => $query->when($lock, fn ($q) => $q->lockForUpdate())])
                ->where('user_id', $owner->id)->orderBy('id')
                ->when($lock, fn ($query) => $query->lockForUpdate())->get(['id', 'semester_schedule_id', 'subject_id', 'teacher_id']);
            foreach ($references as $reference) {
                if (array_key_exists($reference->subject_id, $assignments)) {
                    array_push($assignments[$reference->subject_id], ...$reference->teacher_ids);
                    if ($replacingScheduleId !== null && $reference->semester_schedule_id === $replacingScheduleId) {
                        $replaced[$reference->subject_id] = [...($replaced[$reference->subject_id] ?? []), ...$reference->teacher_ids];
                    } else {
                        $retained[$reference->subject_id] = true;
                    }
                }
            }
        }
        if ($replacingScheduleId !== null) {
            // Legacy lessons (including soft-deleted history) cannot be attributed to this replacement.
            $references = Lesson::withTrashed()->where('user_id', $owner->id)->orderBy('id')
                ->when($lock, fn ($query) => $query->lockForUpdate())->get(['id', 'subject_id']);
            foreach ($references as $reference) {
                $retained[$reference->subject_id] = true;
            }
        }
        foreach ($assignments as $subjectId => &$ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) > 1) {
                $this->repair('Предмет '.$subjectId.' має кілька історичних записів викладачів.');
            }
            $id = $ids[0] ?? null;
            // A catalog-only assignment has no provenance: never silently overwrite it.
            $hasEvidence = in_array($id, $replaced[$subjectId] ?? [], true) && ! isset($retained[$subjectId]);
            $ids = $hasEvidence ? null : $id;
        }
        unset($ids);

        return $assignments;
    }

    public function assignmentErrors(array $rows, array $assignments, bool $replacement = false): array
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
                $errors[$index] = ! $replacement
                    ? 'Предмет має використовувати одного призначеного викладача. Суперечливі призначення заборонено.'
                    : (count(array_unique($bySubject[$row['subject_id']])) > 1
                    ? 'Новий файл призначає одному предмету різних викладачів. Предмет має використовувати одного викладача.'
                    : 'Для предмета зберігається інший викладач: призначення використовується поза розкладом, що замінюється, або його походження неможливо безпечно встановити.');
            }
        }

        return $errors;
    }

    public function sync(WeeklyLessonRule|ScheduleDateExceptionLesson $lesson, int $teacherId): void
    {
        $this->teacherId(['teacher_id' => $lesson->teacher_id]);
        if ($lesson->teacher_id !== $teacherId) {
            $this->invalid('teacher_id', 'Викладач заняття має відповідати збереженому зв’язку з викладачем.');
        }
        if ($lesson->teachers()->count() > 1) {
            $this->repair('Заняття '.$lesson->id.' має кількох викладачів в історії.');
        }
        $subject = $lesson->subject()->with('teachers')->firstOrFail();
        $ids = $subject->teachers->modelKeys();
        if (count($ids) > 1) {
            $this->repair('Предмет '.$subject->id.' має кількох викладачів в історії.');
        }
        if ($ids !== [] && $ids !== [$teacherId]) {
            $this->invalid('teacher_id', 'Для предмета вже призначено іншого викладача.');
        }
        $links = [$teacherId => ['user_id' => $lesson->user_id]];
        $subject->teachers()->syncWithoutDetaching($links);
        $lesson->teachers()->sync($links);
        $lesson->unsetRelation('teachers');
    }

    private function repair(string $detail): never
    {
        $this->invalid('semester_teacher_repair', 'Потрібне ручне виправлення: '.$detail.' Зв’язки викладачів не змінено.');
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
