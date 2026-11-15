<?php

namespace Tests\Feature;

use App\Models\EducationInstitution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactEducationInstitutionApiTest extends TestCase
{
    use RefreshDatabase;

    private function url(EducationInstitution $institution): string
    {
        return '/api/education-institutions/'.$institution->id;
    }

    public function test_collection_is_user_scoped_and_ordered_by_name_then_id(): void
    {
        $user = User::factory()->create();
        $last = EducationInstitution::factory()->for($user)->create(['name' => 'Z University']);
        $first = EducationInstitution::factory()->for($user)->create(['name' => 'A University']);
        $foreign = EducationInstitution::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/education-institutions')->assertOk()->assertExactJson(['data' => [['id' => $first->id, 'name' => $first->name], ['id' => $last->id, 'name' => $last->name]]]);
    }

    public function test_empty_collection_is_a_resource_collection(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/education-institutions')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_owner_can_show_update_and_keep_same_name_without_reassigning_owner(): void
    {
        $institution = EducationInstitution::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($institution->user, 'web')->getJson($this->url($institution))->assertOk()->assertExactJson(['data' => ['id' => $institution->id, 'name' => $institution->name]]);
        $this->patchJson($this->url($institution), ['name' => $institution->name])->assertOk();
        $this->patchJson($this->url($institution), ['name' => 'Renamed University'])->assertOk()->assertExactJson(['data' => ['id' => $institution->id, 'name' => 'Renamed University']]);
        $this->assertDatabaseHas('education_institutions', ['id' => $institution->id, 'user_id' => $institution->user_id, 'name' => 'Renamed University']);
        $this->patchJson($this->url($institution), [])->assertOk();
    }

    public function test_per_user_name_uniqueness_for_create_and_update(): void
    {
        $institution = EducationInstitution::factory()->create(['name' => 'University']);
        $second = EducationInstitution::factory()->for($institution->user)->create(['name' => 'Second University'])->refresh();
        $original = $second->getAttributes();
        $this->actingAs($institution->user, 'web')->postJson('/api/education-institutions', ['name' => 'University'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson($this->url($second), ['name' => 'University'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($original, $second->refresh()->getAttributes());
        $this->assertDatabaseCount('education_institutions', 2);
        EducationInstitution::factory()->create(['name' => 'Shared Name']);
        $this->postJson('/api/education-institutions', ['name' => 'Shared Name'])->assertCreated();
        EducationInstitution::factory()->create(['name' => 'Foreign Name']);
        $this->patchJson($this->url($second), ['name' => 'Foreign Name'])->assertOk();
    }
}
