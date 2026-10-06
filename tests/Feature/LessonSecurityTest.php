<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Models\AcademicPeriod;
use App\Models\Lesson;
use App\Models\ScheduleImportBatch;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LessonSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function url(Lesson $lesson): string
    {
        return '/api/lessons/'.$lesson->id;
    }

    private function values(Subject $subject, array $overrides = []): array
    {
        return array_replace(['subject_id' => $subject->id, 'type' => 'lecture',
            'starts_at' => '2026-10-06T09:00:00Z', 'ends_at' => '2026-10-06T10:00:00Z'], $overrides);
    }

    public static function associations(): array
    {
        return [
            'subject' => ['subject_id', Subject::class],
            'teacher' => ['teacher_id', Teacher::class],
            'period' => ['academic_period_id', AcademicPeriod::class],
        ];
    }

    public function test_foreign_targets_are_hidden_even_before_invalid_patch_validation(): void
    {
        $lesson = Lesson::factory()->create()->refresh();
        $before = $lesson->getAttributes();
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->getJson($this->url($lesson).'?user_id='.$lesson->user_id)->assertNotFound();
        $this->patchJson($this->url($lesson), ['room' => 'Stolen', 'user_id' => $user->id])->assertNotFound();
        $this->patchJson($this->url($lesson), ['subject_id' => null, 'starts_at' => 'invalid'])->assertNotFound();
        $this->deleteJson($this->url($lesson))->assertNotFound();
        $this->assertSame($before, $lesson->refresh()->getAttributes());
    }

    #[DataProvider('associations')]
    public function test_foreign_and_missing_associations_are_identical_errors_without_mutation(string $field, string $model): void
    {
        $lesson = Lesson::factory()->create()->refresh();
        $foreign = $model::factory()->create();
        $before = $lesson->getAttributes();
        $this->actingAs($lesson->user, 'web');
        $errors = [];
        foreach ([$foreign->id, 999999] as $id) {
            $create = $this->postJson('/api/lessons', $this->values($lesson->subject, [$field => $id]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            $update = $this->patchJson($this->url($lesson), [$field => $id, 'room' => 'Changed'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertSame($create->json(), $update->json());
            $errors[] = $create->json();
        }
        $this->assertSame($errors[0], $errors[1]);
        $this->assertSame($before, $lesson->refresh()->getAttributes());
        $this->assertDatabaseCount('lessons', 1);
    }

    public static function legacyRepairs(): array
    {
        return [
            'subject replacement' => ['subject_id', Subject::class, false],
            'teacher replacement' => ['teacher_id', Teacher::class, false],
            'teacher detach' => ['teacher_id', Teacher::class, true],
            'period replacement' => ['academic_period_id', AcademicPeriod::class, false],
            'period detach' => ['academic_period_id', AcademicPeriod::class, true],
        ];
    }

    #[DataProvider('legacyRepairs')]
    public function test_legacy_foreign_link_is_masked_not_repaired_and_requires_explicit_valid_patch(string $field, string $model, bool $detach): void
    {
        $foreign = $model::factory()->create();
        $lesson = Lesson::factory()->create([$field => $foreign->id])->refresh();
        $user = $lesson->user;
        $before = $lesson->getAttributes();
        $foreignBefore = $foreign->refresh()->getAttributes();
        $this->assertNotSame($foreign->user_id, $lesson->user_id);
        $this->actingAs($user, 'web');

        $this->getJson($this->url($lesson))->assertOk()->assertJsonPath('data.'.$field, null);
        $this->assertSame($before, $lesson->refresh()->getAttributes());
        $this->patchJson($this->url($lesson), ['room' => 'Unrelated change'])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $lesson->refresh()->getAttributes());

        $owned = $model::factory()->for($user)->create();
        $id = $detach ? null : $owned->id;
        $this->patchJson($this->url($lesson), [$field => $id, 'user_id' => $foreign->user_id])->assertOk()->assertJsonPath('data.'.$field, $id);
        $this->assertSame($id, $lesson->refresh()->$field);
        $this->assertSame($user->id, $lesson->user_id);
        $this->patchJson($this->url($lesson), ['room' => 'Repaired'])->assertOk()->assertJsonPath('data.room', 'Repaired');
        $this->assertSame($foreignBefore, $foreign->refresh()->getAttributes());
    }

    public function test_all_three_foreign_ids_are_masked_without_exposing_internal_metadata_or_mutating_get(): void
    {
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        $subject = Subject::factory()->for($foreign)->create();
        $teacher = Teacher::factory()->for($foreign)->create();
        $period = AcademicPeriod::factory()->for($foreign)->create();
        $batch = ScheduleImportBatch::factory()->for($period)->create();
        $original = Lesson::factory()->for($user)->create(['status' => LessonStatus::Replaced]);
        $lesson = Lesson::factory()->for($user)->for($subject)->for($teacher)->for($period)->create([
            'schedule_import_batch_id' => $batch->id, 'import_fingerprint' => str_repeat('b', 64), 'replaces_lesson_id' => $original->id,
        ])->refresh();
        $before = $lesson->getAttributes();
        $this->actingAs($user, 'web')->getJson($this->url($lesson))->assertOk()->assertExactJson(['data' => [
            'id' => $lesson->id, 'academic_period_id' => null, 'subject_id' => null, 'teacher_id' => null,
            'type' => 'lecture', 'room' => null, 'starts_at' => '2026-10-05T06:00:00Z', 'ends_at' => '2026-10-05T07:30:00Z', 'status' => 'active',
        ]]);
        $this->assertSame($before, $lesson->refresh()->getAttributes());
    }

    public function test_existing_import_and_replacement_metadata_is_immutable_and_period_check_is_controlled(): void
    {
        $period = AcademicPeriod::factory()->create();
        $batch = ScheduleImportBatch::factory()->for($period)->create();
        $original = Lesson::factory()->for($period->user)->create(['status' => LessonStatus::Replaced]);
        $lesson = Lesson::factory()->for($period->user)->for($period)->create([
            'schedule_import_batch_id' => $batch->id, 'import_fingerprint' => str_repeat('a', 64), 'replaces_lesson_id' => $original->id,
        ])->refresh();
        $before = $lesson->getAttributes();
        $this->actingAs($period->user, 'web')->patchJson($this->url($lesson), [
            'room' => '302', 'status' => 'cancelled', 'schedule_import_batch_id' => null, 'import_fingerprint' => null, 'replaces_lesson_id' => null,
        ])->assertOk();
        $lesson->refresh();
        foreach (['schedule_import_batch_id', 'import_fingerprint', 'replaces_lesson_id', 'status'] as $field) {
            $this->assertSame($before[$field], $lesson->getRawOriginal($field));
        }
        $before = $lesson->getAttributes();
        $this->patchJson($this->url($lesson), ['academic_period_id' => null])->assertUnprocessable()->assertJsonValidationErrors('academic_period_id');
        $this->assertSame($before, $lesson->refresh()->getAttributes());
    }

    public function test_period_change_cannot_leak_a_persisted_fingerprint_unique_error(): void
    {
        $period = AcademicPeriod::factory()->create();
        $next = AcademicPeriod::factory()->for($period->user)->create();
        $lesson = Lesson::factory()->for($period->user)->for($period)->create(['import_fingerprint' => str_repeat('a', 64)])->refresh();
        Lesson::factory()->for($period->user)->for($next)->create([
            'import_fingerprint' => str_repeat('a', 64), 'starts_at' => '2026-10-05 08:00:00', 'ends_at' => '2026-10-05 09:00:00',
        ]);
        $before = $lesson->getAttributes();
        $this->actingAs($period->user, 'web')->patchJson($this->url($lesson), ['academic_period_id' => $next->id])
            ->assertUnprocessable()->assertJsonValidationErrors('academic_period_id');
        $this->assertSame($before, $lesson->refresh()->getAttributes());
    }
}
