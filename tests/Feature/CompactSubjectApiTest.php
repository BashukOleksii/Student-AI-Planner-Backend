<?php

namespace Tests\Feature;

use App\Models\EducationInstitution;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactSubjectApiTest extends TestCase
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

    public function test_list_is_current_user_scoped_and_ordered_by_name(): void
    {
        $user = User::factory()->create();
        $late = Subject::factory()->for($user)->create(['name' => 'Zoology']);
        $early = Subject::factory()->for($user)->create(['name' => 'Algorithms']);
        $foreign = Subject::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/subjects')->assertOk()->assertExactJson(['data' => [$this->publicFields($early), $this->publicFields($late)]]);
    }

    public function test_empty_list_is_a_resource_collection(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/subjects')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_all_optional_fields_owned_institution_and_owner_show(): void
    {
        $institution = EducationInstitution::factory()->create();
        $response = $this->actingAs($institution->user, 'web')->postJson('/api/subjects', ['name' => 'Computer Architecture', 'education_institution_id' => $institution->id, 'code' => 'CA-101', 'color' => '#a1B2c3'])->assertCreated();
        $subject = Subject::sole();
        $response->assertExactJson(['data' => $this->publicFields($subject)]);
        $this->assertSame($institution->id, $subject->education_institution_id);
        $this->assertSame('CA-101', $subject->code);
        $this->assertSame('#a1B2c3', $subject->color);
        $this->getJson($this->url($subject))->assertOk()->assertExactJson(['data' => $this->publicFields($subject)]);
    }

    public function test_name_unique_per_owner_across_institutions_but_code_is_not_unique(): void
    {
        $subject = Subject::factory()->create(['name' => 'Algorithms', 'code' => 'SAME']);
        $secondInstitution = EducationInstitution::factory()->for($subject->user)->create();
        $second = Subject::factory()->for($subject->user)->create(['name' => 'Other', 'code' => 'SAME'])->refresh();
        Subject::factory()->create(['name' => 'Shared Name']);
        $original = $second->getAttributes();
        $this->actingAs($subject->user, 'web')->postJson('/api/subjects', ['name' => 'Algorithms', 'education_institution_id' => $secondInstitution->id])->assertUnprocessable()->assertJsonValidationErrors('name');
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
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/subjects', ['name' => 'Algorithms', 'color' => $color])->assertCreated()->assertJsonPath('data.color', $color);
        $subject = Subject::sole();
        $this->patchJson($this->url($subject), ['color' => $color])->assertOk()->assertJsonPath('data.color', $color);
        $this->assertSame($color, $subject->refresh()->color);
    }

    public static function validColors(): array
    {
        return [0 => ['#A1B2C3']];
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
        return [0 => [['name' => null], 'name']];
    }
}
