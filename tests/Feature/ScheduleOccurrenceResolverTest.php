<?php

namespace Tests\Feature;

use App\Enums\LessonType;
use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Imports\ScheduleImportFingerprint;
use App\Services\Imports\ScheduleOccurrenceMaterializer;
use App\Services\Imports\ScheduleOccurrenceResolver;
use App\Services\Imports\ScheduleWorkbookParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleOccurrenceResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_workbook_pipeline_resolves_thirty_candidates_without_writes(): void
    {
        [$user, $period] = $this->context();
        $template = (new ScheduleWorkbookParser)->parse(__DIR__.'/../Fixtures/ScheduleImports/Приклад розкладу.xlsx');
        $subjects = [];
        $teachers = [];
        foreach (array_unique(array_column($template['rules'], 'subject_name')) as $name) {
            $subjects[$name] = Subject::factory()->for($user)->create(['name' => $name])->id;
        }
        foreach (array_unique(array_column($template['rules'], 'teacher_name')) as $name) {
            $teachers[$name] = Teacher::factory()->for($user)->create(['name' => $name])->id;
        }
        $materialized = (new ScheduleOccurrenceMaterializer)->materialize($template, $period, $user->timezone);
        $before = $this->snapshot();
        $result = app(ScheduleOccurrenceResolver::class)->resolve($user, $period, $materialized);

        $this->assertSame(['group', 'source', 'academic_period_id', 'occurrences'], array_keys($result));
        $this->assertSame('ПІ-231', $result['group']);
        $this->assertSame($template['source'], $result['source']);
        $this->assertSame($period->id, $result['academic_period_id']);
        $this->assertCount(30, $result['occurrences']);
        foreach ($result['occurrences'] as $index => $occurrence) {
            $subjectId = $subjects[$occurrence['subject_name']];
            $this->assertSame([
                ...$materialized['occurrences'][$index],
                'academic_period_id' => $period->id,
                'subject_id' => $subjectId,
                'teacher_id' => $teachers[$occurrence['teacher_name']],
                'fingerprint' => (new ScheduleImportFingerprint)->generate($subjectId, $occurrence['starts_at_utc'], $occurrence['ends_at_utc']),
                'resolution_errors' => [],
            ], $occurrence);
            $this->assertSame(LessonType::Other, $occurrence['type']);
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $occurrence['fingerprint']);
        }
        $this->assertSame($before, $this->snapshot());
        foreach (['schedule_import_batches', 'schedule_import_rows', 'lessons'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    #[DataProvider('missingReferences')]
    public function test_missing_owned_references_produce_errors_without_creation_or_foreign_leaks(bool $hasSubject, bool $hasTeacher, bool $foreignMatches): void
    {
        [$user, $period] = $this->context();
        $subject = $hasSubject ? Subject::factory()->for($user)->create(['name' => 'Physics']) : null;
        $teacher = $hasTeacher ? Teacher::factory()->for($user)->create(['name' => 'Teacher']) : null;
        if ($foreignMatches) {
            $other = User::factory()->create();
            Subject::factory()->for($other)->create(['name' => 'Physics']);
            Teacher::factory()->for($other)->create(['name' => 'Teacher']);
        }
        $before = $this->snapshot();
        $occurrence = $this->resolve($user, $period)['occurrences'][0];
        $errors = [];
        if (! $hasSubject) {
            $errors['subject_name'] = ['No owned subject exactly matches this imported name.'];
        }
        if (! $hasTeacher) {
            $errors['teacher_name'] = ['No owned teacher exactly matches this imported name.'];
        }

        $this->assertSame($subject?->id, $occurrence['subject_id']);
        $this->assertSame($teacher?->id, $occurrence['teacher_id']);
        $this->assertSame($errors, $occurrence['resolution_errors']);
        if ($hasSubject) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $occurrence['fingerprint']);
        } else {
            $this->assertNull($occurrence['fingerprint']);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function missingReferences(): array
    {
        return [
            'missing subject' => [false, true, false], 'missing teacher' => [true, false, false],
            'both missing' => [false, false, false], 'foreign subject only' => [false, true, true],
            'foreign teacher only' => [true, false, true], 'both foreign' => [false, false, true],
            'owned and foreign same names' => [true, true, true],
        ];
    }

    public function test_duplicate_exact_teacher_names_are_ambiguous_without_institution_preference(): void
    {
        [$user, $period] = $this->context();
        $institution = EducationInstitution::factory()->for($user)->create();
        $period->update(['education_institution_id' => $institution->id]);
        $subject = Subject::factory()->for($user)->create(['name' => 'Physics']);
        Teacher::factory()->for($user)->for($institution)->create(['name' => 'Teacher']);
        Teacher::factory()->for($user)->create(['name' => 'Teacher']);
        $before = $this->snapshot();
        $occurrence = $this->resolve($user, $period)['occurrences'][0];

        $this->assertSame($subject->id, $occurrence['subject_id']);
        $this->assertNull($occurrence['teacher_id']);
        $this->assertSame(['teacher_name' => ['Multiple owned teachers exactly match this imported name.']], $occurrence['resolution_errors']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $occurrence['fingerprint']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_different_owned_institutions_do_not_prevent_resolution(): void
    {
        [$user, $period] = $this->context();
        $institutions = EducationInstitution::factory()->count(3)->for($user)->create();
        $period->update(['education_institution_id' => $institutions[0]->id]);
        $subject = Subject::factory()->for($user)->for($institutions[1])->create(['name' => 'Physics']);
        $teacher = Teacher::factory()->for($user)->for($institutions[2])->create(['name' => 'Teacher']);
        $occurrence = $this->resolve($user, $period)['occurrences'][0];

        $this->assertSame($subject->id, $occurrence['subject_id']);
        $this->assertSame($teacher->id, $occurrence['teacher_id']);
        $this->assertSame([], $occurrence['resolution_errors']);
    }

    #[DataProvider('similarSubjectNames')]
    public function test_subject_resolution_requires_strict_name_equality_not_collation_or_code(string $persisted, string $imported): void
    {
        [$user, $period] = $this->context();
        Subject::factory()->for($user)->create(['name' => $persisted, 'code' => 'PHY']);
        Teacher::factory()->for($user)->create(['name' => 'Teacher']);
        $before = $this->snapshot();
        $input = $this->materialized();
        $input['occurrences'][0]['subject_name'] = $imported;
        $occurrence = $this->resolve($user, $period, $input)['occurrences'][0];

        $this->assertNull($occurrence['subject_id']);
        $this->assertNull($occurrence['fingerprint']);
        $this->assertSame(['subject_name' => ['No owned subject exactly matches this imported name.']], $occurrence['resolution_errors']);
        $this->assertSame($before, $this->snapshot());
    }

    public static function similarSubjectNames(): array
    {
        return [
            ['Physics', 'physics'], ['Physics', 'Physics '], ['Physics', 'PHY'], ['Café', 'Cafe'],
            ['Програмування в Інтернет', 'Програмування в Internet'],
        ];
    }

    #[DataProvider('similarTeacherNames')]
    public function test_teacher_resolution_requires_strict_name_equality(string $persisted, string $imported): void
    {
        [$user, $period] = $this->context();
        Subject::factory()->for($user)->create(['name' => 'Physics']);
        Teacher::factory()->for($user)->create(['name' => $persisted]);
        $input = $this->materialized();
        $input['occurrences'][0]['teacher_name'] = $imported;
        $occurrence = $this->resolve($user, $period, $input)['occurrences'][0];

        $this->assertNull($occurrence['teacher_id']);
        $this->assertSame(['teacher_name' => ['No owned teacher exactly matches this imported name.']], $occurrence['resolution_errors']);
        $this->assertNotNull($occurrence['fingerprint']);
    }

    public static function similarTeacherNames(): array
    {
        return [['Teacher', 'teacher'], ['Teacher', 'Teacher '], ['Teacher', 'Teacher Jr.'], ['Béla', 'Bela']];
    }

    public function test_similar_teacher_names_do_not_make_an_exact_teacher_ambiguous(): void
    {
        [$user, $period] = $this->context();
        Subject::factory()->for($user)->create(['name' => 'Physics']);
        $exact = Teacher::factory()->for($user)->create(['name' => 'Teacher']);
        Teacher::factory()->for($user)->create(['name' => 'teacher']);
        $occurrence = $this->resolve($user, $period)['occurrences'][0];

        $this->assertSame($exact->id, $occurrence['teacher_id']);
        $this->assertSame([], $occurrence['resolution_errors']);
    }

    public function test_foreign_period_is_rejected_even_if_in_memory_ownership_is_forged(): void
    {
        [$user] = $this->context();
        $foreign = AcademicPeriod::factory()->create();
        $foreign->user_id = $user->id;
        $before = $this->snapshot();
        try {
            $this->resolve($user, $foreign);
            $this->fail('A foreign AcademicPeriod was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['academic_period_id' => ['The selected academic period is invalid.']], $exception->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_occurrence_provided_identifiers_and_status_are_ignored(): void
    {
        [$user, $period] = $this->context();
        $subject = Subject::factory()->for($user)->create(['name' => 'Physics']);
        $teacher = Teacher::factory()->for($user)->create(['name' => 'Teacher']);
        $otherPeriod = AcademicPeriod::factory()->create();
        $input = $this->materialized();
        $input['academic_period_id'] = $otherPeriod->id;
        $input['occurrences'][0] += [
            'academic_period_id' => $otherPeriod->id, 'subject_id' => 99999, 'teacher_id' => 99999,
            'user_id' => $otherPeriod->user_id, 'owner_id' => $otherPeriod->user_id,
            'fingerprint' => str_repeat('a', 64), 'status' => 'valid', 'row_number' => 1, 'lesson_id' => 99,
        ];
        $result = $this->resolve($user, $period, $input);
        $occurrence = $result['occurrences'][0];

        $this->assertSame($period->id, $result['academic_period_id']);
        $this->assertSame($period->id, $occurrence['academic_period_id']);
        $this->assertSame($subject->id, $occurrence['subject_id']);
        $this->assertSame($teacher->id, $occurrence['teacher_id']);
        $this->assertNotSame(str_repeat('a', 64), $occurrence['fingerprint']);
        foreach (['user_id', 'owner_id', 'status', 'row_number', 'lesson_id'] as $field) {
            $this->assertArrayNotHasKey($field, $occurrence);
        }
    }

    public function test_subject_rename_preserves_identity_but_old_name_is_not_an_alias(): void
    {
        [$user, $period] = $this->context();
        $subject = Subject::factory()->for($user)->create(['name' => 'Physics', 'code' => 'PHY']);
        Teacher::factory()->for($user)->create(['name' => 'Teacher']);
        $original = $this->resolve($user, $period)['occurrences'][0];
        $subject->update(['name' => 'Renamed Physics', 'code' => 'NEW']);
        $input = $this->materialized();
        $input['occurrences'][0]['subject_name'] = 'Renamed Physics';
        $renamed = $this->resolve($user, $period, $input)['occurrences'][0];

        $this->assertSame($original['subject_id'], $renamed['subject_id']);
        $this->assertSame($original['fingerprint'], $renamed['fingerprint']);
        $this->assertSame([], $renamed['resolution_errors']);
        $this->assertNull($this->resolve($user, $period)['occurrences'][0]['fingerprint']);
    }

    public function test_teacher_room_and_type_changes_preserve_fingerprint(): void
    {
        [$user, $period] = $this->context();
        Subject::factory()->for($user)->create(['name' => 'Physics']);
        Teacher::factory()->for($user)->create(['name' => 'Teacher']);
        $alternate = Teacher::factory()->for($user)->create(['name' => 'Another teacher']);
        $original = $this->resolve($user, $period)['occurrences'][0];
        foreach ([['teacher_name', 'Another teacher'], ['room', '456'], ['type', LessonType::Lecture]] as [$field, $value]) {
            $input = $this->materialized();
            $input['occurrences'][0][$field] = $value;
            $changed = $this->resolve($user, $period, $input)['occurrences'][0];
            $this->assertSame($original['fingerprint'], $changed['fingerprint']);
            $this->assertSame([], $changed['resolution_errors']);
            if ($field === 'teacher_name') {
                $this->assertSame($alternate->id, $changed['teacher_id']);
                $this->assertNotSame($original['teacher_id'], $changed['teacher_id']);
            }
        }
    }

    public function test_queries_are_batched_and_read_only_independent_of_occurrence_count(): void
    {
        [$user, $period] = $this->context();
        foreach (['Physics', 'Math'] as $name) {
            Subject::factory()->for($user)->create(['name' => $name]);
        }
        foreach (['Teacher', 'Another teacher'] as $name) {
            Teacher::factory()->for($user)->create(['name' => $name]);
        }
        $short = $this->materialized();
        $extra = $short['occurrences'][0];
        $extra['subject_name'] = 'Math';
        $extra['teacher_name'] = 'Another teacher';
        $short['occurrences'][] = $extra;
        $large = $short;
        $large['occurrences'] = array_merge(...array_fill(0, 250, $short['occurrences']));
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->resolve($user, $period, $short);
            $shortQueries = DB::getQueryLog();
            DB::flushQueryLog();
            $result = $this->resolve($user, $period, $large);
            $largeQueries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertCount(3, $shortQueries);
        $this->assertSame(array_column($shortQueries, 'query'), array_column($largeQueries, 'query'));
        $this->assertSame(array_column($shortQueries, 'bindings'), array_column($largeQueries, 'bindings'));
        $this->assertCount(500, $result['occurrences']);
        foreach ($largeQueries as $query) {
            $this->assertStringStartsWith('select ', $query['query']);
        }
        $this->assertSame([$user->id, 'Physics', 'Math'], $largeQueries[1]['bindings']);
        $this->assertSame([$user->id, 'Teacher', 'Another teacher'], $largeQueries[2]['bindings']);
    }

    public function test_invalid_utc_input_is_never_fingerprinted(): void
    {
        [$user, $period] = $this->context();
        Subject::factory()->for($user)->create(['name' => 'Physics']);
        Teacher::factory()->for($user)->create(['name' => 'Teacher']);
        $input = $this->materialized();
        $input['occurrences'][0]['starts_at_utc'] = '2026-10-05T06:00:00+00:00';

        $this->expectException(InvalidArgumentException::class);
        $this->resolve($user, $period, $input);
    }

    public function test_empty_occurrences_still_require_owned_period_and_preserve_metadata(): void
    {
        [$user, $period] = $this->context();
        $input = $this->materialized();
        $input['occurrences'] = [];

        $this->assertSame([
            'group' => $input['group'], 'source' => $input['source'], 'academic_period_id' => $period->id, 'occurrences' => [],
        ], $this->resolve($user, $period, $input));
    }

    private function context(): array
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $period = AcademicPeriod::factory()->for($user)->create(['starts_on' => '2026-09-07', 'ends_on' => '2026-09-20']);

        return [$user, $period];
    }

    private function resolve(User $user, AcademicPeriod $period, ?array $materialized = null): array
    {
        return app(ScheduleOccurrenceResolver::class)->resolve($user, $period, $materialized ?? $this->materialized());
    }

    private function materialized(): array
    {
        return [
            'group' => 'ПІ-231', 'source' => ['worksheet' => 'Sheet1', 'range' => 'A1:F81'],
            'occurrences' => [[
                'local_date' => '2026-09-07', 'weekday' => 1, 'pair_label' => '1-2', 'parity' => 'all',
                'subject_name' => 'Physics', 'teacher_name' => 'Teacher', 'room' => null, 'type' => LessonType::Other,
                'starts_at_local' => '08:30:00', 'ends_at_local' => '09:50:00',
                'starts_at_utc' => '2026-09-07T05:30:00Z', 'ends_at_utc' => '2026-09-07T06:50:00Z',
                'source' => ['worksheet' => 'Sheet1', 'rows' => [3, 4], 'range' => 'D3:F4', 'pair_range' => 'B2:F5'],
            ]],
        ];
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['subjects', 'teachers', 'academic_periods', 'schedule_import_batches', 'schedule_import_rows', 'lessons'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all();
        }

        return $snapshot;
    }
}
