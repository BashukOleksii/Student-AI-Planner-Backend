<?php

namespace Tests\Feature;

use App\Models\EducationInstitution;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactTeacherApiTest extends TestCase
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

    public function test_list_is_user_scoped_and_ordered_by_name_then_id_for_duplicate_names(): void
    {
        $user = User::factory()->create();
        $late = Teacher::factory()->for($user)->create(['name' => 'Z Teacher']);
        $early = Teacher::factory()->for($user)->create(['name' => 'A Teacher']);
        $tie = Teacher::factory()->for($user)->create(['name' => 'A Teacher']);
        $foreign = Teacher::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/teachers')->assertOk()->assertExactJson(['data' => [$this->publicFields($early), $this->publicFields($tie), $this->publicFields($late)]]);
    }

    public function test_empty_list_returns_resource_collection(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/teachers')->assertOk()->assertExactJson(['data' => []]);
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
        $this->patchJson($this->url($teacher), ['name' => 'Renamed'])->assertOk()->assertExactJson(['data' => ['id' => $teacher->id, 'education_institution_id' => $institution->id, 'name' => 'Renamed']]);
        $this->assertSame($institution->user_id, $teacher->refresh()->user_id);
        $this->patchJson($this->url($teacher), ['education_institution_id' => null])->assertOk()->assertJsonPath('data.education_institution_id', null);
        $this->patchJson($this->url($teacher), ['education_institution_id' => $otherInstitution->id])->assertOk()->assertJsonPath('data.education_institution_id', $otherInstitution->id);
        $this->patchJson($this->url($teacher), [])->assertOk()->assertExactJson(['data' => $this->publicFields($teacher->refresh())]);
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
}
