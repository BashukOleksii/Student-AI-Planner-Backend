<?php

namespace App\Services\Imports;

use App\Enums\LessonType;
use App\Models\AcademicPeriod;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ScheduleOccurrenceResolver
{
    private const OCCURRENCE_FIELDS = [
        'local_date', 'weekday', 'pair_label', 'parity', 'subject_name', 'teacher_name',
        'room', 'type', 'starts_at_local', 'ends_at_local', 'starts_at_utc', 'ends_at_utc', 'source',
    ];

    public function __construct(private ScheduleImportFingerprint $fingerprint) {}

    /**
     * Read-only resolution of the ScheduleOccurrenceMaterializer contract.
     * Preserve occurrence order/provenance; errors do not classify preview rows.
     * Invalid fingerprint inputs throw InvalidArgumentException rather than being hashed.
     *
     * @param  array{group: string, source: array, occurrences: list<array>}  $materialized
     * @return array{
     *   group: string, source: array, academic_period_id: int,
     *   occurrences: list<array{
     *     local_date: string, weekday: int, pair_label: string, parity: string,
     *     subject_name: string, teacher_name: string, room: ?string, type: LessonType,
     *     starts_at_local: string, ends_at_local: string, starts_at_utc: string, ends_at_utc: string,
     *     source: array, academic_period_id: int, subject_id: ?int, teacher_id: ?int,
     *     fingerprint: ?string, resolution_errors: array<string, list<string>>
     *   }>
     * }
     */
    public function resolve(User $user, AcademicPeriod $academicPeriod, array $materialized): array
    {
        // Reload through the owner: stale or forged in-memory ownership is insufficient.
        $period = $user->academicPeriods()->whereKey($academicPeriod->getKey())->first();
        if ($period === null) {
            throw ValidationException::withMessages(['academic_period_id' => ['The selected academic period is invalid.']]);
        }

        $subjectNames = array_values(array_unique(array_column($materialized['occurrences'], 'subject_name'), SORT_STRING));
        $teacherNames = array_values(array_unique(array_column($materialized['occurrences'], 'teacher_name'), SORT_STRING));
        $references = $this->resolveNames($user, $subjectNames, $teacherNames);

        $occurrences = [];
        foreach ($materialized['occurrences'] as $occurrence) {
            $subject = $references['subjects'][$occurrence['subject_name']];
            $teacher = $references['teachers'][$occurrence['teacher_name']];
            $errors = [];
            if ($subject['errors'] !== []) {
                $errors['subject_name'] = $subject['errors'];
            }
            if ($teacher['errors'] !== []) {
                $errors['teacher_name'] = $teacher['errors'];
            }
            $occurrences[] = [
                ...Arr::only($occurrence, self::OCCURRENCE_FIELDS),
                'academic_period_id' => $period->getKey(),
                'subject_id' => $subject['id'],
                'teacher_id' => $teacher['id'],
                'fingerprint' => $subject['id'] === null ? null : $this->fingerprint->generate($subject['id'], $occurrence['starts_at_utc'], $occurrence['ends_at_utc']),
                'resolution_errors' => $errors,
            ];
        }

        return [
            'group' => $materialized['group'], 'source' => $materialized['source'],
            'academic_period_id' => $period->getKey(), 'occurrences' => $occurrences,
        ];
    }

    /**
     * Batched exact owned names shared by initial resolution and unmapped preview refresh.
     *
     * @return array{subjects: array<string, array{id: ?int, errors: list<string>}>, teachers: array<string, array{id: ?int, errors: list<string>}>}
     */
    public function resolveNames(User $user, array $subjectNames, array $teacherNames): array
    {
        $subjectNames = array_values(array_unique($subjectNames, SORT_STRING));
        $teacherNames = array_values(array_unique($teacherNames, SORT_STRING));
        $subjects = [];
        $teachers = [];
        if ($subjectNames !== []) {
            foreach ($user->subjects()->whereIn('name', $subjectNames)->get(['id', 'name']) as $subject) {
                // MySQL collation may return similar names; PHP equality decides the match.
                if (in_array($subject->name, $subjectNames, true)) {
                    $subjects[$subject->name] = $subject->getKey();
                }
            }
        }
        if ($teacherNames !== []) {
            foreach ($user->teachers()->whereIn('name', $teacherNames)->get(['id', 'name']) as $teacher) {
                if (in_array($teacher->name, $teacherNames, true)) {
                    $teachers[$teacher->name][] = $teacher->getKey();
                }
            }
        }

        $result = ['subjects' => [], 'teachers' => []];
        foreach ($subjectNames as $name) {
            $id = $subjects[$name] ?? null;
            $result['subjects'][$name] = [
                'id' => $id,
                'errors' => $id === null ? ['No owned subject exactly matches this imported name.'] : [],
            ];
        }
        foreach ($teacherNames as $name) {
            $ids = $teachers[$name] ?? [];
            $result['teachers'][$name] = [
                'id' => count($ids) === 1 ? $ids[0] : null,
                'errors' => count($ids) === 1 ? [] : [count($ids) === 0
                    ? 'No owned teacher exactly matches this imported name.'
                    : 'Multiple owned teachers exactly match this imported name.'],
            ];
        }

        return $result;
    }
}
