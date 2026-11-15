<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactAcademicPeriodApiTest extends TestCase
{
    use RefreshDatabase;

    private function values(array $overrides = []): array
    {
        return array_replace(['name' => 'Semester 1', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31'], $overrides);
    }

    private function url(AcademicPeriod $period): string
    {
        return '/api/academic-periods/'.$period->id;
    }

    private function publicFields(AcademicPeriod $period): array
    {
        return ['id' => $period->id, 'education_institution_id' => $period->education_institution_id, 'name' => $period->name, 'starts_on' => $period->starts_on->format('Y-m-d'), 'ends_on' => $period->ends_on->format('Y-m-d')];
    }

    public function test_list_is_user_scoped_and_ordered_by_start_then_id(): void
    {
        $user = User::factory()->create();
        $late = AcademicPeriod::factory()->for($user)->create(['starts_on' => '2027-01-01', 'ends_on' => '2027-02-01']);
        $early = AcademicPeriod::factory()->for($user)->create();
        $tie = AcademicPeriod::factory()->for($user)->create();
        $other = AcademicPeriod::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/academic-periods')->assertOk()->assertExactJson(['data' => array_map($this->publicFields(...), [$early, $tie, $late])]);
    }

    public function test_empty_list_uses_resource_collection(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/academic-periods')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_create_with_owned_institution_and_owner_show(): void
    {
        $institution = EducationInstitution::factory()->create();
        $response = $this->actingAs($institution->user, 'web')->postJson('/api/academic-periods', $this->values(['education_institution_id' => $institution->id]))->assertCreated();
        $period = AcademicPeriod::sole();
        $response->assertExactJson(['data' => $this->publicFields($period)]);
        $this->assertSame($institution->id, $period->education_institution_id);
        $this->getJson($this->url($period))->assertOk()->assertExactJson(['data' => $this->publicFields($period)]);
    }

    public function test_equal_date_range_is_valid_on_create_and_partial_patch(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/academic-periods', $this->values(['ends_on' => '2026-09-01']))->assertCreated();
        $period = AcademicPeriod::sole();
        $this->patchJson($this->url($period), ['ends_on' => '2026-10-01'])->assertOk();
        $this->patchJson($this->url($period), ['starts_on' => '2026-10-01'])->assertOk()->assertJsonPath('data.starts_on', '2026-10-01');
    }

    public function test_invalid_complete_range_and_partial_date_changes_return_422_without_changes(): void
    {
        $period = AcademicPeriod::factory()->create()->refresh();
        $original = $period->getAttributes();
        $this->actingAs($period->user, 'web')->postJson('/api/academic-periods', $this->values(['starts_on' => '2027-01-01']))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        foreach ([['starts_on' => '2027-01-01'], ['ends_on' => '2026-08-31']] as $values) {
            $this->patchJson($this->url($period), $values)->assertUnprocessable()->assertJsonValidationErrors('ends_on');
            $this->assertSame($original, $period->refresh()->getAttributes());
        }
        $this->assertDatabaseCount('academic_periods', 1);
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_rejected_for_create_and_patch(array $overrides, string $field): void
    {
        $period = AcademicPeriod::factory()->create()->refresh();
        $original = $period->getAttributes();
        $this->actingAs($period->user, 'web')->postJson('/api/academic-periods', $this->values($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson($this->url($period), $overrides)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($original, $period->refresh()->getAttributes());
        $this->assertDatabaseCount('academic_periods', 1);
    }

    public static function invalidInput(): array
    {
        return [0 => [['name' => null], 'name']];
    }
}
