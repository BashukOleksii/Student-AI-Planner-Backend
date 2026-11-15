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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('integration')]
#[Group('mysql')]
class CompactScheduleOccurrenceResolverTest extends TestCase
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
            $this->assertSame([...$materialized['occurrences'][$index], 'academic_period_id' => $period->id, 'subject_id' => $subjectId, 'teacher_id' => $teachers[$occurrence['teacher_name']], 'fingerprint' => (new ScheduleImportFingerprint)->generate($subjectId, $occurrence['starts_at_utc'], $occurrence['ends_at_utc']), 'resolution_errors' => []], $occurrence);
            $this->assertSame(LessonType::Other, $occurrence['type']);
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $occurrence['fingerprint']);
        }
        $this->assertSame($before, $this->snapshot());
        foreach (['schedule_import_batches', 'schedule_import_rows', 'lessons'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
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
        $this->assertSame(['teacher_name' => ['Кілька ваших викладачів точно відповідають імпортованому імені.']], $occurrence['resolution_errors']);
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
        $this->assertSame(['subject_name' => ['Серед ваших предметів немає точного збігу з імпортованою назвою.']], $occurrence['resolution_errors']);
        $this->assertSame($before, $this->snapshot());
    }

    public static function similarSubjectNames(): array
    {
        return [0 => ['Physics', 'physics']];
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
        $this->assertSame(['teacher_name' => ['Серед ваших викладачів немає точного збігу з імпортованим іменем.']], $occurrence['resolution_errors']);
        $this->assertNotNull($occurrence['fingerprint']);
    }

    public static function similarTeacherNames(): array
    {
        return [0 => ['Teacher', 'teacher']];
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

    public function test_occurrence_provided_identifiers_and_status_are_ignored(): void
    {
        [$user, $period] = $this->context();
        $subject = Subject::factory()->for($user)->create(['name' => 'Physics']);
        $teacher = Teacher::factory()->for($user)->create(['name' => 'Teacher']);
        $otherPeriod = AcademicPeriod::factory()->create();
        $input = $this->materialized();
        $input['academic_period_id'] = $otherPeriod->id;
        $input['occurrences'][0] += ['academic_period_id' => $otherPeriod->id, 'subject_id' => 99999, 'teacher_id' => 99999, 'user_id' => $otherPeriod->user_id, 'owner_id' => $otherPeriod->user_id, 'fingerprint' => str_repeat('a', 64), 'status' => 'valid', 'row_number' => 1, 'lesson_id' => 99];
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

    public function test_semester_teacher_initial_dot_variants_match_only_when_unambiguous(): void
    {
        [$user] = $this->context();
        $teacher = Teacher::factory()->for($user)->create(['name' => 'Студницька Л. М..']);
        $names = ['Студницька Л. М.', 'Студницька Л. М..'];
        $resolve = fn () => app(ScheduleOccurrenceResolver::class)->resolveSemesterNames([], $names, collect(), $user->teachers()->get());
        foreach ($resolve()['teachers'] as $entry) {
            $this->assertSame($teacher->id, $entry['id']);
            $this->assertSame('reuse', $entry['resolution']);
            $this->assertSame([], $entry['errors']);
        }
        Teacher::factory()->for($user)->create(['name' => 'Студницька Л. М.']);
        foreach ($resolve()['teachers'] as $entry) {
            $this->assertSame('invalid', $entry['resolution']);
            $this->assertStringContainsString('Кілька ваших викладачів', $entry['errors'][0]);
        }
        $combined = app(ScheduleOccurrenceResolver::class)->resolveSemesterNames([], ['Студницька Л. М.. Інший А. Б.'], collect(), $user->teachers()->get());
        $this->assertSame('invalid', $combined['teachers']['Студницька Л. М.. Інший А. Б.']['resolution']);
        $this->assertDatabaseCount('teachers', 2);
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
        return ['group' => 'ПІ-231', 'source' => ['worksheet' => 'Sheet1', 'range' => 'A1:F81'], 'occurrences' => [['local_date' => '2026-09-07', 'weekday' => 1, 'pair_label' => '1-2', 'parity' => 'all', 'subject_name' => 'Physics', 'teacher_name' => 'Teacher', 'room' => null, 'type' => LessonType::Other, 'starts_at_local' => '08:30:00', 'ends_at_local' => '09:50:00', 'starts_at_utc' => '2026-09-07T05:30:00Z', 'ends_at_utc' => '2026-09-07T06:50:00Z', 'source' => ['worksheet' => 'Sheet1', 'rows' => [3, 4], 'range' => 'D3:F4', 'pair_range' => 'B2:F5']]]];
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
