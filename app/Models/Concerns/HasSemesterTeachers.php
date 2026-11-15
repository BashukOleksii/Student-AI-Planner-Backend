<?php

namespace App\Models\Concerns;

trait HasSemesterTeachers
{
    public function initializeHasSemesterTeachers(): void
    {
        $this->append('teacher_ids');
    }

    public function getTeacherIdsAttribute(): array
    {
        $ids = $this->teachers->modelKeys();

        if ($this->teacher_id !== null) {
            $ids[] = $this->teacher_id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
