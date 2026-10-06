<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Models\EducationInstitution;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Task;
use App\Models\User;
use App\Services\Academic\SubjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubjectApiTest extends TestCase
{
    use RefreshDatabase;

    private function url(Subject $subject): string
    {
        return '/api/subjects/'.$subject->id;
    }

    private function publicFields(Subject $subject): array
    {
        return $subject->only(['id', 'education_institution_id', 'name', 'code', 'color']);
    }

    public function test_all_endpoints_require_authentication(): void
    {
        $subject = Subject::factory()->create();
        $this->getJson('/api/subjects')->assertUnauthorized();
        $this->postJson('/api/subjects', ['name' => 'Algorithms'])->assertUnauthorized();
        $this->getJson($this->url($subject))->assertUnauthorized();
        $this->patchJson($this->url($subject), [])->assertUnauthorized();
        $this->deleteJson($this->url($subject))->assertUnauthorized();
    }

    public function test_list_is_current_user_scoped_and_ordered_by_name(): void
    {
        $user = User::factory()->create();
        $late = Subject::factory()->for($user)->create(['name' => 'Zoology']);
        $early = Subject::factory()->for($user)->create(['name' => 'Algorithms']);
        $foreign = Subject::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/subjects?user_id='.$foreign->user_id)->assertOk()
            ->assertExactJson(['data' => [$this->publicFields($early), $this->publicFields($late)]]);
    }

    public function test_empty_list_is_a_resource_collection(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/subjects')->assertOk()->assertExactJson(['data' => []]);
    }

    #[DataProvider('nullOptionals')]
    public function test_minimal_create_defaults_optional_fields_to_null_and_ignores_owner(array $optional): void
    {
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        $response = $this->actingAs($user, 'web')->postJson('/api/subjects', ['name' => 'Algorithms', 'user_id' => $foreign->id, 'id' => 999999, ...$optional])->assertCreated();
        $subject = Subject::sole();
        $response->assertExactJson(['data' => ['id' => $subject->id, 'education_institution_id' => null, 'name' => 'Algorithms', 'code' => null, 'color' => null]]);
        $this->assertSame($user->id, $subject->user_id);
        $this->assertNotSame(999999, $subject->id);
    }

    public static function nullOptionals(): array
    {
        return [[[]], [['education_institution_id' => null, 'code' => null, 'color' => null]]];
    }

    public function test_all_optional_fields_owned_institution_and_owner_show(): void
    {
        $institution = EducationInstitution::factory()->create();
        $response = $this->actingAs($institution->user, 'web')->postJson('/api/subjects', [
            'name' => 'Computer Architecture', 'education_institution_id' => $institution->id, 'code' => 'CA-101', 'color' => '#a1B2c3',
        ])->assertCreated();
        $subject = Subject::sole();
        $response->assertExactJson(['data' => $this->publicFields($subject)]);
        $this->assertSame($institution->id, $subject->education_institution_id);
        $this->assertSame('CA-101', $subject->code);
        $this->assertSame('#a1B2c3', $subject->color);
        $this->getJson($this->url($subject))->assertOk()->assertExactJson(['data' => $this->publicFields($subject)]);
    }

    public function test_foreign_and_missing_institutions_are_indistinguishable_and_leave_state_unchanged(): void
    {
        $subject = Subject::factory()->create()->refresh();
        $foreign = EducationInstitution::factory()->create();
        $original = $subject->getAttributes();
        $this->actingAs($subject->user, 'web');
        $errors = [];
        foreach ([$foreign->id, 999999] as $id) {
            $create = $this->postJson('/api/subjects', ['name' => 'New', 'education_institution_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('education_institution_id');
            $update = $this->patchJson($this->url($subject), ['name' => 'Changed', 'education_institution_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('education_institution_id');
            $this->assertSame($create->json(), $update->json());
            $errors[] = $create->json();
        }
        $this->assertSame($errors[0], $errors[1]);
        $this->assertSame($original, $subject->refresh()->getAttributes());
        $this->assertDatabaseCount('subjects', 1);
    }

    public function test_partial_patch_preserves_omitted_fields_attaches_detaches_and_ignores_owner(): void
    {
        $institution = EducationInstitution::factory()->create();
        $subject = Subject::factory()->for($institution->user)->for($institution)->create(['code' => 'ALG', 'color' => '#112233']);
        $other = User::factory()->create();
        $this->actingAs($subject->user, 'web')->patchJson($this->url($subject), ['code' => null, 'user_id' => $other->id, 'id' => 999999])->assertOk()
            ->assertExactJson(['data' => ['id' => $subject->id, 'education_institution_id' => $institution->id, 'name' => $subject->name, 'code' => null, 'color' => '#112233']]);
        $this->assertSame($institution->user_id, $subject->refresh()->user_id);
        $this->patchJson($this->url($subject), ['education_institution_id' => null, 'color' => null])->assertOk();
        $this->assertNull($subject->refresh()->education_institution_id);
        $this->assertNull($subject->color);
        $this->patchJson($this->url($subject), ['education_institution_id' => $institution->id])->assertOk()->assertJsonPath('data.education_institution_id', $institution->id);
        $this->patchJson($this->url($subject), [])->assertOk()->assertExactJson(['data' => $this->publicFields($subject->refresh())]);
    }

    public function test_name_unique_per_owner_across_institutions_but_code_is_not_unique(): void
    {
        $subject = Subject::factory()->create(['name' => 'Algorithms', 'code' => 'SAME']);
        $secondInstitution = EducationInstitution::factory()->for($subject->user)->create();
        $second = Subject::factory()->for($subject->user)->create(['name' => 'Other', 'code' => 'SAME'])->refresh();
        Subject::factory()->create(['name' => 'Shared Name']);
        $original = $second->getAttributes();
        $this->actingAs($subject->user, 'web')->postJson('/api/subjects', ['name' => 'Algorithms', 'education_institution_id' => $secondInstitution->id])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson($this->url($second), ['name' => 'Algorithms'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($original, $second->refresh()->getAttributes());
        $this->patchJson($this->url($subject), ['name' => 'Algorithms'])->assertOk();
        $this->postJson('/api/subjects', ['name' => 'Shared Name', 'code' => 'SAME'])->assertCreated();
        $this->patchJson($this->url($subject), ['code' => 'SAME'])->assertOk();
        $this->assertSame(3, $subject->user->subjects()->count());
    }

    #[DataProvider('validColors')]
    public function test_valid_colors_are_preserved_on_create_and_patch(?string $color): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/subjects', ['name' => 'Algorithms', 'color' => $color])
            ->assertCreated()->assertJsonPath('data.color', $color);
        $subject = Subject::sole();
        $this->patchJson($this->url($subject), ['color' => $color])->assertOk()->assertJsonPath('data.color', $color);
        $this->assertSame($color, $subject->refresh()->color);
    }

    public static function validColors(): array
    {
        return [['#A1B2C3'], ['#a1b2c3'], ['#a1B2c3'], [null]];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_fields_fail_create_and_patch_without_mutating(array $values, string $field): void
    {
        $subject = Subject::factory()->create(['color' => '#112233'])->refresh();
        $original = $subject->getAttributes();
        $this->actingAs($subject->user, 'web')->postJson('/api/subjects', ['name' => 'New', ...$values])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson($this->url($subject), $values)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($original, $subject->refresh()->getAttributes());
        $this->assertDatabaseCount('subjects', 1);
    }

    public static function invalidInput(): array
    {
        return [
            [['name' => null], 'name'], [['name' => ''], 'name'], [['name' => []], 'name'], [['name' => str_repeat('a', 256)], 'name'],
            [['code' => str_repeat('a', 51)], 'code'], [['code' => []], 'code'],
            [['color' => ''], 'color'], [['color' => 'A1B2C3'], 'color'], [['color' => '#ABC'], 'color'],
            [['color' => '#G1B2C3'], 'color'], [['color' => '#A1 B2C'], 'color'], [['color' => '#A1B2C3FF'], 'color'], [['color' => []], 'color'],
            [['education_institution_id' => 0], 'education_institution_id'], [['education_institution_id' => -1], 'education_institution_id'],
            [['education_institution_id' => '1'], 'education_institution_id'], [['education_institution_id' => true], 'education_institution_id'],
            [['education_institution_id' => 1.5], 'education_institution_id'],
        ];
    }

    public function test_code_maximum_length_is_accepted_and_can_be_cleared(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/subjects', ['name' => 'Algorithms', 'code' => str_repeat('A', 50)])->assertCreated();
        $subject = Subject::sole();
        $this->assertSame(str_repeat('A', 50), $subject->code);
        $this->patchJson($this->url($subject), ['code' => str_repeat('B', 50)])->assertOk()->assertJsonPath('data.code', str_repeat('B', 50));
        $this->patchJson($this->url($subject), ['code' => null])->assertOk()->assertJsonPath('data.code', null);
    }

    public function test_create_requires_name(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/subjects', [])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_foreign_resource_is_hidden_even_for_invalid_patch(): void
    {
        $subject = Subject::factory()->create()->refresh();
        $original = $subject->getAttributes();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url($subject))->assertNotFound();
        $this->patchJson($this->url($subject), ['name' => 'Stolen'])->assertNotFound();
        $this->patchJson($this->url($subject), ['color' => 'invalid'])->assertNotFound();
        $this->deleteJson($this->url($subject))->assertNotFound();
        $this->assertSame($original, $subject->refresh()->getAttributes());
    }

    public function test_delete_subject_without_lessons_returns_empty_204(): void
    {
        $subject = Subject::factory()->create();
        $this->actingAs($subject->user, 'web')->deleteJson($this->url($subject))->assertNoContent();
        $this->assertModelMissing($subject);
    }

    #[DataProvider('lessonHistory')]
    public function test_any_lesson_history_blocks_subject_deletion(LessonStatus $status, bool $trashed): void
    {
        $subject = Subject::factory()->create();
        $lesson = Lesson::factory()->for($subject->user)->for($subject)->create(['status' => $status]);
        if ($trashed) {
            $lesson->delete();
        }
        $this->actingAs($subject->user, 'web')->deleteJson($this->url($subject))->assertUnprocessable()->assertJsonValidationErrors('subject');
        $this->assertModelExists($subject);
        if ($trashed) {
            $this->assertSoftDeleted($lesson);
        } else {
            $this->assertModelExists($lesson);
        }
    }

    public static function lessonHistory(): array
    {
        return [[LessonStatus::Active, false], [LessonStatus::Cancelled, false], [LessonStatus::Replaced, false], [LessonStatus::Active, true]];
    }

    #[DataProvider('taskHistory')]
    public function test_tasks_do_not_block_delete_and_their_nullable_fk_is_cleared(bool $trashed): void
    {
        $subject = Subject::factory()->create();
        $task = Task::factory()->for($subject->user)->for($subject)->create();
        if ($trashed) {
            $task->delete();
        }
        $this->actingAs($subject->user, 'web')->deleteJson($this->url($subject))->assertNoContent();
        $this->assertModelMissing($subject);
        $this->assertModelExists($task);
        $this->assertNull($task->refresh()->subject_id);
        if ($trashed) {
            $this->assertSoftDeleted($task);
        }
    }

    public static function taskHistory(): array
    {
        return [[false], [true]];
    }

    public function test_service_merges_latest_persisted_state_instead_of_stale_model(): void
    {
        $subject = Subject::factory()->create();
        $service = app(SubjectService::class);
        $service->update($subject->user, $subject, ['color' => '#112233']);
        $updated = $service->update($subject->user, $subject, ['code' => 'ALG']);
        $this->assertSame('#112233', $updated->color);
        $this->assertSame('ALG', $updated->code);
    }
}
