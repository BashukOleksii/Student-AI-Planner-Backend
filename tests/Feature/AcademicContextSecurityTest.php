<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Lesson;
use App\Models\ScheduleImportBatch;
use App\Models\Subject;
use App\Models\Task;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AcademicContextSecurityTest extends TestCase
{
    use RefreshDatabase;

    public static function institutionChildren(): array
    {
        return [
            'period' => [AcademicPeriod::class, 'academic-periods'],
            'subject' => [Subject::class, 'subjects'],
            'teacher' => [Teacher::class, 'teachers'],
        ];
    }

    public static function allResources(): array
    {
        return ['institution' => [EducationInstitution::class, 'education-institutions'], ...self::institutionChildren()];
    }

    #[DataProvider('institutionChildren')]
    public function test_institution_delete_cannot_null_a_foreign_child(string $model, string $route): void
    {
        $institution = EducationInstitution::factory()->create()->refresh();
        $child = $model::factory()->for(User::factory()->create())->for($institution)->create()->refresh();
        $ownChild = $model::factory()->for($institution->user)->for($institution)->create()->refresh();
        $institutionBefore = $institution->getAttributes();
        $foreignBefore = $child->getAttributes();
        $ownBefore = $ownChild->getAttributes();
        $this->assertNotSame($institution->user_id, $child->user_id);

        $this->actingAs($institution->user, 'web')->deleteJson('/api/education-institutions/'.$institution->id)
            ->assertUnprocessable()->assertJsonValidationErrors('education_institution');

        $this->assertSame($institutionBefore, $institution->refresh()->getAttributes());
        $this->assertSame($foreignBefore, $child->refresh()->getAttributes());
        $this->assertSame($ownBefore, $ownChild->refresh()->getAttributes());
    }

    public function test_valid_institution_delete_preserves_and_detaches_all_three_owned_children(): void
    {
        $institution = EducationInstitution::factory()->create();
        $children = [];
        foreach (self::institutionChildren() as [$model]) {
            $children[] = $model::factory()->for($institution->user)->for($institution)->create();
        }

        $this->actingAs($institution->user, 'web')->deleteJson('/api/education-institutions/'.$institution->id)->assertNoContent();

        $this->assertModelMissing($institution);
        foreach ($children as $child) {
            $this->assertModelExists($child);
            $this->assertNull($child->refresh()->education_institution_id);
        }
    }

    public static function historyStates(): array
    {
        return ['active' => [false], 'soft deleted' => [true]];
    }

    #[DataProvider('historyStates')]
    public function test_subject_delete_cannot_null_a_foreign_task_even_if_soft_deleted(bool $trashed): void
    {
        $subject = Subject::factory()->create()->refresh();
        $task = Task::factory()->for(User::factory()->create())->for($subject)->create();
        if ($trashed) {
            $task->delete();
        }
        $task->refresh();
        $ownTask = Task::factory()->for($subject->user)->for($subject)->create()->refresh();
        $subjectBefore = $subject->getAttributes();
        $taskBefore = $task->getAttributes();
        $ownBefore = $ownTask->getAttributes();

        $this->actingAs($subject->user, 'web')->deleteJson('/api/subjects/'.$subject->id)
            ->assertUnprocessable()->assertJsonValidationErrors('subject');

        $this->assertSame($subjectBefore, $subject->refresh()->getAttributes());
        $this->assertSame($taskBefore, $task->refresh()->getAttributes());
        $this->assertSame($ownBefore, $ownTask->refresh()->getAttributes());
    }

    #[DataProvider('historyStates')]
    public function test_teacher_delete_cannot_null_a_foreign_lesson_even_if_soft_deleted(bool $trashed): void
    {
        $teacher = Teacher::factory()->create()->refresh();
        $lesson = Lesson::factory()->for(User::factory()->create())->for($teacher)->create();
        if ($trashed) {
            $lesson->delete();
        }
        $lesson->refresh();
        $ownLesson = Lesson::factory()->for($teacher->user)->for($teacher)->create()->refresh();
        $teacherBefore = $teacher->getAttributes();
        $lessonBefore = $lesson->getAttributes();
        $ownBefore = $ownLesson->getAttributes();

        $this->actingAs($teacher->user, 'web')->deleteJson('/api/teachers/'.$teacher->id)
            ->assertUnprocessable()->assertJsonValidationErrors('teacher');

        $this->assertSame($teacherBefore, $teacher->refresh()->getAttributes());
        $this->assertSame($lessonBefore, $lesson->refresh()->getAttributes());
        $this->assertSame($ownBefore, $ownLesson->refresh()->getAttributes());
    }

    public function test_valid_subject_and_teacher_deletes_preserve_active_and_soft_deleted_dependents(): void
    {
        $user = User::factory()->create();
        $subject = Subject::factory()->for($user)->create();
        $teacher = Teacher::factory()->for($user)->create();
        $tasks = [];
        $lessons = [];
        foreach ([false, true] as $trashed) {
            $task = Task::factory()->for($user)->for($subject)->create();
            // The Teacher's Lessons intentionally use a different Subject.
            $lesson = Lesson::factory()->for($user)->for($teacher)->create();
            if ($trashed) {
                $task->delete();
                $lesson->delete();
            }
            $tasks[] = $task;
            $lessons[] = $lesson;
        }

        $this->actingAs($user, 'web')->deleteJson('/api/subjects/'.$subject->id)->assertNoContent();
        $this->deleteJson('/api/teachers/'.$teacher->id)->assertNoContent();

        $this->assertModelMissing($subject);
        $this->assertModelMissing($teacher);
        foreach ($tasks as $index => $task) {
            $this->assertModelExists($task);
            $this->assertNull($task->refresh()->subject_id);
            $this->assertModelExists($lessons[$index]);
            $this->assertNull($lessons[$index]->refresh()->teacher_id);
        }
        $this->assertSoftDeleted($tasks[1]);
        $this->assertSoftDeleted($lessons[1]);
    }

    public static function foreignHistory(): array
    {
        return ['lesson' => ['lesson'], 'soft-deleted lesson' => ['trashed'], 'import batch' => ['import']];
    }

    #[DataProvider('foreignHistory')]
    public function test_period_delete_still_blocks_foreign_history_without_changes(string $type): void
    {
        $period = AcademicPeriod::factory()->create()->refresh();
        $foreignUser = User::factory()->create();
        if ($type === 'import') {
            $history = ScheduleImportBatch::factory()->for($period)->for($foreignUser)->create();
        } else {
            $history = Lesson::factory()->for($foreignUser)->for($period)->create();
            if ($type === 'trashed') {
                $history->delete();
            }
        }
        $history->refresh();
        $periodBefore = $period->getAttributes();
        $historyBefore = $history->getAttributes();
        $this->assertNotSame($period->user_id, $history->user_id);

        $this->actingAs($period->user, 'web')->deleteJson('/api/academic-periods/'.$period->id)
            ->assertUnprocessable()->assertJsonValidationErrors('academic_period');

        $this->assertSame($periodBefore, $period->refresh()->getAttributes());
        $this->assertSame($historyBefore, $history->refresh()->getAttributes());
    }

    #[DataProvider('historyStates')]
    public function test_subject_lesson_protection_also_blocks_foreign_history(bool $trashed): void
    {
        $subject = Subject::factory()->create()->refresh();
        $lesson = Lesson::factory()->for(User::factory()->create())->for($subject)->create();
        if ($trashed) {
            $lesson->delete();
        }
        $lesson->refresh();
        $subjectBefore = $subject->getAttributes();
        $lessonBefore = $lesson->getAttributes();

        $this->actingAs($subject->user, 'web')->deleteJson('/api/subjects/'.$subject->id)
            ->assertUnprocessable()->assertJsonValidationErrors('subject');

        $this->assertSame($subjectBefore, $subject->refresh()->getAttributes());
        $this->assertSame($lessonBefore, $lesson->refresh()->getAttributes());
    }

    public static function legacyRepairs(): array
    {
        $cases = [];
        foreach (self::institutionChildren() as $label => [$model, $route]) {
            foreach (['null', 'owned institution'] as $repair) {
                $cases[$label.' '.$repair] = [$model, $route, $repair === 'null'];
            }
        }

        return $cases;
    }

    #[DataProvider('legacyRepairs')]
    public function test_legacy_institution_is_masked_on_reads_and_requires_explicit_repair(string $model, string $route, bool $detach): void
    {
        $user = User::factory()->create();
        $foreign = EducationInstitution::factory()->create();
        $owned = EducationInstitution::factory()->for($user)->create();
        $record = $model::factory()->for($user)->for($foreign)->create()->refresh();
        $before = $record->getAttributes();
        $this->assertNotSame($user->id, $foreign->user_id);
        $this->actingAs($user, 'web');

        $this->getJson('/api/'.$route.'/'.$record->id.'?user_id='.$foreign->user_id)->assertOk()
            ->assertJsonPath('data.education_institution_id', null);
        $this->getJson('/api/'.$route.'?user_id='.$foreign->user_id)->assertOk()
            ->assertJsonPath('data.0.education_institution_id', null)->assertJsonCount(1, 'data');
        $this->assertSame($before, $record->refresh()->getAttributes());

        $this->patchJson('/api/'.$route.'/'.$record->id, ['name' => 'Unrelated change', 'user_id' => $foreign->user_id])
            ->assertUnprocessable()->assertJsonValidationErrors('education_institution_id');
        $this->assertSame($before, $record->refresh()->getAttributes());

        $repairId = $detach ? null : $owned->id;
        $this->patchJson('/api/'.$route.'/'.$record->id, ['education_institution_id' => $repairId])->assertOk()
            ->assertJsonPath('data.education_institution_id', $repairId);
        $this->assertSame($repairId, $record->refresh()->education_institution_id);
        $this->assertSame($user->id, $record->user_id);
        $this->patchJson('/api/'.$route.'/'.$record->id, ['name' => 'Repaired name'])->assertOk()->assertJsonPath('data.name', 'Repaired name');
    }

    #[DataProvider('institutionChildren')]
    public function test_collection_institution_serialization_uses_one_scoped_eager_query(string $model, string $route): void
    {
        $user = User::factory()->create();
        $owned = EducationInstitution::factory()->for($user)->create();
        $foreign = EducationInstitution::factory()->create();
        foreach ([$owned, $foreign] as $institution) {
            for ($index = 0; $index < 3; $index++) {
                $model::factory()->for($user)->for($institution)->create();
            }
        }
        $model::factory()->for($user)->create();
        $this->actingAs($user, 'web');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $response = $this->getJson('/api/'.$route)->assertOk()->assertJsonCount(7, 'data');
            $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from `education_institutions`'));
            $this->assertCount(1, $queries);
            $this->assertStringContainsString('`user_id` = ?', $queries->first()['query']);
            $this->assertSame($user->id, $queries->first()['bindings'][0]);
            $ids = array_column($response->json('data'), 'education_institution_id');
            $this->assertSame(3, count(array_filter($ids, fn ($id) => $id === $owned->id)));
            $this->assertSame(4, count(array_filter($ids, fn ($id) => $id === null)));
            $this->assertNotContains($foreign->id, $ids);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    #[DataProvider('allResources')]
    public function test_direct_foreign_access_remains_hidden_before_validation(string $model, string $route): void
    {
        $record = $model::factory()->create()->refresh();
        $before = $record->getAttributes();
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $this->getJson('/api/'.$route.'/'.$record->id.'?user_id='.$record->user_id)->assertNotFound();
        $this->patchJson('/api/'.$route.'/'.$record->id, ['name' => 'Stolen', 'user_id' => $user->id])->assertNotFound();
        $this->patchJson('/api/'.$route.'/'.$record->id, ['name' => [], 'user_id' => $user->id])->assertNotFound();
        $this->deleteJson('/api/'.$route.'/'.$record->id)->assertNotFound();

        $this->assertSame($before, $record->refresh()->getAttributes());
    }

    public function test_subject_color_exception_is_scoped_and_unrelated_endpoints_still_convert_empty_strings(): void
    {
        $user = User::factory()->create();
        $subject = Subject::factory()->for($user)->create(['color' => '#a1B2c3']);
        $teacher = Teacher::factory()->for($user)->create();
        $period = AcademicPeriod::factory()->for($user)->create();
        $this->actingAs($user, 'web');

        $this->patchJson('/api/subjects/'.$subject->id, ['color' => ''])->assertUnprocessable()->assertJsonValidationErrors('color');
        $this->assertSame('#a1B2c3', $subject->refresh()->color);
        $this->patchJson('/api/subjects/'.$subject->id, ['color' => null])->assertOk()->assertJsonPath('data.color', null);
        // Empty Subject code remains a string, as allowed by the current contract.
        $this->patchJson('/api/subjects/'.$subject->id, ['code' => ''])->assertOk()->assertJsonPath('data.code', '');
        $this->patchJson('/api/teachers/'.$teacher->id, ['education_institution_id' => ''])->assertOk()->assertJsonPath('data.education_institution_id', null);
        $this->patchJson('/api/academic-periods/'.$period->id, ['education_institution_id' => ''])->assertOk()->assertJsonPath('data.education_institution_id', null);
        $fields = ['max_daily_study_minutes', 'max_weekly_study_minutes', 'preferred_break_minutes', 'min_session_minutes', 'max_session_minutes'];
        $this->putJson('/api/planning-preferences', array_fill_keys($fields, ''))->assertOk()->assertExactJson(['data' => array_fill_keys($fields, null)]);
    }
}
