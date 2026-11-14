<?php

namespace App\Services\Imports;

use App\Enums\LessonType;
use App\Models\AcademicPeriod;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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
            throw ValidationException::withMessages(['academic_period_id' => ['Вибраний навчальний період недоступний.']]);
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

    /** Read-only semester resolution against the already loaded, owned catalogs.
     * Negative reference IDs are internal preview identities, never persisted record IDs.
     * Legacy dated import resolution below intentionally remains exact and unchanged.
     */
    public function resolveSemesterNames(array $subjectNames, array $teacherNames, Collection $subjects, Collection $teachers): array
    {
        $result = ['subjects' => [], 'teachers' => []];
        foreach (['subjects' => [$subjectNames, $subjects], 'teachers' => [$teacherNames, $teachers]] as $kind => [$names, $records]) {
            $existing = $records->groupBy(fn ($record) => $this->normalizedName($record->name));
            $prepared = [];
            foreach ($names as $name) {
                $name = (string) $name;
                $normalized = $this->normalizedName($name);
                if (! isset($prepared[$normalized])) {
                    $display = $this->displayName($name);
                    $errors = [];
                    if (! $this->validSemesterName($display, $kind === 'teachers')) {
                        $errors[] = 'Імпортоване ім’я порожнє, некоректне або неоднозначне. Розділіть імена викладачів явними роздільниками.';
                    }
                    $matches = $existing->get($normalized, collect());
                    if ($matches->count() > 1) {
                        $errors[] = ($kind === 'subjects' ? 'Кілька ваших предметів' : 'Кілька ваших викладачів')
                            .' відповідають цій нормалізованій назві.';
                    }
                    $record = $matches->count() === 1 ? $matches->first() : null;
                    $prepared[$normalized] = [
                        'id' => $record?->id ?? -(count($prepared) + 1),
                        'name' => $record?->name ?? $display,
                        ...($kind === 'subjects' ? ['code' => $record?->code, 'color' => $record?->color] : []),
                        'resolution' => $errors !== [] ? 'invalid' : ($record === null ? 'create' : 'reuse'),
                        'errors' => $errors,
                    ];
                }
                $result[$kind][$name] = $prepared[$normalized];
            }
        }

        return $result;
    }

    private function displayName(string $name): string
    {
        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $name) ?? '');
    }

    private function normalizedName(string $name): string
    {
        $name = mb_strtolower(str_replace(['’', 'ʼ', '`'], "'", $this->displayName($name)));

        // Whitespace around initials is presentation, not identity.
        return preg_replace('/\s*\.\s*/u', '.', $name);
    }

    private function validSemesterName(string $name, bool $teacher): bool
    {
        if ($name === '' || mb_strlen($name) > 255 || preg_match('/\p{L}/u', $name) !== 1
            || preg_match('/[\p{Cc}\p{Cf}]/u', $name) === 1) {
            return false;
        }
        if (! $teacher) {
            return preg_match('/^[\p{L}\p{M}\p{N}\p{Zs}.,:()\[\]’ʼ\x{0027}"&+\/\-–—]+$/u', $name) === 1;
        }

        // A surname alone, a full name, or surname + initials. Two unsplit people fail.
        return preg_match('/^[\p{L}\p{M}’ʼ\x{0027}-]{2,}(?:\s+[\p{L}\p{M}’ʼ\x{0027}-]{2,}){0,2}$/u', $name) === 1
            || preg_match('/^[\p{L}\p{M}’ʼ\x{0027}-]{2,}\s+(?:\p{L}\.\s*){1,3}$/u', $name) === 1;
    }

    /**
     * Batched exact owned names shared by initial resolution and unmapped preview refresh.
     *
     * @return array{subjects: array<string, array{id: ?int, errors: list<string>}>, teachers: array<string, array{id: ?int, errors: list<string>}>}
     */
    public function resolveNames(User $user, array $subjectNames, array $teacherNames, bool $lock = false): array
    {
        $subjectNames = array_values(array_unique($subjectNames, SORT_STRING));
        $teacherNames = array_values(array_unique($teacherNames, SORT_STRING));
        $subjects = [];
        $teachers = [];
        if ($subjectNames !== []) {
            foreach ($user->subjects()->whereIn('name', $subjectNames)->when($lock, fn ($q) => $q->lockForUpdate())->get(['id', 'name']) as $subject) {
                // MySQL collation may return similar names; PHP equality decides the match.
                if (in_array($subject->name, $subjectNames, true)) {
                    $subjects[$subject->name] = $subject->getKey();
                }
            }
        }
        if ($teacherNames !== []) {
            foreach ($user->teachers()->whereIn('name', $teacherNames)->when($lock, fn ($q) => $q->lockForUpdate())->get(['id', 'name']) as $teacher) {
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
                'errors' => $id === null ? ['Серед ваших предметів немає точного збігу з імпортованою назвою.'] : [],
            ];
        }
        foreach ($teacherNames as $name) {
            $ids = $teachers[$name] ?? [];
            $result['teachers'][$name] = [
                'id' => count($ids) === 1 ? $ids[0] : null,
                'errors' => count($ids) === 1 ? [] : [count($ids) === 0
                    ? 'Серед ваших викладачів немає точного збігу з імпортованим іменем.'
                    : 'Кілька ваших викладачів точно відповідають імпортованому імені.'],
            ];
        }

        return $result;
    }
}
