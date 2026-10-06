<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Lesson;
use App\Models\ScheduleImportBatch;
use App\Models\User;
use App\Services\Academic\AcademicPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AcademicPeriodApiTest extends TestCase
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
        return [
            'id' => $period->id, 'education_institution_id' => $period->education_institution_id,
            'name' => $period->name, 'starts_on' => $period->starts_on->format('Y-m-d'), 'ends_on' => $period->ends_on->format('Y-m-d'),
        ];
    }

    public function test_all_endpoints_require_authentication(): void
    {
        $period = AcademicPeriod::factory()->create();
        $this->getJson('/api/academic-periods')->assertUnauthorized();
        $this->postJson('/api/academic-periods', $this->values())->assertUnauthorized();
        $this->getJson($this->url($period))->assertUnauthorized();
        $this->patchJson($this->url($period), [])->assertUnauthorized();
        $this->deleteJson($this->url($period))->assertUnauthorized();
    }

    public function test_list_is_user_scoped_and_ordered_by_start_then_id(): void
    {
        $user = User::factory()->create();
        $late = AcademicPeriod::factory()->for($user)->create(['starts_on' => '2027-01-01', 'ends_on' => '2027-02-01']);
        $early = AcademicPeriod::factory()->for($user)->create();
        $tie = AcademicPeriod::factory()->for($user)->create();
        $other = AcademicPeriod::factory()->create();
        $this->actingAs($user, 'web')->getJson('/api/academic-periods?user_id='.$other->user_id)
            ->assertOk()->assertExactJson(['data' => array_map($this->publicFields(...), [$early, $tie, $late])]);
    }

    public function test_empty_list_uses_resource_collection(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/academic-periods')->assertOk()->assertExactJson(['data' => []]);
    }

    #[DataProvider('optionalInstitution')]
    public function test_create_without_institution_uses_trusted_owner_and_exact_public_dates(array $optional): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $response = $this->actingAs($user, 'web')->postJson('/api/academic-periods', ['user_id' => $other->id, 'id' => 999, ...$this->values($optional)])
            ->assertCreated();
        $period = AcademicPeriod::sole();
        $response->assertExactJson(['data' => $this->publicFields($period)]);
        $this->assertSame($user->id, $period->user_id);
        $this->assertNull($period->education_institution_id);
        $this->assertNotSame(999, $period->id);
    }

    public static function optionalInstitution(): array
    {
        return [[[]], [['education_institution_id' => null]]];
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

    public function test_foreign_and_missing_institutions_have_identical_errors_without_mutation(): void
    {
        $period = AcademicPeriod::factory()->create()->refresh();
        $foreign = EducationInstitution::factory()->create();
        $original = $period->getAttributes();
        $this->actingAs($period->user, 'web');
        $errors = [];
        foreach ([$foreign->id, 999999] as $id) {
            $create = $this->postJson('/api/academic-periods', $this->values(['education_institution_id' => $id]))
                ->assertUnprocessable()->assertJsonValidationErrors('education_institution_id');
            $patch = $this->patchJson($this->url($period), ['education_institution_id' => $id, 'name' => 'Changed'])
                ->assertUnprocessable()->assertJsonValidationErrors('education_institution_id');
            $this->assertSame($create->json(), $patch->json());
            $errors[] = $create->json();
        }
        $this->assertSame($errors[0], $errors[1]);
        $this->assertSame($original, $period->refresh()->getAttributes());
        $this->assertDatabaseCount('academic_periods', 1);
    }

    public function test_partial_patch_preserves_omitted_fields_and_can_attach_detach_and_ignore_owner(): void
    {
        $period = AcademicPeriod::factory()->create();
        $institution = EducationInstitution::factory()->for($period->user)->create();
        $foreignUser = User::factory()->create();
        $this->actingAs($period->user, 'web')->patchJson($this->url($period), ['name' => 'Renamed', 'user_id' => $foreignUser->id])->assertOk();
        $this->assertDatabaseHas('academic_periods', ['id' => $period->id, 'user_id' => $period->user_id, 'name' => 'Renamed', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31']);
        $this->patchJson($this->url($period), ['starts_on' => '2026-10-01'])->assertOk()->assertJsonPath('data.starts_on', '2026-10-01');
        $this->patchJson($this->url($period), ['education_institution_id' => $institution->id])->assertOk()->assertJsonPath('data.education_institution_id', $institution->id);
        $this->patchJson($this->url($period), [])->assertOk()->assertExactJson(['data' => $this->publicFields($period->refresh())]);
        $this->patchJson($this->url($period), ['education_institution_id' => null])->assertOk()->assertJsonPath('data.education_institution_id', null);
        $this->assertSame('2026-12-31', $period->refresh()->ends_on->format('Y-m-d'));
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
        $this->actingAs($period->user, 'web')->postJson('/api/academic-periods', $this->values(['starts_on' => '2027-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('ends_on');
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
        $this->actingAs($period->user, 'web')->postJson('/api/academic-periods', $this->values($overrides))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson($this->url($period), $overrides)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($original, $period->refresh()->getAttributes());
        $this->assertDatabaseCount('academic_periods', 1);
    }

    public static function invalidInput(): array
    {
        return [
            [['name' => null], 'name'], [['name' => []], 'name'], [['name' => str_repeat('a', 256)], 'name'],
            [['starts_on' => '2026-9-01'], 'starts_on'], [['starts_on' => '2026-02-30'], 'starts_on'],
            [['starts_on' => '2026-09-01T00:00:00Z'], 'starts_on'], [['starts_on' => null], 'starts_on'],
            [['ends_on' => 'tomorrow'], 'ends_on'], [['ends_on' => '2026-13-01'], 'ends_on'],
            [['education_institution_id' => 0], 'education_institution_id'], [['education_institution_id' => -1], 'education_institution_id'],
            [['education_institution_id' => '1'], 'education_institution_id'], [['education_institution_id' => true], 'education_institution_id'],
            [['education_institution_id' => 1.5], 'education_institution_id'],
        ];
    }

    public function test_create_requires_name_and_dates(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/academic-periods', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'starts_on', 'ends_on']);
    }

    public function test_unique_tuple_is_scoped_to_user_and_start_date_and_enforced_on_partial_patch(): void
    {
        $existing = AcademicPeriod::factory()->create($this->values());
        AcademicPeriod::factory()->create($this->values());
        $this->actingAs($existing->user, 'web')->postJson('/api/academic-periods', $this->values())
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/academic-periods', $this->values(['starts_on' => '2026-10-01']))->assertCreated();
        $later = $existing->user->academicPeriods()->where('starts_on', '2026-10-01')->firstOrFail();
        $original = $later->getAttributes();
        $this->patchJson($this->url($later), ['starts_on' => '2026-09-01'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($original, $later->refresh()->getAttributes());
        $anotherName = AcademicPeriod::factory()->for($existing->user)->create(['name' => 'Other']);
        $this->patchJson($this->url($anotherName), ['name' => 'Semester 1'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson($this->url($existing), $this->values())->assertOk();
        $this->assertDatabaseCount('academic_periods', 4);
    }

    public function test_same_tuple_owned_by_another_user_does_not_block_create(): void
    {
        AcademicPeriod::factory()->create($this->values());
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/academic-periods', $this->values())->assertCreated();
    }

    public function test_foreign_resource_is_404_for_show_patch_invalid_patch_and_delete(): void
    {
        $period = AcademicPeriod::factory()->create()->refresh();
        $original = $period->getAttributes();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson($this->url($period))->assertNotFound();
        $this->patchJson($this->url($period), ['name' => 'Stolen'])->assertNotFound();
        $this->patchJson($this->url($period), ['starts_on' => 'invalid'])->assertNotFound();
        $this->deleteJson($this->url($period))->assertNotFound();
        $this->assertSame($original, $period->refresh()->getAttributes());
    }

    public function test_period_without_history_deletes_with_empty_204(): void
    {
        $period = AcademicPeriod::factory()->create();
        $this->actingAs($period->user, 'web')->deleteJson($this->url($period))->assertNoContent();
        $this->assertModelMissing($period);
    }

    #[DataProvider('historyTypes')]
    public function test_period_with_import_or_active_or_soft_deleted_lesson_history_cannot_be_deleted(string $type): void
    {
        $period = AcademicPeriod::factory()->create();
        if ($type === 'import') {
            $history = ScheduleImportBatch::factory()->for($period)->create();
        } else {
            $history = Lesson::factory()->for($period->user)->create(['academic_period_id' => $period->id]);
            if ($type === 'soft-deleted lesson') {
                $history->delete();
            }
        }
        $this->actingAs($period->user, 'web')->deleteJson($this->url($period))
            ->assertUnprocessable()->assertJsonValidationErrors('academic_period');
        $this->assertModelExists($period);
        if ($type === 'soft-deleted lesson') {
            $this->assertSoftDeleted($history);
        } else {
            $this->assertModelExists($history);
        }
    }

    public static function historyTypes(): array
    {
        return [['import'], ['active lesson'], ['soft-deleted lesson']];
    }

    public function test_service_reloads_stale_period_before_merging_patch_and_validating_dates(): void
    {
        $period = AcademicPeriod::factory()->create();
        $service = app(AcademicPeriodService::class);
        $service->update($period->user, $period, ['ends_on' => '2027-02-01']);
        $updated = $service->update($period->user, $period, ['starts_on' => '2027-01-01']);
        $this->assertSame('2027-02-01', $updated->ends_on->format('Y-m-d'));
        $this->assertSame('2027-01-01', $updated->starts_on->format('Y-m-d'));
    }

    public function test_missing_resource_is_not_found_even_with_invalid_patch(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/academic-periods/999999')->assertNotFound();
        $this->patchJson('/api/academic-periods/999999', ['name' => []])->assertNotFound();
        $this->deleteJson('/api/academic-periods/999999')->assertNotFound();
    }
}
