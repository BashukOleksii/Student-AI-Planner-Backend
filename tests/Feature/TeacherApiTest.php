<?php

namespace Tests\Feature;

use App\Models\EducationInstitution;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Academic\TeacherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeacherApiTest extends TestCase
{
    use RefreshDatabase;

    private function url(Teacher $teacher): string
    {
        return '/api/teachers/'.$teacher->id;
    }

    private function publicFields(Teacher $teacher): array
    {
        return $teacher->only(['id', 'education_institution_id', 'name']);
    }

    public function test_all_endpoints_require_authentication(): void
    {
        $teacher = Teacher::factory()->create();
        $this->getJson('/api/teachers')->assertUnauthorized();
        $this->postJson('/api/teachers', ['name' => 'Teacher'])->assertUnauthorized();
        $this->getJson($this->url($teacher))->assertUnauthorized();
        $this->patchJson($this->url($teacher), [])->assertUnauthorized();
        $this->deleteJson($this->url($teacher))->assertUnauthorized();
    }

    public function test_list_is_user_scoped_and_ordered_by_name_then_id_for_duplicate_names(): void
    {
        $user = User::factory()->create();
        $late = Teacher::factory()->for($user)->create(['name' => 'Z Teacher']);
        $early = Teacher::factory()->for($user)->create(['name' => 'A Teacher']);
        $tie = Teacher::factory()->for($user)->create(['name' => 'A Teacher']);
        $foreign = Teacher::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/teachers?user_id='.$foreign->user_id)->assertOk()
            ->assertExactJson(['data' => [$this->publicFields($early), $this->publicFields($tie), $this->publicFields($late)]]);
    }

    public function test_empty_list_returns_resource_collection(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/teachers')->assertOk()->assertExactJson(['data' => []]);
    }

    #[DataProvider('optionalInstitution')]
    public function test_minimal_create_uses_trusted_owner_and_exact_public_shape(array $optional): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $response = $this->actingAs($user, 'web')->postJson('/api/teachers', ['name' => 'Teacher', 'user_id' => $other->id, 'id' => 999999, ...$optional])->assertCreated();
        $teacher = Teacher::sole();
        $response->assertExactJson(['data' => ['id' => $teacher->id, 'education_institution_id' => null, 'name' => 'Teacher']]);
        $this->assertSame($user->id, $teacher->user_id);
        $this->assertNotSame(999999, $teacher->id);
    }

    public static function optionalInstitution(): array
    {
        return [[[]], [['education_institution_id' => null]]];
    }

    public function test_owned_institution_create_owner_show_and_partial_update_attach_detach(): void
    {
        $institution = EducationInstitution::factory()->create();
        $otherInstitution = EducationInstitution::factory()->for($institution->user)->create();
        $otherUser = User::factory()->create();
        $response = $this->actingAs($institution->user, 'web')->postJson('/api/teachers', ['name' => 'Teacher', 'education_institution_id' => $institution->id])->assertCreated();
        $teacher = Teacher::sole();
        $response->assertExactJson(['data' => $this->publicFields($teacher)]);
        $this->assertSame($institution->id, $teacher->education_institution_id);
        $this->getJson($this->url($teacher))->assertOk()->assertExactJson(['data' => $this->publicFields($teacher)]);
        $this->patchJson($this->url($teacher), ['name' => 'Renamed', 'user_id' => $otherUser->id, 'id' => 999999])->assertOk()
            ->assertExactJson(['data' => ['id' => $teacher->id, 'education_institution_id' => $institution->id, 'name' => 'Renamed']]);
        $this->assertSame($institution->user_id, $teacher->refresh()->user_id);
        $this->patchJson($this->url($teacher), ['education_institution_id' => null])->assertOk()->assertJsonPath('data.education_institution_id', null);
        $this->patchJson($this->url($teacher), ['education_institution_id' => $otherInstitution->id])->assertOk()->assertJsonPath('data.education_institution_id', $otherInstitution->id);
        $this->patchJson($this->url($teacher), [])->assertOk()->assertExactJson(['data' => $this->publicFields($teacher->refresh())]);
    }

    public function test_foreign_and_missing_institution_errors_are_identical_without_mutation(): void
    {
        $teacher = Teacher::factory()->create()->refresh();
        $foreign = EducationInstitution::factory()->create();
        $original = $teacher->getAttributes();
        $this->actingAs($teacher->user, 'web');
        $errors = [];
        foreach ([$foreign->id, 999999] as $id) {
            $create = $this->postJson('/api/teachers', ['name' => 'New', 'education_institution_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('education_institution_id');
            $patch = $this->patchJson($this->url($teacher), ['name' => 'Changed', 'education_institution_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('education_institution_id');
            $this->assertSame($create->json(), $patch->json());
            $errors[] = $create->json();
        }
        $this->assertSame($errors[0], $errors[1]);
        $this->assertSame($original, $teacher->refresh()->getAttributes());
        $this->assertDatabaseCount('teachers', 1);
    }

    public function test_duplicate_teacher_names_are_allowed_on_create_and_update(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Same Teacher']);
        $this->actingAs($teacher->user, 'web')->postJson('/api/teachers', ['name' => 'Same Teacher'])->assertCreated();
        $this->postJson('/api/teachers', ['name' => 'Another'])->assertCreated();
        $other = $teacher->user->teachers()->where('name', 'Another')->firstOrFail();
        $this->patchJson($this->url($other), ['name' => 'Same Teacher'])->assertOk();
        $this->assertSame(3, $teacher->user->teachers()->where('name', 'Same Teacher')->count());
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_create_and_patch_do_not_mutate(array $values, string $field): void
    {
        $teacher = Teacher::factory()->create()->refresh();
        $original = $teacher->getAttributes();
        $this->actingAs($teacher->user, 'web')->postJson('/api/teachers', ['name' => 'New', ...$values])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson($this->url($teacher), $values)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($original, $teacher->refresh()->getAttributes());
        $this->assertDatabaseCount('teachers', 1);
    }

    public static function invalidInput(): array
    {
        return [
            [['name' => null], 'name'], [['name' => ''], 'name'], [['name' => []], 'name'], [['name' => str_repeat('a', 256)], 'name'],
            [['education_institution_id' => 0], 'education_institution_id'], [['education_institution_id' => -1], 'education_institution_id'],
            [['education_institution_id' => '1'], 'education_institution_id'], [['education_institution_id' => true], 'education_institution_id'],
            [['education_institution_id' => 1.5], 'education_institution_id'],
        ];
    }

    public function test_create_requires_name(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/teachers', [])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_foreign_resources_are_hidden_even_for_invalid_patch(): void
    {
        $teacher = Teacher::factory()->create()->refresh();
        $original = $teacher->getAttributes();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url($teacher))->assertNotFound();
        $this->patchJson($this->url($teacher), ['name' => 'Stolen'])->assertNotFound();
        $this->patchJson($this->url($teacher), ['name' => []])->assertNotFound();
        $this->deleteJson($this->url($teacher))->assertNotFound();
        $this->assertSame($original, $teacher->refresh()->getAttributes());
    }

    public function test_owner_delete_without_lessons_returns_empty_204(): void
    {
        $teacher = Teacher::factory()->create();
        $this->actingAs($teacher->user, 'web')->deleteJson($this->url($teacher))->assertNoContent();
        $this->assertModelMissing($teacher);
    }

    #[DataProvider('lessonHistory')]
    public function test_teacher_delete_preserves_active_and_soft_deleted_lessons_with_null_fk(bool $trashed): void
    {
        $teacher = Teacher::factory()->create();
        $lesson = Lesson::factory()->for($teacher->user)->for($teacher)->create();
        if ($trashed) {
            $lesson->delete();
        }
        $this->actingAs($teacher->user, 'web')->deleteJson($this->url($teacher))->assertNoContent();
        $this->assertModelMissing($teacher);
        $this->assertModelExists($lesson);
        $this->assertNull($lesson->refresh()->teacher_id);
        if ($trashed) {
            $this->assertSoftDeleted($lesson);
        }
    }

    public static function lessonHistory(): array
    {
        return [[false], [true]];
    }

    public function test_service_preserves_latest_institution_when_updating_a_stale_model(): void
    {
        $teacher = Teacher::factory()->create();
        $institution = EducationInstitution::factory()->for($teacher->user)->create();
        $service = app(TeacherService::class);
        $service->update($teacher->user, $teacher, ['education_institution_id' => $institution->id]);
        $updated = $service->update($teacher->user, $teacher, ['name' => 'Updated']);
        $this->assertSame($institution->id, $updated->education_institution_id);
        $this->assertSame('Updated', $updated->name);
    }
}
