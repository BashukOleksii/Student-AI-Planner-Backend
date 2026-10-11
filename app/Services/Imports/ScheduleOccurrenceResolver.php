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

        $occurrences = [];
        foreach ($materialized['occurrences'] as $occurrence) {
            $subjectId = $subjects[$occurrence['subject_name']] ?? null;
            $teacherIds = $teachers[$occurrence['teacher_name']] ?? [];
            $errors = [];
            if ($subjectId === null) {
                $errors['subject_name'] = ['No owned subject exactly matches this imported name.'];
            }
            if (count($teacherIds) !== 1) {
                $errors['teacher_name'] = [count($teacherIds) === 0
                    ? 'No owned teacher exactly matches this imported name.'
                    : 'Multiple owned teachers exactly match this imported name.'];
            }
            $occurrences[] = [
                ...Arr::only($occurrence, self::OCCURRENCE_FIELDS),
                'academic_period_id' => $period->getKey(),
                'subject_id' => $subjectId,
                'teacher_id' => count($teacherIds) === 1 ? $teacherIds[0] : null,
                'fingerprint' => $subjectId === null ? null : $this->fingerprint->generate($subjectId, $occurrence['starts_at_utc'], $occurrence['ends_at_utc']),
                'resolution_errors' => $errors,
            ];
        }

        return [
            'group' => $materialized['group'], 'source' => $materialized['source'],
            'academic_period_id' => $period->getKey(), 'occurrences' => $occurrences,
        ];
    }
}
