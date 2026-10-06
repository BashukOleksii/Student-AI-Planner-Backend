<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EducationInstitutionApiTest extends TestCase
{
    use RefreshDatabase;

    private function url(EducationInstitution $institution): string
    {
        return '/api/education-institutions/'.$institution->id;
    }

    public function test_all_endpoints_require_authentication(): void
    {
        $institution = EducationInstitution::factory()->create();
        $this->getJson('/api/education-institutions')->assertUnauthorized();
        $this->postJson('/api/education-institutions', ['name' => 'University'])->assertUnauthorized();
        $this->getJson($this->url($institution))->assertUnauthorized();
        $this->patchJson($this->url($institution), [])->assertUnauthorized();
        $this->deleteJson($this->url($institution))->assertUnauthorized();
    }

    public function test_collection_is_user_scoped_and_ordered_by_name_then_id(): void
    {
        $user = User::factory()->create();
        $last = EducationInstitution::factory()->for($user)->create(['name' => 'Z University']);
        $first = EducationInstitution::factory()->for($user)->create(['name' => 'A University']);
        $foreign = EducationInstitution::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/education-institutions?user_id='.$foreign->user_id)
            ->assertOk()->assertExactJson(['data' => [
                ['id' => $first->id, 'name' => $first->name],
                ['id' => $last->id, 'name' => $last->name],
            ]]);
    }

    public function test_empty_collection_is_a_resource_collection(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/education-institutions')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_create_sets_trusted_owner_and_exposes_only_public_fields(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $response = $this->actingAs($user, 'web')->postJson('/api/education-institutions', [
            'name' => 'University', 'user_id' => $other->id, 'id' => 123456, 'created_at' => '2000-01-01',
        ])->assertCreated();
        $institution = EducationInstitution::sole();
        $response->assertExactJson(['data' => ['id' => $institution->id, 'name' => 'University']]);
        $this->assertSame($user->id, $institution->user_id);
        $this->assertNotSame(123456, $institution->id);
    }

    public function test_owner_can_show_update_and_keep_same_name_without_reassigning_owner(): void
    {
        $institution = EducationInstitution::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($institution->user, 'web')->getJson($this->url($institution))
            ->assertOk()->assertExactJson(['data' => ['id' => $institution->id, 'name' => $institution->name]]);
        $this->patchJson($this->url($institution), ['name' => $institution->name])->assertOk();
        $this->patchJson($this->url($institution), ['name' => 'Renamed University', 'user_id' => $other->id, 'id' => 999])
            ->assertOk()->assertExactJson(['data' => ['id' => $institution->id, 'name' => 'Renamed University']]);
        $this->assertDatabaseHas('education_institutions', ['id' => $institution->id, 'user_id' => $institution->user_id, 'name' => 'Renamed University']);
        $this->patchJson($this->url($institution), [])->assertOk();
    }

    public function test_per_user_name_uniqueness_for_create_and_update(): void
    {
        $institution = EducationInstitution::factory()->create(['name' => 'University']);
        $second = EducationInstitution::factory()->for($institution->user)->create(['name' => 'Second University'])->refresh();
        $original = $second->getAttributes();
        $this->actingAs($institution->user, 'web')->postJson('/api/education-institutions', ['name' => 'University'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson($this->url($second), ['name' => 'University'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($original, $second->refresh()->getAttributes());
        $this->assertDatabaseCount('education_institutions', 2);
        EducationInstitution::factory()->create(['name' => 'Shared Name']);
        $this->postJson('/api/education-institutions', ['name' => 'Shared Name'])->assertCreated();
        EducationInstitution::factory()->create(['name' => 'Foreign Name']);
        $this->patchJson($this->url($second), ['name' => 'Foreign Name'])->assertOk();
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names_fail_create_and_update_without_mutation(mixed $name): void
    {
        $institution = EducationInstitution::factory()->create()->refresh();
        $original = $institution->getAttributes();
        $this->actingAs($institution->user, 'web')->postJson('/api/education-institutions', ['name' => $name])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson($this->url($institution), ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($original, $institution->refresh()->getAttributes());
        $this->assertDatabaseCount('education_institutions', 1);
    }

    public static function invalidNames(): array
    {
        return [[null], [''], [[]], [123], [str_repeat('a', 256)]];
    }

    public function test_create_requires_name(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/education-institutions', [])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_foreign_resource_is_hidden_for_reads_mutations_and_invalid_patch(): void
    {
        $institution = EducationInstitution::factory()->create()->refresh();
        $original = $institution->getAttributes();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url($institution))->assertNotFound();
        $this->patchJson($this->url($institution), ['name' => 'Stolen'])->assertNotFound();
        $this->patchJson($this->url($institution), ['name' => []])->assertNotFound();
        $this->deleteJson($this->url($institution))->assertNotFound();
        $this->assertSame($original, $institution->refresh()->getAttributes());
    }

    public function test_owner_delete_preserves_period_and_sets_nullable_fk_to_null(): void
    {
        $institution = EducationInstitution::factory()->create();
        $period = AcademicPeriod::factory()->for($institution->user)->for($institution)->create()->refresh();
        $this->actingAs($institution->user, 'web')->deleteJson($this->url($institution))->assertNoContent();
        $this->assertModelMissing($institution);
        $this->assertModelExists($period);
        $this->assertNull($period->refresh()->education_institution_id);
    }

    public function test_missing_resource_is_hidden_as_not_found(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/education-institutions/999999')->assertNotFound();
        $this->patchJson('/api/education-institutions/999999', ['name' => []])->assertNotFound();
        $this->deleteJson('/api/education-institutions/999999')->assertNotFound();
    }
}
